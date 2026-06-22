# 1. Anticipated outcomes are returned; faults are thrown

## Status

Accepted

## Context

Failure splits into two kinds, and they must be modelled differently. PHP has no checked exceptions, so a `throw` is invisible to PHPStan: a caller can silently forget to handle it. A nullable or `*Failure` return, by contrast, makes "you didn't handle the failure" a level-9 analyser error. This is the most load-bearing analyser decision in the relay.

## Decision

- **Anticipated domain outcomes** — a well-formed request whose answer is "no" (unauthorised, too large, not found, malformed wire input, policy rejection, rate limited) — are **returned** as a typed value: `?T` for a single failure mode, or a sealed family of `*Failure` value objects (or a backed enum when the failure carries no data) for several. They are never thrown.
- **Faults** — broken invariants, programmer errors and infrastructure failures — are **thrown**. `InvalidArgumentException` (native) covers argument validation of trusted internal values.
- **Some validation commands return `void`** and so throw a typed exception on rejection — the policy, rate-limit and AUTH checks. Their contract is "succeed or throw". The server boundary catches these and maps them to the appropriate `CLOSED`, `NOTICE` or `OK` response.

## Consequences

- PHPStan level 9 forces every caller to handle the failure branch of a returned outcome.
- Exceptions in the relay signify genuine faults (or the "succeed or throw" validation commands) only; a `catch` is never load-bearing control flow for an anticipated "no" carried in a return type.
- The single place that translates a thrown validation fault into a wire response is the server boundary; use cases below it do not catch their own validation throws.
- Do not "simplify" a `?T` / `*Failure` return into a throw — it removes the analyser guarantee that callers handle it.
