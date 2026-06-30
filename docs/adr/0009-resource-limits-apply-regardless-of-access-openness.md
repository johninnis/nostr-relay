# 9. Resource limits apply to every untrusted client, regardless of how open the access policy is

## Status

Accepted

## Context

The relay's policy answers two different kinds of question, and it is tempting to treat them as one:

- **Access** — *may* this client read or write this? An "open" relay (one configured with no tenants) lets any anonymous client read everything and publish any kind; a "closed" relay restricts guests to a readable subset and challenges them to authenticate as a tenant for more.
- **Resource consumption** — *how much* may this client make the host do? Rate limiting (per-IP token buckets for events and subscriptions) and the per-client caps (`max_subscriptions`, `max_filters`, `max_query_limit`) exist to bound the load a single client can impose on the event store and the process.

These are independent axes. Access is about trust; resource limits are a safety mechanism that protects the host from any client, trusted or not. An earlier shape conflated them: an open relay short-circuited *both* the access checks **and** the resource limits, so `isRateLimitExempt()` and the subscription-cap check both returned early when no tenants were configured. The result was that the most common public deployment — an open relay — applied no per-message rate limit and no subscription, filter, or query caps to anonymous clients. A single client could open unbounded subscriptions and fire arbitrarily broad `REQ`/`COUNT` queries, each forcing a full scan of the store. The frame-size limit and the idle timeout still applied, so it was not wide open, but the store-load protections were absent exactly where they matter most.

The conflation reads plausibly — "an open relay trusts everyone, so don't restrict them" — which is why this record exists.

## Decision

Resource limits are decoupled from access openness. Whether the relay is open or closed decides **access** only; it never waives a **resource** limit.

- **Rate limiting and the subscription/filter/query caps apply to every client that is not an authenticated tenant**, including every client on an open relay. `isRateLimitExempt()` and the subscription-cap bypass are gated on `isTenant()` alone, not on `isOpenRelay()`.
- **An authenticated tenant is exempt** from both, because a tenant is a trusted operator identity the host has explicitly authorised — not an anonymous client.
- The **access** decisions remain governed by openness: an open relay still lets anonymous clients publish any kind (`allowEventSubmission`), serves unscoped reads (`filterForClient`, `canClientReceiveEvent`), and accepts any authenticating key (`allowsAuthentication`). Only the resource gates changed.

## Consequences

- An open, publicly-exposed relay rate-limits anonymous clients and caps their concurrent subscriptions and query breadth, bounding the store load any one client can impose. This is the relay's load-shedding guarantee, and it no longer evaporates when tenancy is left unconfigured.
- On an open relay `isTenant()` is always false (there are no tenant keys to authenticate as), so every client is uniformly limited — which is the intended posture for an anonymous public relay.
- A tenant relay still exempts its authenticated operators; guests on it are limited exactly as before.
- Do not re-add `isOpenRelay()` to `isRateLimitExempt()` or the subscription-cap check to "stop bothering trusted users" — an open relay has no trusted users, only anonymous ones, and exempting them removes the only protection the store has from a single abusive client. Trust is proven by authenticating as a tenant, not by the relay's openness.
