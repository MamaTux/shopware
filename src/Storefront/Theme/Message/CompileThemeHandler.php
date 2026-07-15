<?php declare(strict_types=1);

namespace Shopware\Storefront\Theme\Message;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Storefront\Theme\ConfigLoader\AbstractConfigLoader;
use Shopware\Storefront\Theme\Event\ThemeAssignedEvent;
use Shopware\Storefront\Theme\Exception\ThemeException;
use Shopware\Storefront\Theme\StorefrontPluginRegistry;
use Shopware\Storefront\Theme\ThemeCompilerInterface;
use Shopware\Storefront\Theme\ThemeRuntimeConfigService;
use Shopware\Storefront\Theme\ThemeService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[AsMessageHandler]
#[Package('framework')]
final readonly class CompileThemeHandler
{
    /**
     * @param EntityRepository<SalesChannelCollection> $saleschannelRepository
     * @param EntityRepository<EntityCollection<Entity>> $themeSalesChannelRepository
     */
    public function __construct(
        private ThemeCompilerInterface $themeCompiler,
        private AbstractConfigLoader $configLoader,
        private StorefrontPluginRegistry $extensionRegistry,
        private NotificationService $notificationService,
        private EntityRepository $saleschannelRepository,
        private ThemeRuntimeConfigService $runtimeConfigService,
        private EntityRepository $themeSalesChannelRepository,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(CompileThemeMessage $message): void
    {
        $message->getContext()->addState(ThemeService::STATE_NO_QUEUE);
        $themeConfig = $this->configLoader->load($message->getThemeId(), $message->getContext());
        $this->themeCompiler->compileTheme(
            $message->getSalesChannelId(),
            $message->getThemeId(),
            $themeConfig,
            $this->extensionRegistry->getConfigurations(),
            $message->isWithAssets(),
            $message->getContext()
        );

        $this->runtimeConfigService->refreshRuntimeConfig(
            $message->getThemeId(),
            $themeConfig,
            $message->getContext(),
            false,
            $this->extensionRegistry->getConfigurations(),
        );

        if ($message->isAssign()) {
            // Compiled now, so apply the deferred assignment; until here the storefront kept
            // serving the previous theme.
            $this->themeSalesChannelRepository->upsert([[
                'themeId' => $message->getThemeId(),
                'salesChannelId' => $message->getSalesChannelId(),
            ]], $message->getContext());

            $this->eventDispatcher->dispatch(
                new ThemeAssignedEvent($message->getThemeId(), $message->getSalesChannelId(), $message->getContext())
            );
        }

        if ($message->getContext()->getScope() !== Context::USER_SCOPE) {
            return;
        }

        $salesChannel = $this->saleschannelRepository->search(
            new Criteria([$message->getSalesChannelId()]),
            $message->getContext()
        )->getEntities()->first();
        if (!$salesChannel) {
            throw ThemeException::salesChannelNotFound($message->getSalesChannelId());
        }

        $this->notificationService->createNotification(
            [
                'id' => Uuid::randomHex(),
                'status' => 'info',
                'message' => 'sw-theme-manager.detail.asyncCompilation.completed',
                'requiredPrivileges' => [],
            ],
            $message->getContext()
        );
    }
}
