# Command Helper

## Pipeline

```bash
# Ingest dalla cartella di default (storage/reports/import)
./vendor/bin/sail artisan reports:ingest

# Ingest da path custom
./vendor/bin/sail artisan reports:ingest --path=<path>

# Ingest da path custom con destinazione custom
./vendor/bin/sail artisan reports:ingest --path=<path> --move=<dest>

# Riprocessa embedding e clustering per un report già ingestato
./vendor/bin/sail artisan reports:reprocess <report_id>
```

## Worker / Code

```bash
# Processa tutti i job in coda e si ferma
./vendor/bin/sail artisan queue:work --stop-when-empty

# Worker persistente (rimane in ascolto)
./vendor/bin/sail artisan queue:work

# Horizon (Redis, processa automaticamente)
./vendor/bin/sail artisan horizon
```

## Gestione job falliti

```bash
# Lista dei job falliti con UUID
./vendor/bin/sail artisan queue:failed

# Riprocessa tutti i job falliti
./vendor/bin/sail artisan queue:retry all

# Riprocessa un job specifico
./vendor/bin/sail artisan queue:retry <uuid>

# Svuota la tabella failed_jobs
./vendor/bin/sail artisan queue:flush

# Svuota la coda pendente (driver database)
./vendor/bin/sail artisan queue:clear database
```

## Cluster

```bash
# Lista top N cluster dal terminale
./vendor/bin/sail artisan clusters:list --top=10 --since=yesterday

# Ricalcolo bulk degli score (dopo modifica pesi)
./vendor/bin/sail artisan clusters:rescore
```

## Database

```bash
# Applica le migration
./vendor/bin/sail artisan migrate

# Reset completo con seed (distrugge i dati)
./vendor/bin/sail artisan migrate:fresh --seed
```
