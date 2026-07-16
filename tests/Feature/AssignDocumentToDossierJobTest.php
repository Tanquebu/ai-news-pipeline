<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AssignDocumentToDossierJob;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Dossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssignDocumentToDossierJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pipeline.dossier.similarity_threshold' => 0.45]);
    }

    public function test_document_above_threshold_is_assigned_to_closest_dossier(): void
    {
        $target = Dossier::factory()->create();
        $other  = Dossier::factory()->create();

        $this->setCentroid($target, $this->vector([0 => 1.0]));
        $this->setCentroid($other, $this->vector([1 => 1.0]));

        // Due chunk: la media [0.9, 0.1, ...] è vicina all'asse 0.
        $document = $this->makeEmbeddedDocument(
            $this->vector([0 => 1.0, 1 => 0.2]),
            $this->vector([0 => 0.8]),
        );

        $this->app->call([new AssignDocumentToDossierJob($document->id), 'handle']);

        $this->assertSame([$target->id], $document->dossiers()->pluck('dossiers.id')->all());
        $this->assertSame(1, $target->fresh()->document_count);
        $this->assertSame(0, $other->fresh()->document_count);

        $similarity = (float) DB::table('document_dossier')
            ->where('document_id', $document->id)
            ->value('similarity');
        $this->assertGreaterThanOrEqual(0.45, $similarity);

        // Primo document (count era 0): il centroide diventa l'embedding
        // del document, cioè la media dei suoi chunk.
        $centroid = $this->centroidOf($target);
        $this->assertEqualsWithDelta(0.9, $centroid[0], 0.0001);
        $this->assertEqualsWithDelta(0.1, $centroid[1], 0.0001);
    }

    public function test_document_below_threshold_stays_orphan_and_no_dossier_is_created(): void
    {
        $dossier = Dossier::factory()->create();
        $this->setCentroid($dossier, $this->vector([0 => 1.0]));

        // Ortogonale al centroide: similarità 0.
        $document = $this->makeEmbeddedDocument($this->vector([1 => 1.0]));

        $this->app->call([new AssignDocumentToDossierJob($document->id), 'handle']);

        $this->assertSame(0, $document->dossiers()->count());
        $this->assertSame(1, Dossier::count());
        $this->assertSame(0, $dossier->fresh()->document_count);
    }

    public function test_no_assignment_when_all_centroids_are_null(): void
    {
        Dossier::factory()->count(2)->create();

        $document = $this->makeEmbeddedDocument($this->vector([0 => 1.0]));

        $this->app->call([new AssignDocumentToDossierJob($document->id), 'handle']);

        $this->assertSame(0, DB::table('document_dossier')->count());
    }

    public function test_centroid_is_updated_as_incremental_mean(): void
    {
        $dossier = Dossier::factory()->create();
        $this->setCentroid($dossier, $this->vector([0 => 1.0]), documentCount: 1);

        // Similarità col centroide: 0.8 (vettore unitario [0.8, 0.6]).
        $document = $this->makeEmbeddedDocument($this->vector([0 => 0.8, 1 => 0.6]));

        $this->app->call([new AssignDocumentToDossierJob($document->id), 'handle']);

        $this->assertSame(2, $dossier->fresh()->document_count);

        // Media incrementale: ([1,0] * 1 + [0.8,0.6]) / 2 = [0.9, 0.3].
        $centroid = $this->centroidOf($dossier);
        $this->assertEqualsWithDelta(0.9, $centroid[0], 0.0001);
        $this->assertEqualsWithDelta(0.3, $centroid[1], 0.0001);
    }

    public function test_same_document_is_not_assigned_twice(): void
    {
        $dossier = Dossier::factory()->create();
        $this->setCentroid($dossier, $this->vector([0 => 1.0]));

        $document = $this->makeEmbeddedDocument($this->vector([0 => 1.0]));

        $this->app->call([new AssignDocumentToDossierJob($document->id), 'handle']);
        $centroidAfterFirstRun = $this->centroidOf($dossier);

        $this->app->call([new AssignDocumentToDossierJob($document->id), 'handle']);

        $this->assertSame(1, DB::table('document_dossier')->count());
        $this->assertSame(1, $dossier->fresh()->document_count);
        $this->assertEqualsWithDelta($centroidAfterFirstRun[0], $this->centroidOf($dossier)[0], 0.0001);
    }

    public function test_document_without_embedded_chunks_is_skipped(): void
    {
        $dossier = Dossier::factory()->create();
        $this->setCentroid($dossier, $this->vector([0 => 1.0]));

        $document = Document::factory()->create(['status' => 'embedded']);

        $this->app->call([new AssignDocumentToDossierJob($document->id), 'handle']);

        $this->assertSame(0, DB::table('document_dossier')->count());
    }

    public function test_document_not_yet_embedded_is_skipped(): void
    {
        $dossier = Dossier::factory()->create();
        $this->setCentroid($dossier, $this->vector([0 => 1.0]));

        $document = Document::factory()->create(['status' => 'chunked']);
        $chunk    = DocumentChunk::factory()->create([
            'document_id' => $document->id,
            'chunk_index' => 0,
        ]);
        DB::table('document_chunks')
            ->where('id', $chunk->id)
            ->update(['embedding' => $this->vector([0 => 1.0])]);

        $this->app->call([new AssignDocumentToDossierJob($document->id), 'handle']);

        $this->assertSame(0, DB::table('document_dossier')->count());
    }

    // --- helpers ---

    /**
     * Vettore pgvector testuale con i valori indicati agli indici dati
     * (tutto il resto a zero).
     *
     * @param array<int, float> $components
     */
    private function vector(array $components): string
    {
        $values = array_fill(0, (int) config('pipeline.embedding.dimensions', 1536), 0.0);

        foreach ($components as $index => $value) {
            $values[$index] = $value;
        }

        return '[' . implode(',', $values) . ']';
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

    /** @return float[] */
    private function centroidOf(Dossier $dossier): array
    {
        $raw = DB::scalar('SELECT centroid::text FROM dossiers WHERE id = ?', [$dossier->id]);

        return array_map('floatval', explode(',', trim((string) $raw, '[]')));
    }
}
