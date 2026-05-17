# Migrazione di Sail su WSL2

## Perché

Il progetto vive attualmente sul filesystem Windows in `C:\Users\Max\Documents\sviluppi\ai-news-pipeline`
ed è montato dentro il container Sail tramite il bind mount `.:/var/www/html` dichiarato in `compose.yaml`.

Quando Docker Desktop (backend WSL2) monta una directory Windows dentro un container Linux, il filesystem
host non espone metadati Linux (uid/gid, chmod) scrivibili in modo che un utente non-root del container
possa diventarne proprietario o modificarli in modo affidabile. L'utente `sail` (UID 1000) non riesce
quindi a scrivere su `storage/` e `bootstrap/cache/`: il risultato è il fallimento della compilazione
delle view Blade e della scrittura dei log, con una risposta 500.

La soluzione definitiva è spostare il progetto sul **filesystem WSL2**. I bind mount da WSL2
(`~/projects/...`) sono Linux-to-Linux: ownership e permessi si comportano correttamente e `sail` può
scrivere su `storage/` come previsto.

L'immagine PostgreSQL custom (`pgvector/pgvector:pg16`) e i named volume Docker esistenti
(`sail-pgsql`, `sail-redis`) non sono impattati — vivono dentro Docker, non sul filesystem host. Compose
deriva il project name dalla basename della directory: poiché vecchia e nuova posizione condividono la
basename `ai-news-pipeline`, i volumi (`ai-news-pipeline_sail-pgsql`, `ai-news-pipeline_sail-redis`)
vengono riutilizzati automaticamente. Eseguire `docker volume ls` prima/dopo per conferma.

Le sessioni e le memorie di Claude Code vivono fuori dal progetto (`~/.claude/`) e sono legate all'*host*
su cui gira il CLI. Dato che da ora in avanti Claude Code verrà eseguito da WSL2 (coerentemente con la
toolchain Linux già usata da Sail/Composer/Docker), serve una migrazione una tantum anche per questi —
vedi Step 12–13.

> **Caveat di debug.** Durante l'indagine è stato eseguito
> `docker exec -u root ai-news-pipeline-laravel.test-1 php artisan view:cache` per confermare la diagnosi.
> Quel comando è riuscito solo perché root scavalca il problema di permessi; come effetto collaterale ha
> lasciato file di proprietà di `root` sotto `storage/framework/views/` (e potenzialmente in altre
> cache directory). Vanno ripuliti dopo la migrazione — vedi Step 5.

---

## Step della migrazione

### 1. Fermare Sail (da PowerShell Windows)

```powershell
cd C:\Users\Max\Documents\sviluppi\ai-news-pipeline
./vendor/bin/sail down
```

### 2. Aprire un terminale WSL2 e creare la directory di destinazione

```bash
mkdir -p ~/projects
```

### 3. Copiare il progetto sul filesystem WSL2

`vendor/` viene **incluso** nell'rsync: contiene il binario `vendor/bin/sail` che serve già al
prossimo step per il rebuild dell'immagine, e i file PHP sono platform-independent quindi copiarli da
Windows è sicuro. Eventuali `node_modules/` (al momento assenti) sono invece esclusi perché possono
contenere binari nativi platform-specific:

```bash
rsync -av --progress \
  --exclude='node_modules/' \
  /mnt/c/Users/Max/Documents/sviluppi/ai-news-pipeline/ \
  ~/projects/ai-news-pipeline/
```

`.git/` viene mantenuto di proposito per preservare lo stato del working tree e la cronologia del branch.
Dopo la copia, rileggere `.env` nella nuova posizione: è stato copiato così com'è e può contenere
valori host-specifici da rivedere. Verificare anche che `vendor/bin/sail` sia eseguibile:

```bash
chmod +x ~/projects/ai-news-pipeline/vendor/bin/sail
```

### 4. Sistemare ownership e permessi

