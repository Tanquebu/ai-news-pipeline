import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { usePaginatedList } from '../hooks/usePaginatedList';
import LoadMoreButton from './LoadMoreButton';

const COMPONENT_LABELS = {
    consensus:   { label: 'Consensus', desc: 'Quante volte è stato salvato materiale sullo stesso tema (satura al valore di "consensus_saturation").' },
    novelty:     { label: 'Novelty', desc: 'Quanto il cluster introduce contenuto nuovo rispetto a quanto già visto (calcolato dall\'LLM in fase di sintesi).' },
    importance:  { label: 'Importance', desc: 'Media della rilevanza auto-assegnata dall\'AI generatrice ai singoli item (scala 1–5), normalizzata 0–1.' },
    topic_match: { label: 'Topic match', desc: 'Frazione dei tag del cluster presenti nell\'elenco "topic_interest_tags" qui sotto.' },
};

export default function ScoringHelp() {
    const [info, setInfo]       = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError]     = useState(null);
    const [query, setQuery]     = useState('');
    const [promotingId, setPromotingId] = useState(null);

    const {
        items: proposals, loading: proposalsLoading, loadingMore: proposalsLoadingMore,
        hasMore: proposalsHasMore, load: loadProposals, loadMore: loadMoreProposals,
    } = usePaginatedList(api.getTagProposals);

    const loadInfo = () => api.getScoringInfo().then(setInfo).catch((e) => setError(e.message));

    useEffect(() => {
        loadInfo().finally(() => setLoading(false));
        loadProposals();
    }, []);

    const handleSearch = (e) => {
        e.preventDefault();
        loadProposals(query ? { q: query } : {});
    };

    const handlePromote = (proposal) => {
        if (!window.confirm(`Promuovere "${proposal.slug}" a tag reale? Diventerà disponibile per il tagging dei cluster.`)) return;
        setPromotingId(proposal.id);
        api.promoteTagProposal(proposal.id)
            .then(() => {
                loadProposals(query ? { q: query } : {});
                loadInfo();
            })
            .catch((e) => alert(e.message))
            .finally(() => setPromotingId(null));
    };

    if (loading) return <p className="p-6 text-fg-muted">Caricamento…</p>;
    if (error)   return <p className="p-6 text-danger">{error}</p>;

    const { weights, consensus_saturation, topic_interest_tags, tags, tag_proposals_count } = info;

    return (
        <div className="max-w-3xl mx-auto p-6 space-y-8">
            <Link to="/" className="text-primary text-sm hover:underline">← Feed</Link>

            <section>
                <h1 className="text-2xl font-bold mb-2">Come funziona lo scoring</h1>
                <p className="text-fg-secondary">
                    Ogni cluster riceve un <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">total_score</code> calcolato
                    come somma pesata di quattro componenti, ricalcolato ogni notte (job <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">clusters:rescore</code>)
                    o su richiesta dal bottone "Ricalcola punteggi" nel Feed.
                </p>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-3">Pesi correnti</h2>
                <ul className="space-y-3">
                    {Object.entries(weights).map(([key, value]) => (
                        <li key={key} className="bg-surface border border-border rounded-card p-3">
                            <div className="flex justify-between items-baseline">
                                <span className="font-medium text-fg">{COMPONENT_LABELS[key]?.label ?? key}</span>
                                <span className="text-xs font-mono bg-primary-soft text-primary px-2 py-0.5 rounded">{value}</span>
                            </div>
                            <p className="text-sm text-fg-muted mt-1">{COMPONENT_LABELS[key]?.desc}</p>
                        </li>
                    ))}
                </ul>
                <p className="text-xs text-fg-muted mt-3">
                    Saturazione consensus: <span className="font-mono">{consensus_saturation}</span> — oltre questo numero di "salvataggi" sullo stesso tema la componente vale già 1.0.
                </p>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-3">Tag di interesse per il topic match</h2>
                <p className="text-sm text-fg-secondary mb-2">
                    Un cluster guadagna punteggio in proporzione a quanti dei suoi tag rientrano in questo elenco:
                </p>
                <div className="flex gap-1 flex-wrap">
                    {topic_interest_tags.map((t) => (
                        <span key={t} className="text-xs bg-primary-soft text-primary px-2 py-0.5 rounded">{t}</span>
                    ))}
                </div>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-2">Come funziona il tagging</h2>
                <p className="text-sm text-fg-secondary">
                    Durante la sintesi, l'LLM può assegnare a un cluster <strong>solo tag già esistenti</strong> in questa taxonomy
                    (max 5 per cluster). Se propone un concetto nuovo, questo finisce in una "proposta di tag" in attesa di revisione
                    manuale e <strong>non contribuisce allo scoring</strong> finché non viene promosso a tag vero e proprio.
                </p>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-3">
                    Proposte di tag in attesa ({tag_proposals_count})
                </h2>
                <p className="text-sm text-fg-secondary mb-3">
                    Concetti incontrati durante la sintesi ma non ancora presenti in taxonomy, ordinati per frequenza.
                    Promuovendone uno diventa un tag reale, disponibile subito per il tagging dei cluster.
                </p>
                <form onSubmit={handleSearch} className="flex gap-3 mb-4">
                    <input
                        placeholder="Cerca slug (es. agent)"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        className="border border-border rounded px-3 py-1.5 text-sm flex-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    />
                    <button
                        type="submit"
                        className="bg-primary text-on-primary px-4 py-1.5 rounded text-sm hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    >
                        Cerca
                    </button>
                </form>

                {proposalsLoading && <p className="text-fg-muted">Caricamento…</p>}

                <ul className="space-y-2">
                    {proposals.map((p) => (
                        <li key={p.slug} className="bg-surface border border-border rounded-card p-2.5 flex justify-between items-center gap-3">
                            <div className="min-w-0">
                                <span className="font-mono text-sm text-fg">{p.slug}</span>
                                {p.reason && <p className="text-xs text-fg-muted mt-0.5">{p.reason}</p>}
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                                <span className="text-xs font-mono bg-surface-muted px-2 py-0.5 rounded">
                                    {p.frequency}×
                                </span>
                                <button
                                    onClick={() => handlePromote(p)}
                                    disabled={promotingId === p.id}
                                    className="text-xs bg-success-soft text-success px-2 py-1 rounded hover:bg-success/20 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                                >
                                    {promotingId === p.id ? '…' : 'Promuovi'}
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>

                <LoadMoreButton hasMore={proposalsHasMore} loading={proposalsLoadingMore} onClick={loadMoreProposals} />
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-3">Taxonomy attuale ({tags.length} tag)</h2>
                <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    {tags.map((t) => (
                        <li key={t.slug} className="bg-surface border border-border rounded-card p-2.5 text-sm">
                            <span className="font-mono text-fg">{t.slug}</span>
                            {t.description && <p className="text-xs text-fg-muted mt-0.5">{t.description}</p>}
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}
