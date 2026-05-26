# CODEBASE-OVERVIEW.md — AI News Pipeline

---

## 1. Mappa dei componenti

### `app/Actions/`

Operazioni atomiche a singolo metodo pubblico `execute()`. Orchestrano più passi in un'unica unità di lavoro transazionale o logica.

| Classe | Responsabilità | Collegata a |
|---|---|---|
| `App\Actions\IngestReportAction` | Valida un payload JSON, calcola l'hash canonico, crea `Report` + `NewsItem` + `NewsItemSource` + `Entity` + tag pivot, dispatcha `EmbedNewsItemJob` per ogni item | Chiamata da `IngestReportsCommand`; usa `CanonicalJson`; dispatcha verso `EmbedNewsItemJob` |
| `App\Actions\DeleteReportAction` | Elimina un `Report` in transazione, riconcilia i cluster orfani (elimina quelli vuoti con le loro draft, aggiorna `consensus_count` sugli altri) | Chiamata da `ReportController@destroy`; tocca `Cluster`, `Publication` |
| `App\Actions\GenerateLinkedInPostsAction` | Chiama Claude via `LLMClient`, genera 3 varianti post LinkedIn (short/medium/opinion), crea 3 record `Publication` in stato `draft` | Chiamata da `ClusterController@generateLinkedIn`; dipende da `LLMClient` |
| `App\Actions\GenerateArticleAction` | Chiama Claude via `LLMClient`, genera un articolo in markdown, crea un record `Publication` (kind=`article`, status=`draft`); richiede ≥3 items e ≥2 entità nel cluster | Chiamata da `ClusterController@generateArticle`; dipende da `LLMClient` |

---

### `app/Services/`

Logica di dominio riusabile con dipendenze esterne iniettate. Ogni service implementa un contratto (`app/Contracts/`).

| Classe | Responsabilità | Collegata a |
|---|---|---|
| `App\Services\AnthropicService` | Wrapper HTTP per l'API Anthropic. Implementa `LLMClient`. Gestisce retry selettivo: 3 tentativi con backoff esponenziale (1s/2s/4s) su errori di rete; nessun retry interno su 429/529 | Implementa `App\Contracts\LLMClient`; usata da `SynthesizeClusterJob`, `GenerateLinkedInPostsAction`, `GenerateArticleAction` |
| `App\Services\EmbeddingService` | Facade sul driver di embedding attivo. Concatena `title + "\n" + summary` e delega a `EmbeddingDriver`. | Dipende da `App\Contracts\EmbeddingDriver`; usata da `EmbedNewsItemJob` |
| `App\Services\ScoringService` | Calcola e persiste `total_score` e le metriche intermedie (`importance_avg`, `topic_match_score`) su un cluster. | Usata da `SynthesizeClusterJob` e `RescoreClustersCommand` |
| `App\Services\Embedding\OpenAIEmbeddingDriver` | Chiama `POST /v1/embeddings` di OpenAI con `text-embedding-3-small`. Implementa `EmbeddingDriver`. | Implementa `App\Contracts\EmbeddingDriver`; istanziata da `AppServiceProvider` |
| `App\Services\Embedding\VoyageEmbeddingDriver` | Chiama l'API Voyage AI. Driver alternativo al posto di OpenAI. Implementa `EmbeddingDriver`. | Implementa `App\Contracts\EmbeddingDriver`; istanziata da `AppServiceProvider` |

---

### `app/Jobs/`

Job asincroni accodati su Laravel Queue (driver `database` o `redis`). Formano una pipeline a cascata: ogni job dispatcha il successivo al completamento.

| Classe | Responsabilità | Collegata a |
|---|---|---|
| `App\Jobs\EmbedNewsItemJob` | Genera l'embedding vettoriale di un `NewsItem` via `EmbeddingService`, lo persiste come `vector(1536)` in `news_items.embedding`, dispatcha `ClusterNewsItemJob` | Dispatchata da `IngestReportAction` e `ReprocessReportCommand`; usa `EmbeddingService` |
| `App\Jobs\ClusterNewsItemJob` | Cerca il cluster più simile per similarità coseno via pgvector (soglia 0.85, finestra 72h). Se trovato: associa l'item e aggiorna `consensus_count`. Altrimenti crea un nuovo cluster. Dispatcha `SynthesizeClusterJob`. | Dispatchata da `EmbedNewsItemJob`; scrive su `Cluster`, `NewsItem` |
| `App\Jobs\SynthesizeClusterJob` | Invia tutti i newsitem del cluster a Claude, ottiene `canonical_title`, `canonical_summary`, `tags`, `tag_proposals`, `novelty_score`. Sincronizza tag, crea `TagProposal` per tag fuori tassonomia, chiama `ScoringService::updateScore()`. 8 tentativi con backoff progressivo (1m→4h) per gestire 429/529. | Dispatchata da `ClusterNewsItemJob`; usa `LLMClient`, `ScoringService` |

