import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';

export default function ClusterFeed() {
    const [clusters, setClusters] = useState([]);
    const [loading, setLoading]   = useState(true);
    const [error, setError]       = useState(null);
    const [filters, setFilters]   = useState({ score_min: '', tag: '', since: '' });

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
                    className="border rounded px-3 py-1.5 text-sm"
                />
                <input
                    placeholder="Tag (es. mcp)"
                    value={filters.tag}
                    onChange={(e) => setFilters({ ...filters, tag: e.target.value })}
                    className="border rounded px-3 py-1.5 text-sm"
                />
                <input
                    type="date"
                    value={filters.since}
                    onChange={(e) => setFilters({ ...filters, since: e.target.value })}
                    className="border rounded px-3 py-1.5 text-sm"
                />
                <button type="submit" className="bg-blue-600 text-white px-4 py-1.5 rounded text-sm">
                    Filtra
                </button>
            </form>

            {loading && <p className="text-gray-500">Caricamento…</p>}
            {error   && <p className="text-red-500">{error}</p>}

            <ul className="space-y-4">
                {clusters.map((c) => (
                    <li key={c.id} className="bg-white border rounded-lg p-4 shadow-sm">
                        <div className="flex justify-between items-start">
                            <Link to={`/clusters/${c.id}`} className="font-semibold text-blue-700 hover:underline">
                                {c.canonical_title}
                            </Link>
                            <span className="text-xs font-mono bg-gray-100 px-2 py-0.5 rounded">
                                {Number(c.total_score).toFixed(3)}
                            </span>
                        </div>
                        <p className="text-sm text-gray-600 mt-1 line-clamp-2">{c.canonical_summary}</p>
                        <div className="mt-2 flex gap-1 flex-wrap">
                            {c.tags?.map((t) => (
                                <span key={t.slug} className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">
                                    {t.slug}
                                </span>
                            ))}
                        </div>
                        <p className="text-xs text-gray-400 mt-2">
                            consensus: {c.consensus_count} · {c.last_seen_at?.substring(0, 10)}
                        </p>
                    </li>
                ))}
            </ul>
        </div>
    );
}
