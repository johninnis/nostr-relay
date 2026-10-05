# 32. The connection cap decorates the registry at the single allocation point

## Status

Accepted

## Context

The relay bounds concurrent connections (`RelayConfigInterface::getMaxConnections()`). The bound used to live inside `InMemoryClientRegistry::registerClient()`, which threw once its set was full; holding it made the registry's constructor carry a scalar beside its three collaborators, the one argument with nowhere cohesive to live. An earlier revision of this record moved the cap to the connection gate instead: a full relay looked like the same kind of "no" as a denied IP, decided at the same moment, and the registry shed the scalar.

That home failed in the ways a safety invariant fails when it is placed anywhere but the resource it protects. Enforcement at the gate holds only for callers that pass the gate: `ClientSessionCoordinator::open()` registers unconditionally, so any path to it that skips the handler — a test fixture, a second transport, a host-built connection path — runs uncapped, and a hostile-churn fixture did exactly that, registering 275 clients against a cap of 32 with no error anywhere. And the gate's check-then-register is atomic only by caller discipline: it holds today because no await sits between `isIpAllowed()` and `open()` in the connection handler, but nothing stops a future edit inserting one. A style metric had evicted a safety invariant; the invariant resurfaced as a bug.

## Decision

The cap is enforced at registration, the single allocation point every session must pass through. `CappedClientRegistry` decorates the `ClientRegistryInterface`: it refuses `registerClient()` with `ConnectionException::connectionLimitReached()` once the inner registry's count reaches the configured cap, and delegates everything else. The factory wraps once, so every collaborator that receives a registry receives the capped one; read-only introspection keeps the concrete in-memory registry it needs. The connection gate returns to its single job, the host's IP allow/deny policy.

## Consequences

- The invariant "registered ≤ cap" is enforced by the allocation point itself, so it cannot be bypassed: every registration path — the connection handler, the coordinator seam, any future transport — passes through it.
- The refusal count is derived from the inner registry's live count, never tracked in a side counter, so no claim/release bookkeeping can leak or drift; a removed client frees a slot by construction.
- Check-and-insert sit inside one synchronous method, so atomicity no longer depends on what callers do or might do.
- A full relay refuses with a distinct `connection limit reached` message rather than the gate's IP-block wording, so operators can tell capacity refusals from policy refusals.
- `InMemoryClientRegistry` keeps its three collaborators; the rule against long constructors is met by decomposing the concern into a decorator, not by relocating it somewhere weaker.
