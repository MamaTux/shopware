<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Product\DataAbstractionLayer\CheapestPrice;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPriceContainer;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
#[CoversClass(CheapestPriceContainer::class)]
class CheapestPriceContainerSalesChannelTest extends TestCase
{
    public function testHasListPriceRangeUsesNetTaxStateConsistently(): void
    {
        $context = Context::createDefaultContext();
        $context->setTaxState(CartPrice::TAX_STATE_NET);

        $container = new CheapestPriceContainer([
            'variant1' => [
                'default' => [
                    'price' => [[
                        'currencyId' => Defaults::CURRENCY,
                        'gross' => 10.0,
                        'net' => 8.4,
                        'linked' => true,
                        'listPrice' => ['gross' => 20.0, 'net' => 16.8, 'linked' => true],
                        'percentage' => ['gross' => 50.0, 'net' => 50.0],
                    ]],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                ],
            ],
            'variant2' => [
                'default' => [
                    'price' => [[
                        'currencyId' => Defaults::CURRENCY,
                        'gross' => 10.0,
                        'net' => 8.4,
                        'linked' => true,
                        'listPrice' => ['gross' => 20.0, 'net' => 16.8, 'linked' => true],
                        'percentage' => ['gross' => 50.0, 'net' => 50.0],
                    ]],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                ],
            ],
        ]);

        static::assertFalse($container->hasListPriceRange($context));
    }

    public function testHasListPriceRangeDetectsDifferentListPrices(): void
    {
        $context = Context::createDefaultContext();
        $context->setTaxState(CartPrice::TAX_STATE_NET);

        $container = new CheapestPriceContainer([
            'variant1' => [
                'default' => [
                    'price' => [[
                        'currencyId' => Defaults::CURRENCY,
                        'gross' => 10.0,
                        'net' => 8.4,
                        'linked' => true,
                        'listPrice' => ['gross' => 20.0, 'net' => 16.8, 'linked' => true],
                        'percentage' => ['gross' => 50.0, 'net' => 50.0],
                    ]],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                ],
            ],
            'variant2' => [
                'default' => [
                    'price' => [[
                        'currencyId' => Defaults::CURRENCY,
                        'gross' => 10.0,
                        'net' => 8.4,
                        'linked' => true,
                        'listPrice' => ['gross' => 30.0, 'net' => 25.2, 'linked' => true],
                        'percentage' => ['gross' => 66.67, 'net' => 66.67],
                    ]],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                ],
            ],
        ]);

        static::assertTrue($container->hasListPriceRange($context));
    }

    public function testIsVariantAvailableInSalesChannelWithMatchingId(): void
    {
        $salesChannelId = Uuid::randomHex();
        $group = [
            'default' => [
                'price' => [
                    ['currencyId' => Defaults::CURRENCY, 'gross' => 100.0, 'net' => 84.03, 'linked' => true],
                ],
                'sales_channel_ids' => [$salesChannelId],
                'is_ranged' => false,
                'rule_id' => 'default',
                'parent_id' => 'parent1',
                'purchase_unit' => 1.0,
                'reference_unit' => 1.0,
            ],
        ];

        $container = new CheapestPriceContainer([]);
        $reflection = new \ReflectionClass($container);
        $method = $reflection->getMethod('isVariantPriceAvailableInSalesChannel');
        $method->setAccessible(true);

        $price = $group['default'];
        $result = $method->invoke($container, $price, $salesChannelId);

        static::assertTrue($result);
    }

