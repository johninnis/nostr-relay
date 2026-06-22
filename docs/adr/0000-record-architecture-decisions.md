# 0. Record architecture decisions

## Status

Accepted

## Context

Architectural decisions and the reasoning behind them are easily lost. Once the rationale is gone, a later reader cannot tell a deliberate choice from an accident, and "corrects" a design that was right — reintroducing the very bug it was written to avoid.

The rationale must travel with the repository, survive the people who wrote it, and be the first thing a reviewer reads. User-facing documentation is the wrong home for it: a `README.md` explains how to use the package, not why it is shaped the way it is, and it is neither immutable nor read before the code is judged.

## Decision

We record architecture decisions in immutable, sequentially numbered records under `docs/adr/`, following Michael Nygard's convention. Each record has exactly four sections: **Status**, **Context**, **Decision**, **Consequences**.

A record is never edited to change its decision; it is superseded by a later record, and its Status is set to `Superseded by ADR-NNNN`. Revisiting a decision means writing a new record, not rewriting history — the history is the point.

Where a decision reads like a smell at the call site, the code carries a one-line Chesterton's-Fence comment pointing at the record — `// Deliberate: … — see ADR-NNNN` — backed by a test that fails if the design is undone. The comment, the test, and the record together are what stop a well-meaning refactor.

## Consequences

- Design rationale lives in `docs/adr/`, alongside the code, and survives the people who made it. It is not inlined into the `README.md`, which stays user-facing documentation.
- A reviewer reads `docs/adr/` before judging the code, and does not "fix" anything a record justifies. Disagreement is expressed by superseding a record, not by silently undoing it.
- Every non-obvious decision earns a record; only a choice no competent engineer could mistake for a mistake goes unrecorded. The cost is the discipline of writing one.