---

### `app/Console/Commands/`

Comandi Artisan. Tutti thin: orchestrano, non implementano logica di dominio.

| Comando | Signature | Responsabilità |
|---|---|---|
| `App\Console\Commands\IngestReportsCommand` | `reports:ingest [--path=] [--move=]` | Legge file `.json` (singolo o directory), chiama `IngestReportAction` per ognuno, sposta i file processati nella dir di destinazione, stampa un riepilogo |
| `App\Console\Commands\ReprocessReportCommand` | `reports:reprocess {report_id}` | Ridispatcha `EmbedNewsItemJob` per tutti i newsitem di un report (utile dopo cambio driver embedding) |
| `App\Console\Commands\RescoreClustersCommand` | `clusters:rescore` | Itera tutti i cluster attivi via `cursor()` e chiama `ScoringService::updateScore()` su ognuno (utile dopo modifica dei pesi) |
| `App\Console\Commands\ListClustersCommand` | `clusters:list [--top=10] [--since=]` | Mostra i cluster attivi ordinati per `total_score` in formato tabella; `--since` filtra su `last_seen_at` |

---

### `app/Models/`

Modelli Eloquent puri: relazioni, casts, scope. Nessuna business logic.

| Modello | Tabella | Relazioni principali |
|---|---|---|
| `App\Models\Report` | `reports` | `hasMany NewsItem` |
| `App\Models\NewsItem` | `news_items` | `belongsTo Report`, `belongsTo Cluster`, `hasMany NewsItemSource`, `belongsToMany Tag`, `belongsToMany Entity` |
| `App\Models\NewsItemSource` | `news_item_sources` | `belongsTo NewsItem` |
| `App\Models\Cluster` | `clusters` | `hasMany NewsItem`, `belongsToMany Tag`, `hasMany Publication` |
| `App\Models\Tag` | `tags` | `belongsToMany NewsItem`, `belongsToMany Cluster` |
| `App\Models\TagProposal` | `tag_proposals` | — |
| `App\Models\Entity` | `entities` | `belongsToMany NewsItem` |
| `App\Models\Publication` | `publications` | `belongsTo Cluster` |

---

### `app/Http/Controllers/Api/`

Controller API REST. Tutti thin: validano input via Form Request, delegano a Action/Service, serializzano la risposta.

| Controller | Endpoint principali | Responsabilità |
|---|---|---|
| `App\Http\Controllers\Api\ClusterController` | `GET /api/clusters`, `GET /api/clusters/{id}`, `POST /api/clusters/{id}/generate/linkedin`, `POST /api/clusters/{id}/generate/article` | Feed cluster paginato con filtri (tag, score_min, since); dettaglio con items/publications; trigger generazione contenuti |
| `App\Http\Controllers\Api\ReportController` | `GET /api/reports`, `DELETE /api/reports/{id}` | Lista report paginata; eliminazione con riconciliazione cluster |
| `App\Http\Controllers\Api\NewsItemController` | `GET /api/news-items` | Lista newsitem recenti con filtri (query full-text su title/summary, since, section) |
| `App\Http\Controllers\Api\PublicationController` | `GET /api/publications`, `PATCH /api/publications/{id}`, `GET /api/publications/{id}/export` | Lista pubblicazioni; aggiornamento stato/contenuto; download markdown |

Tutti gli endpoint sono protetti dal middleware `App\Http\Middleware\ApiTokenAuth` che verifica l'header `X-API-Token`.

---

## 2. Flusso end-to-end

Dal file JSON grezzo alla bozza LinkedIn pronta per la review.

