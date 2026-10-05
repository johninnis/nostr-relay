# 27. Resource limits apply to every untrusted client, and the read ceiling applies to every client including a tenant

## Status

Accepted. Supersedes ADR-0019.

## Context

ADR-0019 settled that resource limits are decoupled from access openness and that the read ceiling binds a tenant too. That decision stands unchanged; this record restates it because one sentence of ADR-0019 described a validation range that no longer exists. ADR-0019 said the configured read ceiling is refused "outside the range a filter can carry (`1` to `Filter::MAX_LIMIT`)". nostr-core 0.9 removed `Filter::MAX_LIMIT`, and ADR-0024 made the relay's own ceiling, not a filter constant, the only bound: there is no upper range to validate against, only the floor below which the relay can serve nothing.

The carried-forward decision, from ADR-0019 (which itself restated ADR-0009): the relay's policy answers two different kinds of question, and they must not be conflated:

- **Access** — *may* this client read or write this? An "open" relay (no tenants configured) lets any anonymous client read everything and publish any kind; a "closed" relay restricts guests and challenges them to authenticate as a tenant for more.
- **Resource consumption** — *how much* may this client make the host do? Rate limiting and the per-client caps (`max_subscriptions`, `max_filters`, the read ceiling) bound the load a single client can impose on the store and the process.

An earlier shape conflated them: an open relay short-circuited both the access checks and the resource limits, leaving the most common public deployment with no store-load protection at all. And within the exemption a tenant earns, it reads as though all three caps are escaped; the read ceiling is not among the exemptions, because one mistyped filter must never hand the whole process to a single reply, whoever asks.

## Decision

Resource limits are decoupled from access openness. Whether the relay is open or closed decides **access** only; it never waives a **resource** limit.

- **Rate limiting and the subscription and filter caps apply to every client that is not an authenticated tenant**, including every client on an open relay. `isRateLimitExempt()` and the subscription-cap bypass are gated on `isTenant()` alone, not on `isOpenRelay()`.
- **An authenticated tenant is exempt from those**, because a tenant is a trusted operator identity the host has explicitly authorised.
- **The read ceiling is not among the exemptions, and binds a tenant too.** Every filter is bounded before the tenant check decides scoping (ADR-0024). A tenant that wants more than the ceiling pages through it with `until`, which costs the relay one bounded read at a time.
- **The configured ceiling is validated where it is taken, not where it is used.** `SubscriptionLimits` refuses a read ceiling or filter-value ceiling below the floor of `1`, throwing at construction — a relay that answers every read with nothing is a misconfiguration, not a policy, and a host that misconfigures it learns at boot, from a message naming its number.
- The **access** decisions remain governed by openness: an open relay still lets anonymous clients publish any kind (`allowEventSubmission`), serves unscoped reads (`filterForClient`, `canClientReceiveEvent`), and accepts any authenticating key (`allowsAuthentication`). Only the resource gates changed.

## Consequences

- An open, publicly-exposed relay rate-limits anonymous clients and caps their concurrent subscriptions and query breadth. This is the relay's load-shedding guarantee, and it does not evaporate when tenancy is left unconfigured.
- On an open relay `isTenant()` is always false, so every client is uniformly limited — the intended posture for an anonymous public relay.
- A tenant relay still exempts its authenticated operators from rate limiting and the concurrency caps; guests on it are limited exactly as before.
- A tenant's REQ comes back bounded by the read ceiling, the same as a guest's. Authentication lifts what a client may *see*, never how much of it arrives in one reply.
- Do not move the bound below the tenant check to "finish" the tenant exemption. The exemption is deliberately partial, and an unbounded read is a fault whoever asks for it.
- Do not re-add `isOpenRelay()` to `isRateLimitExempt()` or the subscription-cap check. Trust is proven by authenticating as a tenant, not by the relay's openness.
- Do not relax the floor check back into a silent clamp: a ceiling the relay cannot apply is a configuration the operator needs told about. There is deliberately no upper validation bound — the ceiling is the relay's own number, and ADR-0024 clamps filters to it whatever they ask.
