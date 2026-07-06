import { useEffect, useState } from 'react';
import { api } from '../api';

function IngestModal({ onClose, onSuccess }) {
    const [generators, setGenerators] = useState([]);
    const [sourceAi, setSourceAi] = useState('');
    const [reportDate, setReportDate] = useState(new Date().toISOString().split('T')[0]);
    const [itemsJson, setItemsJson] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState(null);

    useEffect(() => {
        api.getGenerators().then(setGenerators).catch(() => {});
    }, []);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError(null);
        setSuccess(null);

        let items;
        try {
            items = JSON.parse(itemsJson);
            if (!Array.isArray(items)) throw new Error('deve essere un array JSON');
        } catch (err) {
            setError(`JSON non valido: ${err.message}`);
            return;
        }

        setSubmitting(true);
        try {
            const res = await api.ingestReport({ source_ai: sourceAi, report_date: reportDate, items });
            const msg = res.status === 'duplicate'
                ? 'Report già presente — duplicato ignorato.'
                : 'Report importato con successo.';
            setSuccess(msg);
            setTimeout(() => { onSuccess(); onClose(); }, 1500);
        } catch (err) {
            setError(err.message);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
                <div className="p-6 border-b flex justify-between items-center shrink-0">
                    <h2 className="text-lg font-semibold">Importa report</h2>
                    <button onClick={onClose} className="text-neutral-400 hover:text-neutral-600 text-2xl leading-none">&times;</button>
                </div>
                <form onSubmit={handleSubmit} className="p-6 flex flex-col gap-4 overflow-y-auto flex-1">
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-neutral-700 mb-1">Sorgente AI</label>
                            <input
                                list="generators-list"
                                value={sourceAi}
                                onChange={(e) => setSourceAi(e.target.value)}
                                required
                                placeholder="es. claude-opus-4-7"
                                className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            <datalist id="generators-list">
                                {generators.map((g) => <option key={g} value={g} />)}
                            </datalist>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-neutral-700 mb-1">Data report</label>
                            <input
                                type="date"
                                value={reportDate}
                                onChange={(e) => setReportDate(e.target.value)}
                                required
                                className="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-neutral-700 mb-1">
                            Items <span className="font-normal text-neutral-400">(array JSON)</span>
                        </label>
                        <textarea
                            value={itemsJson}
                            onChange={(e) => setItemsJson(e.target.value)}
                            required
                            placeholder={'[\n  {\n    "section": "strategic",\n    "title": "...",\n    "summary": "...",\n    "entities": [],\n    "event_date": null,\n    "sources": [],\n    "importance_self_rated": null,\n    "raw_tags": []\n  }\n]'}
                            className="w-full border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500 h-64 resize-y"
                        />
                    </div>
                    {error && (
                        <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">{error}</p>
                    )}
                    {success && (
                        <p className="text-sm text-green-600 bg-green-50 border border-green-200 rounded-lg px-3 py-2">{success}</p>
                    )}
                    <div className="flex justify-end gap-3 shrink-0">
                        <button
                            type="button"
                            onClick={onClose}
                            className="text-sm px-4 py-2 rounded-lg border hover:bg-neutral-50"
                        >
                            Annulla
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="text-sm px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                            {submitting ? 'Importazione…' : 'Importa'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

export default function ReportsList() {
    const [reports, setReports] = useState([]);
    const [loading, setLoading] = useState(true);
    const [deleting, setDeleting] = useState(null);
    const [showIngest, setShowIngest] = useState(false);

    const load = () => {
        setLoading(true);
        api.getReports()
            .then((data) => setReports(data.data ?? []))
            .finally(() => setLoading(false));
    };

    useEffect(load, []);

    const handleDelete = (report) => {
        if (!window.confirm(`Eliminare il report "${report.source_ai}" del ${report.report_date}?\n\nTutte le notizie associate verranno rimosse.`)) {
            return;
        }
        setDeleting(report.id);
        api.deleteReport(report.id)
            .then(load)
            .catch((err) => alert(`Errore: ${err.message}`))
            .finally(() => setDeleting(null));
    };

    return (
        <div className="max-w-4xl mx-auto p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Report importati</h1>
                <button
                    onClick={() => setShowIngest(true)}
                    className="text-sm bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700"
                >
                    Importa report
                </button>
            </div>

            {loading && <p className="text-neutral-500">Caricamento…</p>}

            {!loading && reports.length === 0 && (
                <p className="text-neutral-500">Nessun report importato.</p>
            )}

            <ul className="space-y-2">
                {reports.map((r) => (
                    <li key={r.id}
                        className="bg-white border rounded-lg p-4 shadow-sm flex justify-between items-center gap-4">
                        <div>
                            <p className="font-medium">{r.report_date}</p>
                            <p className="text-sm text-neutral-500 mt-0.5">
                                <span className="bg-neutral-100 text-neutral-600 px-1.5 py-0.5 rounded text-xs mr-1">
                                    {r.source_ai}
                                </span>
                                {r.news_items_count} {r.news_items_count === 1 ? 'notizia' : 'notizie'}
                                {' · '}
                                importato il {new Date(r.ingested_at).toLocaleDateString('it-IT')}
                            </p>
                        </div>
                        <button
                            onClick={() => handleDelete(r)}
                            disabled={deleting === r.id}
                            className="text-xs bg-red-600 text-white px-3 py-1.5 rounded hover:bg-red-700 disabled:opacity-50 shrink-0"
                        >
                            {deleting === r.id ? 'Eliminazione…' : 'Elimina'}
                        </button>
                    </li>
                ))}
            </ul>

            {showIngest && (
                <IngestModal
                    onClose={() => setShowIngest(false)}
                    onSuccess={load}
                />
            )}
        </div>
    );
}