```
File JSON (es. storage/reports/import/2026-05-21_claude.json)
  │
  ▼ artisan reports:ingest
App\Console\Commands\IngestReportsCommand
  │  legge il file, risolve path e move-dir
  ▼
App\Actions\IngestReportAction::execute(array $payload)
  │  1. CanonicalJson::hash($payload) → SHA256
  │  2. check payload_hash in reports (skip se duplicato)
  │  3. Validator::make($payload, $rules) → ValidationException se invalido
  │  4. DB::transaction():
  │     - INSERT reports (report_date, source_ai, payload, payload_hash, ingested_at)
  │     - per ogni item:
  │       · INSERT news_items (section, title, summary, entities, event_date, raw_tags, importance_self_rated)
  │       · INSERT news_item_sources (preservando position)
  │       · find-or-create Entity per ogni stringa in entities[]
  │       · INSERT news_item_tag (Str::slug → match su tags.slug)
  │       · EmbedNewsItemJob::dispatch($newsItem->id)
  ▼
[queue worker]
App\Jobs\EmbedNewsItemJob::handle(EmbeddingService $service)
  │  1. carica NewsItem
  │  2. EmbeddingService::embedNewsItem($item)
  │     → concatena "title\nsummary"
  │     → OpenAIEmbeddingDriver::embed($text) [o Voyage]
  │       POST https://api.openai.com/v1/embeddings
  │       model: text-embedding-3-small, dimensions: 1536
  │     → ritorna float[1536]
  │  3. UPDATE news_items SET embedding = '[0.123,...]' WHERE id = ?
  │  4. ClusterNewsItemJob::dispatch($newsItem->id)
  ▼
App\Jobs\ClusterNewsItemJob::handle()
  │  1. carica NewsItem con embedding
  │  2. query pgvector:
  │     SELECT cluster_id, 1 - (embedding <=> ?) AS similarity
  │     FROM news_items
  │     WHERE cluster_id IS NOT NULL
  │       AND embedding IS NOT NULL
  │       AND id != ?
  │       AND created_at >= NOW() - INTERVAL '72 hours'
  │     ORDER BY embedding <=> ? ASC
  │     LIMIT 1
  │  3a. similarity >= 0.85 → UPDATE news_items SET cluster_id = $found
  │                            UPDATE clusters SET consensus_count += 1, last_seen_at = now()
  │  3b. similarity < 0.85 o nessun match → INSERT clusters (canonical_title = item.title)
  │                                          UPDATE news_items SET cluster_id = $new
  │  4. SynthesizeClusterJob::dispatch($clusterId)
  ▼
App\Jobs\SynthesizeClusterJob::handle(LLMClient $llm, ScoringService $scoring)
  │  1. carica Cluster con newsItems + tags
  │  2. carica tutti i tag slug dalla tassonomia
  │  3. costruisce prompt strutturato (vedi §3)
  │  4. AnthropicService::complete($prompt, maxTokens: 1024)
  │     POST https://api.anthropic.com/v1/messages
  │     model: claude-opus-4-7 (configurabile)
  │  5. json_decode risposta → canonical_title, canonical_summary,
  │     tags[], tag_proposals[], novelty_score
  │  6. UPDATE clusters SET canonical_title, canonical_summary, novelty_score
  │  7. $cluster->tags()->sync($validTagSlugs)  [solo slug nella tassonomia]
  │  8. per ogni tag_proposal: TagProposal::firstOrCreate + increment frequency
  │  9. ScoringService::updateScore($cluster)
  │     → importance_avg = AVG(COALESCE(importance_self_rated, 3))
  │     → topic_match = |cluster_tags ∩ interest_tags| / |cluster_tags|
  │     → consensus = min(consensus_count / 10.0, 1.0)
  │     → total_score = 0.35*consensus + 0.20*novelty + 0.20*importance_norm + 0.25*topic_match
  │     → UPDATE clusters SET importance_avg, topic_match_score, total_score
  ▼
Cluster con total_score popolato — visibile in GET /api/clusters
  │
  ▼ POST /api/clusters/{id}/generate/linkedin   (trigger manuale da UI o API)
App\Http\Controllers\Api\ClusterController::generateLinkedIn()
  │
  ▼
App\Actions\GenerateLinkedInPostsAction::execute(Cluster $cluster)
  │  1. carica newsItems e tags del cluster
  │  2. costruisce prompt con canonical_title, canonical_summary, tag slugs
  │  3. AnthropicService::complete($prompt, maxTokens: 1024)
  │  4. json_decode → {short, medium, opinion}
  │  5. INSERT publications x3 (kind=linkedin_short/medium/opinion, status=draft)
  │  6. ritorna array di Publication
  ▼
3 × Publication (status=draft) in DB
  │
  ▼ PATCH /api/publications/{id}  {"status": "approved"}
App\Http\Controllers\Api\PublicationController::update()
  │  UPDATE publications SET status='approved'
  ▼
Publication approvata — pronta per pubblicazione manuale
```

