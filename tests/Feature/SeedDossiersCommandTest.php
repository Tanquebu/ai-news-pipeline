<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedDossiersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_SLUGS = [
        'coding-agent',
        'agenti-ai-engineering',
        'rag-memoria-context',
        'llm-locali-hardware',
        'governance-sicurezza-ai',
        'ai-pa-concorsi',
        'mcp-tooling',
    ];

    public function test_creates_the_initial_dossiers_with_null_centroid(): void
    {
        $this->artisan('dossiers:seed')->assertSuccessful();

        $this->assertSame(count(self::EXPECTED_SLUGS), Dossier::count());

        foreach (self::EXPECTED_SLUGS as $slug) {
            $dossier = Dossier::where('slug', $slug)->first();

            $this->assertNotNull($dossier, "Missing dossier: {$slug}");
            $this->assertNotEmpty($dossier->description);
            $this->assertSame(0, $dossier->document_count);
        }

        $this->assertSame(
            0,
            (int) DB::scalar('SELECT count(*) FROM dossiers WHERE centroid IS NOT NULL'),
        );
    }

    public function test_is_idempotent_and_preserves_existing_dossiers(): void
    {
        $this->artisan('dossiers:seed')->assertSuccessful();

        // Modifica manuale: il secondo run non deve sovrascriverla.
        Dossier::where('slug', 'coding-agent')->update(['description' => 'custom description']);

        $this->artisan('dossiers:seed')->assertSuccessful();

        $this->assertSame(count(self::EXPECTED_SLUGS), Dossier::count());
        $this->assertSame(
            'custom description',
            Dossier::where('slug', 'coding-agent')->value('description'),
        );
    }
}
