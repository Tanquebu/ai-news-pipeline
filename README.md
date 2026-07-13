![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20.svg)
![pgvector](https://img.shields.io/badge/PostgreSQL-pgvector-336791.svg)

# AI News Pipeline

Pipeline che aggrega report giornalieri da più modelli AI (Claude, ChatGPT, Gemini, Perplexity, Mistral), clusterizza le notizie per similarità semantica, calcola un punteggio di rilevanza e genera bozze di contenuto (post LinkedIn, articoli) pronte per la revisione umana.

**Case study completo:** [Ho chiesto a cinque AI le stesse notizie ogni giorno. Poi ho smesso.](https://massimilianonicosia.it/ia/ai-news-pipeline-cinque-ai)

---

## Il problema

Cinque AI diverse, interrogate ogni giorno con lo stesso prompt, restituiscono report leggermente diversi per stile, enfasi e profondità. La notizia che tutte e cinque menzionano è un segnale forte; quella che compare in una sola vale meno attenzione. Tenerne traccia a mente non scala, e scegliere manualmente su cosa scrivere un post o un articolo è un costo fisso e ricorrente.

## Come funziona

```
report JSON (5 fonti AI)
  │  artisan reports:ingest
  ▼
ingest + dedup (hash canonico sul payload)
  │
  ▼
embedding vettoriale (OpenAI text-embedding-3-small, o Voyage AI)
  │
  ▼
clustering semantico (pgvector, cosine similarity, soglia 0.85, finestra 72h)
  │
  ▼
synthesis (Claude): titolo canonico, riassunto, tag, novelty score
  │
  ▼
scoring (consensus · novelty · importance · topic match)
  │
  ▼
generazione bozze (Claude): post LinkedIn (3 varianti) o articolo
  │
  ▼
review UI (React) → approvazione manuale → pubblicazione
```

## Scelte architetturali non ovvie

- **Clustering greedy con soglia coseno fissa, non HDBSCAN/k-means.** A volumi di poche decine di item/giorno un passaggio greedy è debuggabile e sufficiente; la soglia (default 0.85) resta un parametro di configurazione, non un iperparametro da tunare.
- **Retry asimmetrico sugli errori Anthropic.** Su 429/529 (rate limit, overload) non si ritenta subito: l'errore viene rilanciato immediatamente e il retry a lungo termine è delegato al job della coda, con backoff progressivo su una finestra totale di ~8 ore.
- **Tassonomia dei tag controllata, con proposte separate.** Il modello non può creare tag arbitrari nella tassonomia principale; le proposte finiscono in una tabella separata con un contatore di frequenza, promuovibili manualmente. Previene la deriva semantica dei quasi-duplicati.
- **Hash canonico per l'idempotenza dell'ingest.** Il payload viene ordinato ricorsivamente per chiave e sottoposto a SHA256 prima di aprire qualsiasi transazione: lo stesso report ingerito due volte (ordine chiavi diverso incluso) viene scartato silenziosamente.
- **Nessun orchestratore centrale.** La pipeline è una cascata di job Laravel Queue (`Embed → Cluster → Synthesize`), ognuno dispatcha il successivo al proprio completamento. Se un job fallisce, i successivi restano semplicemente non schedulati — nessuno stato incoerente da riconciliare a mano.

## Stack

PHP 8.2+ · Laravel 12 · PostgreSQL 16 + `pgvector` · Redis (queue) · React 18 + Vite (review UI) · Anthropic API (synthesis, generazione) · OpenAI / Voyage AI (embedding)

## Avvio locale (Laravel Sail)

Richiede Docker.

```bash
git clone https://github.com/Tanquebu/ai-news-pipeline.git
cd ai-news-pipeline
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

Servono chiavi API valide in `.env` per usare le funzionalità AI: `ANTHROPIC_API_KEY` (synthesis, generazione contenuti) e `OPENAI_API_KEY` (embedding). Senza chiavi, ingest e migrazioni funzionano comunque; i job che chiamano le API esterne falliranno.

## Comandi principali

| Comando | Descrizione |
|---|---|
| `artisan reports:ingest --path=` | Ingerisce un file JSON o una directory di report |
| `artisan reports:reprocess {id}` | Ri-genera gli embedding di un report (utile dopo cambio driver) |
| `artisan clusters:rescore` | Ricalcola lo score di tutti i cluster attivi |
| `artisan clusters:list --top=10` | Mostra i cluster con score più alto |

## API

Endpoint REST autenticati via header `X-API-Token` (`GET/DELETE /api/reports`, `GET /api/clusters`, `GET /api/news-items`, `GET/PATCH /api/publications` + trigger di generazione contenuti su `/api/clusters/{id}/generate/{linkedin|article}`).

## Test

```bash
./vendor/bin/sail artisan test
```

## Licenza

MIT — vedi [`LICENSE`](./LICENSE).
