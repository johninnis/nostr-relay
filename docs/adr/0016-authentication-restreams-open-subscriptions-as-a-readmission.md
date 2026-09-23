# 16. Authentication re-streams a client's open subscriptions, as a readmission

## Status

Accepted

Supersedes ADR-0007, which decided that authentication re-streams a client's already-open subscriptions and described the mechanism as running the subscription-creation path again. The re-streaming decision is carried forward below unchanged; what is revised is that re-evaluation is now a readmission with its own rules, because running it as a creation charged the client twice and left a refused subscription open.

## Context

*Carried forward from ADR-0007.* The relay challenges lazily: an unauthenticated client is a guest, and a REQ whose filters exceed guest scope is not rejected but narrowed to the guest-readable subset and served, alongside an offer to authenticate. A subscription opened as a guest therefore remains open, but scoped down, and the client is seeing a restricted view of the events its filter asked for.

When that client later authenticates and gains a wider scope, its open subscriptions are now under-serving it: they were admitted and their stored-event backlog was streamed under the narrow guest scope, so the events that became visible only on authentication were never sent. Live matching picks up new events under the wider scope, but the already-stored events the original filter asked for remain undelivered.

Making the client re-issue every REQ pushes relay bookkeeping onto the client, requires it to remember exactly which subscriptions it opened and with which filters, and races against events arriving in between. For the relay to redeliver correctly it must keep the filters the *client* asked for, not the filters it actually ran, because the scoped set is lossy and cannot be re-widened.

*What this record adds.* Running re-evaluation through the same path as a new subscription looked like reuse and was two mistakes. A client authenticating is not asking for anything more: it already holds those subscriptions and already paid for them, yet each one spent a rate-limit token and was counted against a cap it was already inside, so a client with several open subscriptions could rate-limit itself simply by proving who it was. And where a new subscription that is refused has nothing to clean up, a re-evaluated one is already registered: replying `CLOSED` and leaving it in place meant the relay went on matching live events into a subscription the client had been told was gone.

## Decision

On successful authentication the relay re-evaluates the client's already-open subscriptions against the new scope, without the client re-subscribing. For each open subscription it takes the original client-supplied filters, retained alongside the scoped ones for this purpose, re-scopes them under the now-authenticated identity, replaces the stored subscription, and re-streams the stored events that are now visible, ending with EOSE. A subscription whose original filters were not retained is skipped rather than guessed at.

Re-evaluation goes through `SubscriptionAdmission::readmit()` rather than `admit()`. Readmission asks the policy whether the client may hold this subscription and re-scopes the filters, and does neither of the things that belong to a new request: it spends no rate-limit token, and it discounts the subscription under re-evaluation from the count it passes to the policy.

If readmission is refused, `SubscriptionActivator::reactivate()` removes the subscription before replying `CLOSED`, so the reply is true.

The filters the client asked for travel with the filters it was granted, on `ScopedFilters`. They are two halves of one answer, and carrying them separately was what made registration take four arguments.

## Consequences

- A subscription opened as a guest widens automatically the moment the client authenticates, with no client-side re-subscription.
- The subscription registry stores each subscription's original filters as well as the scoped ones. This is a deliberate second copy, not redundancy: the scoped set cannot reconstruct it.
- Re-streaming replays the backlog under the new scope, so a client may receive stored events it already had under the narrower scope. Subscription ids let it reconcile, and the alternative, tracking exactly what was sent per subscription, is far more state for a rare transition.
- Authenticating no longer costs a client its own open subscriptions, whatever its rate-limit budget or how many it holds.
- A `CLOSED` on the re-evaluation path means the subscription is gone, so a client that acts on it is not left receiving events for a subscription it has forgotten.
- A policy can still close a subscription on authentication by refusing it during readmission. That is the one way a client's subscription count falls without the client asking, and it is deliberate.
- Do not drop the retained original filters to save memory by re-streaming the scoped ones: they cannot widen, and the client would never receive the events authentication was supposed to unlock.
- Do not route re-evaluation back through `admit()` to remove the second method. The two differ in what the client is asking for, and that difference is what the charging and the cap are about.
