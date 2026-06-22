# 7. Authentication re-streams a client's already-open subscriptions

## Status

Accepted

## Context

The relay challenges lazily: an unauthenticated client is a guest, and a REQ whose filters exceed guest scope is not rejected but narrowed to the guest-readable subset and served, alongside an offer to authenticate. A subscription opened as a guest therefore remains open, but scoped down — the client is seeing a restricted view of the events its filter asked for.

When that client later authenticates and gains a wider scope, its open subscriptions are now under-serving it: they were admitted and their stored-event backlog was streamed under the narrow guest scope, so the events that became visible only on authentication were never sent. The subscription's live matching will pick up *new* events under the wider scope, but the already-stored events that the original filter asked for and the client could now see remain undelivered.

The naive resolution is to make the client re-issue every REQ after it authenticates. That pushes relay-internal bookkeeping onto the client, requires the client to remember exactly which subscriptions it opened and with which filters, and races against events arriving in between. A guest subscription that silently stops widening when the client authenticates is also a latent correctness trap: the client proved its identity and reasonably expects to see what that identity may see.

For the relay to redeliver correctly it must keep the filters the *client* asked for, not the filters it actually ran. The scoped-down filters are lossy — they cannot be re-widened, because the information about what was removed is gone. So the original, client-supplied filter set has to be retained per subscription, separate from the scoped set used to query and to match live events.

## Decision

On successful authentication the relay re-evaluates the client's already-open subscriptions against the new scope, without the client re-subscribing (`ProcessAuthUseCase`). For each open subscription it takes the **original client-supplied filters** — retained by the subscription manager alongside the scoped filters precisely for this purpose — and runs the normal subscription-creation path again under the now-authenticated identity. Re-admission re-scopes the original filters against the wider scope, replaces the stored subscription, and re-streams the stored events that are now visible, ending with EOSE.

Retaining the original filters is the load-bearing part. The scoped filters are a lossy projection; only the originals carry enough information to be re-scoped when the scope widens. A subscription opened with empty original filters (nothing was retained to replay) is skipped rather than guessed at.

## Consequences

- A subscription opened as a guest widens automatically the moment the client authenticates: the newly-visible stored events are delivered and live matching continues under the wider scope, with no client-side re-subscription. The client sees what its proven identity may see.
- The subscription manager must store each subscription's original client-supplied filters in addition to the scoped filters it queries and matches with. This is a deliberate second copy, not redundancy — the scoped set cannot reconstruct it, and the re-stream cannot be correct without it.
- Re-streaming replays the stored-event backlog under the new scope, so a client may receive stored events it had already received under the narrower guest scope. This duplication on a scope-widening event is accepted; subscription ids let the client reconcile, and the alternative — tracking exactly which events were already sent per subscription — is far more state for a rare transition.
- Do not drop the retained original filters to "save memory" by re-streaming the scoped filters instead — the scoped filters cannot widen, and the client would never receive the events authentication was supposed to unlock.
