# innis/nostr-relay

**AMPHP-based async WebSocket relay server for Nostr protocol**

A private, high-performance Nostr relay implementation designed to be embedded in PHP applications. Built with AMPHP for concurrent connection handling and clean architecture principles.

---

## Features

- **Interface-driven design** - Storage and policies provided by host application
- **AMPHP async** - Non-blocking concurrent connections (100-1000+)
- **Private relay focus** - Built for single-user/controlled access scenarios
- **NIP-01 compliant** - EVENT, REQ, CLOSE message handling
- **NIP-09 deletion** - Kind 5 event processing
- **NIP-11 support** - Relay information document
- **NIP-42 AUTH** - Challenge/response authentication (challenge sent only once per client)
- **NIP-45 COUNT** - COUNT message support
- **Ephemeral events** - Kinds 20000-29999 skip storage
- **Custom HTTP handlers** - Inject handlers for additional HTTP endpoints (e.g. management APIs, landing pages)
- **Mutable NIP-11 metadata** - Swap in a custom `Nip11InfoProviderInterface` to update relay info at runtime
- **Built-in RelayPolicy** - Configurable tenant/guest permissions
- **Real-time distribution** - Events broadcast to matching subscriptions
- **Rate limiting** - DDoS protection with configurable limits; tenants (and trusted clients via `isRateLimitExempt()`) bypass
- **CORS support** - `OPTIONS` preflight handling and CORS headers on HTTP responses for browser-based clients
- **Trusted proxies** - `X-Forwarded-For` honoured when the client IP matches a trusted proxy
- **PSR-3 logging** - Standard logging interface

---

## Requirements

- PHP 8.3 or higher
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

The relay requires two interfaces to be implemented by your host application:

- **`RelayEventStoreInterface`** - Event persistence and queries
- **`RelayConfigInterface`** - Server and relay configuration

Access control can use the built-in `RelayPolicy` or a custom implementation of `RelayPolicyInterface`.

Two optional interfaces extend the relay's HTTP handling:

- **`HttpRequestHandlerInterface`** - Handle additional HTTP requests (e.g. management API, landing page). Return a response or `null` to fall through to WebSocket.
- **`Nip11InfoProviderInterface`** - Provide NIP-11 metadata dynamically. Defaults to reading from `RelayConfigInterface` if not provided.

### 2. Create and Start the Relay

```php
use Innis\Nostr\Relay\Application\Service\AuthenticationManager;
use Innis\Nostr\Relay\Application\Service\RelayPolicy;
use Innis\Nostr\Relay\Infrastructure\Server\RelayServerFactory;

$authManager = new AuthenticationManager();
$logger = new \Psr\Log\NullLogger();

$policy = new RelayPolicy($authManager, $logger, [
    'tenants' => ['your-hex-pubkey'],
    'guest' => [
        'read' => [
            ['kinds' => [0, 1, 6, 7, 30023], 'from' => 'tenants'],
        ],
        'write' => [
            ['kinds' => [7, 9735]],
        ],
    ],
]);

$factory = new RelayServerFactory(
    eventStore: new MyEventStore(),
    policy: $policy,
    config: new MyRelayConfig(),
    authManager: $authManager,
    logger: $logger,
    // Optional: custom HTTP handler for additional endpoints
    // httpHandler: new MyHttpHandler(),
    // Optional: dynamic NIP-11 metadata provider
    // nip11InfoProvider: new MyNip11InfoProvider(),
);

$relay = $factory->create();
$relay->start();
```

See [`examples/relay.example.php`](examples/relay.example.php) for a complete working example with all interface implementations.

### 3. Configure Nginx

The relay does not handle TLS. Use a reverse proxy for SSL. If the proxy sets `X-Forwarded-For`, return its IP from `RelayConfigInterface::getTrustedProxies()` so the relay records the real client address:

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

`tenants`: array of hex pubkeys or npub strings identifying relay owners. Tenants authenticate via NIP-42 and bypass all guest restrictions. If the array is empty or omitted, the relay operates as an open relay (all writes and reads allowed).

### Limits

Optional keys with sensible defaults:

- `max_subscriptions` - Maximum concurrent subscriptions per client
- `max_filters` - Maximum filters per subscription
- `max_event_size` - Maximum event payload size in bytes
- `max_query_limit` - Maximum limit value in REQ filters

### Rate-Limit Exemption

`RelayPolicyInterface::isRateLimitExempt()` lets the policy opt specific clients out of rate limits and subscription caps. The built-in `RelayPolicy` exempts authenticated tenants (and everyone on an open relay). Implement `RelayPolicyInterface` directly to exempt other trusted clients — for example, internal services or IPs behind a trusted proxy.

### Guest Rules

Unauthenticated clients are treated as guests. Guest permissions are defined under the `guest` key:

**`guest.read`**: array of rules controlling what events guests can query. Each rule has:
- `kinds` (int array) - Event kinds the guest may read
- `from` (optional, `'tenants'`) - Restrict results to events authored by tenants

**`guest.write`**: array of rules controlling what events guests can publish. Each rule has:
- `kinds` (int array) - Event kinds the guest may publish
- `tagged_to_tenant` (optional, `true`) - Require the event to tag a tenant pubkey

If no config is passed, the relay is fully open with no restrictions.

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
│  SubscriptionManager → EventDistributor             │
└─────────────────────────────────────────────────────┘
```

**Relay Handles:**
- WebSocket server lifecycle
- Connection management
- Message parsing (EVENT, REQ, CLOSE, AUTH, COUNT)
- NIP-42 authentication (challenge/response)
- NIP-09 deletion (kind 5 event processing)
- Ephemeral event handling (kinds 20000-29999)
- Subscription management and limits
- Filter matching and event distribution
- Rate limiting

**Host Application Handles:**
- Event storage and queries
- Access control policies (use built-in `RelayPolicy` or implement `RelayPolicyInterface` directly)
- Server and NIP-11 configuration
- Custom HTTP endpoints (optional `HttpRequestHandlerInterface`)
- Runtime NIP-11 metadata (optional `Nip11InfoProviderInterface`, defaults to static config)

---

## Testing

```bash
composer test
```

Runs the unit test suite (120 tests) and PHPStan level 9 static analysis.

Manual testing with [websocat](https://github.com/vi/websocat):

```bash
websocat ws://localhost:8080

["REQ","test",{"kinds":[1],"limit":10}]
```

---

## Performance

**Target Scale:**
- 100-1000 concurrent WebSocket connections
- <10ms event distribution to 100 subscribers
- <5ms filter matching for 1000 subscriptions
- ~50 MB memory overhead for relay logic

**Optimisations:**
- AMPHP fibers for concurrent clients
- Subscription indexing by event kind
- Filter matching via nostr-core
- Non-blocking I/O throughout

---

## License

MIT License. See LICENSE file for details.