---

## 3. Componenti AI

### API Anthropic (LLM)

**Contratto:** `App\Contracts\LLMClient` — un metodo: `complete(string $prompt, int $maxTokens): string`

**Implementazione:** `App\Services\AnthropicService`

- Endpoint: `POST https://api.anthropic.com/v1/messages`
- Header: `x-api-key` + `anthropic-version: 2023-06-01`
- Modello: `config('services.anthropic.model')` → default `claude-opus-4-7`
- Timeout: 60 secondi
- Retry: 3 tentativi, backoff esponenziale 1s/2s/4s, **solo** su errori di rete/timeout. Su 429/529 l'eccezione viene rilanciata immediatamente senza retry interno (il retry a lungo termine è delegato al job queue).

**Tre usi distinti:**

| Chiamante | Scopo | maxTokens | Prompt input | Output atteso |
|---|---|---|---|---|
| `SynthesizeClusterJob` | Sintetizzare il cluster, assegnare tag, calcolare novelty | 1024 | Lista newsitem (section, title, summary, entities, raw_tags) + tassonomia tag | JSON: `{canonical_title, canonical_summary, tags[], tag_proposals[], novelty_score}` |
| `GenerateLinkedInPostsAction` | Generare 3 varianti post LinkedIn | 1024 | canonical_title, canonical_summary, tag slugs del cluster | JSON: `{short, medium, opinion}` |
| `GenerateArticleAction` | Generare un articolo markdown | 2048 | canonical_title, canonical_summary, lista titoli+summary degli item | JSON: `{title, body}` |

Tutti i prompt richiedono esplicitamente risposta come **JSON puro** (nessun markdown wrapper). I risultati vengono passati a `json_decode()` senza `json_decode` lenient: un JSON malformato produce eccezione e attiva il retry del job.

**Prompt di sintesi (estratto strutturale):**

```
Sei un assistente che sintetizza cluster di notizie AI provenienti da più fonti.

NOTIZIE DEL CLUSTER ({N} item):
- [{section}] {title}
  {summary}
  Entità: {entities}
  Tag grezzi: {raw_tags}

TAG DISPONIBILI (usa SOLO questi slug, max 5):
{slug1, slug2, ...}

Restituisci ESCLUSIVAMENTE un oggetto JSON valido con questi campi:
{
  "canonical_title": "...",
  "canonical_summary": "2-4 frasi in italiano",
  "tags": ["slug1"],
  "tag_proposals": [{"slug": "nuovo-slug", "reason": "..."}],
  "novelty_score": 0.0–1.0
}

REGOLE:
- "tags" DEVE contenere SOLO slug dall'elenco TAG DISPONIBILI (max 5)
- Nuovi tag vanno ESCLUSIVAMENTE in "tag_proposals" con reason motivata
- Rispondi con SOLO JSON valido, nessun markdown
```

---

### API OpenAI — Embedding

**Contratto:** `App\Contracts\EmbeddingDriver` — un metodo: `embed(string $text): float[]`

**Implementazione default:** `App\Services\Embedding\OpenAIEmbeddingDriver`

- Endpoint: `POST https://api.openai.com/v1/embeddings`
- Modello: `config('pipeline.embedding.model')` → default `text-embedding-3-small`
- Dimensioni: `config('pipeline.embedding.dimensions')` → default `1536`
- Auth: Bearer token
- Risposta letta da: `$response->json('data.0.embedding')`

**Driver alternativo:** `App\Services\Embedding\VoyageEmbeddingDriver`

- Endpoint: `POST https://api.voyageai.com/v1/embeddings`
- Attivato impostando `EMBEDDING_DRIVER=voyage` in `.env`

