# UI direction — Personal Ops Console

Guida distillata alla direzione UI condivisa dai frontend della famiglia.
Sorgente canonica dei token: [`docs/tokens.css`](./tokens.css). Modifica lì e propaga.

## Posizionamento

Questi frontend sono **console operative personali** ("Personal Ops Console"):
strumenti densi di informazione, orientati al lavoro, non landing page di marketing.
La UI deve leggersi come un pannello di controllo sobrio: neutro di base, colore
solo dove comunica uno **stato**. Coerenza tra i progetti più che personalità del singolo.

## Token colore

Valori light + dark (i token non elencati in dark restano invariati dal light).

| Token | Uso | Light | Dark |
|---|---|---|---|
| `bg-app` | sfondo pagina | `#fafafa` | `#0a0a0a` |
| `surface` | card, pannelli, modali | `#ffffff` | `#171717` |
| `surface-muted` | zone secondarie, righe alternate | `#f5f5f5` | `#262626` |
| `border` | bordi standard | `#e5e5e5` | `#262626` |
| `border-strong` | bordi enfatizzati, divisori | `#d4d4d4` | `#404040` |
| `fg` | testo primario | `#0a0a0a` | `#fafafa` |
| `fg-secondary` | testo secondario | `#525252` | `#a3a3a3` |
| `fg-muted` | metadata, placeholder, hint | `#a3a3a3` | `#737373` |
| `primary` / `primary-hover` | azione primaria | `#2563eb` / `#1d4ed8` | invariati |
| `primary-soft` / `primary-softer` | sfondi soft azione | `#eff6ff` / `#dbeafe` | invariati |
| `on-primary` | testo su primary | `#ffffff` | invariato |
| `success` / `-soft` / `-softer` | stato ok | `#16a34a` / `#f0fdf4` / `#dcfce7` | invariati |
| `warning` / `-soft` / `-softer` | stato attenzione | `#d97706` / `#fffbeb` / `#fef3c7` | invariati |
| `danger` / `-soft` / `-softer` | stato errore | `#dc2626` / `#fef2f2` / `#fee2e2` | invariati |
| `info` / `-soft` / `-softer` | stato informativo | `#0284c7` / `#f0f9ff` / `#e0f2fe` | invariati |
| `focus` | focus ring | `#2563eb` | invariato |

Raggi: `--radius-card: 0.5rem`, `--radius-modal: 0.75rem`.

## Tipografia

Font: **Geist** (self-hosted, `public/fonts/`), esposto come `--font-sans`.
Mono: `--font-mono` (system mono stack) per codice/valori tecnici.

| Ruolo | Classi |
|---|---|
| Page title | `text-xl font-semibold` |
| Body | `text-sm` |
| Metadata | `text-xs text-fg-muted` |
| Badge | `text-xs` |

## Icone

- **Libreria unica: `lucide`** (lucide-react). Nessun'altra icon set.
- **Niente emoji** nella UI: le emoji non sono icone e rompono la coerenza visiva.

## Focus e accessibilità

- Focus ring su ogni elemento interattivo: `focus-visible:ring-2 ring-blue-600 ring-offset-2`.
- Usa `focus-visible` (non `focus`) per non mostrare l'anello sul click del mouse.

## Criterio guida

**Gray = 0, colore = stato.**
Il grigio (neutral) è il default per struttura e testo. Il colore compare solo
quando veicola uno stato semantico (success / warning / danger / info) o l'azione
primaria. Se un colore non comunica nulla, non va usato.
