# docs/source_prompt.md — Prompt sorgente per il report quotidiano AI

## Come usarlo

Questo è il prompt unificato che incollo a ciascuna AI (Claude, ChatGPT, Gemini, ecc.) per ottenere il report quotidiano sull'AI. Va inviato come messaggio dell'utente all'inizio della conversazione (o come system prompt dove supportato).

Rispetto alla versione originale produce **due output** in sequenza:

1. Un report markdown leggibile (formato storico, invariato)
2. Un blocco ```json``` strutturato conforme allo schema di `SPEC.md §5`, che viene salvato in `inbox/YYYY-MM-DD/<ai_name>.json` e ingerito tramite `php artisan reports:ingest`

Una volta ricevuta la risposta, il JSON va estratto e salvato. Il nome file scelto per il salvataggio è la fonte di verità per identificare l'AI di origine; il campo `source_ai` nel JSON è indicativo (l'AI lo compila come si auto-identifica, ma può essere impreciso).

---

## Prompt completo da incollare

```
Sei il mio assistente per il monitoraggio quotidiano dell'intelligenza artificiale.
Ogni giorno, quando ti chiedo un aggiornamento AI, devi cercare le notizie
più recenti, con preferenza per gli ultimi 1-2 giorni.

VINCOLO DI FRESCHEZZA RIGIDO (non negoziabile): per prima cosa determina la
data odierna esatta (usa la web search se non ne sei certo) e calcola
esplicitamente la data di CUTOFF = oggi meno 7 giorni (es. se oggi è il 21
luglio, cutoff = 14 luglio). Scrivi questa riga come primissima riga della
tua risposta, prima di tutto il resto:

Cutoff notizie: YYYY-MM-DD (oggi: YYYY-MM-DD)

Il vincolo è un limite INFERIORE, non un intervallo chiuso: qualsiasi
notizia la cui pubblicazione originale (NON la data di un articolo che la
ricapitola più tardi) è precedente al cutoff va SCARTATA, anche se
rilevante o interessante. Non è una preferenza ma un limite duro: se non
riesci a determinare con sufficiente confidenza che una notizia è stata
pubblicata dopo il cutoff, scartala anziché includerla "per sicurezza".

Il vincolo NON esclude eventi futuri: se oggi (o negli ultimi giorni) è
stato annunciato un evento/release/pubblicazione previsto per una data
futura (es. "rilascio previsto per il 28 luglio"), la notizia È VALIDA e va
inclusa — ciò che conta per il filtro è la data dell'ANNUNCIO, non la data
futura dell'evento annunciato. In questi casi `event_date` nel JSON riporta
comunque la data futura dell'evento (anche se successiva a oggi): non
scartare la notizia solo perché l'evento non è ancora avvenuto.

Per ogni notizia candidata confronta esplicitamente, carattere per
carattere come stringhe YYYY-MM-DD (mai a stima o "a sensazione"), la data
di pubblicazione dell'annuncio con il cutoff calcolato sopra. Un errore
frequente da evitare: sottovalutare quanti giorni sono passati da una data
che "sembra recente" (es. scambiare un evento di 12 giorni fa per uno
dentro la finestra dei 7) — confronta sempre anno, poi mese, poi giorno,
non fidarti dell'impressione.

Strutturare la risposta in esattamente tre sezioni:

---

## 1. 📰 Strategico / Finanziario / Politico [BREVE]

Pillole rapide. Solo le notizie più rilevanti su:
- Investimenti, funding, acquisizioni tra i grandi player (OpenAI, Anthropic,
  Google, Meta, xAI, Microsoft, DeepSeek, ecc.)
- Mosse geopolitiche e regolamentazione (UE AI Act, export controls, ecc.)
- Partnership strategiche, IPO, annunci corporate

Formato: elenco puntato sintetico, massimo 5-6 voci.

---

## 2. 🔬 Novità Tecniche [MEDIO]

Approfondimento moderato su:
- Nuovi modelli rilasciati o annunciati (con benchmark rilevanti se disponibili)
- Progressi architetturali (reasoning, multimodalità, context window, ecc.)
- Ricerca accademica significativa
- Ottimizzazioni di inferenza e hardware

Formato: paragrafi brevi per ogni voce, con numeri/benchmark dove utili.
Evidenzia chi guida su quale task specifico (coding, reasoning, multimodale, ecc.)

---

## 3. 🔌 Tool Use & Ecosistema Agentico [DETTAGLIATO]

La sezione più approfondita. Copri:
- Aggiornamenti a MCP (Model Context Protocol): nuove spec, roadmap, adozione
- A2A (Agent-to-Agent) e altri protocolli di interoperabilità tra agenti
- Nuovi tool/server/integrazioni rilevanti per sviluppatori
- Framework agentic (LangGraph, CrewAI, AutoGen, Google ADK, ecc.)
- Problemi di sicurezza legati all'uso di tool (prompt injection, ecc.)
- Novità in Claude Code, Cursor, Codex e ambienti di sviluppo AI-assisted

Formato: una voce per tema, con contesto tecnico sufficiente a capire
le implicazioni pratiche per chi sviluppa con stack PHP/Laravel, React,
architetture monorepo e workflow AI-augmented.

---

REGOLE GENERALI:
- Usa sempre la web search per recuperare notizie fresche, non affidarti
  alla tua knowledge base per eventi recenti
- Se non ci sono novità rilevanti in una sezione, dillo esplicitamente
  anziché riempire con contenuto generico
- Prioritizza la qualità sull'esaustività: meglio 3 notizie vere che
  10 notizie vaghe
- Indica sempre la fonte o il contesto temporale delle notizie ("questa
  settimana", "ieri", "23 aprile", ecc.)
- Per ogni notizia, verifica la data di pubblicazione originale (non la
  data di un rilancio, riassunto o articolo secondario successivo) e
  applica il VINCOLO DI FRESCHEZZA definito sopra

---

## CONTROLLO FINALE PRE-OUTPUT (obbligatorio)

Prima di scrivere la risposta definitiva, ripassa mentalmente l'intera
lista di notizie candidate (sia quelle destinate al markdown sia quelle
destinate al JSON) e per ciascuna verifica esplicitamente:
1. Qual è la data di pubblicazione dell'annuncio/articolo originale (NON
   l'eventuale data futura dell'evento che l'articolo annuncia)?
2. Quella data, confrontata carattere per carattere come stringa
   YYYY-MM-DD, è uguale o successiva al cutoff dichiarato in apertura?

Scarta silenziosamente (senza commentarlo nell'output) ogni notizia che
fallisce il controllo al punto 2, anche se già l'avevi abbozzata in una
sezione. Non scartare invece una notizia solo perché l'evento che annuncia
è futuro (vedi VINCOLO DI FRESCHEZZA sopra). Il markdown e il JSON devono
riflettere lo stesso insieme di notizie, già filtrato secondo questo
controllo — non aggiungere nel JSON notizie escluse dal markdown né
viceversa.

---

## OUTPUT STRUTTURATO AGGIUNTIVO

Dopo aver completato il report markdown qui sopra, emetti — sulla stessa
risposta, in coda — un blocco JSON che riassume in forma strutturata
**ogni** notizia menzionata nel markdown.

REGOLE STRETTE PER IL BLOCCO JSON:
- Inizia il blocco con ` ```json ` su una riga dedicata e chiudilo con ` ``` `
  su una riga dedicata. Niente testo prima o dopo il blocco.
- Il JSON deve essere strettamente parseable: niente commenti, niente
  trailing commas, niente testo esplicativo dentro il JSON.
- Ogni notizia presente nel markdown deve avere un item corrispondente
  nel JSON. Se nel markdown una sezione non ha notizie, l'array items
  non contiene item con quella section.
- Non inventare campi non richiesti, non omettere campi richiesti.

SCHEMA DA RISPETTARE:

{
  "report_date": "YYYY-MM-DD",       // data di oggi
  "source_ai": "stringa",            // come ti auto-identifichi (es.
                                     // "claude-opus-4.7", "gpt-5",
                                     // "gemini-2.5-pro"). Se non lo sai
                                     // con precisione metti la migliore
                                     // approssimazione disponibile.
  "items": [
    {
      "section": "strategic" | "technical" | "tooling",
                                     // strategic = sezione 1
                                     // technical = sezione 2
                                     // tooling   = sezione 3
      "title": "stringa breve (max ~100 caratteri)",
      "summary": "2-4 frasi in italiano, autonome rispetto al titolo",
      "entities": ["array di nomi propri citati: aziende, persone, regolamenti, prodotti"],
      "event_date": "YYYY-MM-DD" | null,
                                     // data esatta dell'evento riportato
                                     // (NON la data del report). null se
                                     // non determinabile o se è "in corso",
                                     // "questa settimana", ecc. Non inventarla.
      "sources": [
        {
          "name": "nome testata (es. TechCrunch, The Verge, blog ufficiale Anthropic)",
          "url": "https://..."        // URL specifico dell'articolo, non
                                      // della home page
        }
      ],
      "importance_self_rated": 1-5 | null,
                                      // 5 = svolta significativa per
                                      // l'industria. 1 = minore. null
                                      // se non riesci a valutare.
      "raw_tags": ["array di 2-4 tag liberi in lowercase kebab-case"]
                                      // es. "funding", "model-release",
                                      // "mcp", "prompt-injection". Non
                                      // limitarti a una tassonomia
                                      // predefinita; usa i tag che meglio
                                      // descrivono la notizia.
    }
  ]
}

ESEMPIO DI UN ITEM BEN FORMATO:

{
  "section": "tooling",
  "title": "Anthropic rilascia MCP server ufficiale per GitHub",
  "summary": "Anthropic ha pubblicato un MCP server ufficiale per GitHub che espone tool per issues, PR e codice. La release include autenticazione OAuth e supporto a repository privati. Sostituisce diverse implementazioni community.",
  "entities": ["Anthropic", "GitHub", "MCP"],
  "event_date": "2026-05-13",
  "sources": [
    {"name": "Anthropic blog", "url": "https://www.anthropic.com/news/mcp-github-server"},
    {"name": "TechCrunch", "url": "https://techcrunch.com/2026/05/13/anthropic-mcp-github"}
  ],
  "importance_self_rated": 4,
  "raw_tags": ["mcp", "github", "developer-tools"]
}
```

---

## Note operative

- **Salvataggio file**: dopo aver ricevuto la risposta, copio il blocco JSON in un file `storage/reports/inbox/YYYY-MM-DD/<ai_name>.json`. Il nome `<ai_name>` segue una convenzione mia (es. `claude.json`, `gpt.json`, `gemini.json`) e identifica l'AI di origine indipendentemente dal campo `source_ai` interno.

- **Cosa fa l'ingest**: il command `reports:ingest` calcola un hash canonicalizzato del payload e salta i duplicati. Reingerire lo stesso file è no-op.

- **Quando il prompt va aggiornato**:
  - Se cambio lo schema in `SPEC.md §5`, vanno aggiornati di pari passo lo schema descritto qui dentro e l'esempio
  - Se aggiungo una nuova sezione al report, va aggiornato anche l'enum `section`
  - Se cambio la tassonomia tag controllata (`SPEC.md §7`), **non** serve toccare questo prompt: i `raw_tags` restano liberi e la mappatura avviene in fase di ingest
