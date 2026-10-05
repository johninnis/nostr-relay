# 20. Event validation judges `created_at` against the injected clock

## Status

Accepted

## Context

nostr-core's event validator refuses an event whose `created_at` is too far from now, and it no longer decides what now is: `validateEvent` takes the reference instant as an argument (nostr-core ADR-0080). The relay has two callers. `EventAdmission` validates every submitted event, and `ProcessAuthUseCase` validates the AUTH event once its cheap checks have passed (ADR-0018).

The obvious move is to pass `Timestamp::now()` at each call site, which needs no new collaborator. `EventAdmission` already takes a clock for expiry (ADR-0014), but `ProcessAuthUseCase` does not, so adding one to it reads like an argument too many on a constructor that is already fenced (ADR-0010).

## Decision

Both callers hand the validator the instant their injected `ClockInterface` reports, never the wall clock. `EventAdmission` reads the clock once per event and uses that one instant for both the validity window and the expiry check, so an event cannot be judged valid against one "now" and unexpired against another. `ProcessAuthUseCase` takes the clock as a collaborator for this purpose. `RelayServerFactory` wires the same `SystemClock` into both, and into the NIP-42 validator, so every time decision in one relay has one source.

## Consequences

- A test can pin the `created_at` window at a fixed instant, for a submitted event and for an AUTH event alike.
- `ProcessAuthUseCase` takes one more collaborator. It is a distinct one, the source of time, and is covered by the same fence as the rest of its constructor.
- Do not replace the clock with `Timestamp::now()` at either call site to save the argument: the relay would then hold two notions of now, and the validity window could disagree with the expiry check and with the NIP-42 `created_at` check made moments before.
