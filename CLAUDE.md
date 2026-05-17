# CLAUDE.md — AI News Pipeline

## Cosa è questo progetto

Backend Laravel per raccogliere, deduplicare, taggare e curare report quotidiani sull'AI prodotti da più LLM (Claude, GPT, Gemini, ecc.) con un prompt unificato. Il sistema elabora i report grezzi in cluster di notizie con score di rilevanza, e genera bozze di contenuti divulgativi (post LinkedIn, articoli) per pubblicazione previa review umana.

**Fonte di verità funzionale e architetturale: `SPEC.md`.** Questo file copre solo convenzioni operative e regole di lavoro.

## Stack

- PHP 8.3+ / Laravel (ultima stabile)
- PostgreSQL 16 + estensione `pgvector`
- Redis + Laravel Horizon per code job
- Laravel Sail (Docker) per dev locale
- Anthropic API (Claude) per synthesis, tagging, generazione contenuti
- OpenAI API (`text-embedding-3-small`) come driver embedding di default; Voyage AI come driver alternativo
- React + Vite + Tailwind per la UI di review (fase 4, non urgente)

## Comandi comuni

```bash
# Boot ambiente
./vendor/bin/sail up -d

# Database
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed

# Pipeline
./vendor/bin/sail artisan reports:ingest <path>
./vendor/bin/sail artisan reports:reprocess <report_id>
./vendor/bin/sail artisan clusters:rescore

# Worker
./vendor/bin/sail artisan horizon

# Test
./vendor/bin/sail test
./vendor/bin/sail test --filter=<TestName>
```

## Convenzioni codice

- PSR-12, `declare(strict_types=1);` in ogni file PHP
- Form Request per validazione, mai inline nei controller
- **Service classes** in `App\Services\*` per logica di dominio riusabile
- **Action classes** single-public-method (`App\Actions\*`) per operazioni atomiche (es. `IngestReportAction`, `ClusterNewsItemAction`, `SynthesizeClusterAction`)
- Controller e Console Command **thin**: orchestrano, non implementano
- Query via Eloquent; raw SQL solo per operazioni `pgvector` (tramite query builder, parametrizzato)
- Migration sempre reversibili (`down()` implementato)
- Test:
  - **Feature test** per ogni Console Command e ogni endpoint HTTP
  - **Unit test** per Service/Action con logica non banale
  - I client API esterni (Anthropic, OpenAI, Voyage) **vanno sempre mockati** nei test
- Niente Facade dentro i Service: usa constructor injection
- Niente business logic nei Model: solo relazioni, casts, scope semplici
- Naming: classi e metodi in inglese, commenti complessi in italiano se aiutano la chiarezza

## Convenzioni Git

- Branch `main` protetto, lavoro su feature branch
- Nomi branch: `feat/<short-kebab>`, `fix/<short-kebab>`, `chore/<short-kebab>`
- Commit message in inglese, imperative mood (`add embedding job`, non `added embedding job`)
- Commit atomici: un commit = una cosa
- PR description sintetica con riferimento alla sezione di SPEC.md implementata
- Non aggiungere mai Co-authored-by o qualsiasi riferimento a Claude nei commit message

## Cosa NON fare

- **Non installare pacchetti senza prima proporli** e attendere conferma esplicita
- Non scrivere business logic in controller, middleware o model
- Niente astrazioni premature: ogni interfaccia deve avere almeno 2 implementazioni reali oppure un test che la giustifichi
- Non chiamare API esterne reali nei test
- Non hardcodare chiavi API: sempre via `.env` + `config/services.php`
- Non aggiungere endpoint pubblici senza protezione (anche un semplice token statico è sufficiente per uso personale)
- Non implementare fase N+1 prima che la fase N sia completa e testata

## Modo di lavorare (importante)

- **Spec-driven**: SPEC.md è la fonte di verità. Se SPEC.md non copre un caso → chiedi prima di indovinare
- **Task piccoli, uno alla volta**. Non aprire più fronti
- **Prima di un task non banale**: proponi il piano sintetico (file da toccare, decisioni di design, eventuali rischi) e attendi conferma
- **Dopo ogni step di rilievo**: mostra cosa è cambiato (diff o riassunto) e attendi OK prima di proseguire
- Se ti accorgi che SPEC.md è ambiguo, incompleto o sbagliato: proponi un aggiornamento a SPEC.md **prima** di scrivere codice che lo contraddice
- Le dipendenze esterne (Anthropic, OpenAI) vanno sempre dietro un'interfaccia mockabile

## Lingua

Documentazione tecnica e codice in inglese. Conversazione con l'utente in italiano.

