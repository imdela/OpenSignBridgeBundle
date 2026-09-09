# Technical Debt

- **Flex recipe doesn't copy `resources/scripts/`.** The recipe's
  `copy-from-recipe` only handles `config/`. `resources/scripts/opensign-minio-patch.js`
  still requires running `opensignb:install` manually. Extending the recipe
  (a new PR to symfony/recipes-contrib) would let Flex copy it too.

## Brainstorm: replacing HTTP polling with a MongoDB change stream

`OpenSignPollingService` polls the OpenSign HTTP API on a fixed interval — confirmed
(2026-09) via staffos-php's staging resource monitoring that its host container runs
close to its memory limit just idling on this poll, before any real load. No outbound
webhook exists on self-hosted OpenSign (see README/`DOCUMENTATION.md`'s "Detecting
Completion" section) and isn't coming — "Live Webhooks" stay an OpenSign Labs SaaS-only
feature, not something we can enable on our own server.

Idea, not yet investigated: OpenSign stores its documents in MongoDB (Atlas in
staffos-php's case). MongoDB supports **change streams** — a subscription to
insert/update/delete events on a collection, pushed to the client as they happen,
without polling. If OpenSign's `IsCompleted` transition is a plain document update in a
collection we can read (Atlas credentials are already something the host app holds),
a change stream watching for that field flipping could replace the fixed-interval HTTP
poll with an actual push-like mechanism — closer in spirit to a webhook than polling is,
without needing OpenSign itself to support one.

Open questions before this goes anywhere: whether Atlas's free tier exposes change
streams (historically a replica-set-only feature — Atlas free tier runs as a replica
set, so likely yes, but unconfirmed), whether OpenSign's collection/field names are
stable enough to depend on across OpenSign versions, and whether a long-lived change
stream connection is actually lighter on a 954 MB VM than the current periodic HTTP
poll (it trades interval-based CPU/network bursts for one held-open connection —
not obviously a win, needs measuring before committing to it).
