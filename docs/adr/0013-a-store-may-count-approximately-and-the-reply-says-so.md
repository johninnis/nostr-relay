# 13. A store may count approximately, and the reply says so

## Status

Accepted

## Context

NIP-45 COUNT asks the relay for the number of stored events matching a filter set. The relay admits a COUNT through the same path as a REQ (ADR-0006), so the filter a client may run is the same, but the two differ in what the store then does with it. A REQ stops at a row ceiling: the client's `limit`, or the policy's `max_query_limit` where the client states none (ADR-0017), bounds how many rows the store touches. A COUNT has no such ceiling. To answer exactly, the store must visit every matching row, however many there are, and the client controls how many by choosing the filter. On a large store a single unauthenticated COUNT over a popular tag costs seconds of a query worker, and the caps that bound a REQ do nothing for it.

NIP-45 anticipates this. A relay may answer with an approximate figure and say so, by adding `"approximate": true` to the reply. What the protocol allows, this library could not express: the store port returned a bare integer, and the use case had no way to learn that the number was a ceiling rather than a total. nostr-core models the reply payload as an `EventCount`, a count that is either exact or approximate (nostr-core ADR-0064), and its `CountMessage` is built from one, so the vocabulary already exists; the store port simply did not speak it.

The obvious fix inside the library, counting at most a fixed number of rows in the built-in store, is the wrong layer. How much a count may cost is a property of the store and its deployment: the in-memory store counts everything because everything is in memory, and a durable store decides its own ceiling from its own limits. What the library must provide is the vocabulary for a store to say which kind of answer it gave, and a reply that repeats it faithfully.

## Decision

`RelayEventStoreInterface::countByFilters` returns nostr-core's `EventCount`, built through `EventCount::exact()` or `EventCount::approximate()`. `CountSubscriptionUseCase` hands that value to the `CountMessage` unchanged, so an approximate count reaches the wire with `"approximate": true` and an exact one without the key. This library defines no count value of its own. The built-in `InMemoryEventStore` always answers exactly.

A store that bounds the work of a count is expected to report the bounded result as approximate whenever it stopped at its ceiling, so that a client can never mistake a ceiling for a total.

## Consequences

- A durable store can bound a COUNT to the same cost as the REQ it could have been, and stay honest on the wire.
- The flag is the store's to set and the message's to serialise. Do not have the use case decide approximateness from the number, and do not unpack the value into loose fields between the store and the reply: a capped count without the flag is a wrong answer, not a simpler one.
- A store that returns a bare number is a type error, so every implementation is forced to say which kind of count it gives.
