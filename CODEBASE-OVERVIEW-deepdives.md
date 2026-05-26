# CODEBASE-OVERVIEW — Approfondimenti

Sessioni di approfondimento sui concetti chiave del capitolo 5 di `CODEBASE-OVERVIEW.md`.

---

## 1. Canonical JSON Hash — `App\Support\CanonicalJson`

### Il problema che risolve

I report arrivano come file JSON prodotti da LLM diversi (o dallo stesso LLM in run diverse). Due file che contengono le stesse informazioni possono avere chiavi in ordine diverso:

```json
// File A
{ "source_ai": "claude", "items": [...] }

// File B
{ "items": [...], "source_ai": "claude" }
```

`json_encode()` su questi due oggetti produce stringhe diverse → SHA256 diverso → falso negativo nella deduplicazione. Il sistema li tratterebbe come report distinti.

### La soluzione: normalizzare prima di hashare

La classe fa tre cose in sequenza:

**1. Ordinamento ricorsivo delle chiavi (`ksort`)**

```php
private static function sort(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;            // scalari: passa invariato
    }

    if (array_is_list($value)) {
        return array_map(self::sort(...), $value);  // array sequenziale: ricorre sui valori, NON riordina
    }

    ksort($value);                // array associativo: ordina per chiave alfabeticamente
    return array_map(self::sort(...), $value);       // poi ricorre sui valori
}
```

La distinzione tra **array associativo** e **array sequenziale** (`array_is_list`) è cruciale:
- `{"b": 1, "a": 2}` → ordina le chiavi → `{"a": 2, "b": 1}` ✓
- `["primo", "secondo", "terzo"]` → **non riordina** — l'ordine degli elementi in una lista è semantico ✓

**2. Serializzazione deterministica**

```php
json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
```

`JSON_UNESCAPED_UNICODE` evita che caratteri come `à` o `è` vengano trasformati in `à`: stesso testo → stessa stringa.

**3. SHA256 sulla stringa risultante**

```php
hash('sha256', ...)
```

Produce una stringa esadecimale di 64 caratteri. Questo è il `payload_hash` salvato nella colonna `UNIQUE` della tabella `reports`.

### Dove viene usato

In `IngestReportAction::execute()`, **prima di aprire qualsiasi transazione**:

```
CanonicalJson::hash($payload)
    → controlla payload_hash in reports
    → se esiste: return false (scartato silenziosamente)
    → se non esiste: INSERT report + newsitems + …
```

Questo rende `reports:ingest` **idempotente**: puoi eseguirlo più volte sulla stessa cartella senza creare duplicati (vedi punto 9 del cap. 5).

### Cosa NON copre

- **Riordino degli elementi in una lista**: se un LLM produce gli stessi item in ordine diverso, l'hash è diverso e il sistema li tratta come report distinti. Scelta consapevole: l'ordine in una lista è semantico.
- **Normalizzazione dei valori**: `"GPT-4"` e `"gpt-4"` producono hash diversi. Non c'è case-folding o trim automatico.

---

## 2. Pipeline di job in cascata — `EmbedNewsItemJob` → `ClusterNewsItemJob` → `SynthesizeClusterJob`

### Il problema che risolve

Elaborare un newsitem richiede tre operazioni pesanti e sequenziali:

1. **Embedding** — chiamata HTTP a OpenAI/Voyage (latenza ~200ms, dipendente da rete)
2. **Clustering** — query pgvector su potenzialmente molti vettori (dipendente da DB)
3. **Synthesis** — chiamata HTTP a Claude con un prompt lungo (latenza ~2–5s, soggetta a rate limiting)

Eseguirle in sincrono dentro `IngestReportAction` bloccherebbe la richiesta per secondi e renderebbe il sistema fragile: un timeout OpenAI farebbe fallire l'ingest intero. La soluzione è spezzarle in job asincroni.

### La struttura a cascata

Non esiste un orchestratore centrale. La sequenza è codificata nei `handle()` di ciascun job: ogni job, alla fine del suo lavoro, dispatcha il successivo.

```
IngestReportAction
  └─ EmbedNewsItemJob::dispatch($newsItemId)
        └─ [handle] genera embedding, persiste su news_items.embedding
        └─ ClusterNewsItemJob::dispatch($newsItemId)
              └─ [handle] trova o crea cluster via pgvector
              └─ SynthesizeClusterJob::dispatch($clusterId)
                    └─ [handle] chiama Claude, aggiorna canonical_title/summary,
                                sincronizza tag, calcola score
```

### Job 1 — `EmbedNewsItemJob`

- Chiama `EmbeddingService::embedNewsItem($item)` che concatena `title + "\n" + summary` e lo manda al driver attivo (OpenAI o Voyage)
- Persiste il vettore con una raw query: `UPDATE news_items SET embedding = '[...]' WHERE id = ?`
- Dispatcha `ClusterNewsItemJob`

**Perché raw query per il vettore:** Eloquent non sa serializzare un array PHP come `vector(1536)` di PostgreSQL. Il formato atteso da pgvector è la stringa `[0.1,0.2,…,0.9]` — da cui `'[' . implode(',', $embedding) . ']'`.

**Retry:** 3 tentativi (fallimento tipico: timeout di rete verso OpenAI).

### Job 2 — `ClusterNewsItemJob`

1. **Guard clause:** se `cluster_id !== null`, l'item è già clusterizzato (re-dispatch accidentale) → ritorna subito
2. Rilegge l'embedding dal DB come stringa raw (vedi sotto)
3. Cerca il newsitem più simile tra quelli già clusterizzati via pgvector, con due vincoli:
   - Similarità coseno ≥ 0.85 (configurabile via `pipeline.clustering.similarity_threshold`)
   - `created_at` entro le ultime 72h (configurabile via `pipeline.clustering.time_window_hours`)
