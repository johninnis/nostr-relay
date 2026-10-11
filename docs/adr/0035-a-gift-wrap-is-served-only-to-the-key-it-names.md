# 35. A gift wrap is served only to the key it names

## Status

Accepted

## Context

NIP-59 says "To protect recipient metadata, relays SHOULD only serve `kind 1059` events intended for the marked recipient", and NIP-17 says "Relays SHOULD protect message metadata by only serving `kind:1059` events to users p-tagged on the event (enforced using NIP-42 AUTH)". An ephemeral gift wrap, kind 21059, "has the same structure as `kind:1059`". Who a wrap is for is the one thing its outside reveals, and serving every wrap to anyone who asks hands over a map of who receives private messages, and when.

This library left delivery to the host's policy, and the built-in `RelayPolicy` served every wrap to anyone on an open relay. A SHOULD that every host has to remember to implement is one most hosts miss.

Judging each wrap's `p` tag at delivery would need the stored bytes parsed for every delivered event, which ADR-0021 and ADR-0022 rule out. A filter can say the same thing before anything is read, so the relay narrows the request rather than the result. One kind of filter cannot be narrowed that way: a NIP-01 filter that names no kinds matches every kind, and no filter can exclude one.

## Decision

- After the policy has scoped a `REQ` or `COUNT` (`filterForClient`), `ReadScopeGate` confines its gift wraps to the keys the connection has authenticated as, through `GiftWrapFilterRules::confine`. A filter naming kind 1059 or 21059 is split: its other kinds keep the filter as it was, and its gift-wrap kinds are confined to `#p` holding the authenticated keys, intersected with any `#p` the filter states. A guest's gift-wrap part is dropped. The halves of a split filter stay one group in `ScopedFilters` (`getGroups()`), the filters granted for the one filter the client asked for.
- A read narrowed this way is a read beyond scope (ADR-0004): it ends with an `AUTH` challenge and an `EOSE` carrying the `"auth"` hint, and one left able to match nothing is refused `CLOSED` `auth-required:`.
- A filter that names no kind matches no gift wrap; another filter in the same request that names the gift-wrap kind still delivers the wraps it selects. Live delivery asks `DeliveryGate::allowsLive`, which matches a gift wrap only through a filter naming its kind (`GiftWrapFilterRules::matches`). A stored read asks the store for each group on its own (`StoredEventReadGate`), and `DeliveryGate::allowsStored` drops an event of a gift-wrap kind from what a group holding a filter that names no kind read, by the kind in the event's header. The groups' events are merged newest first, the lower id first at a tie (ADR-0021), each event once, using the `created_at` a store returns beside each `StoredEvent`. A filter naming no kind is not a read beyond scope, because authenticating cannot unlock it: the client has to name the kind.
- A group answers within the one `limit` of the filter the client asked for: the store applies each half's limit, and the relay puts the group's events in that same order and keeps the first of them up to that limit, so a split filter returns no more than the client asked for, and of two events created at once it keeps the lower id.
- A `COUNT` is counted exactly from unions of filters, which is what a store counts: the count of all its filters, less the count of every gift wrap any of them matches (each filter confined to the gift-wrap kinds, a filter naming no kind given both), plus the count of the gift wraps the filters naming a gift-wrap kind match (`ReadableEventCounter`). That is the count of the union with the wraps only a filter naming no kind reaches taken out. It is approximate when any of the three counts is.
- This holds whatever the policy grants. A host policy that widens scope for some clients does not widen it for these kinds.

## Consequences

- A relay built on this library honours NIP-59 and NIP-17 without the host doing anything. A client reads its own wraps by authenticating as their recipient and asking for the gift-wrap kind; a client that never authenticates sees none.
- Delivery stays a check of the header and of the filters that read the event, with no parse of stored bytes; the `p` tag is matched by the store's tag index through the filter, and on the live path by the filter matching the event it already holds.
- A stored read costs one store query per filter the client asked for, rather than one for the whole request, and a store must return each `StoredEvent`'s `created_at` (ADR-0021).
- A wrap dropped from what a filter naming no kind read still counts towards that filter's `limit`, so its page can come back short, as an expired event's can (ADR-0014). A `COUNT` holding a filter naming no kind costs two or three counts from the store.
- Do not judge a wrap's recipient at delivery, and do not exempt a trusted client from this confinement.
- Shared decision: nostr-adrs ADR-0120.
