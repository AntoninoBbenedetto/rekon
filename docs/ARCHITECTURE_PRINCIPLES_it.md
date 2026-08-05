# Principi Architetturali

Questo documento spiega **perché** il sistema è costruito in questo modo.
Non descrive *come* funziona il sistema — per quello vedi
[architecture/overview_it.md](architecture/overview_it.md) — né registra le
singole decisioni con le alternative considerate — per quello vedi
[adr/](adr/). Questo documento è il livello sopra entrambi: le convinzioni
di fondo che rendono quelle decisioni prevedibili invece che arbitrarie.

## 1. Coerenza prima della latenza

Uno stato finanziario veloce ma sbagliato è peggio di uno stato finanziario
lento ma corretto. Ogni scelta di design in questo repository — concorrenza
ottimistica invece di eventual consistency, validazione sincrona
all'importazione, audit trail immutabile — scambia un po' di throughput con
la garanzia che lo stato riconciliato, in ogni istante, sia lo stato vero.

**Trade-off accettato:** l'API e il job di matching a volte rifiuteranno
una scrittura chiedendo al chiamante di ritentare (vedi
[ADR-003](adr/ADR-003-optimistic-concurrency_it.md)). Questo è trattato come
comportamento corretto, non come un difetto da eliminare.

## 2. Monolite Modulare, non microservizi — per ora

I moduli (`SharedKernel`, `Reconciliation`, e in futuro `Settlement`,
`Notification`) sono separati per confine di dominio e comunicano tramite
eventi di dominio, non chiamate dirette — ma girano in un solo deployable,
un solo database, un solo confine transazionale.

**Perché:** alla scala di questo progetto, transazioni distribuite ed
eventual consistency tra confini di servizio sarebbero pura complessità
accidentale — il tipo di costo che i microservizi impongono a prescindere
dal fatto che il dominio ne abbia bisogno. I confini tra moduli sono reali
(vedi [ADR-001](adr/ADR-001-modular-monolith_it.md)), quindi un'estrazione
futura è un cambiamento di deployment, non un redesign.

**Trade-off accettato:** nessuna scalabilità o deployment indipendente per
modulo. Accettabile perché nulla, qui, ha un profilo di scalabilità che lo
giustifichi.

## 3. L'event store è la fonte di verità; i read model sono usa e getta

Lo stato di `Transaction` viene derivato ripiegando (fold) il suo stream di
eventi, non memorizzato come riga mutabile. Le tabelle read model esistono
solo per rendere veloci le query e possono essere eliminate e ricostruite
dall'event store in qualsiasi momento.

**Perché:** è questo che rende la tracciabilità (`PROJECT_CONTEXT.md` §3)
una proprietà strutturale invece che una convenzione di logging. Una
colonna `state` mutabile può essere sovrascritta perdendo la storia; uno
stream di eventi append-only no.

**Trade-off accettato:** ogni percorso di lettura richiede una proiezione,
e il costo del replay cresce con la lunghezza dello stream. Volutamente
rimandato, non risolto — vedi "Event store snapshots" in "Fuori scope" nello
[spec della core slice](superpowers/specs/2026-08-01-reconciliation-core-slice-design_it.md#11-esplicitamente-fuori-scope--lavori-futuri).

## 4. La concorrenza si gestisce rifiutando i conflitti, non con i lock

Le scritture su un aggregate sono condizionate alla versione attesa del suo
stream di eventi (concorrenza ottimistica), non tenendo un lock su riga o
tabella per la durata di un'operazione di business.

**Perché:** lock pessimisti tenuti attraverso la logica di business sono una
via diretta verso i deadlock che `PROJECT_CONTEXT.md` §2 elenca come
failure mode attesa, specialmente sotto elaborazione concorrente guidata
dalle code (più esecuzioni del matching job, import risottomessi. Un append
rifiutato è un fallimento noto e ritentabile; un deadlock no.

**Trade-off accettato:** i chiamanti devono implementare retry sul
conflitto. Questo sposta la complessità ai margini (livello API, job
runner) invece di nasconderla dentro il dominio — che è il trade-off che
questo progetto fa in modo coerente (vedi Principio 5).

## 5. Spingere la gestione dei fallimenti verso i margini espliciti, non verso middleware implicito

Chiavi di idempotenza, controlli di versione e guardie della macchina a
stati sono tutti valutati dentro il layer domain/application, dove il
codice che decide "è sicuro rifarlo?" sta accanto al codice che lo fa — non
in middleware generico di retry/dedup che avvolge la chiamata.

**Perché:** il middleware generico di gestione dei fallimenti può reagire
solo alla forma di una richiesta, non al significato di un'operazione di
business. Solo il dominio sa che reimportare la stessa riga CSV è un no-op,
mentre reimportare una riga diversa con lo stesso numero di riferimento è
un conflitto.

**Trade-off accettato:** più codice nel layer di dominio rispetto a un
approccio basato su middleware. Accettato perché è anche il codice coperto
dai test dell'aggregate (spec §10), che è dove questo progetto vuole che
vivano le sue garanzie di correttezza.

## 6. L'Event Sourcing è uno strumento per un aggregate, non uno stile di casa

`Transaction` usa l'event sourcing perché la sua storia completa *è*
l'audit trail richiesto dal dominio. Gli Expected Payment no, perché sono
dati di riferimento senza un requisito di audit comparabile in v1.

**Perché:** applicare l'event sourcing in modo uniforme "perché è il
pattern di questo codebase" significherebbe applicare il pattern oltre il
problema che risolve. La strategia di persistenza di ogni aggregate è una
decisione presa sui suoi meriti.

**Trade-off accettato:** il codebase ha due stili di persistenza fianco a
fianco (aggregate event-sourced vs. modello Eloquent semplice). Questa
disomogeneità è intenzionale e non va "ripulita" in uniformità in futuro
senza riesaminare se il requisito di audit è cambiato.
