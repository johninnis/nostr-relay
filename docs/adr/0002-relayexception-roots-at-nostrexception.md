# 2. Relay faults root at `NostrException`; consumers root their own

## Status

Accepted

## Context

`NostrException` (abstract, extending `\Exception`) is defined in nostr-core and is the **shared** root for faults thrown by Nostr library code across the whole `nostr-*` ecosystem. nostr-relay is Nostr library code, so the question is where its faults belong.

The tempting-but-wrong move is at a different boundary: the **consumer application** embedding the relay. Because such an application depends on nostr-relay (and so on nostr-core), it looks natural to root the application's *own* faults under `NostrException` too, so the whole process shares one throwable root. That is the case this record rejects.

## Decision

Faults are rooted by **whose code raises them, not by the dependency graph** — and the line is drawn between *Nostr library code* and a *consumer application*, not "anything that depends on nostr-core".

- nostr-relay throws **package-specific faults**, so it defines its own abstract base, `RelayException`, extending `NostrException`. The final leaves extend `RelayException`: `AuthRequiredException`, `ConnectionException`, `PolicyViolationException` and `RateLimitException`.
- A **consumer application** embedding the relay roots its OWN faults at its OWN independent base, never under `NostrException`. Such an application throws an exception that extends `\Exception` directly and does NOT extend `NostrException`, even though it depends on the Nostr libraries.
- What decides the root is the authoring code — Nostr library vs consumer application — not what it imports.

## Consequences

- A `catch (NostrException)` catches faults from nostr-relay (and any other `nostr-*` library) across the process, and never an application-originated fault; the root identifies the origin.
- Base exceptions are abstract (`RelayException`); leaf exceptions are `final`.
- Do not root an embedding application's exceptions under `NostrException` or `RelayException` to "share one root" — the authoring code (library vs application) decides, not the dependency direction.
