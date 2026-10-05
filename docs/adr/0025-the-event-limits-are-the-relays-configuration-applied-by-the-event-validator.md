# 25. The event limits are the relay's configuration, applied by the event validator

## Status

Accepted

## Context

An event was bounded in two places. innis/nostr-core's `EventValidator` refused content longer than 65536 characters, more than 5000 tags and a `created_at` outside an hour ahead to ten years behind, as constants. `RelayPolicy` then refused content longer than its configured `max_event_size` with `blocked: event too large`. The validator runs first, so a host that set `max_event_size` above 65536 never got it: the library refused the event before the policy saw it. The README also described `max_event_size` as bytes of payload, though the policy counted characters of content.

NIP-01 sets none of these limits. NIP-11 is where a relay advertises the ones it applies, as `max_content_length` ("maximum number of characters in the `content`"), `max_event_tags`, `created_at_lower_limit` and `created_at_upper_limit`. innis/nostr-core now takes them as host configuration, an `EventLimits` injected into `EventValidator` with its old constants as defaults (its ADR-0131).

## Decision

- `RelayConfigInterface::getEventLimits()` returns the `EventLimits` the relay applies, and `RelayServerFactory` builds its `EventValidator` with them. A host returns `new EventLimits()` for the library defaults.
- `RelayPolicy` no longer checks content length, and `RelayPolicyConfig` has no `max_event_size`. The content length is bounded once, by the validator, with the number the host configured.
- An event outside the limits is answered `invalid:` with the validator's reason, as every other event the validator refuses, and as NIP-01's own example answers a `created_at` "too far off from the current time". It binds a tenant too: the validator runs before the policy's tenant bypass.

## Consequences

- A relay's limits are one configuration, applied as configured and published as configured.
- Breaking: a `RelayConfigInterface` implementation adds `getEventLimits()`; `max_event_size` is removed from the policy configuration, and an oversized event is answered `invalid:` where it was `blocked:`.
- Do not add a content-length check back to a policy. A host that wants a different number configures `EventLimits`.
