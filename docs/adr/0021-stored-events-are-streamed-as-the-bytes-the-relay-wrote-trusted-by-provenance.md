# 21. Stored events are streamed as the bytes the relay wrote, trusted by provenance

## Status

Accepted

## Context

An event's signature covers six fields: pubkey, created_at, kind, tags and content, serialised in one fixed way, and hashed into the id. It says nothing about the JSON the event arrived as. Keys it does not cover, a different key order, whitespace, or a non-string content that a parser coerced can all accompany a valid signature, so input bytes are not something an event vouches for. nostr-core settled this by having `Event::toJson()` always encode the fields the event holds and never keep the bytes it was parsed from (nostr-core ADR-0076).

That is correct and it moved a cost onto the relay. A REQ answered from the store read every stored string, parsed it into an `Event`, and then encoded it again to build the `EVENT` frame, the parse costing more than twice the encode. The store already held the event's own encoding, because a store is handed an `Event` and writes what `toJson()` returns, so the relay was paying to turn its own canonical bytes into an object and back into the same bytes. Live fan-out paid a smaller version of the same cost: one `EventMessage` per subscriber, so an event going to fifty subscribers was encoded fifty times.

Skipping the parse means sending bytes the relay has not looked at, which is the thing nostr-core ADR-0076 exists to prevent. The difference is where the bytes came from. Bytes a client sent are untrusted however they parse. Bytes the relay produced with `Event::toJson()` from an event it verified, and read back from its own store, are trusted because of how they got there, not because of anything checked on the way out. Parsing them again would verify nothing a first parse had not.

What the streamer did with the parsed event was small: it asked whether the event had expired, and asked the policy whether this client may receive it. Neither needs the content.

## Decision

Stored events travel as `StoredEvent`: the event's `EventHeader` (id, author, kind), its earliest stated expiry, and an `EncodedEvent` holding the bytes. `RelayEventStoreInterface::findByFilters` returns a `StoredEventCollection`, and nothing on the read path parses those bytes.

`EncodedEvent` has a private constructor and two named constructors, and they are the whole of its provenance rule:

- `EncodedEvent::of(Event)` encodes an event the relay holds. It is the only way bytes enter a store: `RelayEventStoreInterface::store` takes an `Event`, never a string, and a store keeps `of()` of it.
- `EncodedEvent::fromOwnStore(string)` wraps bytes a store is reading back, and only those. It does not parse or check them; it carries a fence pointing at this record. A store that ever wrote anything other than `of()` output, including rows it held before it followed this rule, must bring those rows into line before it may read them back through this constructor.

An `EVENT` frame is built in one place, `EncodedEvent::framedFor(SubscriptionId)`, which JSON-encodes the frame type and subscription id and splices the bytes in after them unchanged. `ClientMessengerInterface::sendEvent()` is the one way an event reaches a client, stored or live, and it is where a sent event is counted; `send()` refuses an `EventMessage` so the two paths cannot drift. `EventDistributor` encodes a live event once per distribution, the first time a subscriber is found to receive it, and hands every recipient the same `EncodedEvent`.

The expiry travels as the earliest stated expiry that parses, through `StoredEvent::earliestExpiry()`, which a store reading expiries from its own index uses too. An event is expired once any stated expiry has passed, and that holds exactly when the earliest one has, so the streamer's answer is the one `Event::isExpiredAt()` gives; ADR-0014's rule and its injected clock are unchanged.

## Consequences

- Streaming a stored event costs no parse and no encode. Measured with `tools/stream-benchmark.php` over 1,000 stored 2 KB events, turning stored bytes into a frame fell from about 54 µs per event to about 1.4 µs. Distributing one live event to fifty subscribers fell from about 17 µs per recipient, one encode each, to about 1.3 µs per recipient after a single encode.
- The trust is by provenance, so the guarantee is only as good as the store's write path. The type system carries the part it can: a store receives an `Event`, and the only producer of stored bytes is `EncodedEvent::of()`. The rest is the store's obligation, stated at `fromOwnStore()` and at the port, and a store that imports, migrates or restores rows must write them through the same `store(Event)` path or re-encode them before they are read.
- A frame's event bytes are the canonical event encoding, which leaves U+2028 and U+2029 unescaped where an `EventMessage` would escape them. Both are the same JSON value, and the event encoding is the one its id is computed over.
- A store implementation must now return `StoredEvent`s, built from its own indexed columns and bytes. That is a breaking change for any host store.
- Do not parse in `fromOwnStore()` "to be safe", and do not build the frame by decoding and re-encoding it. Either reintroduces the cost this record removes and checks nothing the write path did not already guarantee. A store that cannot make the write-path guarantee must parse and re-encode its rows itself, and hand `of()` the result. `EncodedEventTest` and `StoredEventStreamerTest` pin that stored bytes reach the wire untouched.
- Do not construct an `EventMessage` for delivery. The messenger refuses it, and the one framing path is what keeps what the relay counts and what it sends the same.
