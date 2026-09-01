<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Test\Unit\Model;

use Angeo\UcpCatalog\Model\CatalogProductService;
use Angeo\UcpCatalog\Model\CategoryResolver;
use Angeo\UcpCatalog\Model\ProductLoader;
use Angeo\UcpCatalog\Model\ProductMapper;
use Angeo\UcpCatalog\Model\ResponseBuilder;
use Angeo\UcpCatalog\Model\StoreContext;
use Angeo\UcpCatalog\Model\StoreContextData;
use Angeo\UcpCatalog\Model\VariantResolver;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * get_product's narrowing rules are the subtlest thing in this module: the
 * relaxation ORDER is specified (drop from the end of `preferences` first),
 * and `exists` vs `available` mean different things that are easy to
 * conflate. Both are pinned here.
 *
 * Matrix under test — note Red/Large is absent and Red/Small is out of stock:
 *
 *          Small          Large
 *   Blue   in stock       in stock
 *   Red    out of stock   (does not exist)
 */
#[CoversClass(CatalogProductService::class)]
#[CoversClass(VariantResolver::class)]
final class CatalogProductServiceTest extends TestCase
{
    #[Test]
    public function unconstrained_request_features_a_purchasable_variant(): void
    {
        [$status, $body] = $this->service()->getProduct(['id' => 'gid://magento/Product/10']);

        self::assertSame(200, $status);
        self::assertTrue($body['product']['variants'][0]['availability']['available']);
    }

    #[Test]
    public function exact_match_is_returned_without_a_warning(): void
    {
        [, $body] = $this->service()->getProduct([
            'id'       => 'gid://magento/Product/10',
            'selected' => [
                ['name' => 'Color', 'label' => 'Blue'],
                ['name' => 'Size',  'label' => 'Large'],
            ],
        ]);

        self::assertSame('SHIRT-BLUE-L', $body['product']['variants'][0]['sku']);
        self::assertArrayNotHasKey('messages', $body);
    }

    #[Test]
    public function preferences_relax_the_lowest_priority_option_first(): void
    {
        // Red/Large does not exist. `preferences` ranks Color above Size, so
        // Size is what gets dropped — the result must keep Red, not keep Large.
        [, $body] = $this->service()->getProduct([
            'id'          => 'gid://magento/Product/10',
            'selected'    => [
                ['name' => 'Color', 'label' => 'Red'],
                ['name' => 'Size',  'label' => 'Large'],
            ],
            'preferences' => ['Color', 'Size'],
        ]);

        self::assertSame('SHIRT-RED-S', $body['product']['variants'][0]['sku']);
        self::assertNotEmpty($body['messages']);
    }

    #[Test]
    public function reversing_preferences_relaxes_the_other_axis(): void
    {
        // Same impossible request, opposite priority: Size outranks Color, so
        // Large survives and Color is relaxed to Blue.
        [, $body] = $this->service()->getProduct([
            'id'          => 'gid://magento/Product/10',
            'selected'    => [
                ['name' => 'Color', 'label' => 'Red'],
                ['name' => 'Size',  'label' => 'Large'],
            ],
            'preferences' => ['Size', 'Color'],
        ]);

        self::assertSame('SHIRT-BLUE-L', $body['product']['variants'][0]['sku']);
    }

    #[Test]
    public function option_values_distinguish_exists_from_available(): void
    {
        [, $body] = $this->service()->getProduct([
            'id'       => 'gid://magento/Product/10',
            'selected' => [['name' => 'Color', 'label' => 'Red']],
        ]);

        $sizes = $this->axis($body['product']['options'], 'Size');

        // Red/Small is a real variant that happens to be out of stock.
        self::assertTrue($this->value($sizes, 'Small')['exists']);
        self::assertFalse($this->value($sizes, 'Small')['available']);

        // Red/Large was never built at all — a different fact entirely.
        self::assertFalse($this->value($sizes, 'Large')['exists']);
        self::assertFalse($this->value($sizes, 'Large')['available']);
    }

    #[Test]
    public function variant_identifier_resolves_to_the_parent_and_anchors_that_variant(): void
    {
        [, $body] = $this->service()->getProduct(['id' => 'gid://magento/ProductVariant/12']);

        // The response must carry the full option matrix, not just the child.
        self::assertCount(3, $body['product']['variants']);
        self::assertSame('SHIRT-BLUE-L', $body['product']['variants'][0]['sku']);

        $selected = array_column($body['product']['selected'], 'label', 'name');
        self::assertSame(['Color' => 'Blue', 'Size' => 'Large'], $selected);
    }

