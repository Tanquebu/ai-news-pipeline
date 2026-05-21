# SPEC.md — AI News Pipeline

## 1. Problema

Ogni giorno chiedo a più LLM (Claude, ChatGPT, Gemini, ecc.) un report sull'AI con un prompt unificato che produce 3 sezioni: strategico/finanziario/politico, novità tecniche, tool use & ecosistema agentico. I report grezzi sono:

- **Ripetitivi**: le diverse AI riportano spesso le stesse notizie con framing simili
- **Disomogenei**: variano stile, profondità, enfasi
- **Difficili da consultare nel tempo**: markdown sparso, nessun archivio strutturato
- **Sotto-utilizzati**: un report viene letto una volta e dimenticato; il segnale "una notizia citata da N AI è importante" si perde

## 2. Obiettivi

1. Raccogliere ogni report in un formato strutturato esportabile (JSON)
2. Importarlo in un backend con DB
3. Far elaborare i report a un agente AI per: deduplicare, raggruppare per notizia, taggare con tassonomia controllata, calcolare uno score di rilevanza
4. Produrre bozze di contenuti divulgativi (LinkedIn post, articoli) a partire dai cluster ad alto score, con review umana prima della pubblicazione

## 3. Non-obiettivi

- Non è un aggregatore che scrappa il web (l'input arriva dalle AI)
- Non sostituisce il giudizio editoriale: ogni pubblicazione va approvata manualmente
- Non è multi-utente: uso personale
- Non deve essere real-time: workflow batch on-demand è sufficiente

## 4. Architettura ad alto livello

```
[N AI con stesso prompt]
        │
        │ copia/incolla JSON
        ▼
inbox/YYYY-MM-DD/<ai_name>.json
        │
        │ artisan reports:ingest
        ▼
reports + news_items + news_item_sources  (Postgres)
        │
        │ EmbedNewsItemJob (Horizon)
        ▼
news_items.embedding  (pgvector)
        │
        │ ClusterNewsItemJob
        ▼
clusters  (similarità coseno > soglia, entro finestra temporale)
        │
        │ SynthesizeClusterJob (Claude API)
        ▼
clusters.canonical_*, tags, scores
        │
        │ Generator (Claude API), trigger manuale o batch
        ▼
publications  (status: draft)
        │
        │ review umana via UI
        ▼
publications  (status: approved → published)
```

## 5. Prompt sorgente delle AI

Il prompt unificato già in uso (vedi `docs/source_prompt.md`) produce un report markdown a 3 sezioni. Per questo progetto va **esteso** richiedendo, dopo il markdown, un blocco ```json``` finale conforme a questo schema:

```json
{
  "report_date": "YYYY-MM-DD",
  "source_ai": "string (es. claude-opus-4.7, gpt-5, gemini-2.5-pro)",
  "items": [
    {
      "section": "strategic | technical | tooling",
      "title": "stringa breve",
      "summary": "2-4 frasi in italiano",
      "entities": ["OpenAI", "EU AI Act"],
      "event_date": "YYYY-MM-DD | null (data dell'evento riportato, non del report; null se non determinabile)",
      "sources": [{"name": "TechCrunch", "url": "https://..."}],
      "importance_self_rated": "1-5 | null (null se l'AI non lo dichiara)",
      "raw_tags": ["funding", "regulation"]
    }
  ]
}
```

L'aggiornamento del prompt sorgente è un deliverable della Fase 1.

## 6. Modello dati iniziale

| Tabella | Colonne principali |
|---|---|
| `reports` | id, report_date, source_ai, payload (jsonb), payload_hash (sha256, **unique globale**), ingested_at |
| `news_items` | id, report_id, section (enum), title, summary, entities (jsonb), event_date (**nullable**), raw_tags (jsonb), importance_self_rated (**nullable**, 1-5), embedding (vector(1536)), cluster_id (nullable) |
| `news_item_sources` | id, news_item_id, name, url, position (preserva l'ordine originale nel JSON) |
| `clusters` | id, canonical_title, canonical_summary, first_seen_at, last_seen_at, consensus_count, novelty_score, importance_avg, topic_match_score, total_score, status (active/archived) |
| `tags` | id, slug (unique), name, description |
| `news_item_tag` | news_item_id, tag_id (pivot) |
| `cluster_tag` | cluster_id, tag_id (pivot) |
| `entities` | id, name, type (company/person/regulation/product/other) |
| `news_item_entity` | news_item_id, entity_id (pivot) |
| `publications` | id, cluster_id (nullable), kind (linkedin_short/linkedin_medium/linkedin_opinion/article), status (draft/approved/rejected/published), title, body, variants (jsonb), generated_at, published_at, source_cluster_ids (jsonb) |
| `tag_proposals` | id, slug, reason, frequency, status (pending/approved/rejected) |

**Note di design:**

- `news_item_sources` è una tabella separata e non un campo `jsonb` su `news_items` per supportare query del tipo "tutti gli URL di fonte aggregati dentro il cluster X" (utile per generare riferimenti nei post LinkedIn e articoli) e analisi future per testata (`name`).
- `event_date` nullable: non tutte le notizie hanno una data evento esplicita ("questa settimana", "in corso", o l'AI non la dichiara). Meglio nullable che inventata.
- `importance_self_rated` nullable: alcune AI omettono il campo. Nello scoring (Fase 3) si applica `COALESCE(importance_self_rated, 3)` come fallback al valore mediano.
- `payload_hash` unique globale: poiché il payload include `source_ai`, una collisione tra AI diverse è impossibile per costruzione. Calcolato via `App\Support\CanonicalJson::hash()` (vedi Fase 1).

## 7. Tassonomia tag (seed iniziale)

`mcp`, `agentic-frameworks`, `regulation-eu`, `regulation-us`, `regulation-china`, `funding`, `acquisition`, `model-release`, `benchmark`, `coding-tools`, `security-prompt-injection`, `security-other`, `hardware`, `inference-optimization`, `multimodal`, `reasoning`, `context-window`, `open-source`, `partnership`, `ipo`, `research-paper`, `enterprise-adoption`

Gli slug nel seeder vanno già in forma normalizzata (lowercase, kebab-case). I `raw_tags` dal JSON delle AI vengono mappati ai `tags` esistenti applicando `Str::slug(strtolower($raw))` e cercando un match su `tags.slug`. I `raw_tags` non mappati restano nel campo `news_items.raw_tags` come dato grezzo e **non** alimentano automaticamente `tag_proposals`: la proposta di nuovi tag è un meccanismo riservato alla Fase 3, dove la synthesis di Claude propone nuovi tag motivandoli esplicitamente.

## 8. Roadmap a fasi

### Fase 1 — Foundation (MVP minimo)

- Init progetto Laravel con Sail
- **Override del servizio `pgsql` in `docker-compose.yml`** sostituendo l'immagine di default con `pgvector/pgvector:pg16` (immagine ufficiale upstream, Postgres + pgvector preinstallato, stesso client psql)
- Migration `CREATE EXTENSION IF NOT EXISTS vector` come prima migration: abilitiamo l'estensione subito anche se la colonna `vector(1536)` su `news_items` arriva in Fase 2, per non bloccare la transizione
- Migrations: `reports`, `news_items` (senza colonna `embedding` per ora), `news_item_sources`, `tags`, `entities`, pivot tables
- Seeder `TagSeeder` con la tassonomia del §7
- Helper `App\Support\CanonicalJson::hash(array $data): string` che esegue:
  1. `ksort` ricorsivo sull'array
  2. `json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)`
  3. `hash('sha256', $json)`

  Questo garantisce che payload semanticamente identici producano lo stesso hash indipendentemente da ordine chiavi o whitespace.
- `docs/source_prompt.md` con il prompt sorgente esteso (markdown + JSON come da §5)
- Console command `reports:ingest <path>` che:
  - Accetta un file `.json` o una cartella
  - Valida ogni JSON contro lo schema del §5 (Form Request o `validator()` su array — `event_date` e `importance_self_rated` accettati come `null`)
  - Calcola hash via `CanonicalJson::hash($payload)` e salta se `payload_hash` già presente in `reports` (idempotente)
  - Crea record in `reports` e `news_items`
  - Popola `news_item_sources` da `items[].sources` preservando l'ordine in `position`
  - Popola `entities` da `items[].entities` (find-or-create su `name`)
  - Popola `news_item_tag` mappando i `raw_tags` ai `tags` esistenti via `Str::slug(strtolower($raw))` e match su `tags.slug`. I raw non mappati restano in `news_items.raw_tags`
- Feature test sull'ingest:
  - file valido → record corretti in tutte le tabelle attese
  - file invalido → errore di validazione, nessun record creato
  - stesso file due volte → secondo ingest no-op (idempotenza)
  - file con `event_date` o `importance_self_rated` null → ingest riuscito
  - raw_tag `"Funding"` e `"funding"` entrambi mappati a `tags.slug = funding`

**Done quando**: posso ingerire 3 report di esempio (uno per AI) e vedere righe corrette in `reports`, `news_items`, `news_item_sources`, `news_item_tag`, `entities`.

### Fase 2 — Embedding + Clustering

- Aggiungere colonna `embedding vector(1536)` a `news_items` via migration
- `EmbeddingService` con interface e 2 driver: `OpenAIEmbeddingDriver` (default), `VoyageEmbeddingDriver`
- `EmbedNewsItemJob` accodato dopo `IngestReportAction` per ogni nuovo item
- `ClusterNewsItemJob`:
  - Esegue dopo embedding completato
  - Cerca cluster esistenti per similarità coseno > `CLUSTERING_SIMILARITY_THRESHOLD` (default 0.85)
  - Limita la ricerca alla finestra `CLUSTERING_TIME_WINDOW_HOURS` (default 72)
  - Se trova match: associa l'item al cluster, aggiorna `last_seen_at` e `consensus_count`
  - Altrimenti: crea nuovo cluster con `canonical_title` = title dell'item; il `synth` definitivo verrà fatto in Fase 3
- Console command `reports:reprocess <report_id>` per rifare embedding e clustering

**Done quando**: dopo ingest, gli item appaiono raggruppati in `clusters` in modo sensato su un dataset reale di 3-4 giorni.

### Fase 3 — Synthesis + Scoring

- `AnthropicService` con retry selettivo (3 tentativi con exponential backoff per errori di rete/timeout; nessun retry interno per 429/529 — vedi §12), timeout 60s, logging strutturato
- `SynthesizeClusterJob`:
  - Input: cluster con tutti gli items associati
  - Output: `canonical_title`, `canonical_summary`, tag scelti dalla tassonomia, eventuali `tag_proposals`, `novelty_score`
  - Prompt strutturato che vieta esplicitamente la creazione di tag fuori tassonomia (vanno in `tag_proposals` con `reason` motivata)
- Calcolo `total_score = w1*consensus + w2*novelty + w3*importance_avg + w4*topic_match` (pesi da `.env`)
  - **`importance_avg` è calcolato come media di `COALESCE(importance_self_rated, 3)` sugli items del cluster** (fallback al valore mediano per gli item dove l'AI non ha dichiarato il rating)
  - `topic_match` = frazione di tag del cluster che cadono nei `SCORING_TOPIC_INTEREST_TAGS`
- Console command `clusters:rescore` per ricalcolo bulk dopo modifica pesi
- Console command `clusters:list --top=N --since=DATE` per consultazione da terminale

**Done quando**: posso eseguire `clusters:list --top=10 --since=yesterday` e ottenere una vista ordinata sensata.

### Fase 4 — Generator + Review UI

- `LinkedInPostGenerator`: per cluster con score sopra soglia, genera 3 varianti (`linkedin_short`, `linkedin_medium`, `linkedin_opinion`) salvate come `publications` in `draft`
- `ArticleGenerator`: per cluster ad alto score con tema sufficientemente ricco (heuristic: ≥3 items, ≥2 entità rilevanti), genera bozza articolo lungo (outline → sezioni → coerenza)
- SPA React minimale (Vite + Tailwind):
  - Feed cluster con filtri (sezione, tag, score min, data)
  - Dettaglio cluster con tutti gli items grezzi, le fonti aggregate da `news_item_sources` e le bozze associate
  - Lista publications con stato e azioni: `approve`, `reject`, `edit` (textarea con autosave), `mark as published`
  - Export markdown per articolo approvato
- Auth: token statico in header `X-API-Token` (uso personale, non serve OAuth)

**Done quando**: workflow end-to-end funzionante: report ingerito → cluster generato → bozza LinkedIn → approve → markdown copiabile.

### Fase 6 — Report Management

- API `GET /api/reports` — lista paginata dei report importati con conteggio `news_items_count`, ordinata per data decrescente
- API `DELETE /api/reports/{id}` — elimina il report con cascade DB su `news_items`, `news_item_sources`, `news_item_tag`, `news_item_entity` (FK con `cascadeOnDelete`); riconcilia i cluster coinvolti:
  - cluster rimasti senza items → eliminati insieme alle loro `publications` in stato `draft`
  - cluster con items sopravvissuti → `consensus_count` aggiornato al conteggio reale
  - `publications` in stato `approved`/`published`/`rejected` non vengono mai eliminate
- UI: tab "Report" nella SPA con lista e bottone "Elimina" per riga (browser confirm dialog)
- Auth: stesso token statico `X-API-Token` delle altre route

**Done quando**: posso vedere la lista dei report importati e cancellarne uno selettivamente dall'UI, verificando che il cluster associato venga gestito correttamente.

### Fase 5 (opzionale) — MCP server custom

- MCP server (TypeScript SDK ufficiale) che espone tool:
  - `search_news_items(query, since, section)`
  - `get_cluster(id)` e `list_pending_clusters(top, since)`
  - `draft_linkedin_post(cluster_id, kind)` → invoca generator e ritorna bozza
- Configurazione stdio per uso da Claude Code locale (no auth necessaria, processo figlio)
- Documentazione `docs/mcp_server.md` con setup

## 12. Gestione errori API esterni

### Anthropic — errori di capacità (429 / 529)

Anthropic restituisce **HTTP 429** (rate limit) e **HTTP 529** (overloaded) come errori transitori che possono durare minuti o ore. Questi non vanno confusi con errori di rete o timeout (recuperabili in pochi secondi).

**Strategia adottata:**

| Livello | Comportamento |
|---|---|
| `AnthropicService` (HTTP) | Retry interno attivo **solo** per errori di rete e timeout (max 3 volte, backoff 1s/2s/4s). Su 429/529 l'eccezione è rilanciata immediatamente senza retry. |
| `SynthesizeClusterJob` (queue) | 8 tentativi totali con backoff progressivo: **1m → 5m → 15m → 30m → 1h → 2h → 4h** (finestra totale ~8h). |

**Razionale:** ritentare rapidamente su un 429/529 è controproducente — brucia i tentativi disponibili mentre l'API è ancora sovraccarica. Il retry deve avvenire a livello di queue worker, con attese abbastanza lunghe da permettere ad Anthropic di recuperare.

**Diagnostica:** i job in attesa di retry restano nella tabella `jobs` con `attempts > 0`. I job che esauriscono tutti i tentativi finiscono in `failed_jobs`. Gli errori singoli sono loggati in `storage/logs/laravel.log`.

**Reset manuale:** se si vuole resettare i tentativi di un job bloccato:
```bash
./vendor/bin/sail artisan tinker --execute="DB::table('jobs')->update(['attempts' => 0, 'reserved_at' => null]);"
```

## 9. Decisioni tecniche e razionale

| Decisione | Razionale |
|---|---|
| Postgres vs MySQL | `pgvector` nativo, supporto JSONB più maturo |
| Immagine `pgvector/pgvector:pg16` | Ufficiale upstream, manutenuta, no Dockerfile custom da mantenere |
| Embedding esterno vs locale | Costi minimi (centesimi/mese al volume previsto), qualità superiore, no GPU |
| OpenAI default per embedding | Più economico oggi (`text-embedding-3-small`); Voyage come backup |
| Anthropic per synthesis e generazione | Qualità superiore su istruzioni complesse strutturate; coerente con il workflow personale |
| Clustering greedy con soglia coseno | Volume basso (decine di item/giorno): soluzione semplice e debuggabile, no HDBSCAN |
| Tassonomia controllata + `tag_proposals` solo da Fase 3 | Previene proliferazione tag quasi-duplicati; i raw_tags grezzi delle AI sono rumore eterogeneo |
| `news_item_sources` come tabella, non jsonb | Permette aggregazione fonti per cluster e analisi per testata |
| Hash payload canonicalizzato | Idempotenza robusta a variazioni di formattazione del JSON |
| `event_date` e `importance_self_rated` nullable | Fedeltà al dato originale, nessuna invenzione; fallback esplicito nello scoring |
| `event_date` non denormalizzato su `clusters` | Calcolato on-the-fly via `withMin`/`withMax` sugli `newsItems` associati; esposto come `news_items_min_event_date` / `news_items_max_event_date`. Se in futuro serve filtrare per data evento, aggiungere colonna `event_date date nullable` su `clusters` e popolarla in `ClusterNewsItemJob`. |
| Tutto Laravel/PHP | Coerenza con lo stack principale dell'utente |
| MCP server in TS (Fase 5) | SDK ufficiale più maturo; processo separato non vincola lo stack |

## 10. Configurazione `.env` rilevante

```ini
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-opus-4-7
OPENAI_API_KEY=
EMBEDDING_DRIVER=openai
EMBEDDING_MODEL=text-embedding-3-small
EMBEDDING_DIMENSIONS=1536

CLUSTERING_SIMILARITY_THRESHOLD=0.85
CLUSTERING_TIME_WINDOW_HOURS=72

SCORING_WEIGHT_CONSENSUS=0.35
SCORING_WEIGHT_NOVELTY=0.20
SCORING_WEIGHT_IMPORTANCE=0.20
SCORING_WEIGHT_TOPIC_MATCH=0.25
SCORING_TOPIC_INTEREST_TAGS=mcp,agentic-frameworks,coding-tools

PIPELINE_API_TOKEN=  # token statico per UI/MCP (Fase 4-5)
```

## 11. Stato attuale

Fasi 1–6 implementate e testate. Branch attivo: `feat/foundation-phase-1`.
