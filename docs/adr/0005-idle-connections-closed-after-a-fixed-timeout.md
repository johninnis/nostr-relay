# 5. Idle connections are closed after a fixed receive timeout

## Status

Accepted

## Context

A WebSocket relay holds one long-lived connection per client, and each connection consumes a slot: a fibre, socket, and the per-client state the relay keeps (registered client, subscriptions, rate-limit buckets, auth challenge). The number of slots is bounded — the host configures a maximum connection count — so an occupied slot that is doing nothing is a slot a real client cannot have.

This is the lever a slow-loris attacker pulls. The attacker opens many connections, completes the WebSocket handshake, and then simply goes quiet: no further frames, no close. Each held-open-but-silent connection costs the attacker almost nothing and costs the relay a full slot. With enough of them the relay exhausts its connection budget and refuses legitimate clients, without ever tripping a per-message rate limit — because no messages are sent.

A connection blocked indefinitely on "wait for the next frame" cannot tell an idle-but-honest client from a deliberately-stalled one. The only thing that distinguishes them is elapsed silence, so silence has to be bounded.

## Decision

Every connection's receive is bounded by an idle timeout. The read that waits for the next client frame is wrapped in a cancellation that fires after a fixed number of seconds (`ClientConnectionHandler`, defaulting to 300). If no inbound frame arrives within that window the read is cancelled, the cancellation is caught, and the connection is torn down through the normal disconnection path — freeing its slot and all associated per-client state.

The timeout measures silence, not connection age: any inbound frame resets the window, so a client that keeps talking is never disconnected for being long-lived. The default is injectable, so a host whose clients are legitimately quiet for longer can widen it.

The chosen default of 300 seconds is a balance. Too short and an honest client that simply has nothing to send for a few minutes — an idle subscriber waiting for new events — is disconnected and forced to reconnect, multiplying handshakes. Too long and a stalled connection squats on its slot for that whole window before the relay reclaims it, which is exactly the cost the attacker is trying to impose. Five minutes is long enough that a normal idle subscriber stays connected and short enough that the relay reclaims an abandoned or deliberately-stalled slot promptly.

## Consequences

- A connection that sends nothing for the timeout window is closed and its slot reclaimed, bounding how long a silent connection — abandoned or hostile — can occupy a slot. This is the relay's slow-loris mitigation.
- A genuinely idle but honest client is also disconnected after the window and must reconnect to continue. This is accepted: the cost of a reconnect is small next to the cost of an unbounded silent slot, and the timeout cannot distinguish the two cases by anything other than elapsed silence.
- The window resets on every inbound frame, so an active long-lived connection is never closed for its age. Only silence is bounded.
- A host with a legitimate need for longer idle periods raises the injected timeout rather than removing the bound. Do not remove the timeout to "support long-lived clients" — an unbounded receive is precisely the resource the slow-loris attack consumes.
