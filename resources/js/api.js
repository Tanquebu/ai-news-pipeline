const TOKEN = import.meta.env.VITE_API_TOKEN ?? '';

async function request(method, path, body = null) {
    const res = await fetch(`/api${path}`, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-API-Token': TOKEN,
        },
        body: body ? JSON.stringify(body) : null,
    });

    if (!res.ok) {
        const err = await res.json().catch(() => ({ error: res.statusText }));
        throw new Error(err.error ?? 'Request failed');
    }

    const ct = res.headers.get('content-type') ?? '';
    return ct.includes('application/json') ? res.json() : res.blob();
}

export const api = {
    getClusters: (params = {}) =>
        request('GET', '/clusters?' + new URLSearchParams(params)),

    getCluster: (id) => request('GET', `/clusters/${id}`),

    archiveCluster: (clusterId) =>
        request('POST', `/clusters/${clusterId}/archive`),

    generateLinkedIn: (clusterId) =>
        request('POST', `/clusters/${clusterId}/generate/linkedin`),

    generateArticle: (clusterId) =>
        request('POST', `/clusters/${clusterId}/generate/article`),

    getReports: () => request('GET', '/reports'),

    getGenerators: () => request('GET', '/reports/generators'),

    deleteReport: (id) => request('DELETE', `/reports/${id}`),

    getPublications: (params = {}) =>
        request('GET', '/publications?' + new URLSearchParams(params)),

    updatePublication: (id, data) =>
        request('PATCH', `/publications/${id}`, data),

    exportPublication: (id) => request('GET', `/publications/${id}/export`),
};
