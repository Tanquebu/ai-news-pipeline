import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { usePaginatedList } from '../hooks/usePaginatedList';
import LoadMoreButton from './LoadMoreButton';

const STATUS_COLORS = {
    draft:     'bg-warning-soft text-warning',
    approved:  'bg-success-soft text-success',
    rejected:  'bg-danger-soft text-danger',
    published: 'bg-primary-soft text-primary',
};

function PublicationDetailModal({ pub, onClose }) {
    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={onClose}>
            <div className="bg-surface rounded-modal shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col" onClick={(e) => e.stopPropagation()}>
                <div className="p-6 border-b border-border flex justify-between items-start gap-3 shrink-0">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold truncate">{pub.title}</h2>
                        <p className="text-xs text-fg-muted mt-1">
                            <span className={`inline-block px-1.5 py-0.5 rounded text-xs font-mono ${STATUS_COLORS[pub.status]}`}>
                                {pub.kind}
                            </span>
                            {' · '}
                            <span className={`inline-block px-1.5 py-0.5 rounded text-xs ${STATUS_COLORS[pub.status]}`}>
                                {pub.status}
                            </span>
                            {pub.cluster && <> · da cluster: {pub.cluster.canonical_title}</>}
                        </p>
                    </div>
                    <button onClick={onClose} className="text-fg-muted hover:text-fg-secondary text-2xl leading-none focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">&times;</button>
                </div>
                <div className="p-6 overflow-y-auto flex-1">
                    <p className="text-sm text-fg whitespace-pre-wrap">{pub.body}</p>
                </div>
            </div>
        </div>
    );
}

function PublicationItem({ pub, onUpdate }) {
    const [editing, setEditing] = useState(false);
    const [body, setBody]       = useState(pub.body);
    const [saving, setSaving]   = useState(false);
    const [expanded, setExpanded] = useState(false);

    const act = (status) => {
        setSaving(true);
        api.updatePublication(pub.id, { status })
            .then(onUpdate)
            .finally(() => setSaving(false));
    };

    const saveBody = () => {
        setSaving(true);
        api.updatePublication(pub.id, { body })
            .then(() => { onUpdate(); setEditing(false); })
            .finally(() => setSaving(false));
    };

    const toggleArchive = () => {
        setSaving(true);
        const action = pub.archived_at ? api.unarchivePublication : api.archivePublication;
        action(pub.id)
            .then(onUpdate)
            .finally(() => setSaving(false));
    };

    const exportMd = () => {
        api.exportPublication(pub.id).then((blob) => {
            const url = URL.createObjectURL(blob);
            const a   = document.createElement('a');
            a.href     = url;
            a.download = `${pub.id}.md`;
            a.click();
            URL.revokeObjectURL(url);
        });
    };

    return (
        <li className="bg-surface border border-border rounded-card p-4 shadow-sm">
            <div className="flex justify-between items-start gap-3">
                <div className="flex-1 min-w-0">
                    <p className="font-semibold truncate">{pub.title}</p>
                    <p className="text-xs text-fg-muted mt-0.5">
                        <span className={`inline-block px-1.5 py-0.5 rounded text-xs font-mono ${STATUS_COLORS[pub.status]}`}>
                            {pub.kind}
                        </span>
                        {' · '}
                        <span className={`inline-block px-1.5 py-0.5 rounded text-xs ${STATUS_COLORS[pub.status]}`}>
                            {pub.status}
                        </span>
                    </p>
                </div>

                <div className="flex gap-2 shrink-0 flex-wrap justify-end">
                    {pub.status === 'draft' && (
                        <>
                            <button onClick={() => act('approved')} disabled={saving}
                                    className="text-xs bg-success text-on-primary px-2 py-1 rounded hover:bg-success/90 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                                Approva
                            </button>
                            <button onClick={() => act('rejected')} disabled={saving}
                                    className="text-xs bg-danger text-on-primary px-2 py-1 rounded hover:bg-danger/90 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                                Rifiuta
                            </button>
                        </>
                    )}
                    {pub.status === 'approved' && (
                        <button onClick={() => act('published')} disabled={saving}
                                className="text-xs bg-primary text-on-primary px-2 py-1 rounded hover:bg-primary-hover disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                            Pubblica
                        </button>
                    )}
                    <button onClick={() => setExpanded(true)}
                            className="text-xs border border-border px-2 py-1 rounded hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                        Espandi
                    </button>
                    <button onClick={() => setEditing(!editing)}
                            className="text-xs border border-border px-2 py-1 rounded hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                        {editing ? 'Chiudi' : 'Modifica'}
                    </button>
                    {pub.kind === 'article' && (
                        <button onClick={exportMd}
                                className="text-xs border border-border px-2 py-1 rounded hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                            Export MD
                        </button>
                    )}
                    <button onClick={toggleArchive} disabled={saving}
                            className="text-xs border border-border px-2 py-1 rounded hover:bg-surface-muted disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                        {pub.archived_at ? 'Ripristina' : 'Archivia'}
                    </button>
                </div>
            </div>

            {!editing && (
                <p className="text-sm text-fg mt-3 whitespace-pre-wrap line-clamp-4">{pub.body}</p>
            )}

            {editing && (
                <div className="mt-3">
                    <textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        rows={10}
                        className="w-full border border-border rounded p-2 text-sm font-mono focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    />
                    <button onClick={saveBody} disabled={saving}
                            className="mt-2 bg-primary text-on-primary text-sm px-3 py-1.5 rounded hover:bg-primary-hover disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                        {saving ? 'Salvataggio…' : 'Salva'}
                    </button>
                </div>
            )}

            {expanded && <PublicationDetailModal pub={pub} onClose={() => setExpanded(false)} />}
        </li>
    );
}

