# innis/nostr-relay

[![CI](https://github.com/johninnis/nostr-relay/actions/workflows/ci.yml/badge.svg)](https://github.com/johninnis/nostr-relay/actions/workflows/ci.yml)

**AMPHP-based async WebSocket relay server for Nostr protocol**

A private, high-performance Nostr relay implementation designed to be embedded in PHP applications. Built with AMPHP for concurrent connection handling and clean architecture principles.

---

## Features

- **Interface-driven design** - Storage and policies provided by host application
- **AMPHP async** - Non-blocking concurrent connection handling
- **Private relay focus** - Built for single-user/controlled access scenarios
- **NIP-01 compliant** - EVENT, REQ, CLOSE message handling
- **NIP-09 deletion** - Kind 5 event processing
- **NIP-11 support** - Relay information document
- **NIP-40 expiration** - An already-expired event is refused, and an event that expires while stored is withheld from REQ results
- **NIP-42 AUTH** - Challenge/response authentication over nostr-core's `Nip42Validator`; a challenge is issued only when a request exceeds what the connection may read (guest scope, or a gift wrap addressed to a key it has not authenticated as), or is refused as `auth-required` while the connection holds none (never on connect). A claim failure is answered `invalid:` unless the client answered a challenge the relay has since replaced, which is answered `auth-required:`; an expired `AUTH` event is refused `invalid:` and authenticates nothing. Authenticating changes no open subscription: a client sends its `REQ` again to widen one (see [ADR-0033](docs/adr/0033-authentication-leaves-open-subscriptions-as-admitted.md))
- **NIP-67 EOSE hint** - A `REQ` narrowed to what a guest may read ends with an `AUTH` challenge and an `EOSE` carrying the `"auth"` hint; one narrowed to nothing is refused `CLOSED` `auth-required:`
- **NIP-59 / NIP-17 gift wraps** - A kind 1059 or 21059 event is served only to a connection authenticated as a key its `p` tag names, whatever the policy grants; a filter naming no kind matches no gift wrap (see [ADR-0035](docs/adr/0035-a-gift-wrap-is-served-only-to-the-key-it-names.md))
- **NIP-45 COUNT** - COUNT message support
- **NIP-70 protected events** - An event carrying a tag named `-` (NIP-70's `["-"]`, or the same name followed by any values) is admitted only from a connection authenticated as its author, whatever the policy; an unauthenticated connection is answered `auth-required`, preceded by a challenge when it holds none (see [ADR-0023](docs/adr/0023-a-protected-event-is-admitted-only-from-its-authenticated-author-whatever-the-policy.md))
- **Ephemeral events** - Kinds 20000-29999 skip storage
- **Host-owned HTTP server** - The relay is an `Amp\Http\Server\RequestHandler` you mount on your own `HttpServer`, so the host controls binding, middleware (CORS, forwarded headers, compression) and lifecycle, and serves its own routes on the same origin
- **NIP-11 metadata** - Served from a single `Nip11InfoProviderInterface`: the built-in `StaticNip11InfoProvider` for a fixed document, or a custom implementation to compute it at runtime
- **Built-in RelayPolicy** - Configurable tenant/guest permissions
- **Real-time distribution** - Events broadcast to matching subscriptions
- **Metrics** - `RelayInstance::getMetrics()` returns a `RelayMetrics` snapshot (active connections, events received/sent, subscriptions, start time) via the `MetricsCollectorInterface` port
- **Rate limiting** - DDoS protection with configurable limits; tenants (and trusted clients via `isRateLimitExempt()`) bypass
- **Idle timeout** - Connections with no inbound message for 5 minutes are closed, freeing the slot (see [ADR-0005](docs/adr/0005-idle-connections-closed-after-a-fixed-timeout.md))
- **PSR-3 logging** - Standard logging interface

---

## Requirements

- PHP 8.4 or higher
- `innis/nostr-core` - Core Nostr protocol entities
- `amphp/amp` ^3.0 - Async runtime
- `amphp/http-server` ^3.0 - HTTP server
- `amphp/websocket-server` ^4.0 - WebSocket server
- `psr/log` ^3.0 - Logging interface

---

## Installation

```bash
composer require innis/nostr-relay
```

---

## Quick Start

### 1. Implement Required Interfaces

The relay requires three interfaces from your host application — the store, the policy and the relay config. Everything else has a built-in default and is wired with a named `with*()` call on the factory:

- **`RelayEventStoreInterface`** - Event persistence and queries. Use the built-in `InMemoryEventStore` to run a relay locally or to give a test a real store; it keeps everything in process memory and matches linearly, so a deployment supplies a durable implementation. It implements the replaceable and addressable rules, so storing a newer version of such an event removes the one it supersedes and storing an older one is refused as `Superseded`. `findByFilters` returns a `StoredEventCollection`: each `StoredEvent` carries the event's `EventHeader` (id, author, kind, read from your indexed columns), its `created_at`, its earliest stated expiry, and the bytes you stored (`new StoredEvent($header, $createdAt, $expiresAt, $encoded)`). Return what the filters match newest first and, of two events created at once, the lower id first, as NIP-01 orders them, applying each filter's own `limit` to that order. The relay may ask for one filter group at a time; it puts what each query returns in that order again before it cuts a group to its limit and merges the groups, so a store that breaks ties otherwise never decides which event a limit keeps. Store `EncodedEvent::of($event)->toJson()` when `store()` is called, and hand those bytes back with `EncodedEvent::fromOwnStore()`; the relay streams them without parsing them, so nothing but that encoding may ever reach your store (see [ADR-0021](docs/adr/0021-stored-events-are-streamed-as-the-bytes-the-relay-wrote-trusted-by-provenance.md)). `countByFilters` returns nostr-core's `EventCount`: a store that stops counting at a ceiling reports the count as approximate, and the relay marks the NIP-45 reply accordingly (see [ADR-0013](docs/adr/0013-a-store-may-count-approximately-and-the-reply-says-so.md)). `deleteByCoordinates` carries the deletion's `created_at`: delete a coordinate target only when the stored event is at or before it, so a version published after the retraction survives (see [ADR-0028](docs/adr/0028-a-deletion-deletes-only-its-authors-earlier-events-and-never-a-deletion.md)). A fault your store throws while a subscription's stored events are read ends that subscription: it is logged and the client is answered `CLOSED` `error: could not read stored events`, with no `EOSE`. A read whose subscription the client has meanwhile replaced with a new `REQ` on the same id, or closed, sends nothing more, and its fault is only logged, so a store that suspends while it reads never closes or answers the replacement (see [ADR-0034](docs/adr/0034-a-fault-while-reading-stored-events-ends-the-subscription-with-closed-error.md)).
- **`RelayConfigInterface`** - The relay's own configuration: the relay URL (for NIP-42 AUTH verification), the maximum concurrent connections, and the `EventLimits` every submitted event is validated against (the longest content, the most tags and the `created_at` window; `new EventLimits()` gives innis/nostr-core's defaults). An event outside them is refused `invalid:`, a tenant's included; publish the same numbers as `max_content_length`, `max_event_tags`, `created_at_lower_limit` and `created_at_upper_limit` in your relay-information document (see [ADR-0025](docs/adr/0025-the-event-limits-are-the-relays-configuration-applied-by-the-event-validator.md)). The listening address and trusted proxies are configured on the `HttpServer` the host owns, not here.
Access control can use the built-in `RelayPolicy` or a custom implementation of `RelayPolicyInterface`. See [Implementing `RelayPolicyInterface`](#implementing-relaypolicyinterface) for how a policy signals a rejection.

Optional interfaces extend the relay's behaviour, each wired with the matching `with*()` call:

- **`RateLimitPolicyInterface`** - Per-minute rate-limit budgets keyed by `RateLimitMetric` (events, subscriptions). Defaults to the built-in `StaticRateLimitPolicy` (60 events, 20 subscriptions per minute); implement the interface to vary limits at runtime.
- **`Nip11InfoProviderInterface`** - The single source of the relay's NIP-11 document. Defaults to the built-in `StaticNip11InfoProvider` serving an empty document for the configured relay URL; wrap a fixed document in it, or implement the interface to project metadata at runtime (e.g. reflecting live policy).
- **`AuthenticationRegistryInterface`** - Challenge state and authenticated sessions. Defaults to the in-memory registry; implement it to share auth state across processes.
- **`ConnectionGateInterface`** - Decide whether an IP may connect, before the WebSocket session is established. Defaults to allowing every IP under the configured connection cap; implement it to enforce an allow-list or deny-list.
- **`MetricsCollectorInterface`** - Collect relay metrics (connections, events, subscriptions). Defaults to the in-memory `InMemoryMetricsCollector` exposed via `RelayInstance::getMetrics()`; implement it to export to an external monitoring system.

### 2. Create and Start the Relay

```php
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Nip11Info;
use Innis\Nostr\Core\Infrastructure\Crypto\NativeRandomBytesGenerator;
use Innis\Nostr\Relay\Application\Service\InMemoryAuthenticationRegistry;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Domain\ValueObject\RateLimitConfig;
use Innis\Nostr\Relay\Domain\ValueObject\RelayPolicyConfig;
use Innis\Nostr\Relay\Infrastructure\EventStore\InMemoryEventStore;
use Innis\Nostr\Relay\Infrastructure\Http\StaticNip11InfoProvider;
use Innis\Nostr\Relay\Infrastructure\RateLimiting\StaticRateLimitPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;

use function Amp\trapSignal;

$authenticationRegistry = new InMemoryAuthenticationRegistry(new NativeRandomBytesGenerator());
$logger = new \Psr\Log\NullLogger();

$policyConfig = RelayPolicyConfig::tryFromArray([
    'tenants' => ['your-hex-pubkey'],
    'guest' => [
        'read' => [
            ['kinds' => [0, 1, 6, 7, 30023], 'from' => 'tenants'],
        ],
        'write' => [
            ['kinds' => [7, 9735]],
        ],
    ],
]) ?? throw new RuntimeException('Invalid relay policy configuration');

$policy = new RelayPolicy($authenticationRegistry, $logger, $policyConfig);

$rateLimitPolicy = new StaticRateLimitPolicy(new RateLimitConfig(
    eventsPerMinute: 60,
    subscriptionsPerMinute: 20,
));

$config = new MyRelayConfig();

$nip11InfoProvider = new StaticNip11InfoProvider(Nip11Info::fromArray($config->getRelayUrl(), [
    'name' => 'My Nostr Relay',
    'pubkey' => 'your-hex-pubkey',
    'supported_nips' => [1, 9, 11, 40, 42, 45, 70],
]));

$factory = (new RelayServerFactory(
    eventStore: new InMemoryEventStore(), // swap for a durable store in a deployment
    policy: $policy,
    config: $config,
))
    ->withRateLimitPolicy($rateLimitPolicy)
    ->withAuthenticationRegistry($authenticationRegistry)
    ->withNip11InfoProvider($nip11InfoProvider)
    ->withLogger($logger);
```

The host owns the `HttpServer`, so it decides the listening address, middleware and lifecycle, and mounts the relay's request handler on it. Owning the server is what lets the host serve its own routes — a landing page, a management API, static files — on the same origin as the relay:

```php
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;

use function Amp\trapSignal;

$httpServer = SocketHttpServer::createForDirectAccess($logger);
$httpServer->expose(new InternetAddress('127.0.0.1', 8080));

$relay = $factory->create($httpServer);

$httpServer->start($relay->getRequestHandler(), new DefaultErrorHandler());
trapSignal([SIGINT, SIGTERM]); // start() is non-blocking; keep the event loop alive until interrupted
$httpServer->stop();
```

See [`examples/relay.example.php`](examples/relay.example.php) for a complete runnable relay: tenant/guest policy, rate limiting, NIP-11 metadata and a stderr logger, on the built-in `InMemoryEventStore`.

### 3. Configure Nginx

The relay does not handle TLS. Use a reverse proxy for SSL. If the proxy sets `X-Forwarded-For`, configure the trusted proxies on the `HttpServer` the host owns — amphp's `ForwardedMiddleware` honours the header only for a matching proxy address. The relay reads the client IP from the request the server hands it, so honouring forwarded headers from an untrusted source lets any client spoof their IP; only trust a proxy you control.

```nginx
upstream nostr_relay {
    server 127.0.0.1:8080;
}

server {
    listen 443 ssl;
    server_name relay.example.com;

    location / {
        proxy_pass http://nostr_relay;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Host $host;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }
}
```

---

## Policy Configuration

The built-in `RelayPolicy` accepts a configuration array that controls access for tenants and guests.

### Tenants

`tenants`: array of hex pubkeys or npub strings identifying relay owners. Tenants authenticate via NIP-42 and bypass all guest restrictions, along with the rate limits and the subscription and filter caps. `max_query_limit` and `max_filter_values` are the exceptions: every filter is clamped to the first and refused above the second whoever asks, so a tenant reading more than the ceiling pages through it with `until` (see [ADR-0027](docs/adr/0027-resource-limits-apply-to-every-client-and-the-read-ceiling-to-every-client.md)). If the array is empty or omitted, the relay operates as an open relay (all writes and reads allowed).

### Limits

Optional keys with sensible defaults:

- `max_subscriptions` - Maximum concurrent subscriptions per client. Also gates `COUNT` requests: a `COUNT` from a client already at the cap is rejected with `blocked: too many subscriptions` (see [ADR-0006](docs/adr/0006-count-and-req-share-one-subscription-cap.md)).
- `max_filters` - Maximum filters per subscription
- `max_query_limit` - The ceiling on how many stored events one filter may return. Every filter is clamped to it, and a filter stating no limit of its own is given it, so a client asking for more receives the ceiling rather than a refusal (see [ADR-0024](docs/adr/0024-a-relay-sets-its-own-read-ceiling-and-per-filter-value-ceiling-and-the-value-ceiling.md)). Any value from 1 is accepted; the relay, not the filter library, sets the ceiling. Publish it as `max_limit` in your relay-information document.
- `max_filter_values` - The most values one filter may hold, counted across its `ids`, `authors`, `kinds` and tag conditions (default 5000). A filter above it is refused with `blocked: too many values in one filter (max N)`, for a tenant as well as a guest, because it bounds what one store query binds; set it below your store's parameter limit (see [ADR-0024](docs/adr/0024-a-relay-sets-its-own-read-ceiling-and-per-filter-value-ceiling-and-the-value-ceiling.md)). NIP-11 has no field for it.

### Implementing `RelayPolicyInterface`

A policy answers "may this client do this?" by **returning** an outcome — it never throws to reject. `allowEventSubmission()` and `allowSubscription()` both return `?PolicyRejection`:

```php
use Innis\Nostr\Relay\Domain\ValueObject\PolicyRejection;

public function allowEventSubmission(RelayClient $client, Event $event): ?PolicyRejection
{
    if ($this->isSpam($event)) {
        return PolicyRejection::blocked('event rejected by content filter');
    }

    if (!$this->isAuthenticated($client)) {
        return PolicyRejection::authRequired('authentication required to publish this event kind');
    }

    return null;
}
```

- Return `null` to admit.
- Return `PolicyRejection::blocked($reason)` for a definitive refusal.
- Return `PolicyRejection::authRequired($reason)` when authenticating could change the answer. When the client holds no challenge yet, the relay sends it an `AUTH` challenge just before the refusal, so it can authenticate and retry.
- Return `PolicyRejection::rateLimited($reason)` when the client is asking too often, `PolicyRejection::invalid($reason)` when the event cannot be accepted on its own terms, and `PolicyRejection::restricted($reason)` when this relay is not open to the client at all.

The port has seven methods, and every one of them is yours to answer:

| Method | Answers |
|---|---|
| `allowEventSubmission()` | may this client publish this event |
| `allowSubscription()` | may this client open this subscription |
| `filterForClient()` | what this client may read, as a `ScopedFilters` narrowing of the filters it asked for (the relay then confines gift wraps to the client's own keys, whatever this returns) |
| `canClientReceiveEvent()` | may this client be sent this event, checked per event on delivery against its `EventHeader` (id, author, kind) — see [ADR-0022](docs/adr/0022-the-receive-check-sees-an-events-header-not-the-event.md) |
| `allowsAuthentication()` | may this key authenticate here, returning `?PolicyRejection` so the refusal carries your words rather than the library's |
| `isRateLimitExempt()` | is this client outside the rate limits and subscription caps |
| `offersAuthChallenge()` | should this admitted event still draw an `AUTH` challenge |

The relay frames the rejection as the wire reply that matches the client's message: `OK` for an `EVENT`, `CLOSED` for a `REQ` or `COUNT`. You do not construct wire messages yourself.

Rejections are returned rather than thrown so the analyser forces every caller to handle them — see [ADR-0015](docs/adr/0015-anticipated-outcomes-are-returned-and-framed-by-the-use-case.md). Genuine faults are still exceptions: a structurally invalid or badly-signed event raises `InvalidEventException` from the core validator, which the relay catches and reports as `invalid:`.

`offersAuthChallenge()` is separate from rejection: it lets a policy *admit* an event and still invite the client to authenticate. The challenge is sent just before the event's `OK true`, only once the store has accepted the event, so a duplicate is answered `OK false` `duplicate:` with no challenge (see [ADR-0029](docs/adr/0029-a-challenge-accompanies-every-auth-related-answer.md)). The built-in policy returns `false`.

### Rate-Limit Exemption

`RelayPolicyInterface::isRateLimitExempt()` lets the policy opt specific clients out of rate limits and subscription caps. The built-in `RelayPolicy` exempts authenticated tenants only; rate limits apply to every untrusted client, including on an open relay (see [ADR-0027](docs/adr/0027-resource-limits-apply-to-every-client-and-the-read-ceiling-to-every-client.md)). Implement `RelayPolicyInterface` directly to exempt other trusted clients — for example, internal services or IPs behind a trusted proxy.

### Guest Rules

Unauthenticated clients are treated as guests. Guest permissions are defined under the `guest` key:

**`guest.read`**: array of rules controlling what events guests can query. Each rule has:
- `kinds` (int array) - Event kinds the guest may read
- `from` (optional, `'tenants'`) - Restrict results to events authored by tenants

Guests may read every kind **only when `guest.read` is left out altogether**. Once the section is present, guests read exactly the kinds its rules list and nothing else — so an empty section, or rules listing no valid kinds, means guests read nothing (see [ADR-0012](docs/adr/0012-unrestricted-guest-reads-are-null-not-an-empty-kind-set.md)).

**`guest.write`**: array of rules controlling what events guests can publish. Each rule has:
- `kinds` (int array) - Event kinds the guest may publish
- `tagged_to_tenant` (optional, `true`) - Require the event to tag a tenant pubkey

If no config is passed, the relay is fully open with no restrictions.

### Authentication (NIP-42)

The relay does **not** challenge on connect. It issues an `AUTH` challenge only when a subscription requests something outside the guest's scope — when the requested kinds aren't guest-readable, when the requested authors aren't tenants (under `from = 'tenants'`), or when the filter reads a **tenant's mailbox** (a `#p` tag referencing a tenant). The challenge is an offer: a client that ignores it still receives the guest-scoped results, which end with the challenge and an `EOSE` carrying NIP-67's `"auth"` hint. A subscription narrowed until it can match nothing is refused `CLOSED` `auth-required:` instead, preceded by a challenge when the client holds none. The connection is never blocked for not authenticating. (Why a scope-exceeding request rather than every connection: see [ADR-0004](docs/adr/0004-auth-challenge-only-on-scope-exceeding-request.md).)

Authenticating changes no open subscription. Each keeps the filters it was admitted under, nothing is replayed, and no second `EOSE` is sent; a client that wants a narrowed subscription widened sends its `REQ` again on the same id, which replaces it and counts once against the subscription cap (see [ADR-0033](docs/adr/0033-authentication-leaves-open-subscriptions-as-admitted.md)).

---

## Architecture

```
┌─────────────────────────────────────────────────────┐
│ Host Application                                    │
│ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ │
│ │ MyEventStore │ │ MyPolicy     │ │ MyConfig     │ │
│ └──────────────┘ └──────────────┘ └──────────────┘ │
└───────┬──────────────────┬───────────────┬──────────┘
        │                  │               │
┌───────▼──────────────────▼───────────────▼──────────┐
│ innis/nostr-relay                                   │
│                                                     │
│  WebSocket Server → Message Router → Use Cases      │
│                                                     │
│  SubscriptionRegistry → EventDistributor            │
└─────────────────────────────────────────────────────┘
```

**Relay Handles:**
- WebSocket session handling (as a request handler mounted on the host's server)
- Message parsing (EVENT, REQ, CLOSE, AUTH, COUNT)
- NIP-42 authentication (challenge/response)
- NIP-09 deletion (kind 5 event processing)
- NIP-70 protected events (admitted only from their authenticated author)
- NIP-59 / NIP-17 gift wraps (served only to the key they name)
- Ephemeral event handling (kinds 20000-29999)
- Subscription management and limits
- Filter matching and event distribution
- Rate limiting

**Host Application Handles:**
- The HTTP server: binding, middleware (CORS, forwarded headers, compression) and start/stop lifecycle
- Event storage and queries
- Access control policies (use built-in `RelayPolicy` or implement `RelayPolicyInterface` directly)
- Relay configuration (`RelayConfigInterface`)
- NIP-11 metadata (`Nip11InfoProviderInterface` — built-in `StaticNip11InfoProvider`, or implement for runtime-computed metadata)
- Any additional routes served on the same origin (landing page, management API, static files)

---

## Testing

```bash
composer test
```

Runs the Unit, Integration and Acceptance suites, then the soak harness (`tools/soak-harness.php`), then PHPStan level 9 static analysis. `composer test-unit` runs the Unit suite alone; `composer soak` the harness alone.

Manual testing with [websocat](https://github.com/vi/websocat):

```bash
websocat ws://localhost:8080

["REQ","test",{"kinds":[1],"limit":10}]
```

---

## Performance

The relay is designed for concurrent connection handling. Concrete throughput, latency, and memory figures depend on the host's event store and hardware and are not benchmarked here.

**Design choices that bear on performance:**
- AMPHP fibres for concurrent clients
- Subscriptions pre-indexed by event kind, so event distribution looks up only the subscriptions whose filters declare a matching kind (plus kind-agnostic filters) rather than scanning every subscription
- Per-event filter matching delegated to nostr-core's `Filter`
- Stored events streamed as the bytes the store holds, never parsed and re-encoded, and a live event encoded once however many subscribers receive it (`tools/stream-benchmark.php` measures both)
- Non-blocking I/O throughout

---

## License

MIT License. See LICENSE file for details.
