# 4. An AUTH challenge is issued only when a request exceeds guest scope, never on connect

## Status

Accepted

## Context

NIP-42 lets a relay challenge a client to authenticate. The obvious implementation challenges on connect, gating the whole connection until the client proves its identity. That reads like the safe default, but it gates clients that do not implement NIP-42 — they stall on a challenge they did not expect — even when everything they ask for is within guest scope.

The relay's access model is guest-by-default: an unauthenticated client is a guest, and what a guest may read and write is decided by the policy (`RelayPolicyInterface`; the built-in `RelayPolicy` / `GuestFilterRules`, or a host implementation). This ADR records *when* the relay challenges, not *what* the policy considers in-scope — that is the policy's concern, and the motivation for a particular scope belongs to the consumer application that configures it.

## Decision

The relay never emits an `AUTH` challenge on connect. It emits one **lazily**, only when a request exceeds the guest scope the policy defines:

- a REQ/COUNT whose filters the policy, or the relay's gift-wrap confinement (ADR-0035), narrows (`ScopedFilters::isBeyondScope()`, settled by `SubscriptionAnswers`), or
- an EVENT the guest is not permitted to publish (`PublishingGate`, ADR-0029).

A REQ beyond scope is narrowed to the guest-readable subset and served. Its stored events end with an `AUTH` challenge and then an `EOSE` carrying NIP-67's `"auth"` hint (`SubscriptionAnswers::endOfStoredEvents`), the order NIP-67 requires: "Relays MUST ensure an `AUTH` message (containing a challenge) is sent _before_ the `EOSE` containing an `"auth"` hint." No `NOTICE` is sent. A REQ whose narrowed filters can match nothing (`ScopedFilters::isWhollyBeyondScope()`) is not served empty: `SubscriptionAdmission` refuses it `CLOSED` with `auth-required:`, preceded by a challenge when the client holds none, as NIP-42 shows. A COUNT beyond scope is answered for the guest-readable subset, with a challenge before its count.

The challenge is an **offer**, not a gate. Answering it alone is not enough: the client must authenticate as an identity the policy authorises (`allowsAuthentication`), and it then gains that identity's wider scope — a key the policy does not authorise is rejected and stays at guest scope. The connection is never blocked for not authenticating. Authenticating changes no open subscription: a client that wants a narrowed subscription widened sends its REQ again (ADR-0033).

## Consequences

- Clients that do not implement NIP-42 keep working at guest scope and never stall on an unexpected connect-time challenge, and clients that do not implement NIP-67 ignore the hint.
- What counts as "beyond guest scope" — and the application-domain reasons for choosing it — live with the policy and the consumer that configures it, not in this library decision. The relay only ties the challenge to a scope-exceeding request.
- Do not "harden" this by challenging on connect — it would re-introduce the stall this decision exists to avoid. A challenge is a scope-widening offer triggered by a scope-exceeding request, not a connection gate.
- Shared decision: nostr-adrs ADR-0027.
