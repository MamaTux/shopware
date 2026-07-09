<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Subscriber;

use Shopware\Core\Framework\App\Event\AppInstalledEvent;
use Shopware\Core\Framework\App\Event\AppUpdatedEvent;
use Shopware\Core\Framework\App\Event\ManifestChangedEvent;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Service\WebhookHealthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Resets eligible webhook health after an app install or update without undoing operator disables.
 *
 * @internal
 */
#[Package('framework')]
class ReactivateWebhooksOnAppReregistrationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookHealthService $healthService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AppInstalledEvent::class => 'reactivate',
            AppUpdatedEvent::class => 'reactivate',
        ];
    }

    public function reactivate(ManifestChangedEvent $event): void
    {
        if (!Feature::isActive('WEBHOOKS_REWORK')) {
            return;
        }

        $this->healthService->reactivateForApp($event->getApp()->getId());
    }
}
