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
reports + news_items  (Postgres)
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
      "event_date": "YYYY-MM-DD (data dell'evento riportato, non del report)",
      "sources": [{"name": "TechCrunch", "url": "https://..."}],
      "importance_self_rated": 1-5,
      "raw_tags": ["funding", "regulation"]
    }
  ]
}
```

L'aggiornamento del prompt sorgente è un deliverable della Fase 1.

## 6. Modello dati iniziale

| Tabella | Colonne principali |
|---|---|
| `reports` | id, report_date, source_ai, payload (jsonb), payload_hash (univoco), ingested_at |
| `news_items` | id, report_id, section (enum), title, summary, entities (jsonb), event_date, raw_tags (jsonb), importance_self_rated, embedding (vector(1536)), cluster_id (nullable) |
| `clusters` | id, canonical_title, canonical_summary, first_seen_at, last_seen_at, consensus_count, novelty_score, importance_avg, topic_match_score, total_score, status (active/archived) |
| `tags` | id, slug (univoco), name, description |
| `news_item_tag` | news_item_id, tag_id (pivot) |
| `cluster_tag` | cluster_id, tag_id (pivot) |
| `entities` | id, name, type (company/person/regulation/product/other) |
| `news_item_entity` | news_item_id, entity_id (pivot) |
| `publications` | id, cluster_id (nullable), kind (linkedin_short/linkedin_medium/linkedin_opinion/article), status (draft/approved/rejected/published), title, body, variants (jsonb), generated_at, published_at, source_cluster_ids (jsonb) |
| `tag_proposals` | id, slug, reason, frequency, status (pending/approved/rejected) |

## 7. Tassonomia tag (seed iniziale)

`mcp`, `agentic-frameworks`, `regulation-eu`, `regulation-us`, `regulation-china`, `funding`, `acquisition`, `model-release`, `benchmark`, `coding-tools`, `security-prompt-injection`, `security-other`, `hardware`, `inference-optimization`, `multimodal`, `reasoning`, `context-window`, `open-source`, `partnership`, `ipo`, `research-paper`, `enterprise-adoption`

I tag fuori da questa lista vanno in `tag_proposals` con status `pending` per review prima di essere promossi a `tags`.

## 8. Roadmap a fasi

### Fase 1 — Foundation (MVP minimo)

- Init progetto Laravel con Sail
- Configurazione Postgres 16 + estensione `pgvector` nel container
- Migration: `reports`, `news_items` (senza colonna `embedding` per ora, viene in Fase 2), `tags`, `entities`, pivot tables
- Seeder `TagSeeder` con la tassonomia del §7
- `docs/source_prompt.md` con il prompt sorgente esteso (markdown + JSON)
- Console command `reports:ingest <path>` che:
  - Accetta un file `.json` o una cartella
  - Valida ogni JSON contro lo schema del §5
  - Calcola hash del payload e salta se già ingerito (idempotente)
  - Crea record in `reports` e `news_items`
  - Popola `entities` da `items[].entities`
  - Popola `news_item_tag` mappando i `raw_tags` ai `tags` esistenti (i non mappati restano in `news_items.raw_tags`)
- Feature test sull'ingest (file valido, file invalido, idempotenza)

**Done quando**: posso ingerire 3 report di esempio (uno per AI) e vedere righe corrette in `news_items`.

### Fase 2 — Embedding + Clustering

- Aggiungere colonna `embedding vector(1536)` a `news_items`
- `EmbeddingService` con interface e 2 driver: `OpenAIEmbeddingDriver` (default), `VoyageEmbeddingDriver`
- `EmbedNewsItemJob` accodato dopo `IngestReportAction` per ogni nuovo item
- `ClusterNewsItemJob`:
  - Esegue dopo embedding completato
  - Cerca cluster esistenti per similarità coseno > `CLUSTERING_SIMILARITY_THRESHOLD` (default 0.85)
  - Limita la ricerca alla finestra `CLUSTERING_TIME_WINDOW_HOURS` (default 72)
  - Se trova match: associa l'item al cluster, aggiorna `last_seen_at` e `consensus_count`
  - Altrimenti: crea nuovo cluster con `canonical_title` = title dell'item, ricalcolo `synth` verrà fatto in Fase 3
- Console command `reports:reprocess <report_id>` per rifare embedding e clustering

**Done quando**: dopo ingest, gli item appaiono raggruppati in `clusters` in modo sensato su un dataset reale di 3-4 giorni.

### Fase 3 — Synthesis + Scoring

- `AnthropicService` con retry (3 tentativi, exponential backoff), timeout, logging strutturato
- `SynthesizeClusterJob`:
  - Input: cluster con tutti gli items associati
  - Output: `canonical_title`, `canonical_summary`, tag scelti dalla tassonomia, eventuali `tag_proposals`, `novelty_score`
  - Prompt strutturato che vieta esplicitamente la creazione di tag fuori tassonomia (vanno in `tag_proposals`)
- Calcolo `total_score = w1*consensus + w2*novelty + w3*importance_avg + w4*topic_match` (pesi da `.env`)
- `topic_match` = frazione di tag del cluster che cadono nei `SCORING_TOPIC_INTEREST_TAGS`
- Console command `clusters:rescore` per ricalcolo bulk dopo modifica pesi
- Console command `clusters:list --top=N --since=DATE` per consultazione da terminale

**Done quando**: posso eseguire `clusters:list --top=10 --since=yesterday` e ottenere una vista ordinata sensata.

### Fase 4 — Generator + Review UI

- `LinkedInPostGenerator`: per cluster con score sopra soglia, genera 3 varianti (`linkedin_short`, `linkedin_medium`, `linkedin_opinion`) salvate come `publications` in `draft`
- `ArticleGenerator`: per cluster ad alto score con tema sufficientemente ricco (heuristic: ≥3 items, ≥2 entità rilevanti), genera bozza articolo lungo (outline → sezioni → coerenza)
- SPA React minimale (Vite + Tailwind):
  - Feed cluster con filtri (sezione, tag, score min, data)
  - Dettaglio cluster con tutti gli items grezzi e bozze associate
  - Lista publications con stato e azioni: `approve`, `reject`, `edit` (textarea con autosave), `mark as published`
  - Export markdown per articolo approvato
- Auth: token statico in header `X-API-Token` (uso personale, non serve OAuth)

**Done quando**: workflow end-to-end funzionante: report ingerito → cluster generato → bozza LinkedIn → approve → markdown copiabile.

### Fase 5 (opzionale) — MCP server custom

- MCP server (TypeScript SDK ufficiale) che espone tool:
  - `search_news_items(query, since, section)`
  - `get_cluster(id)` e `list_pending_clusters(top, since)`
  - `draft_linkedin_post(cluster_id, kind)` → invoca generator e ritorna bozza
- Configurazione stdio per uso da Claude Code locale (no auth necessaria, processo figlio)
- Documentazione `docs/mcp_server.md` con setup

## 9. Decisioni tecniche e razionale

| Decisione | Razionale |
|---|---|
| Postgres vs MySQL | `pgvector` nativo, supporto JSONB più maturo |
| Embedding esterno vs locale | Costi minimi (centesimi/mese al volume previsto), qualità superiore, no GPU |
| OpenAI default per embedding | Più economico oggi (`text-embedding-3-small`); Voyage come backup |
| Anthropic per synthesis e generazione | Qualità superiore su istruzioni complesse strutturate; coerente con il workflow personale |
| Clustering greedy con soglia coseno | Volume basso (decine di item/giorno): soluzione semplice e debuggabile, no HDBSCAN |
| Tassonomia controllata + `tag_proposals` | Previene la proliferazione di tag quasi-duplicati |
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

Progetto vuoto. Da implementare: **Fase 1**.
