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
 * (schemas/shopping/types/product.json, spec tag v2026-04-08).
 *
 * Schema-driven decisions:
 *  - product REQUIRES: id, title, description, price_range, variants;
 *    variant REQUIRES: id, title, description, price.
 *  - description is an object with at least one of plain/html/markdown;
 *    empty descriptions fall back to the product title so the required
 *    field is always populated.
 *  - price.amount is in ISO 4217 MINOR units; zero-decimal currencies
 *    (JPY, KRW, VND) are not multiplied by 100.
 *  - v1.0.0 maps every product as a single self-variant (id, sku, price,
 *    availability, media). Configurable-children expansion is roadmap.
 */
class ProductMapper
{
    /** ISO 4217 currencies with zero minor units. */
    private const ZERO_DECIMAL_CURRENCIES = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX'];

    /**
     * @return array<string, mixed> UCP product object
     */
    public function map(Product $product, string $currency, string $mediaBaseUrl): array
    {
        $title       = (string) $product->getName();
        $description = $this->buildDescription($product, $title);
        $price       = $this->buildPrice($product, $currency);
        $url         = (string) $product->getProductUrl();

        $variant = [
            'id'           => $this->variantGid($product),
            'sku'          => (string) $product->getSku(),
            'title'        => $title,
            'description'  => $description,
            'price'        => $price,
            'availability' => [
                'available' => (bool) $product->isSalable(),
            ],
        ];

        if ($url !== '') {
            $variant['url'] = $url;
        }

        $media = $this->buildMedia($product, $mediaBaseUrl, $title);
        if ($media !== []) {
            $variant['media'] = $media;
        }

        $ucpProduct = [
            'id'          => $this->productGid($product),
            'title'       => $title,
            'description' => $description,
            'price_range' => ['min' => $price, 'max' => $price],
            'variants'    => [$variant],
        ];

        if ($url !== '') {
            $ucpProduct['url'] = $url;
        }
        if ($media !== []) {
            $ucpProduct['media'] = $media;
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
