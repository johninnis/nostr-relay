# 29. A challenge accompanies every auth-related answer

## Status

Accepted. Supersedes ADR-0011.

## Context

ADR-0011 let a policy *admit* a write and still invite the connection to authenticate, and put challenge-frame building in one collaborator, `AuthChallengeIssuer`. Two gaps remained.

The read path offered a challenge only on a *scope-exceeding but admitted* request. A request refused outright with `auth-required:` — a guest asking for something authentication could unlock, or a protected event from an unauthenticated connection (ADR-0023) — was answered with the refusal alone. NIP-42's flow expects the client to hold a challenge so it can act on an `auth-required` answer; a refusal that arrives with no challenge leaves a client that was never offered one unable to retry as anyone but a guest.

The second gap was structural. The use cases paired outcomes with challenges themselves: each knew that an auth-required refusal should be preceded by `offerForRefusal()`, that an admitted write with a challenge offer needed `issue()` unshifted, that a scope-exceeding read needed `offerForScope()`. The pairing rule lived at every call site, and every call site carried the issuer as an extra collaborator to do it.

## Decision

A challenge is part of the relay's answer, attached by the unit that decided the answer, never paired by a caller.

- **An accepted write** whose policy offers a challenge is answered with the challenge just before its `OK true`. `PublishingGate` records the policy's offer on the returned `PublishingAnswer` (`offersChallenge()`), and `EventAcceptance` issues the challenge only once `AcceptedEventPipeline` has acknowledged the event as accepted, so an event the store refuses as a duplicate is answered `OK false` `duplicate:` with no challenge. A refusal carries a challenge when it is `auth-required:` and the client holds none yet; `PublishingGate` decided that answer, so it attaches it.
- **A refused read** (`REQ` or `COUNT` answered `auth-required:`) is preceded by a challenge when the client holds none, issued by `SubscriptionAnswers` as it settles the refusal; the client can authenticate and ask again.
- **A scope-exceeding read** draws the lazy challenge of ADR-0004 through the same `SubscriptionAnswers`: a REQ's challenge is written just before its `EOSE`, which carries NIP-67's `"auth"` hint, and a COUNT's before its count.
- **The NIP-42 handshake itself** issues a challenge when an `AUTH` arrives naming none (`Nip42Handshake`).
- `AuthChallengeIssuer` remains the one home of challenge-frame building, and still **returns** messages: the challenge travels the reply list like every other wire frame (ADR-0003), never a side channel. What changes is who calls it: only the answer-deciding units, never the reply-framing use cases.

An offer that arrives when the client already holds a challenge does not re-issue on the refusal paths (the client can act on the one it has); a scope-exceeding read still re-issues, because a client may have missed the first.

## Consequences

- Any `auth-required:` answer from this relay is actionable: the client either already holds a challenge or receives one with the refusal. Do not answer `auth-required:` bare — a client that was never challenged cannot respond to it.
- A policy can still admit a write and challenge the connection, and the default policy never offers, so the generic relay's behaviour is unchanged.
- Use cases frame wire replies; they no longer know the challenge-pairing rules. A new auth-related answer attaches its challenge where it is decided, or it is a bug the pairing tests will catch.
- A policy's challenge on the write path is issued after rate limiting, validation, policy and storage, so a client is only ever challenged over an event that was accepted, or refused for want of authentication.
- Shared decision: nostr-adrs ADR-0027.
