import { Link } from 'react-router-dom';

export default function PublishingHelp() {
    return (
        <div className="max-w-3xl mx-auto p-6 space-y-8">
            <Link to="/publications" className="text-primary text-sm hover:underline">← Pubblicazioni</Link>

            <section>
                <h1 className="text-2xl font-bold mb-2">Cosa fare dopo la generazione</h1>
                <p className="text-fg-secondary">
                    Post LinkedIn e articoli generati da un cluster nascono in stato <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">draft</code> in
                    questa pagina. Da qui: <strong>Approva</strong> o <strong>Rifiuta</strong>, poi <strong>Pubblica</strong>.
                    La pubblicazione in sé non esce automaticamente da qui — i passaggi successivi dipendono dal tipo di contenuto.
                </p>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-2">Post LinkedIn (linkedin_short/medium/opinion/large)</h2>
                <p className="text-sm text-fg-secondary">
                    Non c'è integrazione automatica con l'API di LinkedIn. Dopo <strong>Pubblica</strong>:
                </p>
                <ol className="list-decimal list-inside text-sm text-fg-secondary space-y-1 mt-2">
                    <li>Apri <strong>Espandi</strong> sulla pubblicazione per leggere il testo completo.</li>
                    <li>Copia il testo e pubblicalo manualmente su LinkedIn.</li>
                </ol>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-2">Articoli (article)</h2>
                <p className="text-sm text-fg-secondary">
                    Gli articoli con <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">status=published</code> sono
                    esposti su <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">GET /api/publications?status=published&amp;kind=article</code> e
                    importabili sul sito <strong>massimilianonicosia.it</strong>, sezione <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">/ia/news</code> (separata
                    dagli scritti manuali in <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">/ia</code>).
                </p>
                <p className="text-sm text-fg-secondary mt-2">Sul repo <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">massimilianonicosia.it</code>, dopo aver pubblicato qui:</p>
                <ol className="list-decimal list-inside text-sm text-fg-secondary space-y-1 mt-2">
                    <li><code className="text-xs bg-surface-muted px-1 py-0.5 rounded">pnpm ianews:import</code> — scarica i nuovi articoli pubblicati (idempotente: salta quelli già importati).</li>
                    <li><code className="text-xs bg-surface-muted px-1 py-0.5 rounded">pnpm build</code> e <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">pnpm preview</code> per controllare il risultato.</li>
                    <li>Commit di <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">dist/</code> e push su <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">main</code>.</li>
                    <li>Sul server di produzione: <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">git pull</code> (manuale — vedi <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">DEPLOY.md</code> del sito).</li>
                </ol>
                <p className="text-xs text-fg-muted mt-2">
                    L'export MD (bottone "Export MD") resta disponibile per usi ad-hoc, ma non serve per il flusso verso massimilianonicosia.it.
                </p>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-2">Archiviazione</h2>
                <p className="text-sm text-fg-secondary">
                    "Archivia" nasconde una pubblicazione dalla lista di default ma non la elimina né la retrocede di stato — resta
                    reversibile con "Ripristina" ed è indipendente da <code className="text-xs bg-surface-muted px-1 py-0.5 rounded">status</code>.
                </p>
            </section>

            <section>
                <h2 className="text-lg font-semibold mb-2">Altri siti</h2>
                <p className="text-sm text-fg-secondary">
                    mversus.it e ascissa.it non sono ancora collegati a questo flusso (rispettivamente: WordPress non mantenuto,
                    nessuna infrastruttura pronta). Quando saranno pronti serviranno endpoint dedicati, dato che non vivono sulla
                    stessa VPS di ai-news-pipeline.
                </p>
            </section>
        </div>
    );
}
