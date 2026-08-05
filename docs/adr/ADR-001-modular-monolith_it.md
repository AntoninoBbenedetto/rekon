# ADR-001: Monolite Modulare invece di Microservizi

Stato: Accettato
Data: 2026-08-01

## Contesto

Il sistema copre diversi bounded context con lifecycle e tassi di
cambiamento diversi: Reconciliation (import estratti conto, matching dei
candidati, review manuale — un solo bounded context, poiché import e
matching condividono lo stesso aggregate `Transaction` e lo stesso
ubiquitous language), Settlement (movimento di denaro, futuro),
Notification (futuro), Audit (trasversale). In un sistema finanziario
greenfield si assume spesso che servano i microservizi "per la scala", ma
questo progetto non ha alcun requisito attuale di scalabilità indipendente,
deployment indipendente o isolamento tra team — c'è un solo team e volumi
di dati modesti.

## Decisione

Costruire un'unica applicazione Laravel deployabile, strutturata come
**Monolite Modulare**. Ogni modulo (`SharedKernel`, `Reconciliation`,
e in seguito `Settlement`, `Notification`) possiede i propri layer Domain /
Application / Infrastructure e comunica con gli altri moduli solo tramite
eventi di dominio e interfacce ben definite — mai accedendo direttamente
alla persistenza o alle classi interne di un altro modulo.

## Conseguenze

**Positive:**
- Un solo database, un solo deployment, un solo confine transazionale — la
  correttezza della riconciliazione (priorità massima secondo
  `PROJECT_CONTEXT.md`) è più facile da ragionare senza transazioni
  distribuite o eventual consistency tra servizi.
- I confini tra moduli sono imposti per convenzione e organizzazione del
  codice fin da ora, il che significa che sono confini reali se
  l'estrazione in servizi dovesse mai servire in futuro — lo stile di
  comunicazione basato su eventi non cambia.
- Overhead operativo più basso: nessun service mesh, nessuna
  autenticazione tra servizi, nessuna infrastruttura di tracing distribuito
  necessaria per dimostrare il lavoro sul dominio di cui parla questo
  progetto.

**Negative / trade-off accettati:**
- Nessuna scalabilità indipendente per modulo (es. il worker della coda di
  matching non può scalare indipendentemente dal resto di `Reconciliation`).
  Accettabile — nulla, qui, ha un profilo di scalabilità che lo richieda.
- Nessuna deployability indipendente. Un bug in un modulo richiede comunque
  di ridistribuire l'intera applicazione. Accettabile in questa fase.
- I confini tra moduli sono imposti da disciplina e code review, non da un
  confine di rete o di processo. Se violati silenziosamente, un
  accoppiamento tra moduli può insinuarsi senza un segnale a compile-time o
  deploy-time che lo intercetti.

**Da rivedere se:** un modulo necessita di scalabilità indipendente
variabile, di un ritmo di deployment indipendente, o se un team separato ne
prende la proprietà.