    #[Test]
    public function unknown_identifier_yields_a_spec_shaped_404(): void
    {
        [$status, $body] = $this->service()->getProduct(['id' => 'NOPE']);

        self::assertSame(404, $status);
        // error_response.json requires ucp.status === 'error' and minItems 1.
        self::assertSame('error', $body['ucp']['status']);
        self::assertNotEmpty($body['messages']);
        self::assertSame(['ucp', 'messages'], array_keys($body));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function service(): CatalogProductService
    {
        $children = [
            $this->child(11, 'SHIRT-BLUE-S', 'Shirt Blue / Small', 29.00, true, 101, 201),
            $this->child(12, 'SHIRT-BLUE-L', 'Shirt Blue / Large', 32.50, true, 101, 202),
            $this->child(13, 'SHIRT-RED-S', 'Shirt Red / Small', 29.00, false, 102, 201),
        ];

        $shirt = new Product([
            'id' => 10, 'sku' => 'SHIRT', 'name' => 'Classic Shirt',
            'description' => 'A classic shirt.', 'final_price' => 29.00,
            'is_salable' => true, 'type_id' => 'configurable',
            'type_instance' => new StubConfigurableType($children, $this->attributes()),
        ]);

        $mapper = new ProductMapper(new VariantResolver(new NullLogger()));

        return new CatalogProductService(
            new StubProductLoader([
                'gid://magento/Product/10'        => [$shirt, null],
                'gid://magento/ProductVariant/12' => [$shirt, 'gid://magento/ProductVariant/12'],
            ]),
            new StubStoreContext(),
            $mapper,
            new ResponseBuilder(),
            new StubCategoryResolver()
        );
    }

    private function child(
        int $id,
        string $sku,
        string $name,
        float $price,
        bool $salable,
        int $color,
        int $size
    ): Product {
        return new Product([
            'id' => $id, 'sku' => $sku, 'name' => $name, 'description' => '',
            'final_price' => $price, 'is_salable' => $salable,
            'color' => $color, 'size' => $size,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attributes(): array
    {
        return [
            [
                'attribute_code' => 'color', 'label' => 'Color',
                'values' => [
                    ['value_index' => 101, 'label' => 'Blue'],
                    ['value_index' => 102, 'label' => 'Red'],
                ],
            ],
            [
                'attribute_code' => 'size', 'label' => 'Size',
                'values' => [
                    ['value_index' => 201, 'label' => 'Small'],
                    ['value_index' => 202, 'label' => 'Large'],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $options
     * @return array<int, array<string, mixed>>
     */
    private function axis(array $options, string $name): array
    {
        foreach ($options as $option) {
            if ($option['name'] === $name) {
                return $option['values'];
            }
        }

        self::fail('No option axis named ' . $name);
    }

    /**
     * @param array<int, array<string, mixed>> $values
     * @return array<string, mixed>
     */
    private function value(array $values, string $label): array
    {
        foreach ($values as $value) {
            if ($value['label'] === $label) {
                return $value;
            }
        }

        self::fail('No option value labelled ' . $label);
    }
}

/** Stands in for Magento's configurable type instance. */
final class StubConfigurableType
{
    /**
     * @param array<int, Product> $children
     * @param array<int, array<string, mixed>> $attributes
     */
    public function __construct(
        private readonly array $children,
        private readonly array $attributes
    ) {
    }

    public function getUsedProducts($product): array
    {
        return $this->children;
    }

    public function getConfigurableAttributesAsArray($product): array
    {
        return $this->attributes;
    }
}

final class StubProductLoader extends ProductLoader
{
    /** @param array<string, array{0: ?Product, 1: ?string}> $byId */
    public function __construct(private readonly array $byId)
    {
    }

    public function load(string $identifier): array
    {
        return $this->byId[$identifier] ?? [null, null];
    }
}

final class StubStoreContext extends StoreContext
{
    public function __construct()
    {
    }

    public function resolve(): StoreContextData
    {
        return new StoreContextData('EUR', 'https://shop.example.com/media/');
    }
}

final class StubCategoryResolver extends CategoryResolver
{
    public function __construct()
    {
    }

    public function namesFor($product): array
    {
        return [];
    }

    public function idsForNames(array $names): array
    {
        return [];
    }
}
