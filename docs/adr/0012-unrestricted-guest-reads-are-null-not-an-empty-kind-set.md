# 12. Unrestricted guest reads are null, not an empty kind set

## Status

Accepted

## Context

`GuestFilterRules` scopes what a guest may read to a set of readable kinds. A relay that places no kind restriction on guests is a legitimate configuration — the built-in `RelayPolicy` produces it when no `guest.read` section is configured — so "every kind" has to be expressible.

It was expressed as an empty `EventKindCollection`. That reads naturally inside the class, where an empty set short-circuits every kind check, but it gives the empty set the opposite of its plain meaning, and a consumer has no way to say "guests read nothing".

That matters as soon as the set is operator-editable. An embedding relay that lets an administrator edit the readable kinds at runtime reaches the empty set by the most ordinary route there is: the administrator clears the field to lock guest reads down. Under the sentinel that opened every stored kind to every guest instead, including kinds the embedding relay had deliberately kept out of the set for privacy. A kind list parsed leniently from untrusted input has the same shape of failure: dropping a malformed entry is supposed to narrow what is allowed, and dropping the last one widened it to everything.

## Decision

`GuestFilterRules` and `GuestPolicy` take `?EventKindCollection $readableKinds`.

- **`null` means unrestricted**: no kind constraint is applied, and no filter is beyond scope on account of its kinds.
- **A collection means exactly its members plus the global kinds**, and an empty collection therefore reads nothing but the global kinds. A filter naming any other kind is narrowed to none and is beyond scope.

`RelayPolicyConfig` maps an **absent** `guest.read` section to `null`, which keeps the built-in policy's behaviour for a relay that never configured guest reads. A section that is present reads exactly the kinds its rules list: one that lists none, or none that parses, becomes an empty collection and reads nothing. Unrestricted is never inferred from a list being empty, at any level.

## Consequences

- The analyser makes every reader of the readable set handle the unrestricted case explicitly; it can no longer be reached by accident through an empty list.
- An embedding relay can express "guests read nothing", and clearing an operator-editable kind list narrows rather than widens.
- Do not collapse `null` and empty back together "to simplify the type". They are opposites, and the conflation is what this record removes.
- Constructing `GuestFilterRules` or `GuestPolicy` with an empty collection to mean "everything" is a breaking change for any caller that relied on it: pass `null`.
