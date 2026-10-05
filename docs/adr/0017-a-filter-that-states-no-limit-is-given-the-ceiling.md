# 17. A filter that states no limit is given the ceiling

## Status

Superseded by ADR-0024

## Context

`max_query_limit` reads like the bound on how many stored events one REQ can pull back. NIP-11 describes it that way, under the name this relay publishes it by. It was not one. `SubscriptionLimits` only ever compared it against a limit the *client* had stated, and refused the subscription when the client asked for more. A filter that stated no limit at all was compared against nothing and passed straight through.

What actually bounded such a read was a private constant inside `StoredEventStreamer`, fixed at a thousand, which no host could configure. The two numbers happened to share a default, which is why nobody noticed: a host lowering `max_query_limit` to fifty still had its guests pulling a thousand rows per REQ, and the record that justified capping a NIP-45 COUNT rested its whole argument on a REQ ceiling that was not being applied.

The number belongs to the policy. It is the policy that decides what a client may read, that already holds the limits, and that already narrows a guest's filters before they reach the store.

NIP-11 also settles what should happen to a limit that is too large. The relay advertises the number as `max_limit` in its relay-information document, and the specification says the relay clamps each filter's limit to it. This relay refused the subscription instead, which is a different answer to a question the protocol had already answered, and a harsher one: a client asking for more than the relay allows got a `CLOSED` rather than the events.

## Decision

`SubscriptionLimits::bound()` sets every filter's limit to the lower of what the client asked for and the configured `max_query_limit`, and the policy applies it in `filterForClient()` before scoping. A filter stating no limit is asking for the ceiling and is given it; a filter stating more than the ceiling is clamped to it, silently, as NIP-11 describes. There is no longer a rejection for asking too high.

The constant in `StoredEventStreamer` is gone, and so is the parameter it was passed through: `RelayEventStoreInterface::findByFilters` takes a filter set and nothing else. A store returns what the filters match, applying each filter's own limit. The configured number is the only one the relay applies, and it is carried on the filters themselves, so a host has no second knob to set, to forget, or to disagree with it.

The configured number is not free to be anything. A limit is carried on a `Filter`, which accepts up to `Filter::MAX_LIMIT`, so a larger ceiling would build a filter the constructor rejects. `RelayPolicyConfig::tryFromArray()` refuses a `max_query_limit` outside the range the relay can apply and returns `null`, the answer a host already handles, and `SubscriptionLimits` asserts the same range for a caller that constructs it directly.

## Consequences

- Lowering `max_query_limit` now lowers what a REQ returns, which is what the name and the documentation always claimed.
- ADR-0013's premise holds: a REQ does stop at a row ceiling, so bounding a COUNT the same way is the consistency it argued for.
- A client that states no limit gets an explicit one, so the store sees the same shape of filter either way and there is no unbounded read path left.
- A host implementing `RelayPolicyInterface` itself is responsible for applying its own ceiling in `filterForClient`, exactly as it is responsible for the rest of its limits. There is no backstop behind it: a host that bounds nothing gets unbounded reads, and the single place to look when reads are too large is the policy.
- A client that asks for more than the ceiling now receives the ceiling rather than a refusal. It can discover the number ahead of time: it is published as `max_limit` in the relay-information document, which is where NIP-11 says to look.
- There is one rule for every filter, so no read reaches the store without an explicit limit and nothing has to decide separately what an absent limit means.
- A reader tracing where a REQ's size is decided arrives at one place, the policy, and at one number, the configured one. The separate bound `Filter` puts on the field is not a second ceiling the relay applies; it is the range the configured one has to fit inside, and the constructor check is what keeps the two from disagreeing silently.
- The clamp is applied before the policy asks whether the client is a tenant, so it holds for an authenticated tenant as well. That is the one resource limit a tenant does not escape, and ADR-0019 records why.
