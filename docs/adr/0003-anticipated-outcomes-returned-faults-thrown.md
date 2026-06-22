# 3. Anticipated outcomes are returned; faults are thrown

## Status

Accepted

## Context

Failure splits into two kinds, and they must be modelled differently. PHP has no checked exceptions, so a `throw` is invisible to PHPStan: a caller can silently forget to handle it. A nullable or `*Failure` return, by contrast, makes "you didn't handle the failure" a level-9 analyser error. This is the most load-bearing analyser decision in the relay.

A second question follows from it. Some validation commands return `void` and signal rejection by throwing a typed `RelayException` (the policy, rate-limit and AUTH checks — "succeed or throw"). Where is a thrown rejection turned into a wire response? The tempting answer is a single translator at the server boundary, above the routing `match`. That is wrong here, because the Nostr wire frame for a rejection depends on which client message was being processed:

- an `EVENT` is answered with `OK` (`["OK", id, false, reason]`),
- a `REQ`/`COUNT` is answered with `CLOSED` (`["CLOSED", subId, reason]`),
- a malformed or unroutable message is answered with `NOTICE`.

Only the use case handling a given message knows which frame applies and what identifier to echo back. A single boundary `catch` above the `match` cannot produce the right frame without re-deriving the message type it just dispatched on — reintroducing the dispatch it already performed.

## Decision

- **Anticipated domain outcomes** — a well-formed request whose answer is "no" (unauthorised, too large, not found, malformed wire input, policy rejection, rate limited) — are **returned** as a typed value: `?T` for a single failure mode, or a sealed family of `*Failure` value objects (or a backed enum when the failure carries no data) for several. They are never thrown.
- **Faults** — broken invariants, programmer errors and infrastructure failures — are **thrown**, fail-fast, with context. `InvalidArgumentException` (native) covers argument validation of trusted internal values.
- **Some validation commands return `void`** and throw a typed `RelayException` on rejection (the policy, rate-limit and AUTH checks). Their contract is "succeed or throw".
- **The use case that issues a "succeed or throw" command is the boundary that catches it**, because only it knows the message-type-specific wire frame. Each use case catches the relevant `PolicyViolationException`, `RateLimitException` and `AuthRequiredException` and maps it to its own `OK` / `CLOSED` / `AUTH` response.
- **`MessageRouter` and `ClientConnectionHandler` are last-resort backstops, not the primary translator.** They catch any *unexpected* `Throwable` that escapes a use case and degrade it to a generic `NOTICE` (router) or a logged disconnect (connection handler), so one client's fault never takes down the shared event loop.

## Consequences

- PHPStan level 9 forces every caller to handle the failure branch of a returned outcome.
- Exceptions in the relay signify genuine faults, or the "succeed or throw" validation commands, only. A `catch` is never load-bearing control flow for an anticipated "no" carried in a return type; a `catch` of a `RelayException` subtype *is* load-bearing — but only for those validation commands, and only inside the use case that owns the matching wire frame.
- Wire-response translation lives with the use case, not at a single boundary; the same rejection reason (e.g. rate limited) legitimately appears framed as `OK` in one use case and `CLOSED` in another. This is per-frame correctness, not duplicated behaviour.
- The outer `Throwable` backstops must stay generic: they exist to contain unexpected faults, so adding message-type-specific mapping there would duplicate the use-case translation and is forbidden.
- Do not "simplify" a `?T` / `*Failure` return into a throw — it removes the analyser guarantee that callers handle it. Do not "centralise" the per-use-case `OK`/`CLOSED` mapping into the router — it cannot frame the response correctly without re-dispatching on the message type.