Il binding tra interfaccia e driver avviene in `App\Providers\AppServiceProvider::register()`:

```php
$this->app->bind(EmbeddingDriver::class, function () {
    return config('pipeline.embedding.driver') === 'voyage'
        ? new VoyageEmbeddingDriver(...)
        : new OpenAIEmbeddingDriver(...);
});
```

**Testo embedizzato:** `$item->title . "\n" . $item->summary` — prodotto da `EmbeddingService::embedNewsItem()`.

---

### Clustering semantico con pgvector

**Dove:** `App\Jobs\ClusterNewsItemJob::handle()`

**Colonna:** `news_items.embedding` — tipo `vector(1536)`, colonna aggiunta dalla migration `2026_05_17_000002_add_embedding_and_cluster_to_news_items.php`. L'estensione `vector` è abilitata dalla migration `0000_01_01_000000_enable_pgvector_extension.php`.

**Query di matching:**

```sql
SELECT cluster_id, 1 - (embedding <=> ?) AS similarity
FROM news_items
WHERE cluster_id IS NOT NULL
  AND embedding IS NOT NULL
  AND id != ?
  AND created_at >= NOW() - (? * INTERVAL '1 hour')
ORDER BY embedding <=> ? ASC
LIMIT 1
```

- `<=>` è l'operatore **cosine distance** di pgvector. `1 - cosine_distance = cosine_similarity`.
- Il parametro `?` per l'embedding è la stringa `'[0.123,0.456,...]'` prodotta da `implode(',', $embedding)` wrappato tra `[` e `]`.
- La finestra temporale (`CLUSTERING_TIME_WINDOW_HOURS`, default 72) limita la ricerca ai newsitem degli ultimi 3 giorni, prevenendo che notizie topicamente simili ma temporalmente distanti vengano aggregate nello stesso cluster.

**Soglia di decisione:**

```php
$threshold = config('pipeline.clustering.similarity_threshold', 0.85);

if ($similarity >= $threshold) {
    // associa al cluster trovato
} else {
    // crea nuovo cluster
}
```

**Algoritmo:** greedy (il primo vicino trovato sopra soglia vince). Scelta deliberata: a volumi bassi (decine di item/giorno) è semplice, debuggabile e non richiede HDBSCAN o k-means.

---

## 4. Cosa è implementato vs cosa no

### Fase 1 — Foundation ✅ Completa

Classi principali: `App\Actions\IngestReportAction`, `App\Console\Commands\IngestReportsCommand`, `App\Support\CanonicalJson`, `App\Enums\ReportSection`, `Database\Seeders\TagSeeder`.

Migrations: reports, news_items (senza embedding), news_item_sources, tags, entities, pivot tables (news_item_tag, news_item_entity), tag_proposals.

---

### Fase 2 — Embedding + Clustering ✅ Completa

Classi principali: `App\Jobs\EmbedNewsItemJob`, `App\Jobs\ClusterNewsItemJob`, `App\Services\EmbeddingService`, `App\Services\Embedding\OpenAIEmbeddingDriver`, `App\Services\Embedding\VoyageEmbeddingDriver`, `App\Contracts\EmbeddingDriver`.

Migration: `2026_05_17_000002_add_embedding_and_cluster_to_news_items.php` (aggiunge `embedding vector(1536)` e `cluster_id FK`).

Comando: `App\Console\Commands\ReprocessReportCommand`.

---

### Fase 3 — Synthesis + Scoring ✅ Completa

Classi principali: `App\Services\AnthropicService`, `App\Contracts\LLMClient`, `App\Jobs\SynthesizeClusterJob`, `App\Services\ScoringService`.

Comandi: `App\Console\Commands\RescoreClustersCommand`, `App\Console\Commands\ListClustersCommand`.

---

### Fase 4 — Generator + Review UI ✅ Completa

Classi principali: `App\Actions\GenerateLinkedInPostsAction`, `App\Actions\GenerateArticleAction`, `App\Http\Controllers\Api\ClusterController`, `App\Http\Controllers\Api\PublicationController`, `App\Http\Controllers\Api\NewsItemController`, `App\Http\Requests\UpdatePublicationRequest`, `App\Http\Middleware\ApiTokenAuth`.

Migration: `2026_05_17_000003_create_publications_table.php`.

