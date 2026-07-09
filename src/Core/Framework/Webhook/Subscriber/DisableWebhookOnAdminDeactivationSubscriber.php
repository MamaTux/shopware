<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Service\WebhookHealthService;
use Shopware\Core\Framework\Webhook\WebhookDefinition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Treats an explicit `active = false` update as an operator disable. The health service applies
 * the mirrored-value echo guard.
 *
 * @internal
 */
#[Package('framework')]
class DisableWebhookOnAdminDeactivationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookHealthService $healthService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WebhookDefinition::ENTITY_NAME . '.written' => 'onWebhookWritten',
        ];
    }

    public function onWebhookWritten(EntityWrittenEvent $event): void
    {
        if (!Feature::isActive('WEBHOOKS_REWORK')) {
            return;
        }

        foreach ($event->getWriteResults() as $writeResult) {
            if ($writeResult->getOperation() !== EntityWriteResult::OPERATION_UPDATE) {
                continue;
            }

            $payload = $writeResult->getPayload();
            if (($payload['active'] ?? null) !== false) {
                continue;
            }

            $webhookId = $payload['id'] ?? null;
            if (!\is_string($webhookId)) {
                continue;
            }

            $this->healthService->disableByOperatorOnActiveFlip($webhookId);
        }
    }
}