export default function PublicationsList() {
    const { items: publications, loading, loadingMore, hasMore, load, loadMore } = usePaginatedList(api.getPublications);
    const [statusFilter, setStatusFilter] = useState('');
    const [showArchived, setShowArchived] = useState(false);

    const activeParams = () => ({
        ...(statusFilter ? { status: statusFilter } : {}),
        ...(showArchived ? { archived: '1' } : {}),
    });
    const reload = () => load(activeParams());

    useEffect(reload, [statusFilter, showArchived]);

    return (
        <div className="max-w-4xl mx-auto p-6">
            <div className="flex items-baseline justify-between mb-6">
                <h1 className="text-2xl font-bold">Pubblicazioni</h1>
                <Link to="/help/publishing" className="text-sm text-primary hover:underline">Prossimi passi dopo la pubblicazione?</Link>
            </div>

            <div className="flex gap-2 mb-6 items-center flex-wrap">
                {['', 'draft', 'approved', 'rejected', 'published'].map((s) => (
                    <button
                        key={s}
                        onClick={() => setStatusFilter(s)}
                        className={`text-sm px-3 py-1 rounded border focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 ${statusFilter === s ? 'bg-primary text-on-primary border-primary' : 'border-border hover:bg-surface-muted'}`}
                    >
                        {s || 'Tutti'}
                    </button>
                ))}
                <label className="text-sm text-fg-secondary flex items-center gap-1.5 ml-2">
                    <input
                        type="checkbox"
                        checked={showArchived}
                        onChange={(e) => setShowArchived(e.target.checked)}
                        className="focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    />
                    Mostra archiviate
                </label>
            </div>

            {loading && <p className="text-fg-muted">Caricamento…</p>}

            <ul className="space-y-4">
                {publications.map((pub) => (
                    <PublicationItem key={pub.id} pub={pub} onUpdate={reload} />
                ))}
            </ul>

            {!loading && publications.length === 0 && (
                <p className="text-fg-muted">Nessuna pubblicazione trovata.</p>
            )}

            <LoadMoreButton hasMore={hasMore} loading={loadingMore} onClick={loadMore} />
        </div>
    );
}
