import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
    CallToolRequestSchema,
    ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import {
    searchNewsItems,
    getCluster,
    listClusters,
    generateLinkedIn,
    ragSearch,
    getDocument,
    type RagSearchResponse,
    type DocumentDetail,
} from './api.js';

const server = new Server(
    { name: 'ai-news-pipeline', version: '1.0.0' },
    { capabilities: { tools: {} } },
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
    tools: [
        {
            name: 'search_news_items',
            description: 'Search ingested news items by text, date, or section. Returns up to 50 results ordered by most recent.',
            inputSchema: {
                type: 'object',
                properties: {
                    query:   { type: 'string', description: 'Text to search in title and summary (case-insensitive)' },
                    since:   { type: 'string', description: 'ISO 8601 date — only items created after this timestamp' },
                    section: { type: 'string', enum: ['strategic', 'technical', 'tooling'], description: 'Filter by report section' },
                },
            },
        },
        {
            name: 'get_cluster',
            description: 'Get full detail for a cluster: canonical title and summary, score, news items, tags, and generated publications.',
            inputSchema: {
                type: 'object',
                properties: {
                    id: { type: 'number', description: 'Cluster ID' },
                },
                required: ['id'],
            },
        },
        {
            name: 'list_pending_clusters',
            description: 'List active clusters ordered by relevance score descending. Use this to find the most important stories.',
            inputSchema: {
                type: 'object',
                properties: {
                    top:   { type: 'number', description: 'Maximum results to return (default 10)' },
                    since: { type: 'string', description: 'ISO 8601 date — only clusters last seen after this date' },
                },
            },
        },
        {
            name: 'draft_linkedin_post',
            description: 'Generate LinkedIn post draft(s) for a cluster via the pipeline LLM. Returns 1–3 variants (short, medium, opinion).',
            inputSchema: {
                type: 'object',
                properties: {
                    cluster_id: { type: 'number', description: 'Cluster ID' },
                    kind: {
                        type: 'string',
                        enum: ['short', 'medium', 'opinion'],
                        description: 'Specific variant to return. Omit to receive all three.',
                    },
                },
                required: ['cluster_id'],
            },
        },
        {
            name: 'search_knowledge',
            description: 'Semantic search over the RAG knowledge base (ingested articles, PDFs, notes). Returns matching chunks with title, url, snippet, score, and document/chunk references for follow-up with get_document.',
            inputSchema: {
                type: 'object',
                properties: {
                    query:    { type: 'string', description: 'Natural-language search query' },
                    limit:    { type: 'number', description: 'Maximum results to return' },
                    doc_type: { type: 'string', enum: ['article', 'pdf', 'note'], description: 'Filter by document type' },
                    source:   { type: 'string', description: 'Filter by document source' },
                },
                required: ['query'],
            },
        },
        {
            name: 'get_document',
            description: 'Get a knowledge-base document by ID: metadata (title, source, url, type, summary) plus the full content of its chunks in order.',
            inputSchema: {
                type: 'object',
                properties: {
                    document_id: { type: 'number', description: 'Document ID (as returned by search_knowledge)' },
                },
                required: ['document_id'],
            },
        },
    ],
}));

function formatSearchResults(response: RagSearchResponse): string {
    const header = `Query: "${response.query}" — ${response.count} result(s)`;
    if (response.results.length === 0) {
        return `${header}\n\nNo matches found.`;
    }
    const blocks = response.results.map((r, i) => [
        `${i + 1}. ${r.title} (score ${r.score})`,
        `   type: ${r.doc_type} | source: ${r.source}`,
        `   url: ${r.url ?? '-'}`,
        `   document_id: ${r.document_id} | chunk_id: ${r.chunk_id} | chunk_index: ${r.chunk_index}`,
        `   ${r.snippet}`,
    ].join('\n'));
    return `${header}\n\n${blocks.join('\n\n')}`;
}

function formatDocument(doc: DocumentDetail): string {
    const meta = [
        `Document #${doc.id}: ${doc.title}`,
        `type: ${doc.doc_type} | source: ${doc.source} | status: ${doc.status}`,
        `url: ${doc.url ?? '-'}`,
        `lang: ${doc.lang ?? '-'} | created: ${doc.created_at} | updated: ${doc.updated_at}`,
        `summary: ${doc.summary ?? '-'}`,
    ].join('\n');
    const chunks = [...doc.chunks]
        .sort((a, b) => a.chunk_index - b.chunk_index)
        .map((c) => `--- chunk ${c.chunk_index} (id ${c.id}) ---\n${c.content}`)
        .join('\n\n');
    return `${meta}\n\nContent (${doc.chunks.length} chunk(s)):\n\n${chunks}`;
}

server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: args = {} } = request.params;

    try {
        let result: unknown;

        switch (name) {
            case 'search_news_items': {
                const { query, since, section } = args as { query?: string; since?: string; section?: string };
                result = await searchNewsItems({ query, since, section });
                break;
            }

            case 'get_cluster': {
                const { id } = args as { id: number };
                result = await getCluster(id);
                break;
            }

            case 'list_pending_clusters': {
                const { top = 10, since } = args as { top?: number; since?: string };
                const response = await listClusters({ since });
                result = { data: response.data.slice(0, top) };
                break;
            }

            case 'draft_linkedin_post': {
                const { cluster_id, kind } = args as { cluster_id: number; kind?: string };
                const drafts = await generateLinkedIn(cluster_id);
                result = kind
                    ? drafts.filter((d) => (d as Record<string, unknown>)['kind'] === `linkedin_${kind}`)
                    : drafts;
                break;
            }

            case 'search_knowledge': {
                const { query, limit, doc_type, source } = args as {
                    query: string; limit?: number; doc_type?: string; source?: string;
                };
                const response = await ragSearch({ query, limit, doc_type, source });
                result = formatSearchResults(response);
                break;
            }

            case 'get_document': {
                const { document_id } = args as { document_id: number };
                try {
                    const { document } = await getDocument(document_id);
                    result = formatDocument(document);
                } catch (error) {
                    if ((error as Error).message.startsWith('API 404')) {
                        throw new Error(`Document ${document_id} not found`);
                    }
                    throw error;
                }
                break;
            }

            default:
                throw new Error(`Unknown tool: ${name}`);
        }

        return {
            content: [{
                type: 'text',
                text: typeof result === 'string' ? result : JSON.stringify(result, null, 2),
            }],
        };
    } catch (error) {
        return {
            content: [{ type: 'text', text: `Error: ${(error as Error).message}` }],
            isError: true,
        };
    }
});

const transport = new StdioServerTransport();
await server.connect(transport);
