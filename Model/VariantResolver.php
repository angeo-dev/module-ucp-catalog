<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;

/**
 * Expands a Magento product into the variants a UCP agent can buy.
 *
 * Why this exists: UCP's model is product -> variants, where the VARIANT is
 * the purchasable unit carrying its own price, availability and option
 * selections (types/variant.json). Magento's configurable product is exactly
 * that shape, but 1.0.0 of this module flattened it — every product became a
 * single self-variant, so an agent saw one buyable item at the parent price
 * with no option axes at all.
 *
 * Deliberately duck-typed rather than depending on
 * Magento\ConfigurableProduct\* directly: that module is removable on a
 * Magento install, and a hard `use` would fatal on a store that has taken it
 * out. Every call is guarded by method_exists() and wrapped, so an
 * unavailable or misbehaving type instance degrades to the self-variant
 * fallback instead of failing the whole request.
 */
class VariantResolver
{
    private const TYPE_CONFIGURABLE = 'configurable';

    /**
     * Hard cap on emitted variants. A configurable with four option axes can
     * have thousands of children; serialising all of them would produce a
     * response no agent wants and a query no store wants to run.
     */
    private const MAX_VARIANTS = 100;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Purchasable children of the product, or an empty array when it has
     * none (simple products, or a configurable whose children are all
     * disabled — the caller then falls back to the product itself).
     *
     * @return array<int, Product>
     */
    public function resolve(Product $product): array
    {
        if ($product->getTypeId() !== self::TYPE_CONFIGURABLE) {
            return [];
        }

        try {
            $typeInstance = $product->getTypeInstance();
            if (!method_exists($typeInstance, 'getUsedProducts')) {
                return [];
            }

            $children = $typeInstance->getUsedProducts($product);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[Angeo_UcpCatalog] Could not expand variants for product %s; '
                . 'falling back to a single self-variant. %s',
                (string) $product->getSku(),
                $e->getMessage()
            ));
            return [];
        }

        $variants = [];
        foreach ($children as $child) {
            if (!$child instanceof Product) {
                continue;
            }
            $variants[] = $child;
            if (count($variants) >= self::MAX_VARIANTS) {
                $this->logger->info(sprintf(
                    '[Angeo_UcpCatalog] Product %s has more than %d variants; '
                    . 'the response was truncated.',
                    (string) $product->getSku(),
                    self::MAX_VARIANTS
                ));
                break;
            }
        }

        return $variants;
    }

    /**
     * Option axes of a configurable product, as product.options
     * (name + values, each value an option_value REQUIRING `label`).
     *
     * @return array<int, array{name: string, values: array<int, array{label: string, id?: string}>}>
     */
    public function optionAxes(Product $product): array
    {
        $axes = [];
        foreach ($this->configurableAttributes($product) as $attribute) {
            $label  = (string) ($attribute['label'] ?? $attribute['frontend_label'] ?? '');
            $values = [];

            foreach ((array) ($attribute['values'] ?? []) as $value) {
                $valueLabel = (string) ($value['label'] ?? $value['store_label'] ?? '');
                if ($valueLabel === '') {
                    continue;
                }
                $entry = ['label' => $valueLabel];
                if (isset($value['value_index'])) {
                    $entry['id'] = (string) $value['value_index'];
                }
                $values[] = $entry;
            }

            // option_value REQUIRES a label, and the detail schema requires
            // at least one value per option — an axis with none is dropped
            // rather than emitted empty.
            if ($label !== '' && $values !== []) {
                $axes[] = ['name' => $label, 'values' => $values];
            }
        }

        return $axes;
    }

    /**
     * The option selections that identify one variant, as selected_option
     * entries (REQUIRING both `name` and `label`).
     *
     * @return array<int, array{name: string, label: string, id?: string}>
     */
    public function selectedOptions(Product $variant, Product $parent): array
    {
        if ($variant->getId() === $parent->getId()) {
            return [];
        }

        $selections = [];

        foreach ($this->configurableAttributes($parent) as $attribute) {
            $code  = (string) ($attribute['attribute_code'] ?? '');
            $label = (string) ($attribute['label'] ?? $attribute['frontend_label'] ?? '');
            if ($code === '' || $label === '') {
                continue;
            }

            $valueIndex = $variant->getData($code);
            if ($valueIndex === null || $valueIndex === '') {
                continue;
            }

            foreach ((array) ($attribute['values'] ?? []) as $value) {
                if ((string) ($value['value_index'] ?? '') !== (string) $valueIndex) {
                    continue;
                }
                $valueLabel = (string) ($value['label'] ?? $value['store_label'] ?? '');
                if ($valueLabel === '') {
                    continue;
                }
                $selections[] = [
                    'name'  => $label,
                    'id'    => (string) $valueIndex,
                    'label' => $valueLabel,
                ];
                break;
            }
        }

        return $selections;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configurableAttributes(Product $product): array
    {
        if ($product->getTypeId() !== self::TYPE_CONFIGURABLE) {
            return [];
        }

        try {
            $typeInstance = $product->getTypeInstance();
            if (!method_exists($typeInstance, 'getConfigurableAttributesAsArray')) {
                return [];
            }

            $attributes = $typeInstance->getConfigurableAttributesAsArray($product);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[Angeo_UcpCatalog] Could not read option axes for product %s. %s',
                (string) $product->getSku(),
                $e->getMessage()
            ));
            return [];
        }

        return is_array($attributes) ? array_values($attributes) : [];
    }
}
