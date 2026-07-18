import { useEffect, useState } from 'react';
import { api } from '../api';
import { REPORT_PROMPT } from '../reportPrompt';
import { usePaginatedList } from '../hooks/usePaginatedList';
import LoadMoreButton from './LoadMoreButton';

function copyToClipboard(text) {
    if (navigator.clipboard?.writeText) {
        return navigator.clipboard.writeText(text);
    }

    // navigator.clipboard richiede un secure context (HTTPS o localhost):
    // su HTTP semplice (es. accesso via IP) è undefined, da qui il fallback.
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    try {
        if (!document.execCommand('copy')) throw new Error('execCommand copy fallito');
    } finally {
        document.body.removeChild(textarea);
    }
}

function PromptBox() {
    const [showPrompt, setShowPrompt] = useState(false);
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState(false);

    const handleCopy = async () => {
        try {
            await copyToClipboard(REPORT_PROMPT);
            setCopied(true);
            setCopyError(false);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopyError(true);
            setTimeout(() => setCopyError(false), 2000);
        }
    };

    return (
        <div className="border border-border rounded-lg bg-surface-muted">
            <div className="flex justify-between items-center p-3">
                <button
                    type="button"
                    onClick={() => setShowPrompt((v) => !v)}
                    className="text-sm font-medium text-fg-secondary hover:text-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    {showPrompt ? 'Nascondi' : 'Mostra'} prompt per generare il report
                </button>
                <button
                    type="button"
                    onClick={handleCopy}
                    className="text-xs bg-surface border border-border text-fg-secondary px-3 py-1.5 rounded hover:bg-border-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    {copyError ? 'Copia fallita' : copied ? 'Copiato!' : 'Copia prompt'}
                </button>
            </div>
            {showPrompt && (
                <pre className="text-xs text-fg-secondary whitespace-pre-wrap font-mono p-3 pt-0 max-h-64 overflow-y-auto">
                    {REPORT_PROMPT}
                </pre>
            )}
        </div>
    );
}

