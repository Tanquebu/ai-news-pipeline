import { useEffect, useState } from 'react';
import { api } from '../api';

const STATUS_COLORS = {
    draft:     'bg-yellow-50 text-yellow-700',
    approved:  'bg-green-50 text-green-700',
    rejected:  'bg-red-50 text-red-700',
    published: 'bg-blue-50 text-blue-700',
};

function PublicationItem({ pub, onUpdate }) {
    const [editing, setEditing] = useState(false);
    const [body, setBody]       = useState(pub.body);
    const [saving, setSaving]   = useState(false);

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
        <li className="bg-white border rounded-lg p-4 shadow-sm">
            <div className="flex justify-between items-start gap-3">
                <div className="flex-1 min-w-0">
                    <p className="font-semibold truncate">{pub.title}</p>
                    <p className="text-xs text-gray-500 mt-0.5">
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
                                    className="text-xs bg-green-600 text-white px-2 py-1 rounded disabled:opacity-50">
                                Approva
                            </button>
                            <button onClick={() => act('rejected')} disabled={saving}
                                    className="text-xs bg-red-600 text-white px-2 py-1 rounded disabled:opacity-50">
                                Rifiuta
                            </button>
                        </>
                    )}
                    {pub.status === 'approved' && (
                        <button onClick={() => act('published')} disabled={saving}
                                className="text-xs bg-blue-600 text-white px-2 py-1 rounded disabled:opacity-50">
                            Pubblica
                        </button>
                    )}
                    <button onClick={() => setEditing(!editing)}
                            className="text-xs border px-2 py-1 rounded">
                        {editing ? 'Chiudi' : 'Modifica'}
                    </button>
                    {pub.kind === 'article' && (
                        <button onClick={exportMd}
                                className="text-xs border px-2 py-1 rounded">
                            Export MD
                        </button>
                    )}
                </div>
            </div>

            {!editing && (
                <p className="text-sm text-gray-700 mt-3 whitespace-pre-wrap line-clamp-4">{pub.body}</p>
            )}

            {editing && (
                <div className="mt-3">
                    <textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        rows={10}
                        className="w-full border rounded p-2 text-sm font-mono"
                    />
                    <button onClick={saveBody} disabled={saving}
                            className="mt-2 bg-blue-600 text-white text-sm px-3 py-1.5 rounded disabled:opacity-50">
                        {saving ? 'Salvataggio…' : 'Salva'}
                    </button>
                </div>
            )}
        </li>
    );
}

export default function PublicationsList() {
    const [publications, setPublications] = useState([]);
    const [loading, setLoading]           = useState(true);
    const [statusFilter, setStatusFilter] = useState('');

    const load = () => {
        setLoading(true);
        const params = statusFilter ? { status: statusFilter } : {};
        api.getPublications(params)
            .then((data) => setPublications(data.data ?? []))
            .finally(() => setLoading(false));
    };

    useEffect(load, [statusFilter]);

    return (
        <div className="max-w-4xl mx-auto p-6">
            <h1 className="text-2xl font-bold mb-6">Pubblicazioni</h1>

            <div className="flex gap-2 mb-6">
                {['', 'draft', 'approved', 'rejected', 'published'].map((s) => (
                    <button
                        key={s}
                        onClick={() => setStatusFilter(s)}
                        className={`text-sm px-3 py-1 rounded border ${statusFilter === s ? 'bg-blue-600 text-white border-blue-600' : ''}`}
                    >
                        {s || 'Tutti'}
                    </button>
                ))}
            </div>

            {loading && <p className="text-gray-500">Caricamento…</p>}

            <ul className="space-y-4">
                {publications.map((pub) => (
                    <PublicationItem key={pub.id} pub={pub} onUpdate={load} />
                ))}
            </ul>

            {!loading && publications.length === 0 && (
                <p className="text-gray-500">Nessuna pubblicazione trovata.</p>
            )}
        </div>
    );
}
