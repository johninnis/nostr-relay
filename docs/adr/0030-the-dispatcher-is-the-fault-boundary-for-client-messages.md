# 30. The dispatcher is the fault boundary for client messages

## Status

Accepted

## Context

Every use case used to end in an identical `catch (Throwable)`: log the error and answer `error:` in the verb's wire shape — `OK` for EVENT and AUTH, `CLOSED` for REQ and COUNT, silence for CLOSE. The catch is a fault boundary, and it was duplicated at five call sites with five near-identical bodies; each use case carried a logger largely to service it.

The duplication also blurred a distinction the error-handling rules depend on. An *anticipated* failure — an invalid event, a policy rejection — is a returned outcome, framed by the use case that knows the verb (ADR-0015). A *fault* — a broken store, a programming error — is not anticipated by any verb; there is one correct response to it per wire shape, and one place that already knows every wire shape: the dispatcher that routes the message.

## Decision

`ClientMessageDispatcher` is the fault boundary for client messages. It catches any fault escaping a verb handler, logs it with the message type, and frames the `error:` reply in the verb's shape: `OK` for EVENT and AUTH, `CLOSED` for REQ and COUNT, silence for CLOSE. Use cases do not catch faults: they catch only the anticipated `InvalidEventException` of event validation, which is an outcome conversion at the input boundary, not a fault.

## Consequences

- One fault boundary, one log shape, one place the wire-mapping lives. Do not re-grow per-use-case `catch (Throwable)` blocks: a fault handled in five places is four places too many, and each copy is a boundary that can drift.
- Use cases keep framing *anticipated* rejections themselves (ADR-0015); only faults moved. The distinction is the test: if the use case expects the failure as part of the protocol, it frames it; if it cannot happen unless something is broken, the dispatcher does.
- A fault in a CLOSE handler produces no wire reply, because CLOSE has no reply shape — the log is the record.
