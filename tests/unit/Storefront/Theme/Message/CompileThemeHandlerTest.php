<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Storefront\Theme\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Core\Test\TestDefaults;
use Shopware\Storefront\Theme\ConfigLoader\AbstractConfigLoader;
use Shopware\Storefront\Theme\Event\ThemeAssignedEvent;
use Shopware\Storefront\Theme\Message\CompileThemeHandler;
use Shopware\Storefront\Theme\Message\CompileThemeMessage;
use Shopware\Storefront\Theme\StorefrontPluginRegistry;
use Shopware\Storefront\Theme\ThemeCompiler;
use Shopware\Storefront\Theme\ThemeRuntimeConfigService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 */
#[Package('discovery')]
#[CoversClass(CompileThemeHandler::class)]
class CompileThemeHandlerTest extends TestCase
{
    public function testHandleMessageCompile(): void
    {
        $themeCompilerMock = $this->createMock(ThemeCompiler::class);
        $notificationServiceMock = static::createStub(NotificationService::class);
        $themeId = Uuid::randomHex();
        $context = Context::createDefaultContext();
        $message = new CompileThemeMessage(TestDefaults::SALES_CHANNEL, $themeId, true, $context);

        $themeCompilerMock->expects($this->once())->method('compileTheme');

        $scEntity = new SalesChannelEntity();
        $scEntity->setUniqueIdentifier(Uuid::randomHex());
        $scEntity->setName('Test SalesChannel');

        /** @var StaticEntityRepository<SalesChannelCollection> $salesChannelRep */
        $salesChannelRep = new StaticEntityRepository([new EntityCollection([$scEntity])]);

        // without the assign flag the relation must not be touched and no event dispatched
        $themeSalesChannelRep = $this->createMock(EntityRepository::class);
        $themeSalesChannelRep->expects($this->never())->method('upsert');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $handler = new CompileThemeHandler(
            $themeCompilerMock,
            static::createStub(AbstractConfigLoader::class),
            static::createStub(StorefrontPluginRegistry::class),
            $notificationServiceMock,
            $salesChannelRep,
            static::createStub(ThemeRuntimeConfigService::class),
            $themeSalesChannelRep,
            $eventDispatcher,
        );

        $handler($message);
    }

    public function testHandleMessageAssignsThemeAfterCompile(): void
    {
        $themeCompilerMock = $this->createMock(ThemeCompiler::class);
        $themeId = Uuid::randomHex();
        $context = Context::createDefaultContext();
        $message = new CompileThemeMessage(TestDefaults::SALES_CHANNEL, $themeId, true, $context, true);

        $themeCompilerMock->expects($this->once())->method('compileTheme');

        /** @var StaticEntityRepository<SalesChannelCollection> $salesChannelRep */
        $salesChannelRep = new StaticEntityRepository([]);

        // with the assign flag set, the relation is upserted after compilation ...
        $themeSalesChannelRep = $this->createMock(EntityRepository::class);
        $themeSalesChannelRep->expects($this->once())->method('upsert')->with(
            [[
                'themeId' => $themeId,
                'salesChannelId' => TestDefaults::SALES_CHANNEL,
            ]],
            $context
        );

        // ... and the assignment event is dispatched so caches are invalidated
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->once())->method('dispatch')->with(
            new ThemeAssignedEvent($themeId, TestDefaults::SALES_CHANNEL, $context)
        );

        $handler = new CompileThemeHandler(
            $themeCompilerMock,
            static::createStub(AbstractConfigLoader::class),
            static::createStub(StorefrontPluginRegistry::class),
            static::createStub(NotificationService::class),
            $salesChannelRep,
            static::createStub(ThemeRuntimeConfigService::class),
            $themeSalesChannelRep,
            $eventDispatcher,
        );

        $handler($message);
    }

    public function testHandleMessageNotifiesUserAndRethrowsWhenCompilationFails(): void
    {
        $themeId = Uuid::randomHex();
        // AdminApiSource -> USER_SCOPE, so the failure notification is created
        $context = Context::createDefaultContext(new AdminApiSource(Uuid::randomHex()));
        $message = new CompileThemeMessage(TestDefaults::SALES_CHANNEL, $themeId, true, $context, true);

        $themeCompiler = static::createStub(ThemeCompiler::class);
        $themeCompiler->method('compileTheme')->willThrowException(new \RuntimeException('compile failed'));

        // the user is notified about the failed background compilation ...
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->once())->method('createNotification')->with(
            static::callback(static fn (array $notification): bool => $notification['status'] === 'error'
                && $notification['message'] === 'sw-theme-manager.detail.asyncCompilation.error'),
            $context
        );

        // ... the assignment must not be applied when the compile failed ...
        $themeSalesChannelRep = $this->createMock(EntityRepository::class);
        $themeSalesChannelRep->expects($this->never())->method('upsert');

        /** @var StaticEntityRepository<SalesChannelCollection> $salesChannelRep */
        $salesChannelRep = new StaticEntityRepository([]);

        $handler = new CompileThemeHandler(
            $themeCompiler,
            static::createStub(AbstractConfigLoader::class),
            static::createStub(StorefrontPluginRegistry::class),
            $notificationService,
            $salesChannelRep,
            static::createStub(ThemeRuntimeConfigService::class),
            $themeSalesChannelRep,
            static::createStub(EventDispatcherInterface::class),
        );

        // ... and the exception propagates so the messenger can retry / dead-letter the message
        $this->expectException(\RuntimeException::class);
        $handler($message);
    }
}
