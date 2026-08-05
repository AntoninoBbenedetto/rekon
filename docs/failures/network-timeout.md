# Failure: Network timeout

Status in v1: **Mitigated**

## Scenario

A caller submits a CSV import (or a review resolution) and the connection
times out before the response arrives — the request may or may not have
been fully processed server-side by the time the caller's client gives up.
The caller cannot tell which happened from the timeout alone.

## Why this matters

The natural client response to a timeout is "retry." If retrying a
half-completed or fully-completed operation is not safe, a network timeout
turns into a duplicate-processing bug — the same underlying problem as
[duplicated-statement.md](duplicated-statement.md), triggered by a different
cause (network conditions instead of a deliberate resubmission).

## Mitigation

Because every write in this system is idempotent by design — CSV row import
keyed by `IdempotencyKey` ([duplicated-statement.md](duplicated-statement.md)),
and aggregate commands guarded by expected-state assertions
([PROJECT_CONTEXT.md](../../PROJECT_CONTEXT.md) §4) — a timed-out request is
always safe to retry blindly, without the caller needing to know whether the
original request landed. "Retry on timeout" requires no special-case logic
anywhere in this system; it falls out of idempotency being the default, not
an exception.

Per-row processing (core slice spec §6, §8) means a timeout partway through
a multi-row CSV file only requires resubmitting the same file — already-
processed rows are no-ops, unprocessed rows are processed normally.

## What is NOT covered in v1

- Client-side retry policy (backoff, max attempts) is a caller
  responsibility, not something the server enforces or documents here.
- No request-level idempotency key/response caching (e.g., replaying the
  exact original HTTP response for a retried request) — retries are safe
  because they're no-ops at the domain level, not because the API caches
  responses.

## Verification

No dedicated test beyond what idempotency and partial-import tests already
cover (core slice spec §10) — this failure mode is a consequence of those
guarantees, not a separate mechanism to test in isolation.
