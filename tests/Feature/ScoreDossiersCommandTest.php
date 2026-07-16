<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Dossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreDossiersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scores_and_persists_all_dossiers(): void
    {
        $candidate = Dossier::factory()->create(['slug' => 'candidate-dossier']);
        $empty     = Dossier::factory()->create(['slug' => 'empty-dossier']);

        $this->attachDocument($candidate, 'source-a');
        $this->attachDocument($candidate, 'source-a');
        $this->attachDocument($candidate, 'source-b');

        $this->artisan('dossiers:score')
            ->expectsOutputToContain('candidate-dossier')
            ->expectsOutputToContain('Scored 2 dossier(s); 1 brief candidate(s).')
            ->assertSuccessful();

        $candidate->refresh();
        $empty->refresh();

        $this->assertNotNull($candidate->brief_score);
        $this->assertTrue($candidate->is_brief_candidate);
        $this->assertIsArray($candidate->score_breakdown);
        $this->assertArrayHasKey('components', $candidate->score_breakdown);
        $this->assertNotNull($candidate->scored_at);

        $this->assertNotNull($empty->brief_score);
        $this->assertFalse($empty->is_brief_candidate);
        $this->assertNotNull($empty->scored_at);
    }

    public function test_dry_run_prints_explanation_without_persisting(): void
    {
        $dossier = Dossier::factory()->create();

        $this->attachDocument($dossier, 'source-a');
        $this->attachDocument($dossier, 'source-a');
        $this->attachDocument($dossier, 'source-b');

        $this->artisan('dossiers:score', ['--dry-run' => true])
            ->expectsOutputToContain('CANDIDATO a brief')
            ->expectsOutputToContain('[dry-run] Would score 1 dossier(s); 1 brief candidate(s).')
            ->assertSuccessful();

        $dossier->refresh();

        $this->assertNull($dossier->brief_score);
        $this->assertNull($dossier->score_breakdown);
        $this->assertFalse($dossier->is_brief_candidate);
        $this->assertNull($dossier->scored_at);
    }

    public function test_warns_when_no_dossiers_exist(): void
    {
        $this->artisan('dossiers:score')
            ->expectsOutputToContain('No dossiers found.')
            ->assertSuccessful();
    }

    // --- helpers ---

    private function attachDocument(Dossier $dossier, string $source): Document
    {
        $document = Document::factory()->create([
            'source'     => $source,
            'status'     => 'embedded',
            'created_at' => now()->subDay(),
        ]);

        $dossier->documents()->attach($document->id, ['similarity' => 0.7]);

        return $document;
    }
}
