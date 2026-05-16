<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TagSeeder extends Seeder
{
    private const TAGS = [
        ['slug' => 'mcp',                      'name' => 'MCP'],
        ['slug' => 'agentic-frameworks',        'name' => 'Agentic Frameworks'],
        ['slug' => 'regulation-eu',             'name' => 'Regulation (EU)'],
        ['slug' => 'regulation-us',             'name' => 'Regulation (US)'],
        ['slug' => 'regulation-china',          'name' => 'Regulation (China)'],
        ['slug' => 'funding',                   'name' => 'Funding'],
        ['slug' => 'acquisition',               'name' => 'Acquisition'],
        ['slug' => 'model-release',             'name' => 'Model Release'],
        ['slug' => 'benchmark',                 'name' => 'Benchmark'],
        ['slug' => 'coding-tools',              'name' => 'Coding Tools'],
        ['slug' => 'security-prompt-injection', 'name' => 'Security: Prompt Injection'],
        ['slug' => 'security-other',            'name' => 'Security (Other)'],
        ['slug' => 'hardware',                  'name' => 'Hardware'],
        ['slug' => 'inference-optimization',    'name' => 'Inference Optimization'],
        ['slug' => 'multimodal',                'name' => 'Multimodal'],
        ['slug' => 'reasoning',                 'name' => 'Reasoning'],
        ['slug' => 'context-window',            'name' => 'Context Window'],
        ['slug' => 'open-source',               'name' => 'Open Source'],
        ['slug' => 'partnership',               'name' => 'Partnership'],
        ['slug' => 'ipo',                       'name' => 'IPO'],
        ['slug' => 'research-paper',            'name' => 'Research Paper'],
        ['slug' => 'enterprise-adoption',       'name' => 'Enterprise Adoption'],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::TAGS as $tag) {
            DB::table('tags')->updateOrInsert(
                ['slug' => $tag['slug']],
                ['name' => $tag['name'], 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }
}
