---
paths:
  - 'app/Actions/Monzo/**'
---

# Monzo

## Monzo's full-history window is one-shot and must not be queued
Monzo serves transactions older than 90 days only for ~5 minutes after authenticating. The first backfill therefore runs SYNCHRONOUSLY inside the OAuth callback (MonzoConnectionController::backfill), never on the queue — QUEUE_CONNECTION is `database`, so a queued backfill silently never runs if no worker is up, and the window does not reopen.

`bank_connections.history_backfilled_at` stays null unless a run actually read the whole history; the connections page uses that to offer a reconnect. If a full request 403s AFTER SCA is confirmed, the window has closed: SyncMonzoConnection degrades to an incremental pull and refuses to set the marker.

A 403 is ambiguous — before approval it means the user hasn't accepted the push notification (ScaRequiredException), after approval it means the range is too wide. Only the second may be retried.

Monzo also allows one active access token per user, so overlapping syncs invalidate each other's tokens: SyncMonzoConnection takes a cache lock and the job is ShouldBeUnique.