    public function testIsVariantAvailableInSalesChannelWithNonMatchingId(): void
    {
        $salesChannelId = Uuid::randomHex();
        $otherSalesChannelId = Uuid::randomHex();
        $group = [
            'default' => [
                'price' => [
                    ['currencyId' => Defaults::CURRENCY, 'gross' => 100.0, 'net' => 84.03, 'linked' => true],
                ],
                'sales_channel_ids' => [$otherSalesChannelId],
                'is_ranged' => false,
                'rule_id' => 'default',
                'parent_id' => 'parent1',
                'purchase_unit' => 1.0,
                'reference_unit' => 1.0,
            ],
        ];

        $container = new CheapestPriceContainer([]);
        $reflection = new \ReflectionClass($container);
        $method = $reflection->getMethod('isVariantPriceAvailableInSalesChannel');
        $method->setAccessible(true);

        $price = $group['default'];
        $result = $method->invoke($container, $price, $salesChannelId);

        static::assertFalse($result);
    }

    public function testIsVariantAvailableInSalesChannelWithoutIds(): void
    {
        $salesChannelId = Uuid::randomHex();
        $group = [
            'default' => [
                'price' => [
                    ['currencyId' => Defaults::CURRENCY, 'gross' => 100.0, 'net' => 84.03, 'linked' => true],
                ],
                'is_ranged' => false,
                'rule_id' => 'default',
                'parent_id' => 'parent1',
                'purchase_unit' => 1.0,
                'reference_unit' => 1.0,
            ],
        ];

        $container = new CheapestPriceContainer([]);
        $reflection = new \ReflectionClass($container);
        $method = $reflection->getMethod('isVariantPriceAvailableInSalesChannel');
        $method->setAccessible(true);

        $price = $group['default'];
        $result = $method->invoke($container, $price, $salesChannelId);

        static::assertTrue($result);
    }

