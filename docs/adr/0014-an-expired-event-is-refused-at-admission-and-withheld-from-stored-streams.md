# 14. An expired event is refused at admission and withheld from stored streams

## Status

Accepted

## Context

NIP-40 lets an event carry an `expiration` tag, a unix timestamp after which the event should be treated as gone. The specification says a relay should not send an expired event to clients, may refuse an event that is already expired when it arrives, and should drop expired events from storage. nostr-core answers the question for a single event through `Event::isExpired()`, but this library never asked it: an expired event was stored, fanned out, and served from REQ results like any other.

The three obligations have different owners. Refusing on arrival and withholding on read are properties of the relay's own admission and streaming paths, which this library owns. Purging from storage is a property of the store, which the host supplies and whose engine decides what a periodic sweep costs; the library cannot do it for every store and should not pretend to.

## Decision

`EventAdmission` refuses an event that is already expired, after the event has been validated and before policy runs, with an `invalid: event has expired` OK reply. `StoredEventStreamer` skips an event whose expiry has passed since it was stored, so it never reaches a REQ result. Live fan-out needs no check of its own: an event that was admitted was not expired at admission.

Both ask `Event::isExpiredAt()` against the time the injected `ClockInterface` reports, never the wall clock, and the streamer reads that time once per REQ so every event in one result is judged against one instant. This is why each of those two classes takes a clock it would not otherwise need.

The library does not purge expired events. A store that wants to reclaim the space runs its own sweep.

## Consequences

- A client cannot use a relay built on this library to resurface an event its author said should be gone, whether it arrived expired or expired on the shelf.
- A withheld event still counts toward the store's row ceiling for that REQ and toward a NIP-45 COUNT, since the store answered before the streamer looked. That is the cost of leaving purging to the store, and a host that cares runs the sweep.
- The clock is a collaborator of two classes that are otherwise about admission and streaming, which reads like an argument too many. It is the price of a relay whose expiry behaviour can be tested at the second and whose notion of now has one source. Do not swap it back for `Event::isExpired()`, which reads the wall clock inside the entity.
- `PolicyRejection::invalid()` exists for this refusal. It is a relay rule, not a policy decision, but it travels through admission's returned outcome like the others. Do not move the check into a policy implementation; the rule is the same for every host.
- The reply prefix is `invalid:`, the closest NIP-01 word for an event that cannot be accepted on its own terms. A future NIP that defines a dedicated prefix supersedes this record.
