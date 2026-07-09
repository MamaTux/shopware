<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Subscriber;

use Shopware\Core\Framework\App\Event\AppActivatedEvent;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Service\WebhookHealthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the final paused interval when an app resumes, including periods without a health tick.
 *
 * @internal
 */
#[Package('framework')]
class ResumeSuspensionClockOnAppActivationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookHealthService $healthService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AppActivatedEvent::class => 'onAppActivated',
        ];
    }

    public function onAppActivated(AppActivatedEvent $event): void
    {
        if (!Feature::isActive('WEBHOOKS_REWORK')) {
            return;
        }

        $this->healthService->resumeSuspensionClockForApp($event->getApp()->getId());
    }
}
