# 18. An AUTH signature is verified once the cheap checks allow it, and the policy is asked after

## Status

Accepted

## Context

An AUTH frame is the most expensive thing a client can send. Answering one meant verifying a Schnorr signature before anything else, so any sender could make the relay do that work by writing a kind 22242 event with random bytes in the signature. Verification is the one operation here that costs real time: measured at 0.028 ms against the native library, and far more on the pure-PHP fallback this library also supports.

Nothing about that work depended on it being first. The NIP-42 checks read the event's own tags: the challenge it names, the relay it names, when it was written. They are string comparisons, and a frame that names no challenge this relay issued, or names another relay, is refused whatever its signature says. Only the client that was issued a challenge knows what it is, so checking that first means the expensive work is reached only by someone the relay has already spoken to.

The order of the remaining two matters for a different reason. It is tempting to ask the policy next, since deciding whether a pubkey may authenticate here is also cheap. That would leak: an unauthenticated sender could claim any pubkey and learn from the refusal whether this relay treats it as a tenant, enumerating the operator's keys without holding any of them.

## Decision

An AUTH frame is answered in three steps, cheapest first, and each is only reached if the one before it passed.

1. The challenge the relay issued this connection is looked up. Without one, a fresh challenge is issued and nothing else happens.
2. `AuthEventVerifier::verifyClaim()` runs the NIP-42 checks against the event as it stands. These read the event and nothing else, so they are safe to ask before the signature is known and they gate everything after.
3. The signature is verified, and only then does `AuthEventVerifier::verifyIdentity()` ask the policy whether this key may authenticate.

## Consequences

- A sender who does not hold a challenge cannot make the relay verify a signature, so the cost of an unwanted AUTH frame is a few comparisons.
- A refusal never tells an unauthenticated sender whose keys the relay trusts, because the policy is not consulted until the sender has proved the key is theirs.
- The NIP-42 checks run against an event whose signature is unknown. That reads like trusting unverified input and is not: nothing is granted on the strength of those checks, they only decide whether the event is worth verifying.
- Do not move the policy check back alongside the protocol checks to save a step. The two are separated by the verification precisely so that one cannot answer questions about the operator's keys to someone who has proved nothing.
- No budget is imposed on authenticating clients. A client that authenticates is one the policy admits, and the work it then causes, re-streaming its own subscriptions, is work it is entitled to.
