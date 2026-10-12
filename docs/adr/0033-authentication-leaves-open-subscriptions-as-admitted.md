# 33. Authentication leaves open subscriptions as admitted, and a client sends again what it wants widened

## Status

Accepted. Supersedes ADR-0016.

## Context

A guest `REQ` whose filters exceed guest scope is narrowed and served, and its `EOSE` carries NIP-67's `"auth"` hint (ADR-0004). When the client later authenticates, the stored events it can now see were never sent.

ADR-0016, and ADR-0007 before it, answered that by re-scoping every open subscription the moment its connection authenticated: the relay kept the filters each client had asked for beside the ones it granted, re-admitted every subscription under the wider scope, streamed the newly visible stored events on the same id and sent a second `EOSE`. That breaks what `EOSE` means. NIP-01 says the relay sends `EOSE` "to indicate the end of stored events and the beginning of events newly received in real-time". Events streamed after it are, to every client that follows NIP-01, new real-time events: a client that is not built on these libraries shows a replay of old notes as live ones, and a second `EOSE` on one subscription has no meaning at all.

NIP-42 and NIP-67 give the flow instead. The relay says more may exist after `AUTH`, by the hint or by `CLOSED` with `auth-required:`, and the client, once authenticated, sends its `REQ` again. NIP-01 makes a `REQ` on an open subscription id replace that subscription. Re-scoping a subscription in place for its live events only would leave its stored and live events under two different scopes.

## Decision

- A successful `AUTH` changes no open subscription. Each keeps the filters it was admitted under, for stored and live events alike. The relay replays nothing and sends no second `EOSE`: `ProcessAuthUseCase` answers the `AUTH` with its `OK` and nothing else.
- A client widens a subscription by sending its `REQ` again on the same id. The registry replaces the open subscription, and the new one is answered as any new subscription is, with its own stored events and its own `EOSE`.
- A `REQ` on an id the client already holds counts against the subscription cap once, as the subscription it replaces: `SubscriptionAdmission::admitSubscription` hands the policy the count of the client's other subscriptions. A `COUNT` replaces nothing and is counted against every subscription the client holds (ADR-0006).
- A replacing `REQ` is rate-limited like any other.
- A refused `REQ` withdraws any subscription its id held (`SubscriptionAnswers::settleRefusal`), because the `REQ` replaces it, so the `CLOSED` it is answered with is true. A `COUNT` registers and replaces nothing, so a refused `COUNT` withdraws nothing (`SubscriptionAnswers::refuseCount`): a `REQ` open on the same id stays open.
- The registry keeps only the filters a subscription was granted, with the reader's acting keys it was granted for (ADR-0036). `ScopedFilters` carries the granted filters and whether the policy narrowed the read; the filters the client asked for are not kept, because nothing reads them again.

## Consequences

- `EOSE` keeps NIP-01's meaning on every subscription: one per subscription, and nothing stored after it.
- A client that never sends its `REQ` again keeps guest scope on that subscription, as a client that never authenticates does.
- A host sizes its rate limit so that a client can send each open subscription again once after authenticating.
- `SubscriptionReevaluator`, `SubscriptionActivator::reactivate()`, `SubscriptionAdmission::readmit()`, `ScopedFilters::getRequestedFilters()` and `SubscriptionLookupInterface::getOriginalFilters()` are gone. `ScopedFilters::scoped()` takes the granted filters and the beyond-scope flag, and `SubscriptionRegistryInterface::addSubscription()` takes the client, the subscription and the reader's acting keys it was admitted for (ADR-0036).
- Do not stream stored events after an `EOSE`, and do not re-scope an open subscription when its connection authenticates.
- Shared decision: nostr-adrs ADR-0029.
