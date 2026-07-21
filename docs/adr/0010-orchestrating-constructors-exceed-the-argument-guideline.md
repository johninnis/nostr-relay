# 10. Orchestrating, composing and dispatching constructors coordinate more than three collaborators

## Status

Accepted

## Context

A guideline enforced by the analyser flags any constructor taking more than three arguments as a design signal: usually the unit has taken on too many responsibilities and should be split. The guideline is a heuristic for *accidental* sprawl, and for most classes it is right.

A handful of units in this relay exceed three arguments without being sprawl, because their single responsibility genuinely is to *coordinate* several distinct collaborators:

- **Use-case orchestrators** (`ProcessAuthUseCase`, `ProcessEventSubmissionUseCase`, `CreateSubscriptionUseCase`) run one linear flow that admits a request, hands off to a pipeline or registry, frames the wire reply, and records telemetry. Each collaborator is used once, for a different concern; there is no cohesive value the arguments form, and no sub-responsibility to extract that would not just relocate the same collaborators.
- **A message dispatch table** (`ClientMessageDispatcher`) holds one use case per protocol verb plus the deserialiser and a logger. The breadth *is* the protocol.
- **The composition root** (`RelayServerFactory`) assembles the object graph. Wiring N objects is the definition of a composition root, not a smell.
- **Assembled aggregates and framework adapters** (`RelayInstance`, `RelayRequestHandler`, `ClientConnectionHandler`) hold the parts they expose or the framework objects they wire.
- **Small registries and services** (`InMemoryClientRegistry`, `EventDistributor`, `ClientDisconnectionHandler`, `AcceptedEventPipeline`) coordinate three ports plus a cross-cutting logger, or three collaborators plus a scalar bound.
- **A `Throwable` subclass** (`ConnectionException`) mirrors the native `(message, code, previous)` constructor and adds one context field; the shape is fixed by the language, not chosen.

The two tempting "fixes" both make the design worse. Bundling the arguments into a parameter object hides the responsibility count behind a struct of unrelated fields — the smell the guideline exists to surface, now concealed. Splitting a linear orchestration into per-step thin wrappers adds indirection and duplicated forwarding boilerplate without removing a responsibility from anywhere.

## Decision

These constructors deliberately take more than three arguments. Each argument is a distinct, irreducible collaborator with no cohesive value object to carry it and no responsibility that could be extracted rather than relocated. Each such constructor carries a one-line fence pointing at this record.

The guideline still governs everything else: a constructor that exceeds three arguments and is *not* one of these coordination/composition units is sprawl and must be decomposed, not fenced.

## Consequences

- The analyser flags these constructors; the fence marks each as a reviewed, deliberate exception rather than an oversight.
- A reviewer must not "reduce the count" on a fenced constructor by bundling its arguments into a parameter object (which hides the responsibility count) or by distributing them across per-type thin wrappers (which adds forwarding boilerplate). Both are forbidden; the argument breadth is the honest shape of a unit whose one job is to coordinate.
- Adding a collaborator to a fenced unit is not automatically acceptable: the test is still "is each argument a distinct collaborator this unit must coordinate?" If a new argument is really a second responsibility, the answer is to split the unit, not to widen the fence.
- The "split, don't widen" test has teeth, and this record has been enforced against itself. When a soak harness needed to drive client sessions without a websocket, `ClientConnectionHandler` was audited and found to have drifted past its adapter role: it held the client registry, disconnection handler and message router directly, coordinating the session lifecycle itself rather than merely adapting a socket to it. The lifecycle was extracted into `ClientSessionCoordinator` — a transport-agnostic three-collaborator unit that needs no fence — leaving `ClientConnectionHandler` a genuine framework adapter (session coordinator, IP gate, logger, idle-timeout bound) whose fence now marks real adapting rather than concealed sprawl. The coordinator is exposed on `RelayInstance` as one more assembled part, so any non-websocket embedder can drive real sessions over the factory-built graph.
