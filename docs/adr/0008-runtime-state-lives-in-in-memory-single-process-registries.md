# 8. Relay runtime state lives in in-memory, single-process registries behind narrow role interfaces

## Status

Accepted

## Context

A relay is a long-lived process whose core job is to hold runtime state: which clients are connected (and their live sockets), which subscriptions each client has open, and which clients have authenticated to which identities. That state changes on nearly every inbound frame and is read on nearly every operation.

It cannot be threaded purely through function arguments. Independent WebSocket frames arrive in separate fibres with no shared call stack, so the connection set, the subscription index, and the auth sessions must outlive any single call and be reachable from all of them. Something has to hold mutable state.

This collides with the default shape used everywhere else — pure functions over immutable data, side effects pushed to the edges — so the holders read like a smell: mutable `array` fields on long-lived service objects, injected into use cases as collaborators. A reviewer reaching for the default shape is tempted either to "purify" them away, or to hide them behind a swappable persistence port (a repository), on the assumption that any in-memory store is a placeholder for a "real" one.

Two facts make that temptation wrong here:

- The connection registry holds live socket objects. A socket cannot be serialised to an external store or to another process; it exists only in the process that accepted it. There is exactly one correct implementation, and it is in-memory and in-process. A persistence-port abstraction over it would be ceremony with no second implementation it could ever have.
- The relay is single-process and cooperatively scheduled. Mutations between suspension points are atomic, so the data-race hazard that the "no shared mutable state" default guards against does not arise. The state is the imperative shell around a pure decision core — policy, scope filtering, validation — which is where that default already earns its keep.

A separate question is what these holders should *expose*. One of them had grown two responsibilities — a registry of clients **and** the act of sending to a client's socket — so a caller that only needed to send a message had to depend on the entire registry.

## Decision

Relay runtime state lives in in-memory registries (`ClientManager`, `SubscriptionManager`, `AuthenticationManager`), held for the lifetime of the process and injected as collaborators. They are **not** placed behind swappable persistence ports: in-memory and in-process is the one correct implementation for a single-process relay holding live connections.

Callers depend on **narrow role interfaces that name what they need**, not on the concrete registries:

- `ClientMessengerInterface` — sending a message to a client. The act of sending is a distinct collaborator (`ClientMessenger`) from the registry that stores connections; the call sites that only send depend on this alone.
- `ClientRegistryInterface` — the client lookup, removal, and per-session accounting the application needs.
- `SubscriptionLookupInterface` — the subscription reads the application needs.

The concrete registries implement these interfaces. Lifecycle entry points in infrastructure (the connection handler, the relay instance) use the concrete classes directly, because wiring and lifecycle are infrastructure concerns.

## Consequences

- The registries are the deliberate stateful shell; the decision logic they surround stays pure. Their mutable `array` fields are not an oversight — they are the state the process exists to hold.
- A reviewer must not "purify" a registry into argument-threaded values (there is no call stack to thread through), nor wrap one in a persistence port to make it swappable (the connection registry cannot be externalised, and the others have no second implementation in a single-process relay). Either change adds indirection that buys nothing.
- Each caller depends on the smallest interface it needs, so a use case that only sends cannot reach into client lifecycle, and a test can stub one capability without standing up a registry.
- Sending and storing are separated: `ClientMessenger` resolves a connection from the registry and records the send; the registry itself never sends. Do not re-merge them — the split is what lets the send-only callers depend on sending alone.
- Scaling the relay beyond one process (sharing state across nodes) is out of scope. It would require modelling subscriptions and auth sessions as externalisable data, a different decision to be recorded separately if it is ever taken.
