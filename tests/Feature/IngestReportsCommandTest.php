<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EmbedNewsItemJob;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class IngestReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([EmbedNewsItemJob::class]);

        $this->tmpDir = sys_get_temp_dir() . '/ai-news-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->seed(TagSeeder::class);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*.json') ?: []);
        rmdir($this->tmpDir);

        parent::tearDown();
    }

    // --- helpers ---

    private function writeFixture(array $payload, string $filename = 'report.json'): string
    {
        $path = $this->tmpDir . '/' . $filename;
        file_put_contents($path, json_encode($payload));

        return $path;
    }

    private function validPayload(): array
    {
        return [
            'report_date' => '2026-05-15',
            'source_ai'   => 'claude-opus-4-7',
            'items'       => [
                [
                    'section'               => 'strategic',
                    'title'                 => 'OpenAI raises $40B',
                    'summary'               => 'OpenAI ha raccolto 40 miliardi in un round Series F guidato da SoftBank.',
                    'entities'              => ['OpenAI', 'SoftBank'],
                    'event_date'            => '2026-05-14',
                    'sources'               => [
                        ['name' => 'TechCrunch', 'url' => 'https://techcrunch.com/openai'],
                        ['name' => 'Reuters',    'url' => 'https://reuters.com/openai'],
                    ],
                    'importance_self_rated' => 5,
                    'raw_tags'              => ['funding', 'partnership'],
                ],
            ],
        ];
    }

    // --- tests ---

    public function test_valid_file_creates_correct_records_in_all_tables(): void
    {
        $path = $this->writeFixture($this->validPayload());

        $this->artisan('reports:ingest', ['path' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('news_items', 1);
        $this->assertDatabaseCount('news_item_sources', 2);
        $this->assertDatabaseCount('entities', 2);
        $this->assertDatabaseCount('news_item_entity', 2);
        $this->assertDatabaseCount('news_item_tag', 2); // funding + partnership both in taxonomy

        $this->assertDatabaseHas('reports', [
            'report_date' => '2026-05-15',
            'source_ai'   => 'claude-opus-4-7',
        ]);

        $this->assertDatabaseHas('news_item_sources', ['name' => 'TechCrunch', 'position' => 0]);
        $this->assertDatabaseHas('news_item_sources', ['name' => 'Reuters',    'position' => 1]);

        $this->assertDatabaseHas('entities', ['name' => 'OpenAI']);
        $this->assertDatabaseHas('entities', ['name' => 'SoftBank']);
    }

    public function test_invalid_file_creates_no_records(): void
    {
        $payload = [
            'report_date' => 'not-a-date',
            'source_ai'   => 'claude',
            'items'       => [
                [
                    'section' => 'invalid_section',
                    'title'   => 'Test',
                ],
            ],
        ];

        $path = $this->writeFixture($payload);

        $this->artisan('reports:ingest', ['path' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('news_items', 0);
    }

    public function test_duplicate_payload_is_skipped(): void
    {
        $path = $this->writeFixture($this->validPayload());

        $this->artisan('reports:ingest', ['path' => $path])->assertExitCode(0);
        $this->artisan('reports:ingest', ['path' => $path])->assertExitCode(0);

        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('news_items', 1);
    }

    public function test_null_event_date_and_importance_are_accepted(): void
    {
        $payload                                    = $this->validPayload();
        $payload['items'][0]['event_date']            = null;
        $payload['items'][0]['importance_self_rated'] = null;

        $path = $this->writeFixture($payload);

        $this->artisan('reports:ingest', ['path' => $path])->assertExitCode(0);

        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseHas('news_items', [
            'event_date'            => null,
            'importance_self_rated' => null,
        ]);
    }

    public function test_raw_tags_are_mapped_case_insensitively(): void
    {
        $payload                       = $this->validPayload();
        $payload['items'][0]['raw_tags'] = ['Funding', 'funding', 'FUNDING'];

        $path = $this->writeFixture($payload);

        $this->artisan('reports:ingest', ['path' => $path])->assertExitCode(0);

        // All three variants resolve to slug 'funding' → deduplicated → one pivot row
        $this->assertDatabaseCount('news_item_tag', 1);
    }

    public function test_directory_ingests_all_json_files(): void
    {
        $payload1              = $this->validPayload();
        $payload2              = $this->validPayload();
        $payload2['source_ai'] = 'gpt-5'; // different source → different hash

        $this->writeFixture($payload1, 'claude.json');
        $this->writeFixture($payload2, 'gpt.json');

        $this->artisan('reports:ingest', ['path' => $this->tmpDir])
            ->assertExitCode(0);

        $this->assertDatabaseCount('reports', 2);
    }

    public function test_missing_path_returns_failure(): void
    {
        $this->artisan('reports:ingest', ['path' => '/nonexistent/path/report.json'])
            ->assertExitCode(1);
    }
}
