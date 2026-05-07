<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Service\ServiceClientFactory;
use Shopware\Core\Service\ServiceRegistry\Client as ServiceRegistryClient;
use Shopware\Core\Service\ServiceRegistry\ServiceEntry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @internal
 */
#[CoversClass(ServiceClientFactory::class)]
class ServiceClientFactoryTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;

    private HttpClientInterface&MockObject $scopedClient;

    protected function setUp(): void
    {
        $this->scopedClient = $this->createMock(HttpClientInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testNewForServiceRegistryEntry(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('withOptions')
            ->with([
                'base_uri' => 'https://mycoolservice.com',
            ])
            ->willReturn($this->scopedClient);

        $serviceClientRegistry = static::createMock(ServiceRegistryClient::class);

        $clientFactory = new ServiceClientFactory($this->httpClient, $serviceClientRegistry, '6.6.0.0');
        $client = $clientFactory->newFor(new ServiceEntry('MyCoolService', 'My Cool Service', 'https://mycoolservice.com', '/app-endpoint'));

        static::assertSame($this->scopedClient, $client->client);
    }

    public function testFromNameProxiesToServiceRegistryClient(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('withOptions')
            ->with([
                'base_uri' => 'https://mycoolservice.com',
            ])
            ->willReturn($this->scopedClient);
        $serviceClientRegistry = static::createMock(ServiceRegistryClient::class);
        $serviceClientRegistry->expects($this->once())
            ->method('get')
            ->with('MyCoolService')
            ->willReturn(new ServiceEntry('MyCoolService', 'My Cool Service', 'https://mycoolservice.com', '/app-endpoint'));

        $clientFactory = new ServiceClientFactory($this->httpClient, $serviceClientRegistry, '6.6.0.0');
        $client = $clientFactory->fromName('MyCoolService');

        static::assertSame($this->scopedClient, $client->client);
    }
}
