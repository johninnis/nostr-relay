# 28. A deletion deletes only its author's earlier events, and never another deletion

## Status

Accepted

## Context

NIP-09 lets an author retract an event by publishing a kind-5 deletion event that references it by `e` tag (an event id) or `a` tag (a coordinate of a replaceable or addressable event). Two bounds in the NIP decide what the relay may delete, and the store port has to be able to express both:

- A deletion "only deletes the events that the same pubkey has published" — a deletion naming someone else's event is ignored for that target.
- For a coordinate reference, the relay deletes the addressed event only when it is "older than the deletion event" — a version *newer* than the deletion survives, because the author published again after retracting.

The NIP is also explicit that deletion events themselves are not deleted: an `e` tag referencing a kind-5 event does not remove it. Letting deletions delete deletions would make the retraction record itself retractable, and with it the evidence a relay needs to honour the ordering rule above.

The port's earlier shape — `deleteByCoordinates(coordinates, author)` with no time argument — could not express the created_at bound, so every coordinate deletion risked removing a version published after the retraction.

## Decision

- `RelayEventStoreInterface::deleteByCoordinates` takes the deletion's created_at as a third argument (`Timestamp $until`): a store deletes a coordinate target only when the stored event's created_at is at or before it. The port change is breaking for host stores; the alternative — bounding in the relay by re-reading every target — forces the store to hand back events it is about to delete, twice the work for a rule that is the store's to apply.
- `EventDeletionProcessor` partitions a deletion's references by ownership and kind before asking the store: `e` targets are deleted by id, `a` targets by coordinate with the bound, references to events the deletion's author does not own are dropped, and **`e` targets of any deletion kind are never deleted**. The processor learns which referenced ids name deletions from the events already stored, in one store query.
- A deletion event is itself stored and distributed like any other accepted event; only its *targets* are affected.

## Consequences

- A host store upgrading to this port gets the created_at bound as data it already holds, and applies it in the same query as the ownership check — one round trip, no events read back into the relay.
- A newer version published after a retraction survives it; an author who retracts and then republishes is not silently undone.
- The retraction record is permanent: deletions cannot be retracted away, so the ordering rule has durable evidence.
- Do not "simplify" the port back to an unbounded delete: the bound is what stops a stale deletion erasing fresh content.
