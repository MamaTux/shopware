<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Subscriber;

use Shopware\Core\Framework\App\Event\AppDeactivatedEvent;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Service\WebhookHealthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Pauses SUSPENDED escalation while a deactivated app cannot run recovery trials.
 *
 * @internal
 */
#[Package('framework')]
class PauseSuspensionClockOnAppDeactivationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookHealthService $healthService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AppDeactivatedEvent::class => 'onAppDeactivated',
        ];
    }

    public function onAppDeactivated(AppDeactivatedEvent $event): void
    {
        if (!Feature::isActive('WEBHOOKS_REWORK')) {
            return;
        }

        $this->healthService->pauseSuspensionClockForApp($event->getApp()->getId());
    }
}
