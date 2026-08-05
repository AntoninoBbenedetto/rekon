# ADR-004: REST API come unica interfaccia per la v1, nessun admin panel

Stato: Accettato
Data: 2026-08-01

## Contesto

Lo stack include Filament, un pacchetto Laravel per admin panel basato su
Eloquent, in gran parte dichiarativo. Un sistema di riconciliazione
plausibilmente necessita di una UI di back-office per revisionare le
transazioni `NeedsReview`. La domanda è se costruire questa UI ora, e con
quale strumento.

Il pubblico di questo repository è esplicitamente un pubblico di
ingegneri backend **generalisti** (spec della core slice, §1) a cui viene
mostrato il design del dominio e dell'API — non una dimostrazione di
familiarità con un pacchetto specifico di admin panel.

## Decisione

Per la v1, l'unica interfaccia è una REST API (`POST /imports`,
`GET /transactions`, `GET /transactions/{id}`,
`POST /transactions/{id}/resolve`). Nessuna Filament Resource, nessun
admin panel.

Anche autenticazione e autorizzazione sono fuori scope per la v1 — ma è una
decisione separata, registrata nell'[ADR-008](ADR-008-no-authentication-in-v1_it.md).

## Conseguenze

**Positive:**
- Il 100% dello sforzo sul layer di interfaccia va nel contratto della REST
  API, che è il layer che il progetto vuole far revisionare — forma di
  request/response, modellazione degli errori, come viene esposto l'audit
  trail (`GET /transactions/{id}` che funge anche da vista di audit).
- Footprint di dipendenze più piccolo; niente da configurare o spiegare che
  non faccia parte della dimostrazione principale.
- Mantiene il dominio e l'API pienamente utilizzabili/testabili tramite i
  feature test di Pest senza alcun layer UI in mezzo.

**Negative / trade-off accettati:**
- Nessun modo visuale, no-code, per sfogliare le transazioni `NeedsReview`
  durante una demo o una registrazione — tutto è guidato da `curl`/client
  API.
- L'API è l'unico modo per raggiungere il sistema, quindi eredita per intero
  il peso del "nessuna autenticazione in v1" dell'[ADR-008](ADR-008-no-authentication-in-v1_it.md)
  — esplicitamente **non production-safe**. Vedi quell'ADR per il rischio
  completo.

**Follow-up considerato ma rimandato:** una piccola pagina Livewire
custom per la coda `NeedsReview`, puramente per scopi demo, una volta che
API e dominio sono stabili. È puro lavoro di layer di interfaccia che non
tocca il design del dominio, motivo per cui è rimandato invece di essere
progettato ora.

**Da rivedere se:** un'esigenza di demo/walkthrough dal vivo rende una coda
di review visuale utile per lo sforzo sul layer di interfaccia. (La
prontezza al deployment è tracciata separatamente — vedi
[ADR-008](ADR-008-no-authentication-in-v1_it.md).)
