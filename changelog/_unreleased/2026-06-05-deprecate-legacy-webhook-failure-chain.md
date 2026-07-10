---
title: Deprecate the legacy webhook failure chain ahead of the endpoint-health rework
author: Ghaith Olabi
author_email: m.olabi@shopware.com
author_github: @Gaitholabi
---
# Core
* Deprecated the legacy webhook failure chain for removal alongside `WEBHOOKS_REWORK` in v6.8.0: `WebhookEntity::$active`/`$errorCount` and their accessors, `RelatedWebhooks::updateRelated()`, `RetryWebhookMessageFailedSubscriber::failed()`, and `WebhookHealthService::recordLegacyFailure()`/`resetErrorCount()`.
___
# Upgrade Information
## `webhook.active` and `webhook.error_count` are deprecated
These columns mirror endpoint health for backwards compatibility and will be removed with `WEBHOOKS_REWORK` in v6.8.0. Read `endpointState` (`healthy`/`degraded`/`suspended`/`disabled`) from `GET /api/app-system/webhook/state`; until removal, `errorCount` mirrors the dominant failure streak. See `adr/2026-06-05-webhook-rework-v6.8.0-removal-runbook.md` for the staged cutover.
