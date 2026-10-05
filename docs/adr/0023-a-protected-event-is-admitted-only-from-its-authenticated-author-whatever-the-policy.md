# 23. A protected event is admitted only from its authenticated author, whatever the policy

## Status

Accepted

## Context

NIP-70 says "The default behavior of a relay MUST be to reject any event that contains `["-"]`", and "Relays that want to accept such events MUST first require that the client perform the NIP-42 `AUTH` flow and then check if the authenticated client has the same pubkey as the event being published and only accept the event in that case." Which events are protected is decided once, for every library, by the shared nostr-adrs ADR-0078: exactly those carrying the tag `["-"]`.

The relay admitted a protected event on whatever terms the policy set. The built-in `RelayPolicy` never looked at the tag, so an open relay accepted a protected event from anyone and a tenant could relay another author's. A host policy (innis/hubstr-relay's) enforced the rule itself, so the same requirement lived in two places and a third host would have to know to write it again.

Putting the check in `RelayPolicy` fixes only the built-in policy: a host implements `RelayPolicyInterface` itself, and the NIP's requirement is not a choice a host makes. A policy method a host must remember to call leaves the same gap.

## Decision

- Admission asks the policy first, then applies NIP-70's rule to every event the policy admits, in `PublishingGate`. No policy can admit a protected event on any other terms, and none needs to implement the rule.
- A protected event is admitted only when the connection has authenticated as the event's author.
- A connection that has not authenticated is answered `auth-required: this event may only be published by its author`, and draws a challenge (ADR-0011's `auth-required` path), as NIP-70's example flow shows.
- A connection authenticated only as other keys is answered `restricted:` with the same words: NIP-42 reserves `restricted` "for when a client has already performed `AUTH` but the key used to perform it is still not allowed by the relay".
- A policy's own refusal (a size limit, a blacklist, an invalid zap receipt) is answered first.
- To keep admission's constructor within three collaborators, validity and expiry are judged by `EventValidityGate` and the policy with the NIP-70 rule by `PublishingGate`; `EventAdmission` runs the rate limit, validity and publishing gates in that order.

## Consequences

- A relay built on this library follows NIP-70 whatever policy it is given, including an open relay.
- An author who cannot authenticate here (on a relay whose policy limits authentication to tenants) cannot publish a protected event here. That is NIP-70's default: rejecting the event.
- Do not move the rule into a policy: the requirement would again depend on each host writing it.

## Sources

- nostr-adrs ADR-0078 (which events are protected)
