# 31. A subscription is live once its end of stored events is sent

## Status

Accepted

## Context

A subscription has two phases: the stored events matching its filters are streamed, and from some moment on it receives live events as they arrive. The registry tracks that transition as a state (`Active` → `Live`), and the state matters: distribution and re-evaluation treat a live subscription differently from one still catching up.

The transition used to be flipped by `StoredEventStreamer`, immediately after it sent the `EOSE` frame. That is the right *moment* — NIP-01 defines EOSE as "the end of stored events", after which "all events all subsequent events will be sent" — but the wrong *place*: the streamer had to hold the subscription registry solely for the flip, and any other code path that ever sent an EOSE would have to remember to flip it too. A state transition that depends on every caller remembering it is one caller away from a lie.

## Decision

A subscription is marked `Live` at the moment its EOSE is written to the client, by the thing that writes it. `LiveMarkingClientMessenger` decorates the shared `ClientMessengerInterface`: every frame passes through unchanged, and an outgoing `EoseMessage` additionally marks that client's subscription `Live` in the registry, after the send.

## Consequences

- The state can never disagree with the wire: a subscription is Live exactly when the client has been told stored events are done. Do not flip the state anywhere else — a second flip site is a second way for the registry and the wire to drift.
- The streamer no longer holds the registry, and a future EOSE sender needs no reminder: the marking is a property of the delivery path, not of any caller.
- The flip happens only after the send succeeds, so a disconnected subscriber is not marked Live on the strength of an EOSE it never received.