`rsync` eseguito come utente WSL2 mette già i file sotto il tuo UID, ma rendiamolo esplicito così lo
stato è inequivocabile:

```bash
cd ~/projects/ai-news-pipeline
chown -R $(id -u):$(id -g) storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 5. Rimuovere le cache stantie lasciate dal workaround in root

Eliminare tutto ciò che potrebbe essere stato generato come root durante il debug, così Sail può
rigenerarlo pulito sotto UID 1000:

```bash
rm -rf storage/framework/views/* \
       storage/framework/cache/data/* \
       bootstrap/cache/*.php
```

### 6. Ricostruire l'immagine Sail

L'immagine `sail-8.5/app` era stata buildata sull'host Windows con i precedenti `WWWUSER`/`WWWGROUP`.
Ricostruirla così l'utente `sail` nel container coincide con l'UID di WSL2:

```bash
./vendor/bin/sail build --no-cache
```

### 7. Correggere il mount del volume PostgreSQL

Il `compose.yaml` generato da `sail:install` monta il named volume sul **padre** della data dir di
Postgres:

```yaml
volumes:
    - 'sail-pgsql:/var/lib/postgresql'
```

Questo entra in conflitto con la dichiarazione `VOLUME /var/lib/postgresql/data` ereditata dal
Dockerfile di `postgres:16` (su cui si basa `pgvector/pgvector:pg16`). Conseguenza: Docker crea un
**volume anonimo** per `/var/lib/postgresql/data` (dove Postgres scrive davvero), mentre il named
`sail-pgsql` rimane praticamente vuoto. Ad ogni ricreazione del container nasce un nuovo anonymous
volume; i dati del precedente diventano orfani ma non vengono eliminati. Sintomo tipico: dopo un
`sail down` + `sail up` lo schema sembra "sparito".

Il fix è mountare il named volume **direttamente** sulla data dir:

```bash
cd ~/projects/ai-news-pipeline
sed -i "s|'sail-pgsql:/var/lib/postgresql'|'sail-pgsql:/var/lib/postgresql/data'|" compose.yaml
grep sail-pgsql compose.yaml
# atteso: - 'sail-pgsql:/var/lib/postgresql/data'
```

> **Recovery da setup esistente con dati negli anonymous volume.** Se stai migrando da un'installazione
> dove erano già state lanciate migration/seed con il mount sbagliato, i dati vivono in uno degli
> anonymous volume orfani. Per identificarli:
>
> ```bash
> for v in $(docker volume ls -q); do
>   found=$(docker run --rm -v "$v":/data alpine sh -c \
>            'find /data -maxdepth 3 -name PG_VERSION 2>/dev/null | head -1')
>   if [ -n "$found" ]; then
>     size=$(docker run --rm -v "$v":/data alpine sh -c 'du -sh /data 2>/dev/null | cut -f1')
>     echo "$v -> $found (size: $size)"
>   fi
> done
> ```
>
> Per recuperare lo schema/dati dal volume `<VOL_OK>` identificato, fai un `pg_dump` da un Postgres
> ephemeral attaccato a quel data dir, poi `pg_restore` dopo lo Step 9 (composer install). Se i dati
> vecchi non hanno valore — tipico di un setup fresh con solo schema + seed — salta il recovery e
> cancella il named volume vuoto prima del prossimo `sail up`:
>
> ```bash
> docker volume rm ai-news-pipeline_sail-pgsql
> ```

### 8. Avviare Sail da WSL2

```bash
./vendor/bin/sail up -d
```

### 9. Rigenerare l'autoloader dentro il container

La cartella `vendor/` è già stata copiata allo Step 3, ma rigeneriamo l'autoloader dentro il container
appena avviato per sicurezza (rebuild dei file `vendor/composer/autoload_*`, esecuzione degli script
post-install di Laravel). Questo comando richiede i container già up, ecco perché viene **dopo** lo
Step 8:

```bash
./vendor/bin/sail composer install
```

### 10. Inizializzare lo schema

Con il volume Postgres correttamente persistente (Step 7), applicare le migration e i seed:

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
./vendor/bin/sail artisan migrate:status
```

### 11. Verificare l'applicazione

```bash
# endpoint health di Laravel
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/up
# atteso: 200

# la rotta che originariamente restituiva 500 — sostituire con il path effettivo usato dal frontend
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/<rotta-che-falliva>
# atteso: 200 (o il codice di successo appropriato)

# i named volume devono essere riutilizzati, non ricreati
docker volume ls | grep ai-news-pipeline
```

### 12. Installare Claude Code dentro WSL2

Installare il CLI nella distro WSL2 (Node.js 18+ richiesto):

```bash
# dentro WSL2
npm install -g @anthropic-ai/claude-code
claude --version
```

Poi autenticarsi (interattivo — gira nel tuo terminale, non in questa sessione):

```bash
claude
# completare il login una volta, poi /quit
```

Questo crea lo scheletro lato WSL2 di `~/.claude/`: `~/.claude/settings.json`, `~/.claude.json`, e una
directory `~/.claude/projects/` vuota.

### 13. Migrare sessioni e memorie di Claude Code

Sessioni e memorie di un dato progetto sono in `~/.claude/projects/<path-encoded>/`, dove
`<path-encoded>` è il path assoluto del progetto con `/` e `\` sostituiti da `-`. Dopo lo spostamento,
l'encoding cambia da `C--Users-Max-Documents-sviluppi-ai-news-pipeline` a qualcosa come
`-home-<wsl-user>-projects-ai-news-pipeline`.

Trovare il nome esatto della nuova directory (viene creato la prima volta che si lancia `claude` dentro
il progetto spostato), poi copiarci dentro la cronologia precedente:

```bash
# dentro WSL2, dalla nuova posizione del progetto
cd ~/projects/ai-news-pipeline
claude    # avviarlo per far creare la nuova projects/<encoded>/, poi /quit

# verificare il nome della nuova directory
ls ~/.claude/projects/

# copiare sessioni + memorie dalla home Claude Code lato Windows
NEW_DIR=$(ls -d ~/.claude/projects/*projects-ai-news-pipeline* | head -n1)
cp -r /mnt/c/Users/Max/.claude/projects/C--Users-Max-Documents-sviluppi-ai-news-pipeline/. \
      "$NEW_DIR/"
```

Opzionale, se vuoi preservare la configurazione user-level (CLAUDE.md globale, MCP server, agent
custom, hook):

```bash
# CLAUDE.md globale dell'utente, se presente
cp /mnt/c/Users/Max/.claude/CLAUDE.md ~/.claude/CLAUDE.md 2>/dev/null || true

# configurazione MCP / agent / hook sta in .claude.json — controllare prima di sovrascrivere
diff /mnt/c/Users/Max/.claude.json ~/.claude.json
# poi mergiare o copiare a seconda
```

Rilanciare `claude` dentro `~/projects/ai-news-pipeline` e confermare che le sessioni e le memorie
precedenti siano visibili.

---

## Note

- **Dati del database**: il named volume `sail-pgsql` è preservato — non serve rieseguire le migration.
- **Copia Windows**: la copia originale sotto `C:\Users\Max\Documents\sviluppi\ai-news-pipeline` può
  essere tenuta come backup o rimossa; non viene più usata per eseguire Sail. Tenerla almeno finché
  il setup WSL2 non è verificato end-to-end.
- **IDE**: aprire il progetto da VS Code con l'estensione *Remote - WSL*
  (`code ~/projects/ai-news-pipeline` da dentro WSL2). Il terminale integrato sarà già una shell WSL2,
  quindi lanciando `claude` lì verrà usato il binario Linux appena installato.
- **Doppia installazione di Claude Code**: lasciare installato anche il `claude.exe` Windows non fa
  danni, ma le due installazioni non condividono stato — solo quella WSL2 vedrà le sessioni migrate.
