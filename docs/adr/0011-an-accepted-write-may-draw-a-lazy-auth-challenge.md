# 11. An accepted write may draw a lazy AUTH challenge

## Status

Accepted.

## Context

A relay already offers a NIP-42 AUTH challenge lazily on the read path: a subscription whose filter
exceeds guest scope is admitted, scoped down, and the client is sent a challenge so it can
authenticate for full access. The challenge is a side effect of admission, sent by the admission
service, never on connect.

The write path had no equivalent. Yet a host policy can have the same need there: it may want to
*admit* an event and still invite the connection to authenticate — for example so a client that keeps
publishing a particular kind can upgrade to a standing that changes how later writes are treated. With
no hook, a policy could only reject (throw) to force a challenge, which would deny the write the
policy actually wants to accept.

The two spots that offer a challenge (the read admission and, now, the write admission) were also on
their way to hand-rolling the same "get-or-create a challenge and send it" line, which is one
behaviour and must have one home.

## Decision

- `RelayPolicyInterface` gains `offersAuthChallenge(client, event): bool` — the write-path analogue of
  the read path's scope signal. `allowEventSubmission` still decides accept-or-reject; a policy that
  admits an event answers separately whether that acceptance should also draw a challenge. The default
  policy answers `false`.
- `EventAdmission`, after `allowEventSubmission` admits the event, offers a challenge when the policy
  says so. The event is admitted regardless — the challenge only invites an upgrade, so a client that
  never authenticates keeps publishing.
- The send itself moves into one collaborator, `ClientAuthChallenger`, used by both the subscription
  and event admission services. Offering a challenge is "send an `AUTH` with a fresh-or-existing
  challenge for this client", in exactly one place. It is not gated on an existing challenge: a
  scope-exceeding or challenge-drawing request re-issues, because a client may have missed the first.

## Consequences

- A host policy can admit a write and still challenge the connection, without abusing rejection to do
  it. The generic relay behaviour is unchanged, since the default policy never offers.
- The challenge is drawn only after `allowEventSubmission` has run — which is after signature
  validation and after the rate-limit check — so a policy only ever challenges a client whose event
  was already validated and admitted, and the cheap rate-limit shed still runs first.
- There is one implementation of "offer a client an AUTH challenge". A future change to how a
  challenge is issued or framed happens there, for both admission paths at once.
