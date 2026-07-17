# MCP Server — AI News Pipeline

Server MCP custom (TypeScript SDK ufficiale) che espone i dati della pipeline come tool utilizzabili da Claude Desktop o Claude Code.

## Prerequisiti

- Node.js 22+
- Pipeline Laravel avviata e raggiungibile (default: `http://localhost`)
- `PIPELINE_API_TOKEN` configurato in `.env`

## Build

```bash
cd mcp-server
npm install
npm run build
```

Il file compilato viene generato in `mcp-server/dist/index.js`.

## Configurazione Claude Code (stdio)

Aggiungi il server con:

```bash
claude mcp add ai-news-pipeline \
  -e PIPELINE_API_URL=http://localhost \
  -e PIPELINE_API_TOKEN=<il_tuo_token> \
  -- node /path/to/ai-news-pipeline/mcp-server/dist/index.js
```

Oppure modifica manualmente `.claude/mcp_servers.json` (o il file di config globale `~/.claude/mcp_servers.json`):

```json
{
  "ai-news-pipeline": {
    "command": "node",
    "args": ["/path/to/ai-news-pipeline/mcp-server/dist/index.js"],
    "env": {
      "PIPELINE_API_URL": "http://localhost",
      "PIPELINE_API_TOKEN": "<il_tuo_token>"
    }
  }
}
```

## Configurazione Claude Desktop

In `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) o `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "ai-news-pipeline": {
      "command": "node",
      "args": ["/path/to/ai-news-pipeline/mcp-server/dist/index.js"],
      "env": {
        "PIPELINE_API_URL": "http://localhost",
        "PIPELINE_API_TOKEN": "<il_tuo_token>"
      }
    }
  }
}
```

## Variabili d'ambiente

| Variabile | Default | Descrizione |
|---|---|---|
| `PIPELINE_API_URL` | `http://localhost` | URL base dell'API Laravel |
| `PIPELINE_API_TOKEN` | *(obbligatorio)* | Token statico (`PIPELINE_API_TOKEN` in `.env`) |

## Tool disponibili

### `search_news_items`

Cerca nei news item ingestati per testo, data o sezione.

| Parametro | Tipo | Descrizione |
|---|---|---|
| `query` | string (opz.) | Testo da cercare in titolo e sommario |
| `since` | string (opz.) | Data ISO 8601 — solo item creati dopo |
| `section` | `strategic` \| `technical` \| `tooling` (opz.) | Filtra per sezione del report |

### `get_cluster`

Restituisce il dettaglio completo di un cluster: titolo, sommario canonico, score, news item, tag e pubblicazioni generate.

| Parametro | Tipo | Descrizione |
|---|---|---|
| `id` | number | ID del cluster |

### `list_pending_clusters`

Elenca i cluster attivi ordinati per score decrescente.

| Parametro | Tipo | Descrizione |
|---|---|---|
| `top` | number (opz.) | Numero massimo di risultati (default: 10) |
| `since` | string (opz.) | Data ISO 8601 — solo cluster visti dopo questa data |

### `draft_linkedin_post`

Genera bozze di post LinkedIn per un cluster tramite il generatore LLM della pipeline.

| Parametro | Tipo | Descrizione |
|---|---|---|
| `cluster_id` | number | ID del cluster |
| `kind` | `short` \| `medium` \| `opinion` (opz.) | Variante specifica; ometti per ricevere tutte e tre |

### `search_knowledge`

Ricerca semantica sulla knowledge base documentale (ricerca ibrida full-text + vettoriale, fusione RRF). Restituisce chunk con titolo, URL, snippet, score e riferimenti documento/chunk per l'approfondimento con `get_document`.

| Parametro | Tipo | Descrizione |
|---|---|---|
| `query` | string | Query di ricerca in linguaggio naturale |
| `limit` | number (opz.) | Numero massimo di risultati |
| `doc_type` | `article` \| `pdf` \| `note` (opz.) | Filtra per tipo di documento |
| `source` | string (opz.) | Filtra per fonte del documento |

### `get_document`

Restituisce un documento della knowledge base per ID: metadati (titolo, fonte, URL, tipo, sommario) e contenuto completo dei chunk in ordine.

| Parametro | Tipo | Descrizione |
|---|---|---|
| `document_id` | number | ID del documento (come restituito da `search_knowledge`) |

## Workflow tipico

```
1. list_pending_clusters(top=5)        → scegli il cluster più rilevante
2. get_cluster(id=<id>)                → leggi i dettagli e le fonti
3. draft_linkedin_post(cluster_id=<id>) → genera le bozze
4. (approva via UI o API PATCH /api/publications/{id})
```

Per la knowledge base:

```
1. search_knowledge(query="...")        → trova i chunk pertinenti con fonte
2. get_document(document_id=<id>)       → leggi il documento completo
```
