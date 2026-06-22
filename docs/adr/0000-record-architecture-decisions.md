# 0. Record architecture decisions

## Status

Accepted

## Context

Architectural decisions and the reasoning behind them are easily lost. Once the rationale is gone, a later reader cannot tell a deliberate choice from an accident, and "corrects" designs that were right.

## Decision

We record architecture decisions in this directory, one immutable file per decision, numbered sequentially. Each record has exactly four sections: **Status**, **Context**, **Decision**, **Consequences**.

A record is never edited to change its decision; it is superseded by a new record, and its Status is set to `Superseded by ADR-NNNN`.

## Consequences

- The reasoning behind each decision is preserved alongside the code and survives the people who made it.
- Revisiting a decision means writing a new record, not rewriting history.
