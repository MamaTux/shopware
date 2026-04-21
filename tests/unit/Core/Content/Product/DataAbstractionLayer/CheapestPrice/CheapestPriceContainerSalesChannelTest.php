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

    /**
     * Regression for #16239: the cheapest variant must not be a hidden
     * closeout variant that is out of stock when the
     * `hideCloseoutProductsWhenOutOfStock` setting is active, otherwise
     * the listing shows a "from" price that the customer can never buy.
     */
    public function testResolveHidesCloseoutOutOfStockVariantFromAggregation(): void
    {
        $currentSalesChannelId = Uuid::randomHex();

        $testData = [
            // Cheapest, but hidden closeout + out of stock -> must be skipped
            'variant1' => [
                'default' => [
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 50.0, 'net' => 42.02, 'linked' => true],
                    ],
                    'sales_channel_ids' => [$currentSalesChannelId],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                    'is_closeout' => true,
                    'available' => false,
                ],
            ],
            // Next cheapest available variant -> should be picked
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
                    'is_closeout' => true,
                    'available' => true,
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

        // Default behaviour (flag off) keeps legacy output and picks the cheapest row.
        $cheapestWithoutFlag = $container->resolve($context);
        static::assertNotNull($cheapestWithoutFlag);
        static::assertSame('variant1', $cheapestWithoutFlag->getVariantId());
        $firstPriceNoFlag = $cheapestWithoutFlag->getPrice()->first();
        static::assertNotNull($firstPriceNoFlag);
        static::assertSame(50.0, $firstPriceNoFlag->getGross());

        // With the flag enabled, the out-of-stock closeout variant is excluded.
        $cheapest = $container->resolveExcludingHiddenCloseoutVariants($context);
        static::assertNotNull($cheapest);
        static::assertSame('variant2', $cheapest->getVariantId());

        $firstPrice = $cheapest->getPrice()->first();
        static::assertNotNull($firstPrice);
        static::assertSame(100.0, $firstPrice->getGross());
    }

    public function testResolveHideCloseoutFlagKeepsAvailableCloseoutVariant(): void
    {
        $currentSalesChannelId = Uuid::randomHex();

        $testData = [
            'variant1' => [
                'default' => [
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 50.0, 'net' => 42.02, 'linked' => true],
                    ],
                    'sales_channel_ids' => [$currentSalesChannelId],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                    // closeout, but still available -> must remain in the aggregation
                    'is_closeout' => true,
                    'available' => true,
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
        $cheapest = $container->resolveExcludingHiddenCloseoutVariants($context);

        static::assertNotNull($cheapest);
        static::assertSame('variant1', $cheapest->getVariantId());
    }

    /**
     * Backwards compatibility: price rows serialized before the availability fix
     * (#16239) were persisted without the `is_closeout` / `available` keys. They
     * must still resolve with the hide-closeout flag enabled — i.e. fall back to
     * the previous behavior instead of being silently dropped.
     */
    public function testResolveHideCloseoutFlagFallsBackForLegacySerializedRowsWithoutFlags(): void
    {
        $currentSalesChannelId = Uuid::randomHex();

        $testData = [
            'variant1' => [
                'default' => [
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 50.0, 'net' => 42.02, 'linked' => true],
                    ],
                    'sales_channel_ids' => [$currentSalesChannelId],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                    // No is_closeout / available — shape predating #16239.
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

        $cheapest = (new CheapestPriceContainer($testData))->resolveExcludingHiddenCloseoutVariants($context);

        static::assertNotNull($cheapest, 'Pre-#16239 serialized rows must still resolve with the hide-closeout flag on');
        static::assertSame('variant1', $cheapest->getVariantId());
    }

    public function testResolveHideCloseoutFlagReturnsNullWhenEveryVariantHidden(): void
    {
        $currentSalesChannelId = Uuid::randomHex();

        $testData = [
            'variant1' => [
                'default' => [
                    'price' => [
                        ['currencyId' => Defaults::CURRENCY, 'gross' => 50.0, 'net' => 42.02, 'linked' => true],
                    ],
                    'sales_channel_ids' => [$currentSalesChannelId],
                    'is_ranged' => false,
                    'rule_id' => 'default',
                    'parent_id' => 'parent1',
                    'purchase_unit' => 1.0,
                    'reference_unit' => 1.0,
                    'is_closeout' => true,
                    'available' => false,
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

        static::assertNull($container->resolveExcludingHiddenCloseoutVariants($context));
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
}
