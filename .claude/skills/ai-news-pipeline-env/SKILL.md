---
name: ai-news-pipeline-env
description: Ambiente di esecuzione comandi per il progetto ai-news-pipeline Laravel.
             Usare quando si eseguono comandi artisan, migration, queue, tinker,
             o qualsiasi operazione che richiederebbe Sail o php artisan.
---

## Ambiente shell

Il tool Bash gira in WSL2 (Linux). Sail funziona direttamente dalla shell.

## Comandi artisan

Usare sempre Sail:
```
./vendor/bin/sail artisan <comando>
```

Eseguire sempre dalla directory del progetto: `/home/akela/projects/ai-news-pipeline`

## Esempi comuni

```bash
# Boot ambiente
./vendor/bin/sail up -d

# Migrations
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed

# Test
./vendor/bin/sail test
./vendor/bin/sail test --filter=<TestName>

# Tinker
./vendor/bin/sail artisan tinker

# Worker
./vendor/bin/sail artisan horizon
```
