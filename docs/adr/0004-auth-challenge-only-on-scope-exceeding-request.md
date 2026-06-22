# 4. An AUTH challenge is issued only when a request exceeds guest scope, never on connect

## Status

Accepted

## Context

NIP-42 lets a relay challenge a client to authenticate. The obvious implementation challenges on connect, gating the whole connection until the client proves its identity. That reads like the safe default, but it gates clients that do not implement NIP-42 — they stall on a challenge they did not expect — even when everything they ask for is within guest scope.

The relay's access model is guest-by-default: an unauthenticated client is a guest, and what a guest may read and write is decided by the policy (`RelayPolicyInterface`; the built-in `RelayPolicy` / `GuestFilterRules`, or a host implementation). This ADR records *when* the relay challenges, not *what* the policy considers in-scope — that is the policy's concern, and the motivation for a particular scope belongs to the consumer application that configures it.

## Decision

The relay never emits an `AUTH` challenge on connect. It emits one **lazily**, only when a request exceeds the guest scope the policy defines:

- a REQ/COUNT whose filters the policy returns as `ScopedFilters::isBeyondScope()` (`SubscriptionAdmission`), or
- an EVENT the guest is not permitted to publish (`ProcessEventSubmissionUseCase`).

The challenge is an **offer**, not a gate. An unauthenticated client still receives the guest-scoped results (alongside a `NOTICE`). Answering the challenge alone is not enough: the client must authenticate as an identity the policy authorises (`allowsAuthentication`), and it then gains that identity's wider scope — a key the policy does not authorise is rejected and stays at guest scope. The connection is never blocked for not authenticating. On successful AUTH, the client's already-open subscriptions are re-evaluated against the new scope and re-admitted with their original filters.

## Consequences

- Clients that do not implement NIP-42 keep working at guest scope and never stall on an unexpected connect-time challenge.
- What counts as "beyond guest scope" — and the application-domain reasons for choosing it — live with the policy and the consumer that configures it, not in this library decision. The relay only ties the challenge to a scope-exceeding request.
- Do not "harden" this by challenging on connect — it would re-introduce the stall this decision exists to avoid. A challenge is a scope-widening offer triggered by a scope-exceeding request, not a connection gate.
