# 3. Anticipated outcomes are returned; faults are thrown

## Status

Accepted

## Context

Failure splits into two kinds, and they must be modelled differently. PHP has no checked exceptions, so a `throw` is invisible to PHPStan: a caller can silently forget to handle it. A nullable or `*Failure` return, by contrast, makes "you didn't handle the failure" a level-9 analyser error. This is the most load-bearing analyser decision in the relay.

A second question follows from it. Admission produces a rejection outcome (unauthorised, too large, blocked, rate limited). Where is that rejection turned into a wire response? The tempting answer is a single translator at the server boundary, above the routing `match`. That is wrong here, because the Nostr wire frame for a rejection depends on which client message was being processed:

- an `EVENT` is answered with `OK` (`["OK", id, false, reason]`),
- a `REQ`/`COUNT` is answered with `CLOSED` (`["CLOSED", subId, reason]`),
- a malformed or unroutable message is answered with `NOTICE`.

Only the use case handling a given message knows which frame applies and what identifier to echo back. A single boundary handler above the routing `match` cannot produce the right frame without re-deriving the message type it just dispatched on — reintroducing the dispatch it already performed. So each use case receives the admission outcome as a return value and frames it itself.

## Decision

- **Anticipated domain outcomes** — a well-formed request whose answer is "no" (unauthorised, too large, not found, malformed wire input, policy rejection, rate limited) — are **returned** as a typed value: `?T` for a single failure mode, or a sealed family of `*Failure` value objects (or a backed enum when the failure carries no data) for several. They are never thrown.
- **Faults** — broken invariants, programmer errors and infrastructure failures — are **thrown**, fail-fast, with context. `InvalidArgumentException` (native) covers argument validation of trusted internal values.
- **Admission returns a typed outcome; it never throws for a "no".** `EventAdmission::admit()` returns `PolicyRejection|EventAdmitted`, `SubscriptionAdmission::admit()` returns `PolicyRejection|ScopedFilters`, the policy's `allowEventSubmission`/`allowSubscription` return `?PolicyRejection`, and the rate limiter answers `tryConsume(): bool`. `PolicyRejection` carries the `RejectionReason` and message the wire response needs. The one rejection still *thrown* is `InvalidEventException`, from the core event validator — the single blessed "validate or throw", because signature/NIP validity is a precondition, not a policy answer.
- **The use case that issues an admission command is the boundary that frames its outcome**, because only it knows the message-type-specific wire frame. Each use case matches the returned `PolicyRejection` and maps it to its own `OK` / `CLOSED` response — and, for an auth-required rejection, an accompanying `AUTH` — while catching the lone `InvalidEventException` and framing it as an invalid-event reply.
- **`MessageRouter` and `ClientConnectionHandler` are last-resort backstops, not the primary translator.** They catch any *unexpected* `Throwable` that escapes a use case and degrade it to a generic `NOTICE` (router) or a logged disconnect (connection handler), so one client's fault never takes down the shared event loop.

## Consequences

- PHPStan level 9 forces every caller to handle the failure branch of a returned outcome.
- Exceptions in the relay signify genuine faults only, plus the lone `InvalidEventException` "validate or throw". A `catch` is never load-bearing control flow for an anticipated "no": every policy rejection and rate-limit is a returned value the analyser forces each caller to handle. (This record originally admitted a `RelayException` "succeed or throw" family — `PolicyViolationException`, `RateLimitException`, `AuthRequiredException` — for admission; those were replaced by returned `PolicyRejection` outcomes and deleted, so admission no longer contradicts the top-level rule it sits under.)
- Wire-response translation lives with the use case, not at a single boundary; the same rejection reason (e.g. rate limited) legitimately appears framed as `OK` in one use case and `CLOSED` in another. This is per-frame correctness, not duplicated behaviour.
- The outer `Throwable` backstops must stay generic: they exist to contain unexpected faults, so adding message-type-specific mapping there would duplicate the use-case translation and is forbidden.
- Do not "simplify" a `?T` / `*Failure` return into a throw — it removes the analyser guarantee that callers handle it. Do not "centralise" the per-use-case `OK`/`CLOSED` mapping into the router — it cannot frame the response correctly without re-dispatching on the message type.
