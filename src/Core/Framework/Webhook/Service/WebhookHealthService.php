<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableTransaction;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Webhook\Health\EndpointState;
use Shopware\Core\Framework\Webhook\Health\ErrorClassification;
use Shopware\Core\Framework\Webhook\Health\HealthConfig;
use Shopware\Core\Framework\Webhook\Health\WebhookDispatchDecision;
use Shopware\Core\Framework\Webhook\Outbox\WebhookOutboxStore;
use Shopware\Core\Framework\Webhook\WebhookException;
use Shopware\Core\Framework\Webhook\WebhookFailureStrategy;

/**
 * @internal
 */
#[Package('framework')]
class WebhookHealthService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RelatedWebhooks $relatedWebhooks,
        private readonly WebhookOutboxStore $outboxStore,
        private readonly HealthConfig $config,
        private readonly ClockInterface $clock,
    ) {
    }

    public function gateFor(string $webhookId): WebhookDispatchDecision
    {
        $row = $this->connection->fetchAssociative(
            'SELECT endpoint_state, suspended_since FROM webhook_health WHERE webhook_id = :id',
            ['id' => Uuid::fromHexToBytes($webhookId)]
        );
        if (!\is_array($row)) {
            return WebhookDispatchDecision::Deliver;
        }

        $state = EndpointState::from((string) $row['endpoint_state']);
        if ($state === EndpointState::Healthy) {
            return WebhookDispatchDecision::Deliver;
        }

        if ($state === EndpointState::Degraded && $row['suspended_since'] === null) {
            return WebhookDispatchDecision::Hold;
        }

        // During a suspension incident, only a due trial is delivered; other events are shed.
        return $this->admitIncidentTrial($webhookId);
    }

    public function recordSuccess(string $webhookId): void
    {
        // The guarded writes below absorb a concurrent state change after this read.
        $row = $this->connection->fetchAssociative(
            'SELECT wh.endpoint_state, wh.consecutive_transient_failures, wh.consecutive_non_transient_failures
             FROM webhook_health wh WHERE wh.webhook_id = :id',
            ['id' => Uuid::fromHexToBytes($webhookId)]
        );
        $state = \is_array($row) ? EndpointState::from((string) $row['endpoint_state']) : EndpointState::Healthy;

        // A success recovers one state at a time.
        if ($state === EndpointState::Suspended && $this->deEscalateSuspendedToDegraded($webhookId)) {
            return;
        }

        if ($state === EndpointState::Degraded && $this->promoteDegradedToHealthy($webhookId, keepFailureStreaks: false)) {
            return;
        }

        if (\is_array($row) && ((int) $row['consecutive_transient_failures'] > 0 || (int) $row['consecutive_non_transient_failures'] > 0)) {
            $cleared = (int) $this->connection->executeStatement(
                'UPDATE webhook_health
                 SET consecutive_transient_failures = 0, consecutive_non_transient_failures = 0, updated_at = :now
                 WHERE webhook_id = :id AND endpoint_state = :healthy',
                [
                    'now' => $this->now(),
                    'id' => Uuid::fromHexToBytes($webhookId),
                    'healthy' => EndpointState::Healthy->value,
                ]
            );

            if ($cleared > 0) {
                $this->mirrorBcColumns($webhookId);

                return;
            }
        }

        // Health rows are lazy, so also reconcile a fail-open HEALTHY webhook. Success must not
        // reactivate a legacy inactive webhook.
        $this->connection->executeStatement(
            'UPDATE webhook w
             LEFT JOIN webhook_health wh ON wh.webhook_id = w.id
             SET w.error_count = 0
             WHERE w.id = :id
               AND (wh.webhook_id IS NULL OR wh.endpoint_state = :healthy)
               AND w.error_count <> 0',
            [
                'id' => Uuid::fromHexToBytes($webhookId),
                'healthy' => EndpointState::Healthy->value,
            ]
        );
    }

    public function recordFailure(string $webhookId, ErrorClassification $classification, int $attempt): EndpointState
    {
        return match ($classification) {
            ErrorClassification::Success => throw WebhookException::unexpectedClassification($classification->value),
            ErrorClassification::NonTransientPayload => $this->currentState($webhookId),
            ErrorClassification::NonTransientAuth => $this->recordNonTransientFailure($webhookId, countsStreak: true),
            ErrorClassification::NonTransientEndpoint => $this->recordNonTransientFailure($webhookId, countsStreak: false),
            ErrorClassification::TransientNetwork,
            ErrorClassification::TransientServer,
            ErrorClassification::TransientRateLimit,
            ErrorClassification::TransientRedirect => $this->recordTransientFailure($webhookId, $attempt),
        };
    }

    /**
     * Runs scheduled recovery and cleanup duties.
     */
    public function tick(): int
    {
        return $this->runDueReleases()
            + $this->cancelSurplusSuspendedInFlight()
            + $this->healStrandedHolds()
            + $this->healOrphanedHolds();
    }

    /**
     * @deprecated tag:v6.8.0 - Pre-rework shared-counter failure handling. Runs only with WEBHOOKS_REWORK
     * off and is removed together with the `webhook.active`/`error_count` columns.
     */
    public function recordLegacyFailure(string $webhookId, WebhookFailureStrategy $strategy): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT active, error_count FROM webhook WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($webhookId)]
        );

        if (!\is_array($row) || !$row['active']) {
            return;
        }

        $newCount = (int) $row['error_count'] + 1;

        $params = $strategy === WebhookFailureStrategy::DisableOnThreshold && $newCount >= WebhookFailureStrategy::MAX_ERROR_COUNT
            ? ['error_count' => 0, 'active' => 0]
            : ['error_count' => $newCount];

        $this->relatedWebhooks->updateRelated($webhookId, $params, Context::createDefaultContext());
    }

    /**
     * @deprecated tag:v6.8.0 - Pre-rework shared-counter reset. Runs only with WEBHOOKS_REWORK off and is
     * removed together with the legacy columns.
     */
    public function resetErrorCount(string $webhookId): void
    {
        $this->relatedWebhooks->updateRelated($webhookId, ['error_count' => 0], Context::createDefaultContext());
    }

    /**
     * Releases one due trial or promotes an idle DEGRADED webhook.
     */
    private function runDueReleases(): int
    {
        $now = $this->now();

        /** @var list<string> $candidates */
        $candidates = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(webhook_id))
             FROM webhook_health
             WHERE endpoint_state IN (:degraded, :suspended)
               AND (cooldown_until IS NULL OR cooldown_until <= :now)',
            [
                'now' => $now,
                'degraded' => EndpointState::Degraded->value,
                'suspended' => EndpointState::Suspended->value,
            ]
        );

        $acted = 0;
        foreach ($candidates as $webhookId) {
            $acted += RetryableTransaction::retryable($this->connection, function () use ($webhookId, $now): int {
                // The row lock prevents concurrent ticks from releasing multiple trials.
                $row = $this->lockHealthRow($webhookId);
                if ($row === null) {
                    return 0;
                }

                $state = EndpointState::from((string) $row['endpoint_state']);
                if ($state !== EndpointState::Degraded && $state !== EndpointState::Suspended) {
                    return 0;
                }
                if ($row['cooldown_until'] !== null && (string) $row['cooldown_until'] > $now) {
                    return 0;
                }

                // A trial advances the ladder through its result, not through elapsed time.
                if ($this->outboxStore->hasClaimableOrRunningRows($webhookId)) {
                    return 0;
                }

                if ($this->outboxStore->releaseOneTrial($webhookId) !== null) {
                    return 1;
                }

                if ($state === EndpointState::Suspended) {
                    return 0;
                }

                return $this->promoteDegradedToHealthyLocked($webhookId, keepFailureStreaks: true) ? 1 : 0;
            });
        }

        return $acted;
    }

    /**
     * Cancels crash-recovered rows beyond the single SUSPENDED trial.
     */
    private function cancelSurplusSuspendedInFlight(): int
    {
        $acted = 0;
        foreach ($this->outboxStore->findSuspendedWebhookIdsWithClaimableRows() as $webhookId) {
            $acted += $this->outboxStore->cancelSurplusInFlightRows($webhookId) > 0 ? 1 : 0;
        }

        return $acted;
    }

    /**
     * Resumes rows held by a gate/recovery race on a HEALTHY webhook.
     */
    private function healStrandedHolds(): int
    {
        $acted = 0;
        foreach ($this->outboxStore->findWebhookIdsWithStrandedHolds() as $webhookId) {
            $this->outboxStore->resumeDeliveriesForWebhook($webhookId);
            ++$acted;
        }

        return $acted;
    }

    /**
     * Cancels held rows whose webhook was deleted.
     */
    private function healOrphanedHolds(): int
    {
        return $this->outboxStore->cancelOrphanedHeldRows() > 0 ? 1 : 0;
    }

    private function recordTransientFailure(string $webhookId, int $attempt): EndpointState
    {
        $current = $this->currentState($webhookId);

        if ($current === EndpointState::Degraded || $current === EndpointState::Suspended) {
            return $this->advanceLadder($webhookId, $current);
        }

        // Retries of the same delivery do not count towards endpoint health.
        if ($attempt > 1) {
            return $current;
        }

        return $this->recordHealthyTransientFailure($webhookId);
    }

    private function recordHealthyTransientFailure(string $webhookId): EndpointState
    {
        $threshold = $this->config->degradedThreshold;
        $now = $this->now();
        $firstCooldown = $this->cooldownAt(0);
        $webhookIdBytes = Uuid::fromHexToBytes($webhookId);

        $outcome = RetryableTransaction::retryable($this->connection, function () use ($webhookIdBytes, $threshold, $now, $firstCooldown): ?EndpointState {
            $result = $this->updateHealthyTransientFailure($webhookIdBytes, $threshold, $now, $firstCooldown);
            if ($result !== null) {
                return $result;
            }

            if ((bool) $this->connection->fetchOne('SELECT 1 FROM webhook_health WHERE webhook_id = :id', ['id' => $webhookIdBytes])) {
                return null;
            }

            $newState = $threshold <= 1 ? EndpointState::Degraded : EndpointState::Healthy;
            try {
                $this->connection->executeStatement(
                    'INSERT INTO webhook_health
                        (webhook_id, endpoint_state, consecutive_transient_failures, degraded_cycle_count, cooldown_until, created_at)
                    VALUES (:id, :state, 1, 0, :cooldown, :now)',
                    [
                        'id' => $webhookIdBytes,
                        'state' => $newState->value,
                        'cooldown' => $newState === EndpointState::Degraded ? $firstCooldown : null,
                        'now' => $now,
                    ]
                );

                return $newState;
            } catch (UniqueConstraintViolationException) {
                // Count the failure against the row inserted by the concurrent writer.
                return $this->updateHealthyTransientFailure($webhookIdBytes, $threshold, $now, $firstCooldown);
            }
        });

        if ($outcome === null) {
            return $this->currentState($webhookId);
        }

        if ($outcome === EndpointState::Degraded) {
            $this->outboxStore->pauseDeliveriesForWebhook($webhookId);
        }

        $this->mirrorBcColumns($webhookId);

        return $outcome;
    }

    private function updateHealthyTransientFailure(
        string $webhookIdBytes,
        int $threshold,
        string $now,
        string $firstCooldown,
    ): ?EndpointState {
        $incremented = (int) $this->connection->executeStatement(
            'UPDATE webhook_health
             SET consecutive_transient_failures = consecutive_transient_failures + 1, updated_at = :now
             WHERE webhook_id = :id AND endpoint_state = :healthy
               AND consecutive_transient_failures + 1 < :threshold',
            [
                'healthy' => EndpointState::Healthy->value,
                'now' => $now,
                'id' => $webhookIdBytes,
                'threshold' => $threshold,
            ]
        );
        if ($incremented > 0) {
            return EndpointState::Healthy;
        }

        $crossed = (int) $this->connection->executeStatement(
            'UPDATE webhook_health
             SET endpoint_state = :degraded, degraded_cycle_count = 0, cooldown_until = :firstCooldown,
                 consecutive_transient_failures = consecutive_transient_failures + 1, updated_at = :now
             WHERE webhook_id = :id AND endpoint_state = :healthy
               AND consecutive_transient_failures + 1 >= :threshold',
            [
                'degraded' => EndpointState::Degraded->value,
                'healthy' => EndpointState::Healthy->value,
                'firstCooldown' => $firstCooldown,
                'now' => $now,
                'id' => $webhookIdBytes,
                'threshold' => $threshold,
            ]
        );

        return $crossed > 0 ? EndpointState::Degraded : null;
    }

    /**
     * Advances a failed trial and returns the resulting state.
     */
    private function advanceLadder(string $webhookId, EndpointState $expected): EndpointState
    {
        $suspended = RetryableTransaction::retryable($this->connection, function () use ($webhookId, $expected): bool {
            $row = $this->lockHealthRow($webhookId);
            if ($row === null || EndpointState::from((string) $row['endpoint_state']) !== $expected) {
                return false;
            }

            return $this->advanceLadderLocked($webhookId, $row, $expected, alsoCountAuthStreak: false);
        });

        $this->finishSuspension($webhookId, $suspended);

        return $suspended ? EndpointState::Suspended : $expected;
    }

    /**
     * Advances a trial under the row lock; results inside the cooldown are stale.
     *
     * @param array<string, mixed> $row
     */
    private function advanceLadderLocked(string $webhookId, array $row, EndpointState $state, bool $alsoCountAuthStreak): bool
    {
        $now = $this->now();
        $id = Uuid::fromHexToBytes($webhookId);
        $cooldownElapsed = $row['cooldown_until'] === null || (string) $row['cooldown_until'] <= $now;
        $streak = (int) $row['consecutive_non_transient_failures'] + ($alsoCountAuthStreak ? 1 : 0);

        if (!$cooldownElapsed) {
            if ($alsoCountAuthStreak) {
                // The auth streak is independent of the trial ladder.
                $this->connection->executeStatement(
                    'UPDATE webhook_health SET consecutive_non_transient_failures = :streak, updated_at = :now WHERE webhook_id = :id',
                    ['streak' => $streak, 'now' => $now, 'id' => $id]
                );
                $this->mirrorBcColumns($webhookId);
            }

            return false;
        }

        $topIndex = \count($this->config->cooldownScheduleSeconds) - 1;
        $nextIndex = (int) $row['degraded_cycle_count'] + 1;

        if ($state === EndpointState::Degraded && $nextIndex > $topIndex) {
            $this->suspendLocked($webhookId, $row, $state, nonTransientFailures: $streak, entryIndex: $topIndex);

            return true;
        }

        $index = min($nextIndex, $topIndex);
        $this->connection->executeStatement(
            'UPDATE webhook_health
             SET degraded_cycle_count = :index, cooldown_until = :cooldown,
                 consecutive_non_transient_failures = :streak, updated_at = :now
             WHERE webhook_id = :id',
            [
                'index' => $index,
                'cooldown' => $this->cooldownAt($index),
                'streak' => $streak,
                'now' => $now,
                'id' => $id,
            ]
        );

        if ($alsoCountAuthStreak) {
            $this->mirrorBcColumns($webhookId);
        }

        return false;
    }

    /**
     * Counts auth failures toward suspension; endpoint retirement suspends immediately.
     */
    private function recordNonTransientFailure(string $webhookId, bool $countsStreak): EndpointState
    {
        $result = RetryableTransaction::retryable($this->connection, function () use ($webhookId, $countsStreak): array {
            $row = $this->lockHealthRow($webhookId);

            if ($row === null) {
                $streak = $countsStreak ? 1 : 0;
                $suspend = !$countsStreak || $this->config->nonTransientThreshold <= 1;
                $suspended = $this->insertFreshRowForNonTransient($webhookId, $streak, $suspend);

                return [$suspend ? EndpointState::Suspended : EndpointState::Healthy, $suspended];
            }

            $state = EndpointState::from((string) $row['endpoint_state']);

            if ($state === EndpointState::Suspended) {
                $suspended = $this->advanceLadderLocked($webhookId, $row, $state, alsoCountAuthStreak: $countsStreak);

                return [EndpointState::Suspended, $suspended];
            }

            $streak = (int) $row['consecutive_non_transient_failures'] + ($countsStreak ? 1 : 0);
            if (!$countsStreak || $streak >= $this->config->nonTransientThreshold) {
                $this->suspendLocked($webhookId, $row, $state, nonTransientFailures: $streak);

                return [EndpointState::Suspended, true];
            }

            if ($state === EndpointState::Degraded) {
                // A below-threshold auth failure still counts as a failed trial.
                $suspended = $this->advanceLadderLocked($webhookId, $row, $state, alsoCountAuthStreak: true);

                return [$suspended ? EndpointState::Suspended : $state, $suspended];
            }

            $this->connection->executeStatement(
                'UPDATE webhook_health
                 SET consecutive_non_transient_failures = :streak, updated_at = :now
                 WHERE webhook_id = :id',
                ['streak' => $streak, 'now' => $this->now(), 'id' => Uuid::fromHexToBytes($webhookId)]
            );
            $this->mirrorBcColumns($webhookId);

            return [$state, false];
        });

        $this->finishSuspension($webhookId, $result[1]);

        return $result[0];
    }

    /**
     * Suspends a locked row without restarting an existing suspension clock.
     *
     * @param array<string, mixed> $row
     */
    private function suspendLocked(string $webhookId, array $row, EndpointState $fromState, int $nonTransientFailures, int $entryIndex = 0): void
    {
        $now = $this->now();
        $since = $row['suspended_since'] !== null ? (string) $row['suspended_since'] : $now;

        $this->connection->executeStatement(
            'UPDATE webhook_health
             SET endpoint_state = :suspended, suspended_since = :since, degraded_cycle_count = :index,
                 cooldown_until = :cooldown, consecutive_non_transient_failures = :streak, updated_at = :now
             WHERE webhook_id = :id AND endpoint_state = :from',
            [
                'suspended' => EndpointState::Suspended->value,
                'since' => $since,
                'index' => $entryIndex,
                'cooldown' => $this->cooldownAt($entryIndex),
                'streak' => $nonTransientFailures,
                'now' => $now,
                'id' => Uuid::fromHexToBytes($webhookId),
                'from' => $fromState->value,
            ]
        );
        $this->mirrorBcColumns($webhookId);
    }

    private function finishSuspension(string $webhookId, bool $suspended): void
    {
        if (!$suspended) {
            return;
        }

        $this->outboxStore->pauseDeliveriesForWebhook($webhookId);
    }

    /**
     * Moves a successful SUSPENDED trial to DEGRADED while preserving the incident clock.
     */
    private function deEscalateSuspendedToDegraded(string $webhookId): bool
    {
        $deEscalated = (int) $this->connection->executeStatement(
            'UPDATE webhook_health
             SET endpoint_state = :degraded, degraded_cycle_count = 0, cooldown_until = :cooldown,
                 consecutive_transient_failures = 0, consecutive_non_transient_failures = 0, updated_at = :now
             WHERE webhook_id = :id AND endpoint_state = :suspended',
            [
                'degraded' => EndpointState::Degraded->value,
                'cooldown' => $this->cooldownAt(0),
                'now' => $this->now(),
                'id' => Uuid::fromHexToBytes($webhookId),
                'suspended' => EndpointState::Suspended->value,
            ]
        );

        if ($deEscalated === 0) {
            return false;
        }

        $this->mirrorBcColumns($webhookId);

        return true;
    }

    /**
     * Admits one natural-traffic trial and re-arms the cooldown atomically.
     */
    private function admitIncidentTrial(string $webhookId): WebhookDispatchDecision
    {
        // Avoid locking when a scheduled trial is already available.
        if ($this->outboxStore->hasHeldRows($webhookId)) {
            return WebhookDispatchDecision::Skip;
        }

        $admitted = RetryableTransaction::retryable($this->connection, function () use ($webhookId): bool {
            $row = $this->lockHealthRow($webhookId);
            if ($row === null) {
                return false;
            }
            // Re-check the unlocked gate decision under the row lock.
            $state = EndpointState::from((string) $row['endpoint_state']);
            $inIncident = $state === EndpointState::Suspended
                || ($state === EndpointState::Degraded && $row['suspended_since'] !== null);
            if (!$inIncident) {
                return false;
            }
            if ($row['cooldown_until'] !== null && (string) $row['cooldown_until'] > $this->now()) {
                return false;
            }
            if ($this->outboxStore->hasHeldRows($webhookId) || $this->outboxStore->hasClaimableOrRunningRows($webhookId)) {
                return false;
            }

            $top = \count($this->config->cooldownScheduleSeconds) - 1;
            $index = min((int) $row['degraded_cycle_count'] + 1, $top);
            $this->connection->executeStatement(
                'UPDATE webhook_health
                 SET degraded_cycle_count = :index, cooldown_until = :cooldown, updated_at = :now
                 WHERE webhook_id = :id AND endpoint_state = :state',
                [
                    'index' => $index,
                    'cooldown' => $this->cooldownAt($index),
                    'now' => $this->now(),
                    'id' => Uuid::fromHexToBytes($webhookId),
                    'state' => $state->value,
                ]
            );

            return true;
        });

        return $admitted ? WebhookDispatchDecision::Deliver : WebhookDispatchDecision::Skip;
    }

    /**
     * Creates the first health row for a non-transient failure.
     */
    private function insertFreshRowForNonTransient(string $webhookId, int $nonTransientFailures, bool $suspend): bool
    {
        $now = $this->now();

        try {
            $this->connection->executeStatement(
                'INSERT INTO webhook_health
                    (webhook_id, endpoint_state, consecutive_non_transient_failures, degraded_cycle_count,
                     cooldown_until, suspended_since, created_at, updated_at)
                 VALUES (:id, :state, :streak, 0, :cooldown, :since, :now, :now)',
                [
                    'id' => Uuid::fromHexToBytes($webhookId),
                    'state' => $suspend ? EndpointState::Suspended->value : EndpointState::Healthy->value,
                    'streak' => $nonTransientFailures,
                    'cooldown' => $suspend ? $this->cooldownAt(0) : null,
                    'since' => $suspend ? $now : null,
                    'now' => $now,
                ]
            );
        } catch (UniqueConstraintViolationException) {
            // Failure streaks are evidence, not an exact ledger; losing this race is safe.
            return false;
        }

        $this->mirrorBcColumns($webhookId);

        return $suspend;
    }

    private function promoteDegradedToHealthy(string $webhookId, bool $keepFailureStreaks): bool
    {
        return RetryableTransaction::retryable($this->connection, function () use ($webhookId, $keepFailureStreaks): bool {
            $row = $this->lockHealthRow($webhookId);
            if ($row === null || (string) $row['endpoint_state'] !== EndpointState::Degraded->value) {
                return false;
            }

            return $this->promoteDegradedToHealthyLocked($webhookId, $keepFailureStreaks);
        });
    }

    private function promoteDegradedToHealthyLocked(string $webhookId, bool $keepFailureStreaks): bool
    {
        if (!$this->resetToHealthy($webhookId, $keepFailureStreaks)) {
            return false;
        }

        // Keep the health row locked until the backlog and BC mirror match the new state.
        $this->outboxStore->resumeDeliveriesForWebhook($webhookId);
        $this->mirrorBcColumns($webhookId);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockHealthRow(string $webhookId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT endpoint_state, consecutive_transient_failures, consecutive_non_transient_failures,
                    degraded_cycle_count, cooldown_until, suspended_since
             FROM webhook_health WHERE webhook_id = :id FOR UPDATE',
            ['id' => Uuid::fromHexToBytes($webhookId)]
        );

        return \is_array($row) ? $row : null;
    }

    private function resetToHealthy(string $webhookId, bool $keepFailureStreaks): bool
    {
        return $this->connection->executeStatement(
            'UPDATE webhook_health
             SET endpoint_state = :healthy,
                 consecutive_transient_failures = IF(:keepFailureStreaks = 1, consecutive_transient_failures, 0),
                 consecutive_non_transient_failures = IF(:keepFailureStreaks = 1, consecutive_non_transient_failures, 0),
                 degraded_cycle_count = 0, cooldown_until = NULL, suspended_since = NULL,
                 disabled_since = NULL, disabled_origin = NULL, updated_at = :now
             WHERE webhook_id = :id AND endpoint_state <> :healthy',
            [
                'healthy' => EndpointState::Healthy->value,
                'keepFailureStreaks' => (int) $keepFailureStreaks,
                'now' => $this->now(),
                'id' => Uuid::fromHexToBytes($webhookId),
            ]
        ) > 0;
    }

    private function mirrorBcColumns(string $webhookId): void
    {
        $this->connection->executeStatement(
            'UPDATE webhook w
             JOIN webhook_health wh ON wh.webhook_id = w.id
             SET w.active = IF(wh.endpoint_state IN (:healthy, :degraded), 1, 0),
                 w.error_count = IF(
                     wh.endpoint_state = :healthy,
                     0,
                     GREATEST(wh.consecutive_transient_failures, wh.consecutive_non_transient_failures)
                 )
             WHERE w.id = :id',
            [
                'healthy' => EndpointState::Healthy->value,
                'degraded' => EndpointState::Degraded->value,
                'id' => Uuid::fromHexToBytes($webhookId),
            ]
        );
    }

    private function cooldownAt(int $index): string
    {
        $schedule = $this->config->cooldownScheduleSeconds;
        $seconds = $schedule[min($index, \count($schedule) - 1)];

        return $this->clock->now()
            ->modify(\sprintf('+%d seconds', $seconds))
            ->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    private function currentState(string $webhookId): EndpointState
    {
        $state = $this->connection->fetchOne(
            'SELECT endpoint_state FROM webhook_health WHERE webhook_id = :id',
            ['id' => Uuid::fromHexToBytes($webhookId)]
        );

        // Missing health rows fail open during rollout.
        return $state === false ? EndpointState::Healthy : EndpointState::from((string) $state);
    }

    private function now(): string
    {
        return $this->clock->now()->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }
}
