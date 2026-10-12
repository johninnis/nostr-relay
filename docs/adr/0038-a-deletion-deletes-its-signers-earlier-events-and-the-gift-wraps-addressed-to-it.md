# 38. A deletion deletes its signer's earlier events and the gift wraps addressed to it, and never another deletion

## Status

Accepted

Supersedes ADR-0028. Carried forward unchanged: a coordinate target is deleted only when the deletion's signer published it and it was created at or before the deletion (`deleteByCoordinates` takes the deletion's `created_at`); an `e` target that is itself a deletion is never deleted; the deletion is stored and distributed like any other accepted event. Revised: an `e` target that is a gift wrap whose `p` tag names the deletion's signer is deleted too, and the store judges every `e` target in its delete, so the relay no longer reads the targets back. This record holds the complete current decision.

## Context

NIP-09 lets an author retract an event by publishing a kind 5 deletion that references it by `e` tag (an event id) or `a` tag (a coordinate). Its bounds, carried from ADR-0028:

- "Relays SHOULD delete or stop publishing any referenced events that have an identical `pubkey` as the deletion request."
- "When an `a` tag is used, relays SHOULD delete all versions of the replaceable event up to the `created_at` timestamp of the deletion request event", so a version published after the retraction survives.
- "Publishing a deletion request event against a deletion request has no effect." The retraction record stays, and with it the evidence the ordering rule needs.

NIP-59 adds a reach NIP-09 does not have: "Since signing keys are random, relays SHOULD delete `kind:1059` events whose p-tag matches the signer of NIP-09 deletions or NIP-62 vanish requests." NIP-17 relies on it: "gift wraps can be deleted by the `p`-tagged recipient". A wrap is signed by a one-time key its sender throws away, so under NIP-09 alone nobody could ever delete it, and its recipient, the one party that holds it and wants it gone, has no way to.

ADR-0028 deleted only the signer's own events. The relay read the referenced events back, kept those the signer authored that were not deletions, and asked the store to delete them by id. A recipient's wrap was dropped there as someone else's. Deciding whether a wrap is addressed to the signer needs its `p` tags, which the relay never parses (ADR-0039); the store's tag index holds them. ADR-0028 already gave the store the coordinate rule for the same reason: a rule the store's own data answers belongs in the store's delete, rather than a read that hands back events about to be deleted.

## Decision

- `RelayEventStoreInterface::deleteByEventIds(EventIdCollection $eventIds, ActingKeys $deleter)`: of these ids, a store deletes the events one of the deleter's keys signed, and the events of a gift-wrap kind whose `p` tag names one of those keys, and never a kind 5 deletion. `ActingKeys::canDelete(Event)` defines it for a store that holds events. A store with an index applies the same three conditions in its delete, using the acting keys and `getConfinedKinds()`, the same rule a read applies (ADR-0036).
- `EventDeletionProcessor` hands the store every `e` target with `ActingKeys` holding the deletion's signer alone, and reads nothing back. It is the signer NIP-09 and NIP-59 name, not whatever keys the connection authenticated as.
- An `a` target is deleted through `deleteByCoordinates` only when its coordinate names the signer, and only versions created at or before the deletion. No gift wrap has a coordinate.
- The deletion event itself is stored and distributed like any other accepted event; only its targets are affected.
- This library does not implement NIP-62. A request to vanish is stored as an ordinary event, and deletes nothing.

## Consequences

- A recipient can remove its gift wraps from the relay. A key a wrap is not addressed to cannot delete it, the wrap's signer still can, a recipient cannot delete another kind of event that merely tags it, and a wrap addressed to several keys can be deleted by any of them. The contract cases pin each of these (ADR-0037).
- A deletion costs one store call for its ids and one for its coordinates, and reads nothing back. The warning about references to events the signer does not own now counts coordinates only, because the relay no longer learns who signed an id's event.
- The relay follows NIP-09 and NIP-59 as written: a recipient's deletion is a public kind 5 event, stored and served to anyone like every deletion, and never withheld. It names the wrap's id and is signed by the recipient's own key, so whoever reads it learns, without being able to read the wrap, that the key received that wrap and when it deleted it. That is why innis/nostr-core and @innis/nostr-core build a deletion only for its author's own events and refuse a gift wrap addressed to the author (shared ADR-0125): a relay built on this library honours such a request from any client, but those libraries never write one.
- This is a breaking change for every host store: `deleteByEventIds` takes the deleter's acting keys, and the store applies the never-a-deletion rule and the recipient rule itself.
- Do not drop the `created_at` bound on a coordinate, do not delete a deletion, do not extend recipient deletion to other kinds that merely tag the deleter, and do not read the targets back into the relay to judge them there.
- Shared decisions: nostr-adrs ADR-0093, ADR-0123, ADR-0124 and ADR-0125.
