<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Dossier;
use App\Services\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsolidateDossiersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pipeline.dossier.similarity_threshold' => 0.45]);
    }

    public function test_bootstraps_null_centroids_from_description(): void
    {
        $dossier = Dossier::factory()->create();

        $service = $this->createMock(EmbeddingService::class);
        $service->expects($this->once())
            ->method('embedText')
            ->willReturn($this->embedding([0 => 1.0]));
        $this->app->instance(EmbeddingService::class, $service);

        $this->artisan('dossiers:consolidate')->assertSuccessful();

        $centroid = $this->centroidOf($dossier);
        $this->assertNotNull($centroid);
        $this->assertEqualsWithDelta(1.0, $centroid[0], 0.0001);
    }

    public function test_assigns_orphan_documents_above_threshold(): void
    {
        $dossier = Dossier::factory()->create();
        $this->setCentroid($dossier, $this->vector([0 => 1.0]));

        $matching = $this->makeEmbeddedDocument($this->vector([0 => 1.0]));
        $farAway  = $this->makeEmbeddedDocument($this->vector([1 => 1.0]));

        $this->mockEmbeddingServiceNeverCalled();

        $this->artisan('dossiers:consolidate')->assertSuccessful();

        $this->assertSame([$dossier->id], $matching->dossiers()->pluck('dossiers.id')->all());
        $this->assertSame(0, $farAway->dossiers()->count());
        $this->assertSame(1, $dossier->fresh()->document_count);
    }

    public function test_recalculates_centroids_and_counts_from_member_documents(): void
    {
        $dossier = Dossier::factory()->create();
        // Centroide e conteggio volutamente sbagliati: il consolidamento
        // deve riallinearli ai membri reali.
        $this->setCentroid($dossier, $this->vector([5 => 1.0]), documentCount: 42);

        $memberA = $this->makeEmbeddedDocument($this->vector([0 => 1.0]));
        $memberB = $this->makeEmbeddedDocument($this->vector([1 => 1.0]));

        $dossier->documents()->attach([$memberA->id, $memberB->id]);

        $this->mockEmbeddingServiceNeverCalled();

        $this->artisan('dossiers:consolidate')->assertSuccessful();

        $this->assertSame(2, $dossier->fresh()->document_count);

        // Media dei due membri: [0.5, 0.5, 0, ...].
        $centroid = $this->centroidOf($dossier);
        $this->assertEqualsWithDelta(0.5, $centroid[0], 0.0001);
        $this->assertEqualsWithDelta(0.5, $centroid[1], 0.0001);
        $this->assertEqualsWithDelta(0.0, $centroid[5], 0.0001);
    }

    public function test_is_idempotent(): void
    {
        $dossier = Dossier::factory()->create();

        $document = $this->makeEmbeddedDocument($this->vector([0 => 1.0]));

        // Il bootstrap embedda la descrizione UNA sola volta: al secondo
        // run il centroide non è più null.
        $service = $this->createMock(EmbeddingService::class);
        $service->expects($this->once())
            ->method('embedText')
            ->willReturn($this->embedding([0 => 1.0]));
        $this->app->instance(EmbeddingService::class, $service);

        $this->artisan('dossiers:consolidate')->assertSuccessful();

        $pivotCount = DB::table('document_dossier')->count();
        $centroid   = $this->centroidOf($dossier);

        $this->artisan('dossiers:consolidate')->assertSuccessful();

        $this->assertSame($pivotCount, DB::table('document_dossier')->count());
        $this->assertSame(1, $dossier->fresh()->document_count);
        $this->assertEqualsWithDelta($centroid[0], $this->centroidOf($dossier)[0], 0.0001);
        $this->assertSame([$dossier->id], $document->dossiers()->pluck('dossiers.id')->all());
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $dossier = Dossier::factory()->create();
        $this->makeEmbeddedDocument($this->vector([0 => 1.0]));

        $this->mockEmbeddingServiceNeverCalled();

        $this->artisan('dossiers:consolidate', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(DB::scalar('SELECT centroid::text FROM dossiers WHERE id = ?', [$dossier->id]));
        $this->assertSame(0, DB::table('document_dossier')->count());
    }

    // --- helpers ---

    private function mockEmbeddingServiceNeverCalled(): void
    {
        $service = $this->createMock(EmbeddingService::class);
        $service->expects($this->never())->method('embedText');
        $this->app->instance(EmbeddingService::class, $service);
    }

    /**
     * @param array<int, float> $components
     *
     * @return float[]
     */
    private function embedding(array $components): array
    {
        $values = array_fill(0, (int) config('pipeline.embedding.dimensions', 1536), 0.0);

        foreach ($components as $index => $value) {
            $values[$index] = $value;
        }

        return $values;
    }

    /** @param array<int, float> $components */
    private function vector(array $components): string
    {
        return '[' . implode(',', $this->embedding($components)) . ']';
    }

    private function makeEmbeddedDocument(string ...$chunkEmbeddings): Document
    {
        $document = Document::factory()->create(['status' => 'embedded']);

        foreach ($chunkEmbeddings as $index => $embedding) {
            $chunk = DocumentChunk::factory()->create([
                'document_id' => $document->id,
                'chunk_index' => $index,
            ]);

            DB::table('document_chunks')->where('id', $chunk->id)->update(['embedding' => $embedding]);
        }

        return $document;
    }

    private function setCentroid(Dossier $dossier, string $vector, int $documentCount = 0): void
    {
        DB::table('dossiers')->where('id', $dossier->id)->update([
            'centroid'       => $vector,
            'document_count' => $documentCount,
        ]);
    }

    /** @return float[]|null */
    private function centroidOf(Dossier $dossier): ?array
    {
        $raw = DB::scalar('SELECT centroid::text FROM dossiers WHERE id = ?', [$dossier->id]);

        if ($raw === null) {
            return null;
        }

        return array_map('floatval', explode(',', trim((string) $raw, '[]')));
    }
}
