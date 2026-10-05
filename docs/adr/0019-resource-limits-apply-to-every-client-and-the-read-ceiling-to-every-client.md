# 19. Resource limits apply to every untrusted client, and the read ceiling applies to every client including a tenant

## Status

Superseded by ADR-0027

Supersedes ADR-0009, which decoupled resource limits from access openness but placed `max_query_limit` among the caps an authenticated tenant escapes. It never did escape it, and once `filterForClient()` became the single place a read is bounded (ADR-0017) the difference between that cap and the other two became a decision in its own right. This record restates the whole of ADR-0009 and settles it.

## Context

The relay's policy answers two different kinds of question, and it is tempting to treat them as one:

- **Access** — *may* this client read or write this? An "open" relay (one configured with no tenants) lets any anonymous client read everything and publish any kind; a "closed" relay restricts guests to a readable subset and challenges them to authenticate as a tenant for more.
- **Resource consumption** — *how much* may this client make the host do? Rate limiting (per-IP token buckets for events and subscriptions) and the per-client caps (`max_subscriptions`, `max_filters`, `max_query_limit`) exist to bound the load a single client can impose on the event store and the process.

These are independent axes. Access is about trust; resource limits are a safety mechanism that protects the host from any client, trusted or not. An earlier shape conflated them: an open relay short-circuited *both* the access checks **and** the resource limits, so `isRateLimitExempt()` and the subscription-cap check both returned early when no tenants were configured. The result was that the most common public deployment — an open relay — applied no per-message rate limit and no subscription, filter, or query caps to anonymous clients. A single client could open unbounded subscriptions and fire arbitrarily broad `REQ`/`COUNT` queries, each forcing a full scan of the store. The frame-size limit and the idle timeout still applied, so it was not wide open, but the store-load protections were absent exactly where they matter most.

The conflation reads plausibly — "an open relay trusts everyone, so don't restrict them" — which is why this record exists.

A second conflation sat inside the first. Having established that an authenticated tenant is exempt from resource limits, it reads as though the exemption covers all three caps. It does not, and it should not. `max_subscriptions` and `max_filters` bound how much concurrent work one client sets in motion, and a trusted operator can be left to judge that for itself. `max_query_limit` bounds a single reply, and the cost of getting that one wrong is not the tenant's alone to bear: a filter matching a million stored events would have the relay read, scope and serialise a million events into one subscription, holding the process while it does. Nobody writes a filter meaning that. Exempting a tenant from it would buy a trusted operator nothing it cannot already have by paging, and would hand the whole process to a single mistyped filter.

## Decision

Resource limits are decoupled from access openness. Whether the relay is open or closed decides **access** only; it never waives a **resource** limit.

- **Rate limiting and the subscription and filter caps apply to every client that is not an authenticated tenant**, including every client on an open relay. `isRateLimitExempt()` and the subscription-cap bypass are gated on `isTenant()` alone, not on `isOpenRelay()`.
- **An authenticated tenant is exempt from those**, because a tenant is a trusted operator identity the host has explicitly authorised — not an anonymous client.
- **`max_query_limit` is not among the exemptions, and binds a tenant too.** `filterForClient()` clamps every filter before it asks who is asking, so the ceiling is applied on the way in and the tenant check decides only scoping. A tenant that wants more than the ceiling pages through it with `until`, which costs the relay one bounded read at a time.
- **The configured ceiling is validated where it is taken, not where it is used.** `SubscriptionLimits` refuses a `max_query_limit` outside the range a filter can carry (`1` to `Filter::MAX_LIMIT`), throwing at construction. A host that misconfigures it learns at boot, from a message naming its number and the range.
- The **access** decisions remain governed by openness: an open relay still lets anonymous clients publish any kind (`allowEventSubmission`), serves unscoped reads (`filterForClient`, `canClientReceiveEvent`), and accepts any authenticating key (`allowsAuthentication`). Only the resource gates changed.

## Consequences

- An open, publicly-exposed relay rate-limits anonymous clients and caps their concurrent subscriptions and query breadth, bounding the store load any one client can impose. This is the relay's load-shedding guarantee, and it no longer evaporates when tenancy is left unconfigured.
- On an open relay `isTenant()` is always false (there are no tenant keys to authenticate as), so every client is uniformly limited — which is the intended posture for an anonymous public relay.
- A tenant relay still exempts its authenticated operators from rate limiting and the concurrency caps; guests on it are limited exactly as before.
- A tenant's REQ comes back bounded by `max_query_limit`, the same as a guest's. Authentication lifts what a client may *see*, never how much of it arrives in one reply.
- Do not move the clamp in `filterForClient()` below the tenant check to "finish" the tenant exemption. The exemption is deliberately partial, and an unbounded read is a fault whoever asks for it.
- Do not re-add `isOpenRelay()` to `isRateLimitExempt()` or the subscription-cap check to "stop bothering trusted users" — an open relay has no trusted users, only anonymous ones, and exempting them removes the only protection the store has from a single abusive client. Trust is proven by authenticating as a tenant, not by the relay's openness.
- Before the ceiling was validated at its source, a host configuring one above what a filter accepts got a relay that booted cleanly and then failed every single `REQ`, because the clamp built a filter the constructor refused. The failure surfaced as a generic error on every subscription, nowhere near the setting that caused it. Do not relax the constructor check back into a silent clamp: a ceiling the relay cannot apply is a configuration the operator needs told about.
