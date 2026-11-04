# 40. A store refuses an event a stored deletion request deletes, in the write that would store it

## Status

Accepted

## Context

ADR-0038 applies a deletion request when it is accepted, to the events the store holds at that moment. NIP-09 asks relays to keep serving deletion requests "as clients may already have the event that's intended to be deleted", and asks clients to send a request on to relays that lack it, so a relay is often sent a request before the event it deletes. Up to v0.13.0 the event was then stored and served like any other: a rebroadcast brought a deleted event back.

The relay already holds the record it needs: the deletion requests it stored. The check could be made in three places. `AcceptedEventPipeline` could read the store for covering requests before asking it to store the event, through `findByFilters`, with no change to the port. But the read and the write are then two steps, and a store that interleaves writes, as a host's worker-backed store does, can accept a request between them, so the event is stored after the request that deletes it was applied and survives both. The store's own write is where the relay already decides what may be kept: it decides `Duplicate` and `Superseded` there, in the same step that stores, for the same reason.

Having found the event deleted, the relay can store and withhold it, or refuse it. Storing it keeps bytes the relay will never serve and makes every read filter them out; refusing it keeps the store equal to what the requests it holds allow.

## Decision

- `RelayEventStoreInterface::store` refuses an event that a deletion request it holds deletes, and answers `EventStoreOutcome::Deleted`, deciding it in the same write that would store the event. A store finds the requests with innis/nostr-core's `DeletionCoverage::lookupFilters` and decides each with `DeletionCoverage::covers`, which also decides that a deletion request is never refused this way.
- `AcceptedEventPipeline` answers `Deleted` with `OK` false and `blocked: a deletion request deletes this event`, and neither distributes nor processes the event.
- `EventDeletionProcessor` takes the request's own coordinates from `DeletionCoverage::deletedCoordinates`, and does nothing for an event that is not a deletion request.
- The store contract (ADR-0037) runs the shared deletion corpus, `tests/Contract/deletion-vectors.json`, copied byte for byte from @innis/nostr-core, against every store twice: the request applied through `EventDeletionProcessor` to a target already stored, and the target stored after the request. Each case's verdict is the corpus's.

## Consequences

- A deleted event cannot be brought back by rebroadcasting it, and a version of a replaceable or addressable event created after the request is still stored.
- Every event stored costs the store a lookup of the requests that could delete it: by id, and for a replaceable or addressable version by coordinate.
- A store that applies the deletion rule in its own query language is held to nostr-core's judgement by the corpus, through both its delete and its write.
- This is a breaking change for every host store: `store` must now refuse a deleted event, and a `match` over `EventStoreOutcome` must handle `Deleted`.
- Do not move the check out of the store's write into a read before it; that reopens the race this record closes.
- Shared decisions: nostr-adrs ADR-0124, ADR-0126 and ADR-0127.
