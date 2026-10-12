# 39. Stored events are streamed as the bytes the relay wrote, trusted by provenance, and the store's order decides which events a limit keeps

## Status

Accepted

Supersedes ADR-0021. Carried forward unchanged: stored events travel as `StoredEvent`, a header, a `created_at`, an earliest stated expiry and the bytes, and nothing on the read path parses the bytes; `EncodedEvent::of()` and `EncodedEvent::fromOwnStore()` are the whole provenance rule; an `EVENT` frame is built only by `EncodedEvent::framedFor()` and sent only through `ClientMessengerInterface::sendEvent()`; a live event is encoded once per distribution; the expiry is nostr-core's earliest stated expiry; a store returns what the filters match newest first and the lower id first at a tie, applying each filter's own `limit` to that order; the relay puts what it streams in that order again. Revised: the relay reads a `REQ` with one store call (ADR-0036) and no longer cuts each filter's events to its `limit` after putting them in order, so which events a `limit` keeps at a tie is decided by the store's order, and a store that breaks a tie the other way keeps the wrong one. This record holds the complete current decision.

## Context

*Carried forward from ADR-0021.* An event's signature covers six fields: pubkey, created_at, kind, tags and content, serialised in one fixed way, and hashed into the id. It says nothing about the JSON the event arrived as. Keys it does not cover, a different key order, whitespace, or a non-string content that a parser coerced can all accompany a valid signature, so input bytes are not something an event vouches for. nostr-core settled this by having `Event::toJson()` always encode the fields the event holds and never keep the bytes it was parsed from (nostr-core ADR-0076).

That is correct and it moved a cost onto the relay. A REQ answered from the store read every stored string, parsed it into an `Event`, and then encoded it again to build the `EVENT` frame, the parse costing more than twice the encode. The store already held the event's own encoding, because a store is handed an `Event` and writes what `toJson()` returns, so the relay was paying to turn its own canonical bytes into an object and back into the same bytes. Live fan-out paid a smaller version of the same cost: one `EventMessage` per subscriber, so an event going to fifty subscribers was encoded fifty times.

Skipping the parse means sending bytes the relay has not looked at, which is the thing nostr-core ADR-0076 exists to prevent. The difference is where the bytes came from. Bytes a client sent are untrusted however they parse. Bytes the relay produced with `Event::toJson()` from an event it verified, and read back from its own store, are trusted because of how they got there, not because of anything checked on the way out. Parsing them again would verify nothing a first parse had not.

What the streamer did with the parsed event was small: it asked whether the event had expired, and asked the policy whether this client may receive it. Neither needs the content.

*What this record revises.* ADR-0021 said a store that breaks a tie the other way never decides which event a limit keeps, because the relay read each group of filters with a store call of its own, put that group's events in NIP-01's order, and cut them to the group's `limit` itself. ADR-0036 replaced the groups with one store read per `REQ`, confined in the store. The relay cannot cut the result again: a filter's `limit` bounds the events that filter matched, and the relay, holding only each event's header, cannot tell which filter matched which event without reading the tags it never parses. So the cut the store makes is the only cut there is.

## Decision

Stored events travel as `StoredEvent`: the event's `EventHeader` (id, author, kind), its `created_at`, its earliest stated expiry, and an `EncodedEvent` holding the bytes. `RelayEventStoreInterface::findByFilters` returns a `StoredEventCollection`, and nothing on the read path parses those bytes.

Stored events are read in the order NIP-01 gives a REQ's: newest first, and "In case of events with the same created_at, the event with the lowest id (first in lexical order) should be first." A store returns its union in that order, each event once, and applies each filter's `limit` to that order, as `InMemoryEventStore` does; which events a `limit` keeps when it falls between two events created at once is therefore the store's to decide, and NIP-01's order decides it for a store that keeps this contract. The relay puts what the store returns in that order again (`StoredEventCollection::newestFirst`, over `StoredEvent::precedes`) before it streams it, so the order on the wire never depends on the store's. The store contract cases pin the order and the cut at a tie (ADR-0037).

`EncodedEvent` has a private constructor and two named constructors, and they are the whole of its provenance rule:

- `EncodedEvent::of(Event)` encodes an event the relay holds. It is the only way bytes enter a store: `RelayEventStoreInterface::store` takes an `Event`, never a string, and a store keeps `of()` of it.
- `EncodedEvent::fromOwnStore(string)` wraps bytes a store is reading back, and only those. It does not parse or check them; it carries a fence pointing at this record. A store that ever wrote anything other than `of()` output, including rows it held before it followed this rule, must bring those rows into line before it may read them back through this constructor.

An `EVENT` frame is built in one place, `EncodedEvent::framedFor(SubscriptionId)`, which JSON-encodes the frame type and subscription id and splices the bytes in after them unchanged. `ClientMessengerInterface::sendEvent()` is the one way an event reaches a client, stored or live, and it is where a sent event is counted; `send()` refuses an `EventMessage` so the two paths cannot drift. `EventDistributor` encodes a live event once per distribution, the first time a subscriber is found to receive it, and hands every recipient the same `EncodedEvent`.

The expiry travels as the earliest stated expiry that parses, derived by nostr-core — `Event::expiresAt()` on the write path, and `ExpirationDerivation::earliestStated()` for a store reading expiries from its own index. An event is expired once any stated expiry has passed, and that holds exactly when the earliest one has, so the streamer's answer is the one `Event::isExpiredAt()` gives; ADR-0014's rule and its injected clock are unchanged. The rule lives in nostr-core (nostr-core ADR-0071, shared ADR-0011); this package holds no copy of it.

## Consequences

- Streaming a stored event costs no parse and no encode. Measured with `tools/stream-benchmark.php` over 1,000 stored 2 KB events, turning stored bytes into a frame fell from about 54 µs per event to about 1.4 µs. Distributing one live event to fifty subscribers fell from about 17 µs per recipient, one encode each, to about 1.3 µs per recipient after a single encode.
- The trust is by provenance, so the guarantee is only as good as the store's write path. The type system carries the part it can: a store receives an `Event`, and the only producer of stored bytes is `EncodedEvent::of()`. The rest is the store's obligation, stated at `fromOwnStore()` and at the port, and a store that imports, migrates or restores rows must write them through the same `store(Event)` path or re-encode them before they are read.
- A frame's event bytes are the canonical event encoding, which leaves U+2028 and U+2029 unescaped where an `EventMessage` would escape them. Both are the same JSON value, and the event encoding is the one its id is computed over.
- A store implementation must return `StoredEvent`s, built from its own indexed columns and bytes, newest first and the lower id first at a tie.
- A store that breaks a tie the other way streams in the right order and keeps the wrong event where a `limit` falls between two events created at once. The contract case "a limit falling between two events created at once keeps the lower id" is how a host finds out; the relay cannot correct it.
- Do not parse in `fromOwnStore()` "to be safe", and do not build the frame by decoding and re-encoding it. Either reintroduces the cost this record removes and checks nothing the write path did not already guarantee. A store that cannot make the write-path guarantee must parse and re-encode its rows itself, and hand `of()` the result. `EncodedEventTest` and `StoredEventStreamerTest` pin that stored bytes reach the wire untouched.
- Do not construct an `EventMessage` for delivery. The messenger refuses it, and the one framing path is what keeps what the relay counts and what it sends the same.
- Do not re-cut the store's answer to each filter's `limit` in the relay by matching the stored events against the filters: it needs the tags, which means parsing the bytes this record streams unparsed.
- Shared decisions: nostr-adrs ADR-0009 and ADR-0034.
