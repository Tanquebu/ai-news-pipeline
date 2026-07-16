const BASE_URL = (process.env.PIPELINE_API_URL ?? 'http://localhost').replace(/\/$/, '');
const API_TOKEN = process.env.PIPELINE_API_TOKEN ?? '';

async function apiFetch(path: string, options: RequestInit = {}): Promise<unknown> {
    const response = await fetch(`${BASE_URL}/api${path}`, {
        ...options,
        headers: {
            'X-API-Token': API_TOKEN,
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(options.headers ?? {}),
        },
    });

    if (!response.ok) {
        throw new Error(`API ${response.status}: ${await response.text()}`);
    }

    return response.json();
}

export async function searchNewsItems(params: {
    query?: string;
    since?: string;
    section?: string;
}): Promise<unknown> {
    const qs = new URLSearchParams();
    if (params.query)   qs.set('query', params.query);
    if (params.since)   qs.set('since', params.since);
    if (params.section) qs.set('section', params.section);
    const suffix = qs.size > 0 ? `?${qs}` : '';
    return apiFetch(`/news-items${suffix}`);
}

export async function getCluster(id: number): Promise<unknown> {
    return apiFetch(`/clusters/${id}`);
}

export async function listClusters(params: { since?: string }): Promise<{ data: unknown[] }> {
    const qs = new URLSearchParams();
    if (params.since) qs.set('since', params.since);
    const suffix = qs.size > 0 ? `?${qs}` : '';
    return apiFetch(`/clusters${suffix}`) as Promise<{ data: unknown[] }>;
}

export async function generateLinkedIn(clusterId: number): Promise<unknown[]> {
    return apiFetch(`/clusters/${clusterId}/generate/linkedin`, { method: 'POST' }) as Promise<unknown[]>;
}

export interface RagSearchResult {
    chunk_id: number;
    document_id: number;
    title: string;
    url: string | null;
    doc_type: string;
    source: string;
    chunk_index: number;
    snippet: string;
    score: number;
}

export interface RagSearchResponse {
    query: string;
    count: number;
    results: RagSearchResult[];
}

export interface DocumentChunk {
    id: number;
    document_id: number;
    chunk_index: number;
    content: string;
}

export interface DocumentDetail {
    id: number;
    source: string;
    url: string | null;
    title: string;
    doc_type: string;
    lang: string | null;
    summary: string | null;
    status: string;
    created_at: string;
    updated_at: string;
    chunks: DocumentChunk[];
}

export async function ragSearch(params: {
    query: string;
    limit?: number;
    doc_type?: string;
    source?: string;
}): Promise<RagSearchResponse> {
    const qs = new URLSearchParams();
    qs.set('q', params.query);
    if (params.limit !== undefined) qs.set('limit', String(params.limit));
    if (params.doc_type) qs.set('doc_type', params.doc_type);
    if (params.source)   qs.set('source', params.source);
    return apiFetch(`/rag/search?${qs}`) as Promise<RagSearchResponse>;
}

export async function getDocument(id: number): Promise<{ document: DocumentDetail }> {
    return apiFetch(`/documents/${id}`) as Promise<{ document: DocumentDetail }>;
}
