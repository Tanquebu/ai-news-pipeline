<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Dossier;
use App\Services\DossierScoringService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DossierScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private DossierScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pipeline.dossier.scoring' => [
            'window_days'             => 30,
            'volume_saturation'       => 10,
            'diversity_saturation'    => 4,
            'recency_half_life_days'  => 7,
            'weight_volume'           => 0.35,
            'weight_diversity'        => 0.25,
            'weight_recency'          => 0.25,
            'weight_cohesion'         => 0.15,
            'candidate_min_documents' => 3,
            'candidate_min_sources'   => 2,
        ]]);

        $this->service = app(DossierScoringService::class);
    }

    public function test_computes_components_on_known_fixture(): void
    {
        $dossier = Dossier::factory()->create();

        // 3 document nella finestra, 2 fonti distinte, ultimo 1 giorno fa,
        // similarità 0.6 / 0.7 / 0.8 (media 0.7).
        $this->attachDocument($dossier, 'source-a', now()->subDays(3), 0.6);
        $this->attachDocument($dossier, 'source-a', now()->subDays(2), 0.7);
        $this->attachDocument($dossier, 'source-b', now()->subDay(), 0.8);

        $breakdown = $this->service->evaluate($dossier);

        // volume: 3 / saturazione 10 = 0.3
        $this->assertSame(3, $breakdown['components']['volume']['raw']);
        $this->assertEqualsWithDelta(0.3, $breakdown['components']['volume']['normalized'], 0.0001);
        $this->assertEqualsWithDelta(0.105, $breakdown['components']['volume']['weighted_value'], 0.0001);

        // diversity: 2 fonti / saturazione 4 = 0.5
        $this->assertSame(2, $breakdown['components']['diversity']['raw']);
        $this->assertEqualsWithDelta(0.5, $breakdown['components']['diversity']['normalized'], 0.0001);
        $this->assertEqualsWithDelta(0.125, $breakdown['components']['diversity']['weighted_value'], 0.0001);

        // recency: ultimo document 1 giorno fa, half-life 7 → 2^(-1/7) ≈ 0.9057
        $this->assertEqualsWithDelta(1.0, $breakdown['components']['recency']['days_since_last_document'], 0.02);
        $this->assertEqualsWithDelta(0.9057, $breakdown['components']['recency']['normalized'], 0.005);

        // cohesion: media similarità pivot = 0.7
        $this->assertEqualsWithDelta(0.7, $breakdown['components']['cohesion']['avg_similarity'], 0.0001);
        $this->assertEqualsWithDelta(0.7, $breakdown['components']['cohesion']['normalized'], 0.0001);

        // score = 0.105 + 0.125 + ~0.2264 + 0.105 ≈ 0.5614
        $this->assertEqualsWithDelta(0.5614, $breakdown['score'], 0.002);

        $this->assertTrue($breakdown['candidacy']['is_candidate']);
    }

    public function test_volume_saturates_instead_of_growing_linearly(): void
    {
        $dossier = Dossier::factory()->create();

        // 15 document nella finestra, oltre la saturazione (10): la
        // componente si ferma a 1.0 (robustezza ai dossier catch-all).
        foreach (range(1, 15) as $i) {
            $this->attachDocument($dossier, "source-{$i}", now()->subDay(), 0.5);
        }

        $breakdown = $this->service->evaluate($dossier);

        $this->assertSame(15, $breakdown['components']['volume']['raw']);
        $this->assertEqualsWithDelta(1.0, $breakdown['components']['volume']['normalized'], 0.0001);
        $this->assertLessThanOrEqual(1.0, $breakdown['score']);
    }

    public function test_documents_outside_window_do_not_count_for_volume_and_diversity(): void
    {
        $dossier = Dossier::factory()->create();

        $this->attachDocument($dossier, 'source-a', now()->subDay(), 0.7);
        $this->attachDocument($dossier, 'source-b', now()->subDays(2), 0.7);
        // Fuori finestra (30gg): esclusi da volume e diversità.
        $this->attachDocument($dossier, 'source-c', now()->subDays(40), 0.7);

        $breakdown = $this->service->evaluate($dossier);

        $this->assertSame(2, $breakdown['components']['volume']['raw']);
        $this->assertSame(2, $breakdown['components']['diversity']['raw']);
        $this->assertFalse($breakdown['candidacy']['is_candidate']);
    }

    public function test_below_three_documents_is_not_candidate(): void
    {
        $dossier = Dossier::factory()->create();

        $this->attachDocument($dossier, 'source-a', now()->subDay(), 0.7);
        $this->attachDocument($dossier, 'source-b', now()->subDay(), 0.7);

        $breakdown = $this->service->evaluate($dossier);

        $this->assertFalse($breakdown['candidacy']['is_candidate']);
        $this->assertFalse($breakdown['candidacy']['checks']['min_documents_in_window']['passed']);
        $this->assertTrue($breakdown['candidacy']['checks']['min_distinct_sources_in_window']['passed']);
    }

    public function test_three_documents_from_single_source_is_not_candidate(): void
    {
        $dossier = Dossier::factory()->create();

        $this->attachDocument($dossier, 'source-a', now()->subDay(), 0.7);
        $this->attachDocument($dossier, 'source-a', now()->subDays(2), 0.7);
        $this->attachDocument($dossier, 'source-a', now()->subDays(3), 0.7);

        $breakdown = $this->service->evaluate($dossier);

        $this->assertFalse($breakdown['candidacy']['is_candidate']);
        $this->assertTrue($breakdown['candidacy']['checks']['min_documents_in_window']['passed']);
        $this->assertFalse($breakdown['candidacy']['checks']['min_distinct_sources_in_window']['passed']);
    }

    public function test_three_recent_documents_from_two_sources_is_candidate(): void
    {
        $dossier = Dossier::factory()->create();

        $this->attachDocument($dossier, 'source-a', now()->subDay(), 0.7);
        $this->attachDocument($dossier, 'source-a', now()->subDays(2), 0.7);
        $this->attachDocument($dossier, 'source-b', now()->subDays(3), 0.7);

        $breakdown = $this->service->evaluate($dossier);

        $this->assertTrue($breakdown['candidacy']['is_candidate']);
    }

    public function test_empty_dossier_scores_zero_and_is_not_candidate(): void
    {
        $dossier = Dossier::factory()->create();

        $breakdown = $this->service->evaluate($dossier);

        $this->assertEqualsWithDelta(0.0, $breakdown['score'], 0.0001);
        $this->assertNull($breakdown['components']['recency']['days_since_last_document']);
        $this->assertNull($breakdown['components']['cohesion']['avg_similarity']);
        $this->assertFalse($breakdown['candidacy']['is_candidate']);
    }

    public function test_persist_writes_score_breakdown_and_candidacy(): void
    {
        $dossier = Dossier::factory()->create();

        $this->attachDocument($dossier, 'source-a', now()->subDay(), 0.7);
        $this->attachDocument($dossier, 'source-a', now()->subDays(2), 0.7);
        $this->attachDocument($dossier, 'source-b', now()->subDays(3), 0.7);

        $breakdown = $this->service->evaluate($dossier);
        $this->service->persist($dossier, $breakdown);

        $dossier->refresh();

        $this->assertEqualsWithDelta($breakdown['score'], $dossier->brief_score, 0.0001);
        $this->assertTrue($dossier->is_brief_candidate);
        $this->assertNotNull($dossier->scored_at);
        $this->assertSame(
            $breakdown['components']['volume']['raw'],
            $dossier->score_breakdown['components']['volume']['raw'],
        );
    }

    public function test_explanation_is_reconstructable_from_breakdown(): void
    {
        $dossier = Dossier::factory()->create();

        $this->attachDocument($dossier, 'source-a', now()->subDay(), 0.7);
        $this->attachDocument($dossier, 'source-a', now()->subDays(2), 0.7);
        $this->attachDocument($dossier, 'source-b', now()->subDays(3), 0.7);

        $breakdown = $this->service->evaluate($dossier);

        $explanation = $this->service->explain($breakdown);

        $this->assertStringContainsString('CANDIDATO a brief', $explanation);
        $this->assertStringContainsString('volume:', $explanation);
        $this->assertStringContainsString('diversity:', $explanation);
        $this->assertStringContainsString('recency:', $explanation);
        $this->assertStringContainsString('cohesion:', $explanation);
        $this->assertStringContainsString('criteri:', $explanation);
        $this->assertStringContainsString('peso 0.35', $explanation);
    }

    // --- helpers ---

    private function attachDocument(
        Dossier $dossier,
        string $source,
        CarbonInterface $createdAt,
        float $similarity,
    ): Document {
        $document = Document::factory()->create([
            'source'     => $source,
            'status'     => 'embedded',
            'created_at' => $createdAt,
        ]);

        $dossier->documents()->attach($document->id, ['similarity' => $similarity]);

        return $document;
    }
}
