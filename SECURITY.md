# Security

This document describes the security properties `innis/nostr-relay` provides, the properties it deliberately leaves to the host, and the reasoning behind the non-obvious decisions. It is the reference an operator should read before exposing a relay built on this library to the internet, and the reference a contributor should read before changing any admission, authentication or resource-limiting path.

This library is the relay engine. It does not own the socket's TLS, the process supervisor, or the event store, so several of the guarantees an operator needs are the host's to provide. The split is stated explicitly below.

## Audit status

**This library has not undergone an independent third-party security audit.** It is built and reviewed with care, with the design decisions recorded in [`docs/adr/`](docs/adr/) and a soak harness that drives hostile client behaviour against the registries on every CI run, but internal review is not a substitute for an external audit. A relay is an internet-facing service that strangers connect to by design; treat these guarantees as best-effort and not externally verified. If you commission or perform an audit, please share the results through the vulnerability-reporting channel below.

## Reporting a vulnerability

If you have found a security vulnerability in `innis/nostr-relay`, report it privately through GitHub's built-in vulnerability reporting: **Security → Advisories → Report a vulnerability** on the repository page. Do not open a public issue for security-sensitive bugs.

Include:

- A description of the vulnerability and its impact.
- Reproduction steps or a proof-of-concept.
- The affected version (tag or commit SHA).

Acknowledgement is best-effort within 72 hours. Fixes land first, then the advisory is published.

For non-security bugs (typos, error-message tweaks, protocol-parsing issues that do not affect admission or authentication), open a regular issue.

This project does not run a bug bounty.

## Supported versions

Only the latest tagged release is supported. Older releases do not receive backported fixes, ever. Use the latest version.

## Security properties

### What this library provides

- **Every connection is bounded before it is trusted.** A WebSocket frame larger than 128 KiB is refused by the parser. A connection that sends nothing for 300 seconds is closed. The client registry refuses a new connection once the configured maximum is reached, and the refusal names the address.
- **Resource limits apply to every client that has not authenticated as a tenant, including on an open relay.** Rate limiting, the subscription cap and the filter cap are gated on tenancy alone, never on whether the relay has tenants configured. An open relay is the most common public deployment and is exactly where these matter, so it gets them. See [ADR-0027](docs/adr/0027-resource-limits-apply-to-every-client-and-the-read-ceiling-to-every-client.md).
- **The read ceiling binds everyone, including a tenant.** Every filter is clamped to the configured `max_query_limit` before the policy asks who is asking, so no single subscription can ask the store for an unbounded result. The configured value must be at least 1 and is checked as the config is parsed: `RelayPolicyConfig::tryFromArray()` returns `null` for a value below it, which is the refusal a host already handles, and `SubscriptionLimits` asserts the same for a caller constructing it directly. Either way a misconfiguration is refused at startup rather than failing every request. See [ADR-0024](docs/adr/0024-a-relay-sets-its-own-read-ceiling-and-per-filter-value-ceiling-and-the-value-ceiling.md).
- **The per-filter value ceiling binds everyone, including a tenant.** The filter library holds any number of values, so `max_filter_values` (default 5000) is what stops one filter from naming more values than a store query can bind. A filter above it is refused with `blocked: too many values in one filter (max N)` before the policy asks who is asking. A host whose store binds fewer parameters sets it lower. See [ADR-0024](docs/adr/0024-a-relay-sets-its-own-read-ceiling-and-per-filter-value-ceiling-and-the-value-ceiling.md).
- **A COUNT costs no more than the REQ it could have been.** COUNT is admitted through the same subscription cap as REQ, and a store that stops counting at a ceiling reports the number as approximate, so a client cannot mistake a ceiling for a total. See [ADR-0006](docs/adr/0006-count-and-req-share-one-subscription-cap.md) and [ADR-0013](docs/adr/0013-a-store-may-count-approximately-and-the-reply-says-so.md).
- **An AUTH frame cannot be used to make the relay do expensive work.** The challenge is looked up and the cheap NIP-42 checks run before the signature is verified, so a sender who was never issued a challenge cannot force a signature verification. See [ADR-0018](docs/adr/0018-a-signature-is-verified-once-the-cheap-checks-allow-it.md).
- **A refusal does not enumerate the operator's keys.** The policy is asked whether a key may authenticate only after the signature has verified, so an unauthenticated sender cannot claim a pubkey and learn from the answer whether the relay treats it as a tenant.
- **Challenges are compared in constant time and cannot be empty.** A challenge is a value object that refuses the empty string and compares with `hash_equals`, which closes the case where an unset challenge would have matched an absent one.
- **Rate-limit state cannot grow without bound.** The token-bucket limiter evicts stale buckets on a timer and enforces a hard ceiling on how many it will hold, so an attacker cycling source addresses cannot exhaust memory through the limiter.
- **An expired event is refused on arrival and withheld from a stored stream.** Expiry is judged against an injected clock, never the wall clock. See [ADR-0014](docs/adr/0014-an-expired-event-is-refused-at-admission-and-withheld-from-stored-streams.md).
- **A fault in one client's request cannot take down the shared event loop.** Anticipated refusals are returned as values the analyser forces the caller to handle, and the router and connection handler catch any unexpected throwable and degrade it to a notice or a logged disconnect. See [ADR-0015](docs/adr/0015-anticipated-outcomes-are-returned-and-framed-by-the-use-case.md).

