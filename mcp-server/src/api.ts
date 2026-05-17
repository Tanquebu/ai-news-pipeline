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
