# 22. The receive check sees an event's header, not the event

## Status

Accepted

## Context

`RelayPolicyInterface::canClientReceiveEvent` is asked once for every event on its way to a client: for each stored event a REQ returns, and for each live event fanned out to a subscriber. It took the whole `Event`.

Stored events no longer arrive as `Event`s. They arrive as the bytes the relay wrote, trusted by provenance and never parsed (ADR-0021), so handing the policy an `Event` for them would mean parsing every stored event again for the one question the streamer asks, which is most of what ADR-0021 saves.

What the question actually reads is small. The built-in `RelayPolicy` answers it through `GuestFilterRules::allowsEvent`, which looks at the kind and the author and nothing else, and a guest's filters were already narrowed on the same two fields before the store ran them. A durable store holds both as indexed columns next to the id, so it can supply them without touching the bytes.

A policy could want more: a recipient check against an event's `p` tags, say. Giving it the full event keeps that door open, at the price of a parse per stored event for every host whether or not its policy uses it.

## Decision

`canClientReceiveEvent(RelayClient, EventHeader)` takes an `EventHeader`: the event's id, author and kind. The live path builds it with `EventHeader::of($event)` once per distribution; a store builds it from its own columns. `GuestFilterRules::allowsEvent` takes the same header.

## Consequences

- Deciding whether a client receives a stored event costs no parse, and the live and stored paths ask the policy the same question in the same shape.
- A host policy implementing `canClientReceiveEvent` must change its signature. The two built-in callers of `GuestFilterRules::allowsEvent` pass a header.
- A policy cannot decide delivery on tags or content. Scoping on those belongs in `filterForClient`, where the store applies it before anything is read. A host that genuinely needs a per-event decision over the tags is asking to reverse this record, and the cost it must accept is a parse per delivered stored event.
- Do not widen the header with fields the store cannot read from an index. The header is what makes the check cheap; a field that forces a parse to fill it undoes ADR-0021 by the back door.