SPA React (Vite + Tailwind): presente nel progetto, compilata via `sail npm run build`. Gestisce il feed cluster, il dettaglio con items/fonti/bozze, la lista publications con azioni approve/reject/edit/export. Il routing è coperto dalla wildcard `GET /{any}` in `routes/web.php`.

---

### Fase 6 — Report Management ✅ Completa

Classi principali: `App\Actions\DeleteReportAction`, `App\Http\Controllers\Api\ReportController`.

Endpoint: `GET /api/reports` (lista paginata con `news_items_count`), `DELETE /api/reports/{id}` (cascade + riconciliazione cluster).

---

### Fase 5 — MCP Server custom ❌ Non implementata (opzionale)

Prevede un server MCP TypeScript separato con tool `search_news_items`, `get_cluster`, `list_pending_clusters`, `draft_linkedin_post`. Non esiste codice TypeScript nel repository.

---

## 5. Concetti chiave da capire

**1. Canonical JSON Hash** — `App\Support\CanonicalJson`

Meccanismo di deduplicazione: prima di ingerire un report, il payload viene ordinato ricorsivamente per chiave (`ksort` su array associativi, invariante su array sequenziali), serializzato in JSON e sottoposto a SHA256. Il risultato (`payload_hash`) è `UNIQUE` sulla tabella `reports`. Se lo stesso report viene ingerito due volte — anche con ordine diverso delle chiavi o spazi diversi — l'hash coincide e il secondo ingest viene scartato silenziosamente.

**2. Pipeline di job in cascata** — `EmbedNewsItemJob` → `ClusterNewsItemJob` → `SynthesizeClusterJob`

L'elaborazione di un newsitem avviene in tre job sequenziali: ognuno dispatcha il successivo al completamento. Non esiste un orchestratore centrale: la sequenza è codificata nel `handle()` di ciascun job. Questa struttura significa che se un job fallisce (es. timeout OpenAI), i job successivi non vengono nemmeno dispatchati, e il newsitem rimane a uno stadio intermedio (embedding senza cluster, o cluster senza synthesis).

**3. Greedy clustering con soglia coseno** — `App\Jobs\ClusterNewsItemJob`

Ogni nuovo newsitem viene confrontato con i newsitem già clusterizzati nell'ultima finestra di 72 ore. Si prende il vicino con similarità coseno più alta. Se supera 0.85, l'item entra nel cluster esistente; altrimenti nasce un cluster nuovo. Non c'è riorganizzazione retroattiva: l'assegnazione è definitiva. La soglia (0.85) e la finestra (72h) sono configurabili via `.env`.

**4. Operatore pgvector `<=>`** — usato in `ClusterNewsItemJob`

`<=>` è l'operatore di distanza coseno di pgvector. Restituisce un valore tra 0 (vettori identici) e 2 (vettori opposti). La query calcola `1 - (embedding <=> ?)` per ottenere la similarità coseno (0–1). L'`ORDER BY embedding <=> ? ASC LIMIT 1` sfrutta l'indice HNSW/IVFFlat se presente, altrimenti fa una scansione sequenziale (accettabile a volumi bassi).

**5. Contratti e binding** — `app/Contracts/`, `App\Providers\AppServiceProvider`

`LLMClient` e `EmbeddingDriver` sono interfacce PHP. Le implementazioni concrete (`AnthropicService`, `OpenAIEmbeddingDriver`, `VoyageEmbeddingDriver`) vengono risolte dal container Laravel tramite binding in `AppServiceProvider`. Nei test è sufficiente fare `$this->mock(LLMClient::class, ...)` per intercettare tutte le chiamate alle API esterne senza toccare le classi che le usano.

**6. Scoring formula** — `App\Services\ScoringService`

`total_score = 0.35·consensus + 0.20·novelty + 0.20·importance_norm + 0.25·topic_match`

- `consensus`: min(consensus_count / 10, 1.0) — quante AI diverse hanno riportato la notizia
- `novelty`: valore 0–1 assegnato da Claude durante la synthesis
- `importance_norm`: (AVG(COALESCE(importance_self_rated, 3)) - 1) / 4 — rating medio normalizzato, con fallback 3 per i report che omettono il campo
- `topic_match`: frazione dei tag del cluster presenti in `SCORING_TOPIC_INTEREST_TAGS`

