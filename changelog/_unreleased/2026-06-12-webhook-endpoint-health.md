---
title: Per-webhook endpoint health with automatic recovery
issue: shopware/shopware#16565
flag: WEBHOOKS_REWORK
---
# Core
* Added a per-webhook circuit breaker (`healthy` → `degraded` → `suspended` → `disabled`) on the internal `webhook_health` table. It replaces shared `error_count` propagation and permanent `active = 0` disables while `WEBHOOKS_REWORK` is active; flag-off is unchanged.
* Classified delivery failures by endpoint impact. Network errors, TLS, `404`, `408`, `429`, `5xx`, and unfollowed `3xx` degrade after repeated failures; three consecutive `401`/`403` responses suspend; `410` suspends immediately; `400` and other payload-specific `4xx` responses do not affect health.
* Added one half-open recovery ladder (5 m → 4 h): one trial runs per cooldown and each `2xx` climbs one state. SUSPENDED retires after `shopware.webhook.health.max_suspended_days` (default 7); app install/update resets eligible webhooks.
* Held queued deliveries as `paused` during DEGRADED/SUSPENDED and resumed them on recovery. Rows older than 24 hours are cancelled but remain replayable in `webhook_event_log`; new SUSPENDED events are not recorded and are reconciled by the `suspendedSince` window.
* Added Flow-compatible lifecycle events (`webhook.health.activated`, `webhook.health.degraded`, `webhook.health.suspended`, `webhook.health.disabled`) for the owning app, plus Admin notices for suspension (once per episode), disable, and recovery.
* Added `GET /api/app-system/webhook/state`, `POST /api/app-system/webhook/reactivate` (10/min per integration, max 50 names, refuses operator-disabled webhooks), `GET /api/_action/webhook/health-status`, and the operator kill-switch `POST /api/_action/webhook/{id}/deactivate`.
* Changed `webhook.active` under the flag: from v6.8.0, `true` means dispatch is eligible, so DEGRADED remains active while paused. Use `endpointState` for current status. Admin writes carry intent only when the value changes; legacy automation inherits the kill-switch semantics.
* Disabling `WEBHOOKS_REWORK` switches execution paths but does not restore state unless the flag was never enabled. Run `bin/console webhook:drain-to-async` after disabling it to re-publish held and queued deliveries.
