# ADR-007: Composition of the IdempotencyKey

Status: Accepted
Date: 2026-08-03

## Context

[ADR-006](ADR-006-deterministic-aggregate-id.md) established that a
`Transaction`'s identity is derived from its content:

```
TransactionId = UUIDv5(APP_NAMESPACE, IdempotencyKey)
```

The event store's `UNIQUE (aggregate_id, version)` constraint is then the sole
arbiter of the duplicate-import race: the loser of a race gets a violation on
`version = 1` and is treated as a no-op.

That mechanism is only as good as its input. **Which fields make up the
`IdempotencyKey` was never decided.** The gap was load-bearing rather than
cosmetic:

- [`failures/duplicated-statement.md`](../failures/duplicated-statement.md)
  deferred to the technical design addendum "for the exact fields hashed";
- the technical design addendum never specified them — it showed an
  `idempotency_key` field and a second, never-explained `raw_row_checksum` in
  the `TransactionImported` payload.

The cross-reference was circular, so the guarantee everything else in the
system rests on had no definition behind it. Two consequences follow from the choice, and
they pull in opposite directions:

- **Too much in the key** (e.g. a file identifier or upload timestamp): a
  manual re-upload of the same statement derives *different* keys, therefore
  different aggregate IDs, therefore duplicates — precisely the scenario
  `duplicated-statement.md` claims is mitigated.
- **Too little in the key** (e.g. omitting `statement_date`): two genuinely
  distinct payments that happen to look alike collapse into one aggregate.
  Under-counting real money is the worse of the two failure directions, and
  under deterministic IDs it is not recoverable downstream — the second payment
  is not *representable*.

## Decision

```
IdempotencyKey = sha256(
    reference,
    amount_minor_units,
    currency,
    statement_date,
    occurrence_index
)
```

`occurrence_index` is the zero-based position of the row among the rows in the
same statement that are identical on the other four fields: `0` for the first
occurrence, `1` for the second, and so on. Rows that are not duplicated within
the statement always have `occurrence_index = 0`.

The field values are normalized before hashing (trimmed, `currency` uppercased,
`statement_date` as ISO-8601 `YYYY-MM-DD`, `amount_minor_units` as a decimal
integer string) so that cosmetic formatting differences in the source file do
not change the key.

**The file's own identity — filename, upload id, request id, upload timestamp —
is deliberately not part of the key.**

### `raw_row_checksum` is a different thing, and stays

`raw_row_checksum` is the SHA-256 of the raw, un-normalized CSV line exactly as
received. It is **forensic evidence, not an identity**: it records what the
source file literally said, so that a later dispute about a reconciled
transaction can be settled against the original bytes even after the parser's
normalization rules change. It is carried in the `TransactionImported` payload
and is never used for deduplication, matching, or ID derivation.

## Consequences

**Positive:**
- **Re-upload stays idempotent.** The same statement submitted twice — under a
  different filename, after a timeout, or by a different caller — derives the
  same keys, the same aggregate IDs, and collapses on the event store's unique
  constraint exactly as ADR-006 describes.
- **Legitimately identical rows stay distinct.** Two real payments of the same
  amount, on the same date, under the same reference get
  `occurrence_index = 0` and `1`, hence two aggregates. Without this field the
  design would silently under-count money, and — because identity is
  deterministic — would offer no way to repair it afterwards.
- **The broken cross-reference is closed.** `duplicated-statement.md` and the
  technical design addendum now both point here, and here is a definition.

**Negative / accepted trade-offs:**
- **The parser is no longer stateless per row.** Computing `occurrence_index`
  requires knowing what came earlier in the same statement, so rows cannot be
  hashed in isolation as they stream past. Rows must be grouped by the other
  four fields within a statement before keys are derived.
- **Idempotency is per-statement, not global.** The `occurrence_index` of a row
  is defined relative to the statement it arrives in. A file containing a
  *reordered subset* of rows already imported — say, a re-export that drops
  some rows — can assign a different index to the same real-world payment, and
  that row will be imported again as a new aggregate. Re-submitting the
  **same** file is always safe; re-submitting a **partial or reshuffled**
  export of overlapping data is not. This is a known limitation, recorded here
  rather than discovered later.
- **Changing this definition later changes aggregate identity.** Per ADR-006
  the derived `TransactionId` is coupled to this composition; revising it would
  require a migration for already-imported data. Not a v1 concern (there is
  none yet), but it makes this ADR expensive to reverse.
- Two rows describing the same real payment but differing on any hashed field
  (e.g. a re-export that renders the reference differently) are still not
  detected as duplicates. The key is content-based, not semantic — unchanged
  from the limitation already recorded in `duplicated-statement.md`.

**Revisit if:** statements begin arriving as partial/overlapping exports rather
than whole files, which is the case `occurrence_index` does not cover — the fix
would be a semantic (fuzzy) duplicate check, a different design, not a
different hash.