4. **Se match:** associa l'item al cluster, incrementa `consensus_count`, aggiorna `last_seen_at`
5. **Se nessun match:** crea un nuovo `Cluster` con `canonical_title = item.title` come placeholder
6. Dispatcha `SynthesizeClusterJob($clusterId)`

**Perché rilegge l'embedding come stringa raw:**
`EmbedNewsItemJob` non passa il vettore al job successivo, passa solo `$newsItemId`. E anche se lo passasse, Eloquent non ha un cast nativo per `vector(1536)` — casterebbe la colonna come stringa generica. La soluzione è rileggerlo con un cast PostgreSQL esplicito:

```php
$embeddingRaw = DB::scalar('SELECT embedding::text FROM news_items WHERE id = ?', [$item->id]);
```

Il `::text` converte il tipo interno pgvector nella sua rappresentazione testuale: `[0.0412,-0.1873,...,0.0091]`. Quella stringa viene passata **verbatim** come parametro alla query di ricerca:

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

L'operatore `<=>` calcola la **distanza coseno** (0 = identici, 2 = opposti). Notare la distinzione:
- `embedding <=> ?` → distanza (minore = più simile)
- `1 - (embedding <=> ?)` → similarità (maggiore = più simile)
- `ORDER BY embedding <=> ? ASC` ordina dal più simile → si prende il primo con `LIMIT 1`
- Poi si filtra: `similarity >= 0.85`

Passare la stringa `[...]` direttamente funziona perché pgvector accetta quel formato testuale come input per i prepared statement — è lo stesso formato usato per salvare il vettore.

**La finestra temporale di 72h** serve a evitare che una notizia "fredda" di giorni fa si raggruppi con una notizia di oggi semanticamente simile ma contestualmente diversa.

**Retry:** 3 tentativi.

### Job 3 — `SynthesizeClusterJob`

1. Carica il cluster con tutti i suoi newsitem e tag
2. Costruisce un prompt con: tutti gli item (sezione, titolo, summary, entità, raw_tags) + lista slug tag validi
3. Chiede a Claude un JSON con: `canonical_title`, `canonical_summary`, `tags[]`, `tag_proposals[]`, `novelty_score`
4. Aggiorna il cluster con titolo/summary canonici
5. **Tag sync:** `$cluster->tags()->sync($tagIds)` — sostituisce tutti i tag con quelli restituiti da Claude (solo quelli nella tassonomia esistente)
6. **Tag proposals:** per ogni tag suggerito fuori tassonomia, crea o aggiorna un `TagProposal` con `firstOrCreate`; se esiste già, incrementa `frequency`
7. Chiama `ScoringService::updateScore($cluster)`

**Retry asimmetrico:** 8 tentativi con backoff progressivo: 1min → 5min → 15min → 30min → 1h → 2h → 4h (~8h di finestra totale). Pensato specificamente per i 429/529 di Anthropic (vedi punto 8).

### Meccanismo di retry in Laravel Queue

`$tries` e `backoff()` non sono definiti da `ShouldQueue` né dal `Queueable` trait — sono una convenzione di naming che il worker Laravel legge via reflection:

- `ShouldQueue` è un'interfaccia **marker** (zero metodi): dice solo "questa classe è un job asincrono"
- `Queueable` è un trait che fornisce metodi fluent (`onQueue()`, `onConnection()`, `delay()`) ma non tocca `$tries`
- Il worker (`Illuminate\Queue\Worker`) ispeziona la classe del job con `property_exists()` e `method_exists()` e usa i valori che trova

Altri setting riconosciuti dallo stesso meccanismo:

| Proprietà/metodo | Tipo | Effetto |
|---|---|---|
| `$tries` | `int` | Tentativi massimi totali |
| `$timeout` | `int` | Secondi prima che il worker termini il processo |
| `$maxExceptions` | `int` | Max eccezioni distinte prima di fallire (indipendente da `$tries`) |
| `backoff()` | `array\|int` | Secondi di attesa tra un tentativo e il successivo |
| `retryUntil()` | `Carbon` | Timestamp limite (alternativo a `$tries`) |
| `uniqueId()` | `string` | Rende il job unico in coda (richiede `ShouldBeUnique`) |
| `$queue` | `string` | Coda di destinazione |
| `$delay` | `int` | Secondi di ritardo prima del primo tentativo |

### `findOrFail` senza try/catch

`findOrFail` lancia `ModelNotFoundException` (estende `\Exception`) se il record non esiste. Non serve un try/catch esplicito perché **il worker Laravel cattura qualsiasi eccezione non gestita** che emerge da `handle()` e si comporta così:

- **Tentativi rimanenti:** rimette il job in coda con il backoff configurato
- **Tentativi esauriti:** sposta il job nella tabella `failed_jobs` con stack trace completo

Il flusso di `handle()` **si interrompe** sull'eccezione — nessuna istruzione successiva viene eseguita. Un caso pratico: se un `NewsItem` viene eliminato mentre il suo job è ancora in coda, il job fallirà 3 volte e finirà in `failed_jobs`.

### Conseguenze di un fallimento intermedio

| Job fallito | Stato lasciato | Recupero |
|---|---|---|
| `EmbedNewsItemJob` | NewsItem senza embedding, senza `cluster_id` | `reports:reprocess <id>` |
| `ClusterNewsItemJob` | NewsItem con embedding ma senza `cluster_id` | `reports:reprocess <id>` |
| `SynthesizeClusterJob` | Cluster con titolo placeholder, `total_score` null | Re-dispatchato automaticamente al prossimo item che entra nel cluster |
