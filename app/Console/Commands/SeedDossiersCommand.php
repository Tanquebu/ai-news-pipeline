<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dossier;
use Illuminate\Console\Command;

class SeedDossiersCommand extends Command
{
    protected $signature = 'dossiers:seed';

    protected $description = 'Create the initial thematic dossiers (idempotent: existing slugs are left untouched)';

    /**
     * Dossier di partenza allineati ai temi reali del corpus. Le
     * descrizioni sono in inglese (la lingua prevalente del corpus)
     * perché fanno da bootstrap del centroide in dossiers:consolidate.
     *
     * @var list<array{slug: string, name: string, description: string}>
     */
    private const DOSSIERS = [
        [
            'slug'        => 'coding-agent',
            'name'        => 'Coding agent',
            'description' => 'AI coding agents and assistants: Claude Code, Copilot, Cursor, Codex. Agentic software development, autonomous code generation, token consumption, developer workflows and productivity with LLM-powered tools.',
        ],
        [
            'slug'        => 'agenti-ai-engineering',
            'name'        => 'Agenti AI e engineering',
            'description' => 'AI agent architectures and engineering: multi-agent systems, orchestration, planning, tool use, evaluation, reliability and production deployment of autonomous LLM agents.',
        ],
        [
            'slug'        => 'rag-memoria-context',
            'name'        => 'RAG, memoria e context',
            'description' => 'Retrieval-augmented generation, vector databases, embeddings, semantic search, chunking, agent memory, context windows and context engineering for LLM applications.',
        ],
        [
            'slug'        => 'llm-locali-hardware',
            'name'        => 'LLM locali e hardware',
            'description' => 'Local and open-weight LLMs: llama.cpp, Ollama, quantization, GPU and consumer hardware requirements, self-hosted inference, edge deployment and small language models.',
        ],
        [
            'slug'        => 'governance-sicurezza-ai',
            'name'        => 'Governance e sicurezza AI',
            'description' => 'AI governance, regulation and security: EU AI Act, compliance, privacy, prompt injection, jailbreaks, model safety, risk management and responsible AI adoption in organizations.',
        ],
        [
            'slug'        => 'ai-pa-concorsi',
            'name'        => 'AI nella PA e concorsi',
            'description' => 'Artificial intelligence in the Italian public administration: digitalization of public services, procurement, public sector IT jobs and concorsi pubblici, e-government and PA modernization.',
        ],
        [
            'slug'        => 'mcp-tooling',
            'name'        => 'MCP e tooling',
            'description' => 'Model Context Protocol and LLM tooling: MCP servers and clients, tool integrations, function calling, developer SDKs and the ecosystem connecting models to external systems.',
        ],
    ];

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;

        foreach (self::DOSSIERS as $definition) {
            $dossier = Dossier::firstOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name'        => $definition['name'],
                    'description' => $definition['description'],
                ],
            );

            $dossier->wasRecentlyCreated ? $created++ : $skipped++;
        }

        $this->info("Dossiers seeded: {$created} created, {$skipped} already present.");
        $this->line('Centroids start NULL: run `dossiers:consolidate` to bootstrap them from the descriptions.');

        return self::SUCCESS;
    }
}