I pesi sono sovrascrivibili via `.env` (`SCORING_WEIGHT_*`). Il rescore bulk si esegue con `clusters:rescore`.

**7. Tag proposals** — `App\Models\TagProposal`, `App\Jobs\SynthesizeClusterJob`

La tassonomia è fissa (22 slug nel seeder). Claude non può aggiungere tag arbitrari: il prompt lo vieta esplicitamente. Se Claude identifica un concetto non coperto, lo inserisce in `tag_proposals` con una `reason` motivata. I proposal accumulano un contatore `frequency` (quante volte Claude ha proposto lo stesso slug). L'operatore umano può promuoverli a tag reali approvandoli manualmente.

**8. Retry asimmetrico su 429/529** — `AnthropicService` + `SynthesizeClusterJob`

Errori 429 (rate limit) e 529 (overload Anthropic) non vanno ritentati immediatamente: l'API è sotto pressione e un retry rapido aggraverebbe la situazione. `AnthropicService` rilancia questi errori senza retry interno. `SynthesizeClusterJob` li gestisce con 8 tentativi totali e backoff crescente (1min → 5min → 15min → 30min → 1h → 2h → 4h), per una finestra di recupero totale di circa 8 ore.

**9. Idempotenza dell'ingest** — `App\Actions\IngestReportAction`

`IngestReportAction::execute()` ritorna `true` se il report è stato creato, `false` se era già presente. Il check avviene sul `payload_hash` prima di aprire qualsiasi transazione. Questo rende il comando `reports:ingest` sicuro da rieseguire sulla stessa cartella: i file già processati vengono saltati, non duplicati.

**10. event_date e importance_self_rated come nullable** — `App\Models\NewsItem`

Due campi del newsitem sono deliberatamente nullable: la data dell'evento (non sempre dichiarata dall'AI) e il rating di importanza (alcune AI lo omettono). Il sistema non inventa valori: `event_date` rimane null se assente, `importance_self_rated` usa `COALESCE(value, 3)` solo al momento del calcolo dello score, senza modificare il dato originale.

**11. Cluster date range calcolato on-the-fly** — `App\Http\Controllers\Api\ClusterController`

I cluster non hanno colonne `event_date_min`/`event_date_max`. L'intervallo viene calcolato dinamicamente con `.withMin('newsItems', 'event_date')` e `.withMax('newsItems', 'event_date')` di Eloquent. La risposta espone `news_items_min_event_date` e `news_items_max_event_date`. Se in futuro serve filtrare per data evento, sarà necessario aggiungere una colonna denormalizzata su `clusters`.

**12. Riconciliazione cluster alla cancellazione di un report** — `App\Actions\DeleteReportAction`

Cancellare un report non cancella i cluster meccanicamente. `DeleteReportAction` raccoglie gli id dei cluster coinvolti prima del delete, poi per ognuno controlla quanti newsitem sono sopravvissuti: zero → cluster eliminato con le sue draft; più di zero → `consensus_count` aggiornato al conteggio reale. Le publication in stato `approved`, `rejected` o `published` non vengono mai toccate.

---

## 6. Dipendenze esterne

| Servizio | Scopo | Configurato in | Env var |
|---|---|---|---|
| **Anthropic API** | LLM per synthesis cluster, generazione post LinkedIn e articoli | `config/services.php` → chiave `anthropic`; binding in `AppServiceProvider` → `LLMClient` | `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` |
| **OpenAI API** | Embedding vettoriale dei newsitem (driver default) | `config/services.php` → chiave `openai`; binding in `AppServiceProvider` → `EmbeddingDriver` | `OPENAI_API_KEY` |
| **Voyage AI** | Embedding alternativo a OpenAI (driver opzionale) | `config/services.php` → chiave `voyage`; attivato con `EMBEDDING_DRIVER=voyage` | `VOYAGE_API_KEY`, `EMBEDDING_DRIVER=voyage` |
| **PostgreSQL 16 + pgvector** | Database principale; estensione `vector` per similarità coseno | `config/database.php`; immagine Docker `pgvector/pgvector:pg16` in `docker-compose.yml` | `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| **Redis** | Backend per Laravel Queue (driver `redis`) e Laravel Horizon | `config/queue.php`; `config/horizon.php` | `REDIS_HOST`, `QUEUE_CONNECTION=redis` |
