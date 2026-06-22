# 6. COUNT and REQ share a single per-client subscription cap

## Status

Accepted

## Context

The relay enforces a per-client cap on concurrent subscriptions (`max_subscriptions`). A REQ that would take a client over the cap is rejected. The question is what to do with COUNT.

NIP-45 COUNT and NIP-01 REQ carry the same payload — a subscription id and a filter set — and the relay admits both through the same path: rate-limit check, then the policy's subscription check (which enforces the cap), then scope filtering. The only difference downstream is that REQ runs the scoped filters as `findByFilters` and streams matching events, whereas COUNT runs them as `countByFilters` and returns a single number.

That difference is cosmetic from the relay's point of view. Both messages make the host execute an arbitrary client-supplied filter against the event store. The filter is the expensive part — a broad filter with no kind constraint forces a full scan whether the store is asked to return the events or merely count them. COUNT is not a cheap operation that deserves a separate, looser budget; it is the same query with the result discarded.

A reviewer who reads "COUNT is rejected when the client is already at the subscription cap" can easily mistake it for a bug: a COUNT does not open a lasting subscription, so coupling it to the *subscription* cap looks like the wrong limit applied to the wrong message. This record exists so that coupling is not "fixed" into a separate, more permissive limit.

## Decision

COUNT and REQ share the single per-client subscription cap as one combined load-shedding signal. Both are admitted through the same check (`SubscriptionAdmission::admit` calling the policy's subscription check), so a client already holding `max_subscriptions` open subscriptions has a COUNT rejected with the same `too many subscriptions` reason a REQ would receive, framed as `CLOSED` (`blocked: too many subscriptions`).

The cap is not modelling "number of long-lived subscription objects" — it is modelling "how many store-querying requests this client may have the relay service". A client at the cap has already been granted as much store-querying work as the relay is willing to do for it; letting it additionally fire COUNTs would hand it more of the exact resource the cap exists to ration, through a second door.

## Consequences

- A client at its subscription cap cannot use COUNT to make the relay run additional unbounded filters against the store. The cap shields the event store from both message types with one number, and there is no second limit to keep consistent with it.
- COUNT does not register a lasting subscription, yet it is gated by the subscription cap. This is deliberate: the cap rations store-querying work, not subscription objects, and COUNT consumes that work. It is not a misapplied limit.
- A host that wants COUNT and REQ rationed independently would need a distinct policy, accepting that the store can then be made to run more total work than the single cap permits. Do not split COUNT onto its own looser budget under the impression the shared cap is a bug — the sharing is the protection.