function IngestModal({ onClose, onSuccess }) {
    const [generators, setGenerators] = useState([]);
    const [format, setFormat] = useState('object');
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

        let payload;
        try {
            const parsed = JSON.parse(itemsJson);

            if (format === 'object') {
                if (Array.isArray(parsed) || typeof parsed !== 'object' || parsed === null) {
                    throw new Error('deve essere un oggetto JSON con report_date, source_ai e items');
                }
                if (!parsed.source_ai || !parsed.report_date || !Array.isArray(parsed.items)) {
                    throw new Error('mancano report_date, source_ai o items nell\'oggetto');
                }
                payload = { source_ai: parsed.source_ai, report_date: parsed.report_date, items: parsed.items };
            } else {
                if (!Array.isArray(parsed)) throw new Error('deve essere un array JSON');
                payload = { source_ai: sourceAi, report_date: reportDate, items: parsed };
            }
        } catch (err) {
            setError(`JSON non valido: ${err.message}`);
            return;
        }

        setSubmitting(true);
        try {
            const res = await api.ingestReport(payload);
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
            <div className="bg-surface rounded-modal shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
                <div className="p-6 border-b border-border flex justify-between items-center shrink-0">
                    <h2 className="text-lg font-semibold">Importa report</h2>
                    <button onClick={onClose} className="text-fg-muted hover:text-fg-secondary text-2xl leading-none">&times;</button>
                </div>
                <form onSubmit={handleSubmit} className="p-6 flex flex-col gap-4 overflow-y-auto flex-1">
                    <PromptBox />
                    <div>
                        <label className="block text-sm font-medium text-fg-secondary mb-1">Formato JSON incollato</label>
                        <select
                            value={format}
                            onChange={(e) => setFormat(e.target.value)}
                            className="w-full border border-border rounded-lg px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        >
                            <option value="object">Oggetto completo — con report_date e source_ai (output del prompt)</option>
                            <option value="array">Solo array items — sorgente e data inserite a mano</option>
                        </select>
                    </div>
                    {format === 'array' && (
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-fg-secondary mb-1">Sorgente AI</label>
                                <input
                                    list="generators-list"
                                    value={sourceAi}
                                    onChange={(e) => setSourceAi(e.target.value)}
                                    required
                                    placeholder="es. claude-opus-4-7"
                                    className="w-full border border-border rounded-lg px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                                />
                                <datalist id="generators-list">
                                    {generators.map((g) => <option key={g} value={g} />)}
                                </datalist>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-fg-secondary mb-1">Data report</label>
                                <input
                                    type="date"
                                    value={reportDate}
                                    onChange={(e) => setReportDate(e.target.value)}
                                    required
                                    className="w-full border border-border rounded-lg px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                                />
                            </div>
                        </div>
                    )}
                    <div>
                        <label className="block text-sm font-medium text-fg-secondary mb-1">
                            {format === 'object'
                                ? <>JSON report <span className="font-normal text-fg-muted">(oggetto con report_date, source_ai, items)</span></>
                                : <>Items <span className="font-normal text-fg-muted">(array JSON)</span></>}
                        </label>
                        <textarea
                            value={itemsJson}
                            onChange={(e) => setItemsJson(e.target.value)}
                            required
                            placeholder={format === 'object'
                                ? '{\n  "report_date": "2026-07-17",\n  "source_ai": "claude-opus-4-7",\n  "items": [\n    {\n      "section": "strategic",\n      "title": "...",\n      "summary": "...",\n      "entities": [],\n      "event_date": null,\n      "sources": [],\n      "importance_self_rated": null,\n      "raw_tags": []\n    }\n  ]\n}'
                                : '[\n  {\n    "section": "strategic",\n    "title": "...",\n    "summary": "...",\n    "entities": [],\n    "event_date": null,\n    "sources": [],\n    "importance_self_rated": null,\n    "raw_tags": []\n  }\n]'}
                            className="w-full border border-border rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 h-64 resize-y"
                        />
                    </div>
                    {error && (
                        <p className="text-sm text-danger bg-danger-soft border border-danger-softer rounded-lg px-3 py-2">{error}</p>
                    )}
                    {success && (
                        <p className="text-sm text-success bg-success-soft border border-success-softer rounded-lg px-3 py-2">{success}</p>
                    )}
                    <div className="flex justify-end gap-3 shrink-0">
                        <button
                            type="button"
                            onClick={onClose}
                            className="text-sm px-4 py-2 rounded-lg border border-border hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        >
                            Annulla
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="text-sm px-4 py-2 rounded-lg bg-primary text-on-primary hover:bg-primary-hover disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        >
                            {submitting ? 'Importazione…' : 'Importa'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function ReportDetailModal({ reportId, onClose, onUpdate }) {
    const [report, setReport] = useState(null);
    const [error, setError] = useState(null);
    const [archiving, setArchiving] = useState(false);

    useEffect(() => {
        api.getReport(reportId)
            .then(setReport)
            .catch((err) => setError(err.message));
    }, [reportId]);

    const toggleArchive = () => {
        setArchiving(true);
        const action = report.archived_at ? api.unarchiveReport : api.archiveReport;
        action(reportId)
            .then((updated) => { setReport({ ...report, ...updated }); onUpdate(); })
            .finally(() => setArchiving(false));
    };

    return (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div className="bg-surface rounded-modal shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
                <div className="p-6 border-b border-border flex justify-between items-center shrink-0">
                    <div className="flex items-center gap-3">
                        <h2 className="text-lg font-semibold">
                            Report {report ? `— ${report.source_ai} (${report.report_date})` : ''}
                        </h2>
                        {report && (
                            <button onClick={toggleArchive} disabled={archiving}
                                    className="text-xs border border-border px-2 py-1 rounded hover:bg-surface-muted disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2">
                                {report.archived_at ? 'Ripristina' : 'Archivia'}
                            </button>
                        )}
                    </div>
                    <button onClick={onClose} className="text-fg-muted hover:text-fg-secondary text-2xl leading-none">&times;</button>
                </div>
                <div className="p-6 overflow-y-auto flex-1">
                    {error && <p className="text-sm text-danger">{error}</p>}
                    {!report && !error && <p className="text-fg-muted">Caricamento…</p>}
                    {report && report.news_items?.length === 0 && (
                        <p className="text-fg-muted">Nessuna notizia in questo report.</p>
                    )}
                    {report && report.news_items?.length > 0 && (
                        <ul className="space-y-3">
                            {report.news_items.map((item) => (
                                <li key={item.id} className="bg-surface border border-border rounded p-3 text-sm">
                                    <div className="flex justify-between items-start gap-2">
                                        <p className="font-medium">[{item.section}] {item.title}</p>
                                    </div>
                                    <p className="text-fg-secondary mt-1">{item.summary}</p>
                                    {item.entities?.length > 0 && (
                                        <div className="flex gap-1 mt-2 flex-wrap">
                                            {item.entities.map((e, i) => (
                                                <span key={i} className="text-xs bg-surface-muted text-fg-secondary px-1.5 py-0.5 rounded">
                                                    {e}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}

function ProcessingBadge({ total, processed }) {
    if (total === 0) {
        return <span className="text-xs bg-surface-muted text-fg-muted px-1.5 py-0.5 rounded">Nessuna notizia</span>;
    }
    if (processed === 0) {
        return <span className="text-xs bg-surface-muted text-fg-muted px-1.5 py-0.5 rounded">In attesa</span>;
    }
    if (processed < total) {
        return (
            <span className="text-xs bg-warning-soft text-warning px-1.5 py-0.5 rounded">
                In corso · {processed}/{total}
            </span>
        );
    }
    return <span className="text-xs bg-success-soft text-success px-1.5 py-0.5 rounded">Completato</span>;
}

export default function ReportsList() {
    const { items: reports, loading, loadingMore, hasMore, load, loadMore } = usePaginatedList(api.getReports);
    const [deleting, setDeleting] = useState(null);
    const [showIngest, setShowIngest] = useState(false);
    const [detailId, setDetailId] = useState(null);
    const [showArchived, setShowArchived] = useState(false);

    const activeParams = () => showArchived ? { archived: '1' } : {};
    const reload = () => load(activeParams());

    useEffect(reload, [showArchived]);

    const handleDelete = (report, e) => {
        e.stopPropagation();
        if (!window.confirm(`Eliminare il report "${report.source_ai}" del ${report.report_date}?\n\nTutte le notizie associate verranno rimosse.`)) {
            return;
        }
        setDeleting(report.id);
        api.deleteReport(report.id)
            .then(reload)
            .catch((err) => alert(`Errore: ${err.message}`))
            .finally(() => setDeleting(null));
    };

    return (
        <div className="max-w-4xl mx-auto p-6">
            <div className="flex justify-between items-center mb-6">
                <h1 className="text-2xl font-bold">Report importati</h1>
                <button
                    onClick={() => setShowIngest(true)}
                    className="text-sm bg-primary text-on-primary px-4 py-2 rounded-lg hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    Importa report
                </button>
            </div>

            <label className="text-sm text-fg-secondary flex items-center gap-1.5 mb-4">
                <input
                    type="checkbox"
                    checked={showArchived}
                    onChange={(e) => setShowArchived(e.target.checked)}
                    className="focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                />
                Mostra archiviati
            </label>

            {loading && <p className="text-fg-muted">Caricamento…</p>}

            {!loading && reports.length === 0 && (
                <p className="text-fg-muted">Nessun report importato.</p>
            )}

            <ul className="space-y-2">
                {reports.map((r) => (
                    <li key={r.id}
                        onClick={() => setDetailId(r.id)}
                        className="bg-surface border border-border rounded-card p-4 shadow-sm flex justify-between items-center gap-4 cursor-pointer hover:bg-surface-muted">
                        <div>
                            <p className="font-medium flex items-center gap-2">
                                {r.report_date}
                                <ProcessingBadge total={r.news_items_count} processed={r.processed_items_count} />
                                {r.archived_at && (
                                    <span className="text-xs bg-surface-muted text-fg-muted px-1.5 py-0.5 rounded">Archiviato</span>
                                )}
                            </p>
                            <p className="text-sm text-fg-muted mt-0.5">
                                <span className="bg-surface-muted text-fg-secondary px-1.5 py-0.5 rounded text-xs mr-1">
                                    {r.source_ai}
                                </span>
                                {r.news_items_count} {r.news_items_count === 1 ? 'notizia' : 'notizie'}
                                {' · '}
                                importato il {new Date(r.ingested_at).toLocaleDateString('it-IT')}
                            </p>
                        </div>
                        <button
                            onClick={(e) => handleDelete(r, e)}
                            disabled={deleting === r.id}
                            className="text-xs bg-danger text-on-primary px-3 py-1.5 rounded hover:bg-danger/90 disabled:opacity-50 shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        >
                            {deleting === r.id ? 'Eliminazione…' : 'Elimina'}
                        </button>
                    </li>
                ))}
            </ul>

            <LoadMoreButton hasMore={hasMore} loading={loadingMore} onClick={loadMore} />

            {showIngest && (
                <IngestModal
                    onClose={() => setShowIngest(false)}
                    onSuccess={reload}
                />
            )}

            {detailId && (
                <ReportDetailModal
                    reportId={detailId}
                    onClose={() => setDetailId(null)}
                    onUpdate={reload}
                />
            )}
        </div>
    );
}
