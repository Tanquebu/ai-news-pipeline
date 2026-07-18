import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';

export default function ClusterFeed() {
    const [clusters, setClusters]   = useState([]);
    const [loading, setLoading]     = useState(true);
    const [error, setError]         = useState(null);
    const [filters, setFilters]     = useState({ score_min: '', tag: '', since: '', source_ai: '' });
    const [generators, setGenerators] = useState([]);

    useEffect(() => {
        api.getGenerators().then(setGenerators).catch(() => {});
    }, []);

    const load = () => {
        setLoading(true);
        const params = Object.fromEntries(
            Object.entries(filters).filter(([, v]) => v !== '')
        );
        api.getClusters(params)
            .then((data) => setClusters(data.data ?? []))
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    };

    useEffect(load, []);

    const handleFilter = (e) => {
        e.preventDefault();
        load();
    };

    return (
        <div className="max-w-4xl mx-auto p-6">
            <h1 className="text-2xl font-bold mb-6">Cluster Feed</h1>

            <form onSubmit={handleFilter} className="flex gap-3 mb-6 flex-wrap">
                <input
                    placeholder="Score min (es. 0.5)"
                    value={filters.score_min}
                    onChange={(e) => setFilters({ ...filters, score_min: e.target.value })}
                    className="border border-border rounded px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                />
                <input
                    placeholder="Tag (es. mcp)"
                    value={filters.tag}
                    onChange={(e) => setFilters({ ...filters, tag: e.target.value })}
                    className="border border-border rounded px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                />
                <input
                    type="date"
                    value={filters.since}
                    onChange={(e) => setFilters({ ...filters, since: e.target.value })}
                    className="border border-border rounded px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                />
                <select
                    value={filters.source_ai}
                    onChange={(e) => setFilters({ ...filters, source_ai: e.target.value })}
                    className="border border-border rounded px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    <option value="">Tutti i generatori</option>
                    {generators.map((g) => (
                        <option key={g} value={g}>{g}</option>
                    ))}
                </select>
                <button
                    type="submit"
                    className="bg-primary text-on-primary px-4 py-1.5 rounded text-sm hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    Filtra
                </button>
            </form>

            {loading && <p className="text-fg-muted">Caricamento…</p>}
            {error   && <p className="text-danger">{error}</p>}

            <ul className="space-y-4">
                {clusters.map((c) => (
                    <li key={c.id} className="bg-surface border border-border rounded-card p-4 shadow-sm">
                        <div className="flex justify-between items-start">
                            <Link to={`/clusters/${c.id}`} className="font-semibold text-primary hover:underline">
                                {c.canonical_title}
                            </Link>
                            <span className="text-xs font-mono bg-surface-muted px-2 py-0.5 rounded">
                                {Number(c.total_score).toFixed(3)}
                            </span>
                        </div>
                        <p className="text-sm text-fg-secondary mt-1 line-clamp-2">{c.canonical_summary}</p>
                        <div className="mt-2 flex gap-1 flex-wrap">
                            {c.tags?.map((t) => (
                                <span key={t.slug} className="text-xs bg-primary-soft text-primary px-2 py-0.5 rounded">
                                    {t.slug}
                                </span>
                            ))}
                            {[...new Set(c.news_items?.map((i) => i.report?.source_ai).filter(Boolean))].map((ai) => (
                                <span key={ai} className="text-xs bg-surface-muted text-fg-muted px-2 py-0.5 rounded">
                                    {ai}
                                </span>
                            ))}
                        </div>
                        <p className="text-xs text-fg-muted mt-2">
                            consensus: {c.consensus_count} · {c.last_seen_at?.substring(0, 10)}
                            {c.news_items_min_event_date && (
                                <> · evento: {c.news_items_min_event_date}{c.news_items_max_event_date && c.news_items_max_event_date !== c.news_items_min_event_date ? ` → ${c.news_items_max_event_date}` : ''}</>
                            )}
                        </p>
                    </li>
                ))}
            </ul>
        </div>
    );
}
