<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\LLMClient;
use App\Models\Brief;
use App\Models\Document;
use App\Models\Dossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateBriefsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_briefs_for_top_scoring_candidates_within_limit(): void
    {
        $this->bindFakeLLM();

        $top    = $this->makeCandidateDossier(0.9, documents: 3);
        $middle = $this->makeCandidateDossier(0.7, documents: 3);
        $bottom = $this->makeCandidateDossier(0.5, documents: 3);
        $this->makeNonCandidateDossier();

        $this->artisan('briefs:generate', ['--limit' => 2])->assertSuccessful();

        $this->assertDatabaseCount('briefs', 2);
        $this->assertDatabaseHas('briefs', ['dossier_id' => $top->id, 'status' => 'draft']);
        $this->assertDatabaseHas('briefs', ['dossier_id' => $middle->id]);
        $this->assertDatabaseMissing('briefs', ['dossier_id' => $bottom->id]);

        $brief = Brief::where('dossier_id', $top->id)->firstOrFail();

        $this->assertSame('Weekly brief title', $brief->title);
        $this->assertSame(now()->startOfWeek()->toDateString(), $brief->period_start->toDateString());
        $this->assertEqualsWithDelta(0.9, $brief->score, 0.001);
    }

    public function test_default_limit_comes_from_config(): void
    {
        config(['pipeline.briefs.max_per_run' => 1]);
        $this->bindFakeLLM();

        $this->makeCandidateDossier(0.9, documents: 3);
        $this->makeCandidateDossier(0.7, documents: 3);

        $this->artisan('briefs:generate')->assertSuccessful();

        $this->assertDatabaseCount('briefs', 1);
    }

    public function test_brief_payload_contains_synthesis_sources_and_motivation(): void
    {
        $this->bindFakeLLM();

        $dossier   = $this->makeCandidateDossier(0.84, documents: 0);
        $document  = Document::factory()->create([
            'title' => 'Agents in production',
            'url'   => 'https://example.com/agents',
        ]);
        $dossier->documents()->attach($document->id, ['similarity' => 0.75]);

        $this->artisan('briefs:generate')->assertSuccessful();

        $payload = Brief::firstOrFail()->payload;

        $this->assertSame($dossier->name, $payload['theme']);
        $this->assertSame('Central thesis.', $payload['thesis']);
        $this->assertSame('First claim', $payload['key_claims'][0]['claim']);
        $this->assertSame(['Counter one'], $payload['counterarguments']);
        $this->assertSame(['Risky one'], $payload['risky_claims']);
        $this->assertSame('linkedin-post', $payload['suggested_format']);
        $this->assertSame(['Angle one'], $payload['editorial_angles']);

        // Fonti citabili: prese dai document del dossier, con URL.
        $this->assertCount(1, $payload['sources']);
        $this->assertSame('Agents in production', $payload['sources'][0]['title']);
        $this->assertSame('https://example.com/agents', $payload['sources'][0]['url']);

        // Motivazione: spiegazione leggibile ricostruita dallo score breakdown.
        $this->assertIsString($payload['why_now']);
        $this->assertStringContainsString('score 0.8400', $payload['why_now']);
        $this->assertSame(0.84, $payload['score_breakdown']['score']);
    }

    public function test_is_idempotent_within_the_same_week(): void
    {
        $fake = $this->bindFakeLLM();

        $this->makeCandidateDossier(0.9, documents: 3);

        $this->artisan('briefs:generate')->assertSuccessful();
        $this->artisan('briefs:generate')->assertSuccessful();

        $this->assertDatabaseCount('briefs', 1);
        $this->assertSame(1, $fake->calls);
    }

    public function test_previous_week_brief_does_not_block_generation(): void
    {
        $this->bindFakeLLM();

        $dossier = $this->makeCandidateDossier(0.9, documents: 3);

        Brief::factory()->create([
            'dossier_id'   => $dossier->id,
            'period_start' => now()->subWeek()->startOfWeek()->toDateString(),
        ]);

        $this->artisan('briefs:generate')->assertSuccessful();

        $this->assertSame(2, Brief::where('dossier_id', $dossier->id)->count());
        $this->assertDatabaseHas('briefs', [
            'dossier_id'   => $dossier->id,
            'period_start' => now()->startOfWeek()->toDateString(),
        ]);
    }

    public function test_dry_run_calls_no_llm_and_writes_nothing(): void
    {
        $fake = $this->bindFakeLLM();

        $dossier = $this->makeCandidateDossier(0.9, documents: 3);

        $this->artisan('briefs:generate', ['--dry-run' => true])
            ->expectsOutputToContain($dossier->slug)
            ->assertSuccessful();

        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseCount('briefs', 0);
    }

    public function test_skips_candidate_without_documents_in_window(): void
    {
        $fake = $this->bindFakeLLM();

        $dossier = $this->makeCandidateDossier(0.9, documents: 0);

        $stale = Document::factory()->create(['created_at' => now()->subDays(90)]);
        $dossier->documents()->attach($stale->id, ['similarity' => 0.6]);

        $this->artisan('briefs:generate')
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();

        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseCount('briefs', 0);
    }

    public function test_invalid_llm_json_fails_without_blocking_other_dossiers(): void
    {
        $fake = $this->bindFakeLLM(raw: 'this is not JSON');

        $this->makeCandidateDossier(0.9, documents: 3);
        $this->makeCandidateDossier(0.7, documents: 3);

        $this->artisan('briefs:generate')->assertFailed();

        // Entrambi i dossier sono stati tentati: il primo fallimento non
        // interrompe il run.
        $this->assertSame(2, $fake->calls);
        $this->assertDatabaseCount('briefs', 0);
    }

    // --- helpers ---

    private function makeCandidateDossier(float $score, int $documents): Dossier
    {
        $dossier = Dossier::factory()->create([
            'brief_score'        => $score,
            'score_breakdown'    => $this->makeBreakdown($score),
            'is_brief_candidate' => true,
            'scored_at'          => now(),
        ]);

        Document::factory($documents)->create()->each(
            fn (Document $document) => $dossier->documents()->attach($document->id, ['similarity' => 0.7]),
        );

        return $dossier;
    }

    private function makeNonCandidateDossier(): Dossier
    {
        return Dossier::factory()->create([
            'brief_score'        => 0.95,
            'is_brief_candidate' => false,
            'scored_at'          => now(),
        ]);
    }

    /**
     * Breakdown con la stessa struttura persistita da DossierScoringService,
     * sufficiente perché explain() possa ricostruire la motivazione.
     *
     * @return array<string, mixed>
     */
    private function makeBreakdown(float $score): array
    {
        $component = fn (array $extra) => $extra + [
            'normalized'     => 0.5,
            'weight'         => 0.25,
            'weighted_value' => 0.125,
        ];

        return [
            'window_days' => 30,
            'score'       => $score,
            'components'  => [
                'volume'    => $component(['raw' => 5, 'saturation' => 10]),
                'diversity' => $component(['raw' => 2, 'saturation' => 4]),
                'recency'   => $component(['days_since_last_document' => 3.0, 'half_life_days' => 7.0]),
                'cohesion'  => $component(['avg_similarity' => 0.61]),
            ],
            'candidacy'   => [
                'is_candidate' => true,
                'checks'       => [
                    'min_documents_in_window' => ['required' => 3, 'actual' => 5, 'passed' => true],
                    'min_distinct_sources_in_window' => ['required' => 2, 'actual' => 2, 'passed' => true],
                ],
            ],
        ];
    }

    /**
     * Fake LLM che conta le chiamate: permette di verificare che dry-run e
     * run idempotenti non chiamino l'API.
     */
    private function bindFakeLLM(?string $raw = null): object
    {
        $json = $raw ?? json_encode([
            'title'            => 'Weekly brief title',
            'thesis'           => 'Central thesis.',
            'key_claims'       => [
                ['claim' => 'First claim', 'source_urls' => ['https://example.com/agents']],
            ],
            'counterarguments' => ['Counter one'],
            'risky_claims'     => ['Risky one'],
            'suggested_format' => 'linkedin-post',
            'editorial_angles' => ['Angle one'],
        ]);

        $fake = new class ($json) implements LLMClient {
            public int $calls = 0;

            public function __construct(private readonly string $json) {}

            public function complete(string $prompt, int $maxTokens = 1024): string
            {
                $this->calls++;

                return $this->json;
            }
        };

        $this->app->instance(LLMClient::class, $fake);

        return $fake;
    }
}