### What this library does not provide

- **TLS.** The library serves plain HTTP and WebSocket. Terminate TLS in a reverse proxy. Without it, everything below is moot: an AUTH event, a challenge and every event a client reads are on the wire in clear.
- **The client's real address.** When the relay sits behind a proxy, the address it sees is the proxy's unless the host supplies the trusted-proxy configuration. Rate limiting and connection caps key on that address, so a misconfigured proxy collapses every client into one bucket, or lets a client forge its own address. Configure it.
- **A durable event store.** The shipped `InMemoryEventStore` keeps everything in process memory and matches linearly. It exists to run and exercise a relay locally. A deployment supplies its own implementation, and that implementation owns its own injection safety, its own query cost and its own ceilings.
- **Any guarantee about a host-supplied policy.** A host implementing `RelayPolicyInterface` itself owns the scoping and the ceiling its `filterForClient` applies. There is no backstop behind it: a host that bounds nothing gets unbounded reads.
- **Persistence of runtime state.** Connections, subscriptions, challenges and authenticated sessions live in memory in one process. Restarting the process forgets them, and running two processes does not share them. See [ADR-0008](docs/adr/0008-runtime-state-lives-in-in-memory-single-process-registries.md).
- **Protection against a malicious tenant.** A tenant is an operator identity the host has explicitly authorised. It is exempt from rate limiting and the concurrency caps by design. Do not configure a key as a tenant unless you would hand that key the relay.
- **Spam or abuse filtering.** The library enforces resource limits and the policy's access rules. Deciding that a well-formed, correctly-signed event is unwanted is the host's job.

## Design decisions

### Resource limits are decoupled from access openness

An earlier shape short-circuited both the access checks and the resource limits when no tenants were configured, so the most common public deployment applied no rate limit and no caps to anonymous clients. The two are now independent axes: openness decides access, and never waives a resource limit. Do not re-add the openness check to the rate-limit exemption to stop bothering trusted users, because an open relay has no trusted users. See [ADR-0027](docs/adr/0027-resource-limits-apply-to-every-client-and-the-read-ceiling-to-every-client.md).

### The AUTH challenge is an offer, never a connection gate

A challenge is issued only when a request exceeds the guest scope the policy defines. Gating every connection would be the obvious secure default and would break the large number of clients that do not implement NIP-42, without buying anything: the scope the client gets without authenticating is the scope the policy already decided to give a stranger. See [ADR-0004](docs/adr/0004-auth-challenge-only-on-scope-exceeding-request.md).

### The NIP-42 checks run against an unverified event

This reads like trusting unverified input and is not. Nothing is granted on the strength of those checks. They decide only whether the event is worth the cost of verifying, and the signature is verified before anything the sender claims is acted on. See [ADR-0018](docs/adr/0018-a-signature-is-verified-once-the-cheap-checks-allow-it.md).

### A re-stream after authentication spends no rate-limit token

When a client authenticates, its already-open subscriptions are re-evaluated at the wider scope. That re-evaluation replaces subscriptions the client already holds rather than asking for new ones, so it takes no fresh token and discounts the client's own subscription from the cap. A client cannot use it to obtain more than it is entitled to, because it receives the same number of subscriptions it already had. See [ADR-0016](docs/adr/0016-authentication-restreams-open-subscriptions-as-a-readmission.md).

### An idle connection is closed on a receive timeout, not a liveness ping

The timeout is on receiving anything at all. A connection holding a subscription and receiving no matching events is still idle by this definition and will be closed, which is the intended trade: a socket costs the relay whether or not the client is waiting politely. Clients that want to stay connected send something. See [ADR-0005](docs/adr/0005-idle-connections-closed-after-a-fixed-timeout.md).

### Refusals are values, not exceptions

Every anticipated refusal is returned as a typed value the analyser forces the caller to handle, so a forgotten refusal is a build failure rather than a silently admitted request. Thrown faults are reserved for broken invariants, and are contained at the connection boundary so one client cannot take down the loop. See [ADR-0015](docs/adr/0015-anticipated-outcomes-are-returned-and-framed-by-the-use-case.md).