    public function testResolveWithSalesChannelFiltering(): void
    {
        $currentSalesChannelId = Uuid::randomHex();
        $otherSalesChannelId = Uuid::randomHex();

        $testData = [
            'variant1' => [
                'default' => [
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 50.0, 'net' => 42.02, 'linked' => true],
                    ],
                    'sales_channel_ids' => [$otherSalesChannelId],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                ],
            ],
            'variant2' => [
                'default' => [
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 100.0, 'net' => 84.03, 'linked' => true],
                    ],
                    'sales_channel_ids' => [$currentSalesChannelId],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                ],
            ],
        ];

        $context = new Context(
            new SalesChannelApiSource($currentSalesChannelId),
            [],
            Defaults::CURRENCY,
            [Defaults::LANGUAGE_SYSTEM],
            Defaults::LIVE_VERSION,
            1.0,
            true,
            CartPrice::TAX_STATE_GROSS
        );

        $container = new CheapestPriceContainer($testData);
        $cheapestPrice = $container->resolve($context);

        static::assertNotNull($cheapestPrice);
        static::assertSame('variant2', $cheapestPrice->getVariantId());

        $firstPrice = $cheapestPrice->getPrice()->first();
        static::assertNotNull($firstPrice);
        static::assertSame(100.0, $firstPrice->getGross());
    }

    public function testResolveWithNoMatchingSalesChannel(): void
    {
        $currentSalesChannelId = Uuid::randomHex();
        $otherSalesChannelId = Uuid::randomHex();

        $testData = [
            'variant1' => [
                'default' => [
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 50.0, 'net' => 42.02, 'linked' => true],
                    ],
                    'sales_channel_ids' => [$otherSalesChannelId],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                ],
            ],
        ];

        $context = new Context(
            new SalesChannelApiSource($currentSalesChannelId),
            [],
            Defaults::CURRENCY,
            [Defaults::LANGUAGE_SYSTEM],
            Defaults::LIVE_VERSION,
            1.0,
            true,
            CartPrice::TAX_STATE_GROSS
        );

        $container = new CheapestPriceContainer($testData);
        $cheapestPrice = $container->resolve($context);

        static::assertNull($cheapestPrice);
    }

    public function testHasListPriceRangeCountsPositiveAndMissingListPrices(): void
    {
        $container = new CheapestPriceContainer([
            'variant1' => ['default' => $this->createVariantPrice(10.0, 20.0)],
            'variant2' => ['default' => $this->createVariantPrice(10.0)],
        ]);

        static::assertTrue($container->hasListPriceRange($this->createSalesChannelContext()));
    }

    public function testHasListPriceRangeIgnoresZeroPercentListPrices(): void
    {
        $container = new CheapestPriceContainer([
            'variant1' => ['default' => $this->createVariantPrice(10.0, 10.0)],
            'variant2' => ['default' => $this->createVariantPrice(10.0)],
        ]);

        static::assertFalse($container->hasListPriceRange($this->createSalesChannelContext()));
    }

    public function testHasDisplayableListPriceIgnoresZeroPercentListPrices(): void
    {
        $container = new CheapestPriceContainer([
            'variant1' => ['default' => $this->createVariantPrice(10.0, 10.0)],
            'variant2' => ['default' => $this->createVariantPrice(10.0)],
        ]);

        static::assertFalse($container->hasDisplayableListPrice($this->createSalesChannelContext()));
    }

    public function testHasDisplayableListPriceCountsPositiveListPrices(): void
    {
        $container = new CheapestPriceContainer([
            'variant1' => ['default' => $this->createVariantPrice(10.0, 20.0)],
            'variant2' => ['default' => $this->createVariantPrice(10.0)],
        ]);

        static::assertTrue($container->hasDisplayableListPrice($this->createSalesChannelContext()));
    }

    public function testResolvePrefersDisplayableListPriceWhenUnitPricesAreEqual(): void
    {
        $container = new CheapestPriceContainer([
            'variant1' => ['default' => $this->createVariantPrice(12.0)],
            'variant2' => ['default' => $this->createVariantPrice(12.0, 20.0)],
        ]);

        $cheapestPrice = $container->resolve($this->createSalesChannelContext());

        static::assertNotNull($cheapestPrice);
        static::assertSame('variant2', $cheapestPrice->getVariantId());
        static::assertSame(20.0, $cheapestPrice->getCurrencyPrice(Defaults::CURRENCY)?->getListPrice()?->getGross());
    }

    public function testResolvePrefersLowerDisplayableListPriceWhenUnitPricesAreEqual(): void
    {
        $container = new CheapestPriceContainer([
            'variant1' => ['default' => $this->createVariantPrice(12.0, 30.0)],
            'variant2' => ['default' => $this->createVariantPrice(12.0, 20.0)],
        ]);

        $cheapestPrice = $container->resolve($this->createSalesChannelContext());

        static::assertNotNull($cheapestPrice);
        static::assertSame('variant2', $cheapestPrice->getVariantId());
        static::assertSame(20.0, $cheapestPrice->getCurrencyPrice(Defaults::CURRENCY)?->getListPrice()?->getGross());
    }

    private function createSalesChannelContext(): Context
    {
        return new Context(
            new SalesChannelApiSource(Uuid::randomHex()),
            [],
            Defaults::CURRENCY,
            [Defaults::LANGUAGE_SYSTEM],
            Defaults::LIVE_VERSION,
            1.0,
            true,
            CartPrice::TAX_STATE_GROSS
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createVariantPrice(float $gross, ?float $listPriceGross = null): array
    {
        $price = [
            'currencyId' => Defaults::CURRENCY,
            'gross' => $gross,
            'net' => $gross,
            'linked' => true,
        ];

        if ($listPriceGross !== null) {
            $price['listPrice'] = [
                'gross' => $listPriceGross,
                'net' => $listPriceGross,
                'linked' => true,
            ];
            $price['percentage'] = [
                'gross' => round(100 - $gross / $listPriceGross * 100, 2),
                'net' => round(100 - $gross / $listPriceGross * 100, 2),
            ];
        }

        return [
            'price' => [$price],
            'is_ranged' => false,
            'rule_id' => 'default',
            'parent_id' => 'parent1',
            'purchase_unit' => 1.0,
            'reference_unit' => 1.0,
        ];
    }
}
