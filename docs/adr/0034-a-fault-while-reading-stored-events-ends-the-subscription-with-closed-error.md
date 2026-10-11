# 34. A fault while reading stored events ends the subscription with `CLOSED` `error:`

## Status

Accepted

## Context

A `REQ` is admitted and registered while its message is handled, and its stored events are read and streamed afterwards, on a deferred task (ADR-0031). By the time the store is read, the handler that accepted the `REQ` has returned, so the dispatcher's fault boundary (ADR-0030) no longer stands around the read, and the stream has no wire frame of its own.

The streamer answered a store fault with a `NOTICE` saying `error: failed to fetch events` and left the subscription registered. The client never received an `EOSE` or a `CLOSED` for it, a `NOTICE` is prose no client can tie to a subscription, and the relay went on matching live events into a subscription whose stored events it had silently dropped. NIP-01 gives the frame for this: `CLOSED` "can also be sent when a relay decides to kill a subscription on its side".

A read can also outlive its subscription. A store that suspends while it reads lets the client's next messages be handled in the meantime, and NIP-01 says a `REQ` on an id already open replaces that subscription. Settling a fault by the subscription id alone then withdrew the replacing subscription and answered `CLOSED` for it, and the replacement's own read went on to send events and an `EOSE` for an id the client had been told was closed. A read that finished after its subscription was replaced, or closed by the client, likewise sent its events and an `EOSE` under an id that now meant something else.

## Decision

- `StoredEventStreamer` is the fault boundary for the deferred read of a subscription's stored events. A fault from the read, whether the store's or the read gate's, is settled by `SubscriptionAnswers::settleFault`: it is logged with the client and the subscription id, the subscription is removed, and the client is answered `CLOSED` with `error: could not read stored events`. No `EOSE` follows.
- A fault settles only the subscription whose read faulted. A read carries the `Subscription` its `REQ` registered, and `settleFault` withdraws and answers only while the registry still holds that registration (`SubscriptionLookupInterface::holds`, which compares the instance the `REQ` registered, never the id or the filters). A fault on a read whose subscription a later `REQ` replaced, or the client closed, is logged and answered with nothing, so the replacement stays open and no `CLOSED` is sent for it.
- A read whose subscription was replaced or closed sends nothing after that. `HeldSubscriptionSender` sends every frame of a read — each `EVENT`, and the challenge and `EOSE` that end it — only while the client still holds the subscription, and asks before each frame, because a send can suspend too. It works out how the read ends, which may issue a challenge, only while the subscription is held.
- A peer that disconnects while its stored events are being sent is not a fault. The `ConnectionException` ends the deferred task, and `AmphpDeferredExecutor` records it at debug level as a task whose peer went away; any other fault escaping a deferred task is still logged as a failure.

## Consequences

- The `CLOSED` is true: the subscription is gone, so no live event follows it, and the client can send its `REQ` again.
- The streamer takes no logger. The fault is logged where it is settled, and the moment a subscription goes live is logged by the registry that records it.
- Do not answer a failed read with a `NOTICE`, and do not leave the subscription registered.
- Do not settle a fault, or decide whether a read may still send, by subscription id: the id is the client's, and a replacing `REQ` reuses it. Do not compare by value either, since a client may send the same `REQ` again. And do not ask once per read instead of once per frame.
- `InMemorySubscriptionRegistry` keeps each subscription as its `REQ` registered it beside the subscription in its current state, because going `Live` replaces the instance it holds. That second map is what `holds` reads, and it is not redundant.
- A frame already being written when a `REQ` replaces the subscription cannot be recalled, so at most that one frame of the old read follows the `REQ`. If it is the `EOSE`, it still marks the subscription on that id `Live` (ADR-0031), which is reported state only.
- Shared decision: nostr-adrs ADR-0032.
