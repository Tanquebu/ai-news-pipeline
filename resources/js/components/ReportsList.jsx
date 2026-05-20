import { useEffect, useState } from 'react';
import { api } from '../api';

export default function ReportsList() {
    const [reports, setReports] = useState([]);
    const [loading, setLoading] = useState(true);
    const [deleting, setDeleting] = useState(null);

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
            <h1 className="text-2xl font-bold mb-6">Report importati</h1>

            {loading && <p className="text-gray-500">Caricamento…</p>}

            {!loading && reports.length === 0 && (
                <p className="text-gray-500">Nessun report importato.</p>
            )}

            <ul className="space-y-2">
                {reports.map((r) => (
                    <li key={r.id}
                        className="bg-white border rounded-lg p-4 shadow-sm flex justify-between items-center gap-4">
                        <div>
                            <p className="font-medium">{r.report_date}</p>
                            <p className="text-sm text-gray-500 mt-0.5">
                                <span className="bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-xs mr-1">
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
        </div>
    );
}
