---
name: ai-news-pipeline-env
description: Ambiente di esecuzione comandi per il progetto ai-news-pipeline Laravel.
             Usare quando si eseguono comandi artisan, migration, queue, tinker,
             o qualsiasi operazione che richiederebbe Sail o php artisan.
---

## Ambiente shell

Il tool Bash usa Git Bash (Windows). Sail NON funziona da Git Bash.

## Comandi artisan

NON usare mai:
```
./vendor/bin/sail artisan <comando>
```

Usare sempre:
```
docker exec ai-news-pipeline-laravel.test-1 php artisan <comando>
```

Questo comando va eseguito da PowerShell, non da Git Bash.
Se il contesto è Git Bash, istruire l'utente a eseguirlo manualmente da PowerShell.
