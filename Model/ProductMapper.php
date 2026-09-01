<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Magento\Catalog\Model\Product;

/**
 * Maps a Magento product onto the UCP product shape
 * (schemas/shopping/types/product.json, spec tag v2026-08-25).
 *
 * Schema-driven decisions:
 *  - product REQUIRES id, title, description, price_range, variants;
 *    variant REQUIRES id, title, description, price.
 *  - description is an object with at least one of plain/html/markdown;
 *    an empty description falls back to the product title so the required
 *    field is always populated.
 *  - price.amount is in ISO 4217 MINOR units; zero-decimal currencies are
 *    not multiplied by 100.
 *
 * Changes in 2.0.0:
 *  - CONFIGURABLE PRODUCTS EXPAND INTO REAL VARIANTS. 1.0.0 mapped every
 *    product as a single self-variant, so a configurable shirt was
 *    advertised as one buyable item at the parent's price. An agent adding
 *    it to a cart had nothing to select and no way to know which size
 *    existed — the product's whole option axis was invisible. Children are
 *    now emitted as variants carrying their own price, availability, media
 *    and `options`, and `price_range` spans the real min/max across them.
 *  - `availability.status` is populated (`in_stock` / `out_of_stock`), which
 *    the schema lists as a well-known qualifier alongside `available`.
 *  - Product `categories` and `options` are emitted, so `filters.categories`
 *    has something to match against and agents can see the option axes.
 */
class ProductMapper
{
    /** ISO 4217 currencies with zero minor units. */
    private const ZERO_DECIMAL_CURRENCIES = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX'];

    public function __construct(
        private readonly VariantResolver $variantResolver
    ) {
    }

    /**
     * @param array<int, string> $categoryNames Resolved category labels.
     * @return array<string, mixed> UCP product object
     */
    public function map(
        Product $product,
        string $currency,
        string $mediaBaseUrl,
        array $categoryNames = []
    ): array {
        $title       = (string) $product->getName();
        $description = $this->buildDescription($product, $title);
        $url         = (string) $product->getProductUrl();

        $children = $this->variantResolver->resolve($product);

        $variants = [];
        foreach ($children as $child) {
            $variants[] = $this->buildVariant($child, $product, $currency, $mediaBaseUrl, $title);
        }

        if ($variants === []) {
            // Never emit an empty variants array: the schema requires the
            // member, and a product with no buyable variant is useless to an
            // agent. Fall back to the parent as its own variant.
            $variants[] = $this->buildVariant($product, $product, $currency, $mediaBaseUrl, $title);
        }

        $ucpProduct = [
            'id'          => $this->productGid($product),
            'title'       => $title,
            'description' => $description,
            'price_range' => $this->priceRange($variants, $currency),
            'variants'    => $variants,
        ];

        $handle = (string) $product->getData('url_key');
        if ($handle !== '') {
            $ucpProduct['handle'] = $handle;
        }
        if ($url !== '') {
            $ucpProduct['url'] = $url;
        }

        $media = $this->buildMedia($product, $mediaBaseUrl, $title);
        if ($media !== []) {
            $ucpProduct['media'] = $media;
        }

        if ($categoryNames !== []) {
            $ucpProduct['categories'] = array_map(
                static fn (string $name): array => ['value' => $name],
                array_values($categoryNames)
            );
        }

        $options = $this->variantResolver->optionAxes($product);
        if ($options !== []) {
            $ucpProduct['options'] = $options;
        }

        return $ucpProduct;
    }

    public function productGid(Product $product): string
    {
        return 'gid://magento/Product/' . (int) $product->getId();
    }

    public function variantGid(Product $product): string
    {
        return 'gid://magento/ProductVariant/' . (int) $product->getId();
    }

    /**
     * Build one UCP variant. `$parent` supplies the fallbacks a child may
     * not define for itself (title, media, description).
     *
     * @return array<string, mixed>
     */
    private function buildVariant(
        Product $variantProduct,
        Product $parent,
        string $currency,
        string $mediaBaseUrl,
        string $parentTitle
    ): array {
        $title = (string) $variantProduct->getName();
        if ($title === '') {
            $title = $parentTitle;
        }

        $variant = [
            'id'           => $this->variantGid($variantProduct),
            'sku'          => (string) $variantProduct->getSku(),
            'title'        => $title,
            'description'  => $this->buildDescription($variantProduct, $title),
            'price'        => $this->buildPrice($variantProduct, $currency),
            'availability' => $this->buildAvailability($variantProduct),
        ];

        $url = (string) $variantProduct->getProductUrl();
        if ($url === '') {
            $url = (string) $parent->getProductUrl();
        }
        if ($url !== '') {
            $variant['url'] = $url;
        }

        $media = $this->buildMedia($variantProduct, $mediaBaseUrl, $title);
        if ($media === []) {
            $media = $this->buildMedia($parent, $mediaBaseUrl, $parentTitle);
        }
        if ($media !== []) {
            $variant['media'] = $media;
        }

        $options = $this->variantResolver->selectedOptions($variantProduct, $parent);
        if ($options !== []) {
            $variant['options'] = $options;
        }

        return $variant;
    }

    /**
     * price_range spans the real min/max across variants, so a configurable
     * product advertises the range a shopper would actually see rather than
     * the parent's nominal price repeated twice.
     *
     * @param array<int, array<string, mixed>> $variants
     * @return array{min: array{amount: int, currency: string}, max: array{amount: int, currency: string}}
     */
    private function priceRange(array $variants, string $currency): array
    {
        $amounts = array_map(
            static fn (array $v): int => (int) $v['price']['amount'],
            $variants
        );

        $currency = strtoupper($currency);

        return [
            'min' => ['amount' => min($amounts), 'currency' => $currency],
            'max' => ['amount' => max($amounts), 'currency' => $currency],
        ];
    }

    /**
     * @return array{available: bool, status: string}
     */
    private function buildAvailability(Product $product): array
    {
        $available = (bool) $product->isSalable();

        return [
            'available' => $available,
            // Well-known values per types/availability.json. Magento does not
            // distinguish backorder/preorder at this level without stock
            // configuration, so only the two unambiguous states are claimed.
            'status'    => $available ? 'in_stock' : 'out_of_stock',
        ];
    }

    /**
     * @return array{plain: string}
     */
    private function buildDescription(Product $product, string $fallback): array
    {
        $raw = (string) ($product->getData('description')
            ?: $product->getData('short_description')
            ?: '');

        $plain = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5));

        return ['plain' => $plain !== '' ? $plain : $fallback];
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private function buildPrice(Product $product, string $currency): array
    {
        $major = (float) $product->getFinalPrice();
        $isZeroDecimal = in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true);

        return [
            'amount'   => (int) round($isZeroDecimal ? $major : $major * 100),
            'currency' => strtoupper($currency),
        ];
    }

    /**
     * @return array<int, array{type: string, url: string, alt_text?: string}>
     */
    private function buildMedia(Product $product, string $mediaBaseUrl, string $altText): array
    {
        $image = (string) $product->getData('image');
        if ($image === '' || $image === 'no_selection') {
            return [];
        }

        $entry = [
            'type' => 'image',
            'url'  => rtrim($mediaBaseUrl, '/') . '/catalog/product/' . ltrim($image, '/'),
        ];
        if ($altText !== '') {
            $entry['alt_text'] = $altText;
        }

        return [$entry];
    }
}
