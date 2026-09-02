# Code review: the AI-generated v1 slice

*[Versione italiana](CODE_REVIEW_it.md)*

Most of the v1 vertical slice — the `Transaction` aggregate, the application
services, the event store, the controllers, the test suite — was generated
by an AI assistant from the [core slice spec](superpowers/specs/2026-08-01-reconciliation-core-slice-design.md)
and [PROJECT_CONTEXT.md](../PROJECT_CONTEXT.md). That is disclosed here
deliberately: generated code is a draft, not a deliverable. The spec steers
generation; review decides what actually ships. This document is that
review — what it found, why each finding matters in this specific domain,
and what changed as a result.

## What the review accepted

The generated code got the hard parts right, and it's worth saying so
before listing what it got wrong:

- `Transaction::assertState()` is called before every event is recorded —
  illegal state transitions are structurally impossible, not just
  conventionally avoided ([Transaction.php](../app/Modules/Reconciliation/Domain/Transaction.php)).
- `TransactionRepository::save()` derives `expectedVersion` correctly, and
  `PostgresEventStore::append()` lets a unique-constraint violation
  propagate out of `DB::transaction()` untouched — a partial multi-event
  write rolls back completely, it does not commit half.
- `ImportStatementService` re-projects the existing transaction on a
  conflict instead of failing — idempotent behavior that is actually
  exercised by `EndToEndReconciliationTest`, not just asserted.
- `MatchPendingTransactionJob` checked current state before v1.1 already —
  a redelivered job was already safe to retry. It just didn't retry.

Two findings below were judged worth fixing before this slice is presented
as portfolio work.

## Finding 1: no `declare(strict_types=1)` anywhere in `app/`

**What was there.** All 52 files in `app/` ran in PHP's default weak-typing
mode. `Money::__construct(int $amountMinorUnits, ...)` and
`StoredEventRow::__construct(..., int $version, ...)` declare `int`
parameters, but weak mode coerces silently: `new Money('12345', $currency)`
is legal, and a `'123'` string arriving anywhere near a typed `int`
parameter is accepted rather than rejected.

**Why it matters here specifically, not generically.** This is a financial
ledger where amounts are integer minor units and "never sacrifice
correctness for performance" is the explicit non-functional priority #1
([PROJECT_CONTEXT.md](../PROJECT_CONTEXT.md), Non Functional Requirements).
The concrete risk was the boundary in
[`PostgresEventStore::loadStream()`](../app/Modules/SharedKernel/Infrastructure/EventStore/PostgresEventStore.php):
it reads `$row->version` straight from a PDO result row into
`StoredEventRow`'s `int $version` parameter. PDO's return type for integer
columns depends on driver configuration (`PDO::ATTR_EMULATE_PREPARES`); a
string leaking through there would, in weak mode, be coerced silently and
compared as a string only if it ever escaped the constructor unconverted.
Weak typing was not *causing* a bug — it was removing the safety net that
would catch one if this environment's PDO configuration, or the DB driver,
ever changed underneath the code.

**What actually happened when the fix was applied.** `declare(strict_types=1);`
was added to all 52 files in `app/` (test, database, and config files were
intentionally left out of scope). The full suite was run before and after:
**95 tests passed before, 95 passed after — zero breakage.** Rather than
leave that as an assumption, the PDO boundary was inspected directly:

```
bigint -> integer
int    -> integer
emulate_prepares -> false
```

This environment already returns native PHP integers for Postgres integer
columns, because `ATTR_EMULATE_PREPARES` is `false`. So the specific
boundary risk was not live *in this configuration*. The honest conclusion
is not "a bug was fixed" — it's that **an invariant the code was silently
relying on (native integer types from PDO) is now enforced by the type
system instead of by an unstated assumption about driver configuration.**
If that PDO setting, or the driver, ever changes, this now fails loudly
with a `TypeError` at the exact call site instead of coercing a string
into arithmetic somewhere downstream. That is the value strict typing adds
in a codebase like this one: not bugs caught today, but a narrower failure
surface for tomorrow.

