# 26. Orchestrating constructors decompose into named units, never fences

## Status

Accepted. Supersedes ADR-0010.

## Context

ADR-0010 held that a handful of units — the use-case orchestrators, the dispatch table, the composition root, the assembled aggregates, the small registries — exceeded the three-collaborator guideline *deliberately*, because coordination was their single responsibility, and it fenced each constructor instead of splitting it. Coding-standards 0.2.1 then made the limit mechanical: `innis.tooManyParameters` fails the build at four arguments, takes no fence, and exempts only value construction. Every exception ADR-0010 catalogued became a build failure.

ADR-0010's premise was also weaker than it looked. It claimed no sub-responsibility could be extracted "that would not just relocate the same collaborators". The decomposition that replaced it found, in nearly every case, a cohesive unit hiding inside the coordination:

- `ProcessAuthUseCase` (7 collaborators) held the whole NIP-42 handshake — challenge retrieval and issuance, claim and identity verification — which is one concept and is now `Nip42Handshake`, leaving the use case to order claim → signature → identity (ADR-0018) and frame replies.
- `ClientMessageDispatcher` (6) held the protocol verb table as constructor slots; the table is data, and is now `ClientVerbHandlers` over `ClientVerbHandlerInterface`, leaving the dispatcher to parse, route, count inbound events and own the fault boundary (ADR-0030).
- `EventDistributor` (5) held "who may receive this event" — policy, subscription lookup, client registry — which is now `EventAudience`, leaving the distributor to encode once and fan out.
- `StoredEventStreamer` (6) held "what this client may read now" — store, policy, clock — which is now `StoredEventReadGate`, leaving the streamer to send; its Live-state flip turned out to belong to the moment the EOSE is written (ADR-0031).
- `SubscriptionActivator` (5) held registration-plus-deferral, now `RegisteringStoredEventStreamer`, and refusal settlement, now `SubscriptionAnswers`.
- `ProcessEventSubmissionUseCase` (5) held the challenge offer, which is a property of the admission answer itself (ADR-0029), and the received-event count, which is a property of an inbound EVENT message and lives at the dispatcher.
- `AcceptedEventPipeline` and `ClientDisconnectionHandler` (4 each) were pushed over only by a logger; the store-outcome logs moved to a `LoggingEventStore` decorator at the decision point, and the disconnect log moved to the registry that owns the client set.
- `InMemoryClientRegistry` (3 + scalar) bounded its own set; the bound is enforced by `CappedClientRegistry` decorating the registry at registration, the single allocation point (ADR-0032).
- `RelayServerFactory` (11) took every port the graph needs at construction; the host's irreducible ports are the store, the policy and the relay config — everything else has a sane default and is named `with*()` wiring, the same shape the client library records for optional handlers.
- `ConnectionException` mirrored the full `Throwable` signature, but nothing ever passed a code; the parameter was deleted rather than fenced.

## Decision

A constructor over three collaborators is decomposed, never fenced and never exempted. The decomposition follows the shapes above: an outcome-deciding cluster becomes a named gate or answer object, a fan-out cluster becomes a named audience or read gate, wiring breadth becomes a handler table or named `with*()` wiring, and a cross-cutting concern (logging, Live-marking, deferral, capping) becomes a decorator or a gate at the layer that owns the moment.

## Consequences

- The analyser's finding is always acted on, so its signal stays trustworthy: a future constructor that trips it is sprawl to split, not a candidate for a fence.
- Each extracted unit is named for the one question it answers, and is testable in isolation — the new unit suites pin the handshake, the audience, the read gate, the refusal settlement and the cap without standing up the whole graph.
- Do not re-introduce a fence comment as a way to keep a fourth collaborator: the record that sanctioned fences is superseded, and a fence on a design rule no longer builds.
- The logger is a collaborator like any other. When it is the fourth argument, the logs belong somewhere more precise — the unit where the logged event actually occurs — not the logger dropped and the record lost.
