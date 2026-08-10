<?php declare(strict_types=1);

namespace Shopware\Tests\Integration\Storefront\Framework\Twig;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Category\SalesChannel\SalesChannelCategoryEntity;
use Shopware\Core\Content\Category\Tree\Tree;
use Shopware\Core\Content\Category\Tree\TreeItem;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Twig\Environment;

/**
 * @internal
 */
#[Package('framework')]
class NavigationTreeComponentTest extends TestCase
{
    use IntegrationTestBehaviour;

    public function testRendersNestedListsWithSingleNavLandmark(): void
    {
        $child = $this->treeItem('Shirts', '/shirts');
        $parent = $this->treeItem('Clothing', '/clothing', children: [$child]);

        $html = $this->render([
            'navigationTree' => [$parent],
            'expandAll' => true,
        ]);

        static::assertStringContainsString('<nav', $html);
        static::assertSame(1, substr_count($html, '<nav'));
        static::assertSame(1, substr_count($html, 'is--root'));
        static::assertSame(2, substr_count($html, '<ul'));
        static::assertStringContainsString('href="/clothing"', $html);
        static::assertStringContainsString('href="/shirts"', $html);
    }

    public function testMarksActiveItemOnlyOnce(): void
    {
        $active = $this->treeItem('Shirts', '/shirts');
        $other = $this->treeItem('Shoes', '/shoes');

        $html = $this->render([
            'navigationTree' => [$active, $other],
            'activeId' => $active->getCategory()->getId(),
        ]);

        static::assertSame(1, substr_count($html, 'aria-current="page"'));
        static::assertSame(1, substr_count($html, ' active'));
        static::assertStringContainsString('href="/shirts"', $html);
    }

    public function testRendersFolderAsNonInteractiveText(): void
    {
        $folder = $this->treeItem('Structure', null, CategoryDefinition::TYPE_FOLDER);

        $html = $this->render(['navigationTree' => [$folder]]);

        static::assertStringContainsString('sw-navigation-tree-items__folder', $html);
        static::assertStringContainsString('Structure', $html);
        static::assertStringNotContainsString('<a', $html);
    }

    public function testRendersMisconfiguredLinkAsNonInteractiveText(): void
    {
        $brokenLink = $this->treeItem('Broken', null, CategoryDefinition::TYPE_LINK);

        $html = $this->render(['navigationTree' => [$brokenLink]]);

        static::assertStringContainsString('sw-navigation-tree-items__folder', $html);
        static::assertStringNotContainsString('<a', $html);
        static::assertStringNotContainsString('href=""', $html);
    }

    public function testStopsRecursionAtMaxDepth(): void
    {
        $level3 = $this->treeItem('Level3', '/level-3');
        $level2 = $this->treeItem('Level2', '/level-2', children: [$level3]);
        $level1 = $this->treeItem('Level1', '/level-1', children: [$level2]);

        $html = $this->render([
            'navigationTree' => [$level1],
            'navigationMaxDepth' => 2,
            'expandAll' => true,
        ]);

        static::assertStringContainsString('Level1', $html);
        static::assertStringContainsString('Level2', $html);
        static::assertStringNotContainsString('Level3', $html);
    }

    public function testRendersChildrenOnlyForBranchOnActivePath(): void
    {
        $activeChild = $this->treeItem('ActiveChild', '/active-child');
        $activeBranch = $this->treeItem('ActiveBranch', '/active-branch', children: [$activeChild]);

        $collapsedChild = $this->treeItem('CollapsedChild', '/collapsed-child');
        $collapsedBranch = $this->treeItem('CollapsedBranch', '/collapsed-branch', children: [$collapsedChild]);

        $html = $this->render([
            'navigationTree' => [$activeBranch, $collapsedBranch],
            'activePath' => [$activeBranch->getCategory()->getId()],
        ]);

        static::assertStringContainsString('ActiveChild', $html);
        static::assertStringNotContainsString('CollapsedChild', $html);
        static::assertStringContainsString('is--in-path', $html);
    }

    public function testExpandAllRendersCollapsedBranches(): void
    {
        $child = $this->treeItem('HiddenChild', '/hidden-child');
        $branch = $this->treeItem('Branch', '/branch', children: [$child]);

        $collapsed = $this->render(['navigationTree' => [$branch]]);
        $expanded = $this->render(['navigationTree' => [$branch], 'expandAll' => true]);

        static::assertStringNotContainsString('HiddenChild', $collapsed);
        static::assertStringContainsString('HiddenChild', $expanded);
    }

    public function testAcceptsTreeStructAsWellAsItemList(): void
    {
        $item = $this->treeItem('Clothing', '/clothing');

        $fromList = $this->render(['navigationTree' => [$item]]);
        $fromTree = $this->render(['navigationTree' => new Tree(null, [$item])]);

        static::assertStringContainsString('href="/clothing"', $fromList);
        static::assertSame($fromList, $fromTree);
    }

    public function testRendersNothingForEmptyTree(): void
    {
        $html = $this->render(['navigationTree' => []]);

        static::assertStringNotContainsString('<nav', $html);
        static::assertStringNotContainsString('<ul', $html);
    }

    public function testOpensLinkCategoryInNewTab(): void
    {
        $item = $this->treeItem(
            'External',
            'https://example.com',
            CategoryDefinition::TYPE_LINK,
            ['linkNewTab' => true]
        );

        $html = $this->render(['navigationTree' => [$item]]);

        static::assertStringContainsString('target="_blank"', $html);
        static::assertStringContainsString('href="https://example.com"', $html);
    }

    /**
     * Every prop is passed explicitly so the component's global-reading defaults
     * (`shopware.navigation`, `context.salesChannel`, the translator) are never evaluated.
     *
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        $twig = static::getContainer()->get('twig');
        static::assertInstanceOf(Environment::class, $twig);

        $props = \array_merge([
            'navigationMaxDepth' => 3,
            'expandAll' => false,
            'activeId' => Uuid::randomHex(),
            'activePath' => [],
            'ariaLabel' => 'Categories',
        ], $props);

        return $twig
            ->createTemplate('{{ component(\'Sw:Navigation:Tree\', props) }}')
            ->render(['props' => $props]);
    }

    /**
     * @param TreeItem[] $children
     * @param array<string, mixed> $translated
     */
    private function treeItem(
        string $name,
        ?string $seoUrl,
        string $type = CategoryDefinition::TYPE_PAGE,
        array $translated = [],
        array $children = []
    ): TreeItem {
        $category = new SalesChannelCategoryEntity();
        $category->setId(Uuid::randomHex());
        $category->setType($type);
        $category->setTranslated(\array_merge(['name' => $name], $translated));

        if ($seoUrl !== null) {
            $category->setSeoUrl($seoUrl);
        }

        return new TreeItem($category, $children);
    }
}
