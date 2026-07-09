<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Core\Framework\Webhook\Event;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\AclPrivilegeCollection;
use Shopware\Core\Framework\Webhook\Event\WebhookActivatedEvent;
use Shopware\Core\Framework\Webhook\Event\WebhookActivationTrigger;
use Shopware\Core\Framework\Webhook\Event\WebhookDegradedEvent;
use Shopware\Core\Framework\Webhook\Event\WebhookDisabledEvent;
use Shopware\Core\Framework\Webhook\Event\WebhookSuspendedEvent;
use Shopware\Core\Framework\Webhook\Health\DisabledOrigin;
use Shopware\Core\Framework\Webhook\Health\EndpointState;
use Shopware\Core\Framework\Webhook\Health\SuspensionCause;

/**
 * @internal
 */
#[Package('framework')]
class WebhookLifecycleEventsTest extends TestCase
{
    public function testPayloadsCarryIdsNamesStateAndTimesOnlyNeverTheUrl(): void
    {
        $since = new \DateTimeImmutable('2026-06-01 12:00:00');
        $occurredAt = new \DateTimeImmutable('2026-06-02 08:30:00');
        $payloads = [
            (new WebhookActivatedEvent('wh-id', 'app-id', EndpointState::Degraded, WebhookActivationTrigger::Trial, 'order-sync', 'checkout.order.placed', $occurredAt, $since))->getWebhookPayload(),
            (new WebhookDegradedEvent('wh-id', 'app-id', EndpointState::Healthy, 'order-sync', 'checkout.order.placed', $occurredAt))->getWebhookPayload(),
            (new WebhookSuspendedEvent('wh-id', 'app-id', EndpointState::Healthy, $since, SuspensionCause::AuthStreak, 'order-sync', 'checkout.order.placed', $occurredAt))->getWebhookPayload(),
            (new WebhookDisabledEvent('wh-id', 'app-id', EndpointState::Suspended, DisabledOrigin::Escalation, 'order-sync', 'checkout.order.placed', $occurredAt))->getWebhookPayload(),
        ];

        foreach ($payloads as $payload) {
            static::assertSame('wh-id', $payload['webhookId']);
            static::assertSame('order-sync', $payload['webhookName']);
            static::assertSame('checkout.order.placed', $payload['eventName']);
            static::assertSame($occurredAt->format(\DateTimeInterface::ATOM), $payload['occurredAt']);
            static::assertArrayNotHasKey('url', $payload);
            foreach ($payload as $value) {
                static::assertTrue($value === null || \is_string($value), 'payload values are scalar ids/state only');
            }
        }

        static::assertSame('trial', $payloads[0]['trigger']);
        static::assertSame($since->format(\DateTimeInterface::ATOM), $payloads[2]['suspendedSince']);
        static::assertSame('auth_streak', $payloads[2]['cause']);
        static::assertSame('escalation', $payloads[3]['origin']);
    }

    public function testOnlyTheOwningAppMaySeeItsEndpointsHealth(): void
    {
        $event = $this->suspendedEvent('wh-id', 'owner-app');
        $permissions = new AclPrivilegeCollection([]);

        static::assertTrue($event->isAllowed('owner-app', $permissions));
        static::assertFalse($event->isAllowed('another-app', $permissions), 'one app must never see another app\'s failures');

        $appless = $this->suspendedEvent('wh-id', null);
        static::assertFalse($appless->isAllowed('any-app', $permissions), 'an app-less webhook\'s health is nobody\'s business event');
    }

    private function suspendedEvent(string $webhookId, ?string $appId): WebhookSuspendedEvent
    {
        return new WebhookSuspendedEvent(
            $webhookId,
            $appId,
            EndpointState::Healthy,
            new \DateTimeImmutable('2026-06-01 12:00:00'),
            SuspensionCause::AuthStreak,
            'order-sync',
            'checkout.order.placed',
            new \DateTimeImmutable('2026-06-01 12:00:00'),
        );
    }
}