## Finding 2: `MatchPendingTransactionJob` had no retry policy

**What was there.** The job implements `ShouldQueue` but declared no
`$tries`, no `$backoff`, no `failed()`. [`composer.json`](../composer.json)'s
`dev` script runs the queue worker with `--tries=1`. `docs/failures/retry-strategy.md`
was marked **Mitigated**, but the mitigation it actually describes is the
matching job's own idempotency guard against *at-least-once delivery* —
it says nothing about what happens when the job fails outright.

**The distinction that matters.** These are two different properties, and
the generated code only had one of them:

- **Safe to retry** — a redelivered job that finds the transaction is no
  longer `Pending` no-ops instead of corrupting state. This existed already.
- **Retried at all** — something has to actually schedule the retry. This
  did not exist. With `--tries=1` and no sweep of any kind (`app/Console/Commands`
  is empty; nothing in the codebase greps for `replay`/`rebuild`/`reproject`),
  a transient failure — a dropped DB connection, a Redis blip, a deploy
  mid-flight — left the transaction **`Pending` forever, silently.** No
  error surfaces anywhere a human would see it.

**The fix.** `MatchPendingTransactionJob` now declares:

```php
public int $tries = 5;
public array $backoff = [5, 15, 60, 180];
```

The backoff grows rather than staying fixed for a reason specific to this
system, not a generic best practice: per [ADR-003](adr/ADR-003-optimistic-concurrency.md),
the loser of a concurrency conflict on the same aggregate must give the
winner time to commit. Retrying immediately would just contend for the
same row and lose again — a fixed short delay would make retries
self-defeating exactly in the scenario they exist to handle.

A `failed()` method was added to close the visibility gap once retries are
exhausted:

```php
public function failed(?Throwable $exception): void
{
    Log::error('Matching permanently failed; transaction left Pending.', [
        'transaction_id' => $this->transactionId,
        'correlation_id' => $this->correlationId,
        'exception' => $exception?->getMessage(),
    ]);
}
```

Only identifiers are logged — no statement contents — per the Security
section of `PROJECT_CONTEXT.md`. Two tests were added in
[`MatchPendingTransactionJobTest.php`](../tests/Feature/Modules/Reconciliation/MatchPendingTransactionJobTest.php):
one asserts the retry/backoff configuration, one asserts `failed()` logs
the right identifiers with the right message. The `failed()` test was
mutation-checked by hand — changing the logged message string was
confirmed to break the assertion — so it is known to actually constrain
the behavior, not just execute it. Full suite after both changes: **97
passed** (95 baseline + 2 new).

**What this fix does not close.** A transaction that exhausts all 5
retries is now *visible* (in `failed_jobs` and the application log) but
still **not recovered** — nothing re-drives a stranded `Pending`
transaction automatically. `docs/failures/retry-strategy.md` now states
this explicitly instead of implying the mitigation is complete. Turning
visibility into recovery — a scheduled command that finds stranded
`Pending` transactions past some age and requeues them — is future work,
named here rather than left implicit.

## What this pass did not touch

Also identified during the broader quality pass, deliberately left open
here rather than folded into an unrelated diff:

- No CI (`.github/workflows` absent) — 97 tests exist and nothing runs
  them on push.
- No static analysis (PHPStan/Larastan) — plausible given the amount of
  `match(true)` and explicit casting in this codebase, which is exactly
  where a type-level pass earns its cost.
- Pint is a dependency but not wired into a `composer` script, and the
  codebase is not currently Pint-clean under its default preset — style
  enforcement is optional in practice, not enforced.
- No command to rebuild the read-model projection from the event stream,
  despite the read model being documented as disposable and reconstructible.

Two findings were fixed in this pass because they were the two with a
concrete, arguable failure scenario attached — not because the list above
matters less. It's recorded as future work, not swept under a claim that
this pass made the codebase complete.
