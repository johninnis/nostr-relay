# 24. A relay sets its own read ceiling and per-filter value ceiling, and the value ceiling binds every client

## Status

Accepted

Supersedes ADR-0017. Carried forward unchanged: `SubscriptionLimits::bound()` sets every filter's limit to the lower of the client's limit and the configured `max_query_limit`, a filter stating no limit is given the ceiling, a larger limit is clamped rather than refused, the store applies each filter's own limit, and the ceiling is validated where it is configured. Revised: the ceiling no longer has to fit under a library maximum, because innis/nostr-core no longer has one (its ADR-0102), and the relay gains a ceiling on the number of values one filter may hold, which innis/nostr-core also no longer imposes (its ADR-0125).

## Context

innis/nostr-core used to refuse a filter whose `limit` was above 5000 or whose `ids`, `authors`, `kinds` or single tag held more than 1000 values. Both caps were library figures, not protocol ones: NIP-01 sets neither, and NIP-11 gives the read ceiling to the relay as `max_limit`, "the relay server will clamp each filter's `limit` value to this number". The library now holds any non-negative `limit` and any number of values, and says that how much a relay serves is the relay's policy.

That leaves two things this package has to own.

- ADR-0017 bounded `max_query_limit` above by `Filter::MAX_LIMIT`, so a clamped filter would always be one the constructor accepted. With no library maximum there is nothing for the ceiling to fit under; the only figure a relay cannot serve is one below 1.
- The value counts bounded nothing the relay had decided on, but they did stop a filter of a hundred thousand authors reaching the store. Without them a single filter can name as many values as the frame carries, and a store that turns a filter into one query pays for every value: an SQL store binds one parameter per value and has a fixed maximum (SQLite's is 32766 by default), so an over-large filter becomes a database error in the middle of a read rather than an answer.

The value ceiling bounds one store query, like the read ceiling bounds one reply. ADR-0019 exempts a tenant from the caps that bound how much concurrent work a client starts, and binds a tenant to the read ceiling because an unbounded read is a fault whoever asks for it. A query the store cannot bind is the same kind of fault.

## Decision

- `max_query_limit` is any integer from 1. `SubscriptionLimits::isCeilingInRange()` is the one check, used by `RelayPolicyConfig::tryFromArray()` (which returns `null`) and by the `SubscriptionLimits` constructor (which throws).
- `max_filter_values` (default 5000) is the most values one filter may hold, counted across its `ids`, `authors`, `kinds` and every tag condition, because that is what one store query binds. It is validated by the same check.
- `SubscriptionLimits::refuseOversizedFilters()` answers a request holding a filter above the ceiling with `blocked: too many values in one filter (max N)`, the prefix the relay already uses for its other policy limits (`too many filters`). `RelayPolicy::allowSubscription()` asks it before the tenant check, so it binds every client; the subscription and filter counts are still asked after it, for clients that are not tenants. A `COUNT` is admitted through the same gate (ADR-0006), so it is refused the same way.
- NIP-11 defines `max_limit` for the read ceiling and no field for a value count. A host publishes `max_query_limit` as `max_limit`; the value ceiling is answered only by the refusal.

## Consequences

- A client that names more values than the relay serves gets a `CLOSED` saying so and naming the figure, never an error from the store.
- A host whose store binds fewer parameters than the default sets `max_filter_values` below that bound; innis/hubstr-relay validates its own configuration against its SQLite store.
- A host implementing `RelayPolicyInterface` itself applies both ceilings in its own policy, as it does every other limit.
- Do not move the value check below the tenant check: a tenant's oversized filter breaks the store as surely as a guest's.
- Do not reintroduce an upper bound on `max_query_limit` from the library; the relay's own figure is the only one.
