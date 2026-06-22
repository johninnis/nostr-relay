# 1. Value objects expose state through `getX()` accessors, not public properties or hooks

## Status

Accepted

## Context

PHP 8.4 makes it idiomatic to drop an accessor and expose state directly: a `public readonly` property for a plain carrier, or a property hook for a computed or write-controlled one. Applied to this package's value objects, that move would replace each `getX()` method with a public property, and a reviewer reaching for the modern idiom will be tempted to make exactly that change.

## Decision

Every value object and DTO exposes its state through `getX()` (or `toX()`) accessors over private properties. No public readonly state, no property hooks, no asymmetric visibility (`public private(set)`). The surface is uniform.

The decision is about keeping one stable, uniform surface — not about what the language happens to permit:

1. **A `getX()` accessor is a stable surface; a public property is not.** Binding an accessor to an interface, normalising its representation, or computing it on read later is a non-event behind `getX()` — no call site changes. The same change behind a public property is a breaking change to every reader. The accessor is the seam that keeps those future moves cheap and invisible, and they are not hypothetical: identity-bound (`equals()`), normalised (bech32/hex encodings), and computed (derived predicates) reads already exist across this ecosystem's value objects.

2. **Some reads can only be methods, so a uniform surface means all reads are methods.** A value computed or normalised on read, or bound to an interface, is not a stored property and cannot be a bare `public` field; and a `final readonly class` cannot carry a property hook at all, because a hook requires a non-readonly property. Those accessors are therefore methods no matter what. Exposing only the *plain* carriers as public properties would split the surface into two access styles — a bare `$vo->size` beside a `$vo->getHash()` call — for every reader and call site to navigate. One `getX()` style is the only internally consistent surface.

3. **The conversion is purely syntactic and buys nothing.** Replacing `getX()` with a property changes no behaviour and gives the analyser nothing it did not already have. It trades a uniform surface for a split one and rewrites call sites for no gain.

Collections differ in *shape*, not in principle: a typed collection exposes the iterable and countable surface (`IteratorAggregate`/`Countable`) plus `toArray()`, rather than a `getItems()` accessor.

## Consequences

- The value-object surface is uniformly `getX()`/`toX()`. Entity lifecycle state likewise stays behind a `getX()` method, mutated through named transformations that return a new instance — never a publicly-readable `private(set)` property.
- Setters do not arise: value objects are immutable and transform by returning a new instance.
- Do not "modernise" a `getX()` accessor into a public property or a hook. It fractures the access style, and turns a future interface-binding or computed read from a non-event into a breaking change across every call site.
