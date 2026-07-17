![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-8892BF.svg)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20.svg)
![pgvector](https://img.shields.io/badge/PostgreSQL-pgvector-336791.svg)

# AI News Pipeline

Pipeline che aggrega report giornalieri da più modelli AI (Claude, ChatGPT, Gemini, Perplexity, Mistral), clusterizza le notizie per similarità semantica, calcola un punteggio di rilevanza e genera bozze di contenuto (post LinkedIn, articoli) pronte per la revisione umana.

Sulla stessa base è costruita una **knowledge base documentale**: un'API di ingest idempotente accetta documenti da qualsiasi sistema sorgente esterno, li spezza in chunk con embedding vettoriale, li rende interrogabili con ricerca ibrida full-text + semantica (RAG con fonti citabili), li aggrega in dossier tematici persistenti e genera ogni settimana brief editoriali con scoring spiegabile.

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

## Knowledge base documentale e RAG

Accanto al flusso news, un dominio documentale separato (`documents`, `document_chunks`, `ingestion_events`):

```
POST /api/documents/ingest   (da un sistema sorgente esterno)
  │  idempotente: chiave source_system + source_record_id + content_hash
  │  full_text salvato content-addressable su storage (rag/raw/<sha256[0:2]>/<sha256>.txt)
  ▼
ChunkDocumentJob (finestre ~900 token, overlap 150)
  ▼
EmbedDocumentChunksJob (stesso driver embedding delle news, vector 1536d)
  ▼
AssignDocumentToDossierJob (similarità coseno documento ↔ centroide dossier)
```

- **Idempotenza a livello DB**: la tripla `(source_system, source_record_id, content_hash)` è `UNIQUE` su `ingestion_events`. Un retry risponde `duplicate` senza rifare nulla; un contenuto cambiato per un record già noto risponde `updated` e ri-chunka il documento. Risposta sempre `202` con `ingestion_id`.
- **Il payload decide cosa è notizia**: se l'ingest arriva con `category=news`, oltre al documento viene creato un `news_item` collegato che entra nel flusso embed → cluster → synthesis esistente. Tutte le altre categorie producono solo il documento.
- **Ricerca ibrida**: `GET /api/rag/search` combina full-text PostgreSQL e similarità pgvector, fondendo i due ranking con Reciprocal Rank Fusion (k=60). Ogni risultato espone titolo, URL, snippet e riferimenti documento/chunk — mai embedding nei payload. Filtri: `doc_type`, `source`.

## Dossier tematici e brief settimanali

I **dossier** sono argomenti persistenti (distinti dagli story cluster a finestra temporale): ogni documento ingestato viene assegnato al dossier più affine per similarità col centroide. Un consolidamento notturno ricalcola i centroidi e ritenta gli orfani.

Lo **scoring è spiegabile**: volume, diversità fonti, recency (decadimento esponenziale) e coesione, con pesi configurabili via env. Un dossier è candidato a brief solo con almeno N documenti e M fonti distinte nella finestra di attività.

I **brief settimanali** (`briefs:generate`, cap 3–5 per run) sono sintesi decisionali, non bozze pronte: tesi, claim con fonti citabili, controargomenti, angoli editoriali e formato suggerito. Ciclo di stato `draft → approved → sent` via `PATCH /api/briefs/{id}`, solo transizioni in avanti. Dopo la generazione, un webhook configurabile (`BRIEFS_WEBHOOK_URL`) riceve un riepilogo JSON — la pipeline resta agnostica sul consumer; un fallimento del webhook non blocca mai la generazione.

Pianificazione via Laravel scheduler (`routes/console.php`): `clusters:archive` giornaliero, `dossiers:consolidate` alle 03:30, `dossiers:score` alle 03:45, `briefs:generate` la domenica alle 05:00.

## Scelte architetturali non ovvie

- **Clustering greedy con soglia coseno fissa, non HDBSCAN/k-means.** A volumi di poche decine di item/giorno un passaggio greedy è debuggabile e sufficiente; la soglia (default 0.85) resta un parametro di configurazione, non un iperparametro da tunare.
- **Retry asimmetrico sugli errori Anthropic.** Su 429/529 (rate limit, overload) non si ritenta subito: l'errore viene rilanciato immediatamente e il retry a lungo termine è delegato al job della coda, con backoff progressivo su una finestra totale di ~8 ore.
- **Tassonomia dei tag controllata, con proposte separate.** Il modello non può creare tag arbitrari nella tassonomia principale; le proposte finiscono in una tabella separata con un contatore di frequenza, promuovibili manualmente. Previene la deriva semantica dei quasi-duplicati.
- **Hash canonico per l'idempotenza dell'ingest.** Il payload viene ordinato ricorsivamente per chiave e sottoposto a SHA256 prima di aprire qualsiasi transazione: lo stesso report ingerito due volte (ordine chiavi diverso incluso) viene scartato silenziosamente.
- **Nessun orchestratore centrale.** La pipeline è una cascata di job Laravel Queue (`Embed → Cluster → Synthesize`), ognuno dispatcha il successivo al proprio completamento. Se un job fallisce, i successivi restano semplicemente non schedulati — nessuno stato incoerente da riconciliare a mano.

## Stack

PHP 8.2+ · Laravel 12 · PostgreSQL 16 + `pgvector` · Redis (queue) · React 18 + Vite (review UI) · Anthropic API (synthesis, generazione) · OpenAI / Voyage AI (embedding) · TypeScript (MCP server)

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
| `artisan clusters:archive [--older-than=] [--dry-run]` | Archivia i cluster attivi non aggiornati di recente |
| `artisan dossiers:seed` | Crea i dossier tematici iniziali (idempotente) |
| `artisan dossiers:consolidate [--dry-run]` | Bootstrap/ricalcolo centroidi e riassegnazione documenti orfani |
| `artisan dossiers:score [--dry-run]` | Scoring spiegabile e candidatura a brief di tutti i dossier |
| `artisan briefs:generate [--limit=] [--dry-run]` | Genera i brief settimanali dai dossier candidati |

## API

Endpoint REST autenticati via header `X-API-Token`:

- **News pipeline:** `GET/DELETE /api/reports`, `POST /api/reports/ingest`, `GET /api/clusters`, `POST /api/clusters/{id}/archive`, `GET /api/news-items`, `GET/PATCH /api/publications` + trigger di generazione contenuti su `/api/clusters/{id}/generate/{linkedin|article}`.
- **Knowledge base:** `POST /api/documents/ingest` (idempotente, `202` + `ingestion_id`), `GET /api/documents/{id}` (metadati + chunk in ordine), `GET /api/rag/search?q=...&limit=&doc_type=&source=` (ricerca ibrida con fonti).
- **Dossier e brief:** `GET /api/dossiers[?candidates_only=1]` (score, breakdown, candidatura), `GET /api/briefs[?status=draft|approved|sent]`, `GET /api/briefs/{id}`, `PATCH /api/briefs/{id}` (transizioni `draft→approved→sent`).

## MCP server

In `mcp-server/` un server MCP TypeScript (stdio) espone la pipeline a client come Claude Code. Tool disponibili:

| Tool | Scopo |
|---|---|
| `search_news_items` | Ricerca testuale sugli item ingestati (query, data, sezione) |
| `get_cluster` | Dettaglio completo di un cluster (item, tag, publications) |
| `list_pending_clusters` | Cluster attivi ordinati per score |
| `draft_linkedin_post` | Genera bozze LinkedIn per un cluster via LLM |
| `search_knowledge` | Ricerca semantica sulla knowledge base (chunk con fonte e riferimenti) |
| `get_document` | Documento per ID: metadati + contenuto completo dei chunk |

Configurazione: `PIPELINE_API_URL` (base URL dell'app, senza `/api`) e `PIPELINE_API_TOKEN`.

## Configurazione (env principali)

Oltre alle chiavi API, i parametri di pipeline sono tutti sovrascrivibili via `.env` (default e commenti in [`.env.example`](./.env.example)): soglie di clustering (`CLUSTERING_*`), pesi e saturazione dello scoring news (`SCORING_*`, inclusa `SCORING_CONSENSUS_SATURATION`), assegnazione e scoring dossier (`DOSSIER_*`), generazione brief (`BRIEFS_MAX_PER_RUN`, `BRIEFS_TOP_DOCUMENTS`, `BRIEFS_MAX_TOKENS`) e webhook di delivery (`BRIEFS_WEBHOOK_URL`).

## Test

```bash
./vendor/bin/sail artisan test
```

## Licenza

MIT — vedi [`LICENSE`](./LICENSE).
