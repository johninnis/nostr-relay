# 37. The store contract ships as cases a host runs against its own store

## Status

Accepted

## Context

The store a host supplies now carries rules the relay used to apply itself: it confines gift wraps to the reader's keys on every filter before that filter's `limit` (ADR-0036), and it deletes the gift wraps addressed to a deletion's signer while never deleting a deletion (ADR-0038). A store that gets either wrong fails silently. It serves every wrap to anyone, fills a page with wraps it then withholds, or leaves a recipient's wraps on the relay after the recipient deleted them. Nothing in the relay can notice, because the relay no longer re-reads what the store answers.

The library cannot test a host's store, but it can hand the host the cases a store must pass. Its tests live under `tests/`, which is `autoload-dev`, so nothing there is autoloadable for a consumer. Three ways to ship the cases look obvious, and each has a cost:

- An abstract PHPUnit test case in `src/` puts a development dependency in the production autoload, and shares behaviour by inheritance, which nothing else in this codebase's tests does.
- A separate package must be released in step with the port it tests, and a host could run cases written for another version of the port.
- Mapping all of `tests/` into `autoload` exposes every test and fixture of the library to its consumers.

## Decision

- The cases live in `tests/Contract`, namespace `Innis\Nostr\Relay\Tests\Contract`, and that directory alone is mapped in `composer.json`'s `autoload`. The rest of `tests/` stays `autoload-dev`. The namespace keeps its `Tests` segment because the code is test code.
- `tests/Contract` ships in the package's dist archive. Nothing may export-ignore it, through `.gitattributes` or `composer.json`'s `archive.exclude`: a consumer's autoload maps the namespace to that directory, so a host installing from the dist archive would be handed a class it cannot load, and a path-linked consumer would never notice. `ContractArchiveTest` fails if an `export-ignore` rule or an `archive.exclude` entry covers the directory.
- `RelayEventStoreContract::cases()` returns the named cases in the shape a PHPUnit data provider takes, `array<string, array{RelayEventStoreContractCase}>`. Each `RelayEventStoreContractCase` holds the events to store, one operation (a read, a count, or a delete followed by a read-back of what remains) and the answer the contract requires, as event ids in order, a number, or the ids still stored. A case is built in two steps: the operation with its answer (`reading`, `counting` or `deleting`, each given the filters or ids, the acting keys and the answer), then the events it stores (`given`).
- `RelayEventStoreContractCase::observe(RelayEventStoreInterface)` stores the case's events in the store it is handed and returns that store's answer, and `getExpected()` returns the required one. Neither names a PHPUnit type.
- A host runs the contract with one test: a data provider returning `RelayEventStoreContract::cases()`, and one assertion that `observe()` on a fresh store gives `getExpected()`. The library runs `InMemoryEventStore` and `LoggingEventStore` through it the same way.
- The cases cover the read contract (gift wraps confined on every kind of filter, for a reader with no keys, one key or several; confined before the `limit` of a filter naming no kind, of one mixing kinds, of an `ids` lookup and of an `authors` filter; every other event unaffected; newest first and the lower id first at a tie, also at a `limit`; each filter bounded by its own `limit`; filters that overlap returning each event once), the count contract (exact for the reader, ignoring each filter's `limit`, and counting each event filters that overlap match once, as NIP-45 says: "Multiple filters are OR'd together and aggregated into a single count result.") and the delete contract (ADR-0038: the deleter's own events and the gift wraps addressed to it, by any of the keys a wrap names and by its signer; never another kind of event that merely tags the deleter, a wrap addressed to another key or a deletion; and never an event, of the deleter's or addressed to it, that the deletion does not name).

## Consequences

- A host proves its store keeps the contract in a dozen lines. A store that confines after the `limit` of any of those filter shapes, ignores the reader on a read or a count, returns an event twice, counts an event twice, deletes only an author's events, deletes an event of another kind that merely tags the deleter, or deletes beyond the ids it is handed fails a named case. Each of those stores was built and run through the cases, and each failed one.
- The cases ship with the port they test, so a host always runs the contract of the version it installed.
- The cases build events with fixed keys and a placeholder signature. A store never verifies an event it is handed, because the relay verified it at admission, so every store can run them.
- The production autoload knows one test namespace. Nothing loads from it unless a test refers to it.
- Because it ships, the code is held to the rules production code is held to, whatever exemption its `Tests` namespace earns from the analyser: no helper takes more than three arguments, which is why a case is built in two steps rather than handed its events, its operation and its answer at once.
- When the port's contract changes, the cases change in the same release. Do not move them into `src/`, do not turn them into an abstract test case a host extends, and do not export-ignore `tests/` or `tests/Contract` from the dist archive.
