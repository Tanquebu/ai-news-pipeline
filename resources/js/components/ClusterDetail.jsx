import { useEffect, useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { api } from '../api';

export default function ClusterDetail() {
    const { id }                      = useParams();
    const navigate                    = useNavigate();
    const [data, setData]             = useState(null);
    const [loading, setLoading]       = useState(true);
    const [error, setError]           = useState(null);
    const [generating, setGenerating] = useState(null);
    const [archiving, setArchiving]   = useState(false);

    useEffect(() => {
        api.getCluster(id)
            .then(setData)
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [id]);

    const generate = (kind) => {
        setGenerating(kind);
        const action = kind === 'linkedin'
            ? api.generateLinkedIn(id)
            : api.generateArticle(id);

        action
            .then(() => api.getCluster(id).then(setData))
            .catch((e) => alert(e.message))
            .finally(() => setGenerating(null));
    };

    const archive = () => {
        if (!window.confirm('Archiviare questo cluster? Non sarà più visibile nel feed.')) return;
        setArchiving(true);
        api.archiveCluster(id)
            .then(() => navigate('/'))
            .catch((e) => { alert(e.message); setArchiving(false); });
    };

    if (loading) return <p className="p-6 text-gray-500">Caricamento…</p>;
    if (error)   return <p className="p-6 text-red-500">{error}</p>;

    const { cluster, publications } = data;

    return (
        <div className="max-w-4xl mx-auto p-6 space-y-8">
            <Link to="/" className="text-blue-600 text-sm hover:underline">← Feed</Link>

            <section>
                <h1 className="text-2xl font-bold">{cluster.canonical_title}</h1>
                <p className="text-gray-600 mt-2">{cluster.canonical_summary}</p>
                <div className="flex gap-1 mt-3 flex-wrap">
                    {cluster.tags?.map((t) => (
                        <span key={t.slug} className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded">
                            {t.slug}
                        </span>
                    ))}
                </div>
                <p className="text-sm text-gray-400 mt-2">
                    Score: {Number(cluster.total_score).toFixed(3)} · Consensus: {cluster.consensus_count}
                    {cluster.news_items_min_event_date && (
                        <> · Evento: {cluster.news_items_min_event_date}{cluster.news_items_max_event_date && cluster.news_items_max_event_date !== cluster.news_items_min_event_date ? ` → ${cluster.news_items_max_event_date}` : ''}</>
                    )}
                </p>
            </section>

            <section>
                <div className="flex gap-3 mb-4 flex-wrap items-center">
                    <button
                        onClick={() => generate('linkedin')}
                        disabled={generating !== null || archiving}
                        className="bg-blue-600 text-white px-4 py-2 rounded text-sm disabled:opacity-50"
                    >
                        {generating === 'linkedin' ? '…' : 'Genera LinkedIn Posts'}
                    </button>
                    <button
                        onClick={() => generate('article')}
                        disabled={generating !== null || archiving}
                        className="bg-green-600 text-white px-4 py-2 rounded text-sm disabled:opacity-50"
                    >
                        {generating === 'article' ? '…' : 'Genera Articolo'}
                    </button>
                    <span className="border-l border-gray-200 h-6 mx-1" />
                    <button
                        onClick={archive}
                        disabled={generating !== null || archiving}
                        className="text-gray-500 border border-gray-300 px-4 py-2 rounded text-sm hover:bg-gray-50 disabled:opacity-50"
                    >
                        {archiving ? '…' : 'Archivia'}
                    </button>
                </div>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-3">Notizie ({cluster.news_items?.length ?? 0})</h2>
                <ul className="space-y-3">
                    {cluster.news_items?.map((item) => (
                        <li key={item.id} className="bg-white border rounded p-3 text-sm">
                            <div className="flex justify-between items-start gap-2">
                                <p className="font-medium">[{item.section}] {item.title}</p>
                                {item.report?.source_ai && (
                                    <span className="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded shrink-0">
                                        {item.report.source_ai}
                                    </span>
                                )}
                            </div>
                            <p className="text-gray-600 mt-1">{item.summary}</p>
                            {item.sources?.length > 0 && (
                                <ul className="mt-2 space-y-0.5">
                                    {item.sources.map((s) => (
                                        <li key={s.id}>
                                            <a href={s.url} target="_blank" rel="noreferrer"
                                               className="text-blue-600 hover:underline text-xs">
                                                {s.name}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            </section>

            {publications?.length > 0 && (
                <section>
                    <h2 className="text-lg font-semibold mb-3">Bozze ({publications.length})</h2>
                    <Link to="/publications" className="text-sm text-blue-600 hover:underline">
                        Gestisci tutte le pubblicazioni →
                    </Link>
                </section>
            )}
        </div>
    );
}
