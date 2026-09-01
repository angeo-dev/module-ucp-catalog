<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Resolves a UCP identifier to the product an agent should be shown, plus
 * the variant it named if it named one.
 *
 * get_product REQUIRES support for both product and variant identifiers.
 * A variant identifier resolves to its configurable PARENT, because the
 * response has to carry the full option axes for narrowing to mean anything
 * — the named child comes back as the anchoring selection instead.
 */
class ProductLoader
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * @return array{0: ?Product, 1: ?string} [product, requested variant gid]
     */
    public function load(string $identifier): array
    {
        if ($identifier === '') {
            return [null, null];
        }

        if (preg_match('#^gid://magento/(Product|ProductVariant)/(\d+)$#', $identifier, $m)) {
            $product = $this->byId((int) $m[2]);
            if ($product === null) {
                return [null, null];
            }

            if ($m[1] === 'Product') {
                return [$product, null];
            }

            return $this->withParent($product);
        }

        $product = $this->bySku($identifier);
        if ($product === null) {
            return [null, null];
        }

        return $this->withParent($product);
    }

    /**
     * @return array{0: Product, 1: ?string}
     */
    private function withParent(Product $product): array
    {
        $variantGid = 'gid://magento/ProductVariant/' . (int) $product->getId();
        $parent     = $this->parentOf($product);

        return $parent !== null ? [$parent, $variantGid] : [$product, null];
    }

    /**
     * Duck-typed for the same reason as VariantResolver:
     * Magento_ConfigurableProduct is a removable module.
     */
    private function parentOf(Product $product): ?Product
    {
        if ($product->getTypeId() !== 'simple') {
            return null;
        }

        try {
            $typeInstance = $product->getTypeInstance();
            if (!method_exists($typeInstance, 'getParentIdsByChild')) {
                return null;
            }
            $parentIds = $typeInstance->getParentIdsByChild($product->getId());
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($parentIds) || $parentIds === []) {
            return null;
        }

        $parent = $this->byId((int) reset($parentIds));

        return $parent !== null && $parent->getTypeId() === 'configurable' ? $parent : null;
    }

    private function byId(int $id): ?Product
    {
        try {
            $product = $this->productRepository->getById($id);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $product instanceof Product ? $product : null;
    }

    private function bySku(string $sku): ?Product
    {
        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $product instanceof Product ? $product : null;
    }
}
