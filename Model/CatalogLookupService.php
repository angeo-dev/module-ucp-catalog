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
 * catalog.lookup over the Magento product repository.
 *
 * Per catalog_lookup.json#lookup_request, implementations MUST support
 * product ID and variant ID lookups and MAY support secondary identifiers.
 * Supported identifier forms:
 *   gid://magento/Product/{id}         -> match `featured`
 *   gid://magento/ProductVariant/{id}  -> match `exact`
 *   {sku}                              -> match `exact`
 * Unresolvable identifiers produce a warning; the response omits them
 * (spec: "May contain fewer items if some identifiers not found").
 *
 * Changes in 2.0.0:
 *  - A ProductVariant gid now resolves to its PARENT product with the child
 *    marked as the matched variant, instead of returning the child as a
 *    standalone product. That matters because 1.0.0 answered a variant
 *    lookup with a product carrying one self-variant and no option axes —
 *    an agent could not tell which of the parent's options it had just been
 *    handed, which is the whole purpose of variant-level lookup.
 *  - `inputs` correlation is attached only to the variant that actually
 *    matched, not blanket-applied to every variant of the product. The
 *    schema's match semantics (`exact` vs `featured`) are meaningless if
 *    every variant claims the same one.
 */
class CatalogLookupService
{
    private const MAX_IDS = 50;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreContext               $storeContext,
        private readonly ProductMapper              $productMapper,
        private readonly ResponseBuilder            $responseBuilder,
        private readonly CategoryResolver           $categoryResolver
    ) {
    }

    /**
     * @param array<string, mixed> $request decoded lookup_request body
     * @return array<string, mixed> lookup_response body
     */
    public function lookup(array $request): array
    {
        $ids = array_values(array_filter(
            array_map('strval', (array) ($request['ids'] ?? [])),
            static fn (string $id): bool => $id !== ''
        ));
        $ids = array_slice(array_unique($ids), 0, self::MAX_IDS);

        $context  = $this->storeContext->resolve();
        $products = [];
        $messages = [];

        foreach ($ids as $requestedId) {
            $resolved = $this->resolve($requestedId);

            if ($resolved === null) {
                // Standard code per error_code.json; a warning rather than an
                // error because a partial result is a legitimate outcome here.
                $messages[] = $this->responseBuilder->warningMessage(
                    'not_found',
                    sprintf('Identifier "%s" not found.', $requestedId)
                );
                continue;
            }

            [$product, $matchedVariantId, $match] = $resolved;

            $mapped = $this->productMapper->map(
                $product,
                $context->currency,
                $context->mediaBaseUrl,
                $this->categoryResolver->namesFor($product)
            );

            $products[] = $this->correlate($mapped, $requestedId, $match, $matchedVariantId);
        }

        return $this->responseBuilder->lookupResponse($products, $messages);
    }

    /**
     * Attach the `inputs` entry to the variant the identifier actually
     * resolved to. lookup_variant REQUIRES `inputs` on every variant, so
     * unmatched variants get an entry with the `featured` match semantics
     * — the server chose to include them, which is exactly what `featured`
     * describes.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function correlate(
        array $product,
        string $requestedId,
        string $match,
        ?string $matchedVariantId
    ): array {
        if ($matchedVariantId === null) {
            return $this->responseBuilder->withInputCorrelation($product, $requestedId, $match);
        }

        foreach ($product['variants'] as &$variant) {
            $variant['inputs'][] = [
                'id'    => $requestedId,
                'match' => $variant['id'] === $matchedVariantId ? $match : 'featured',
            ];
        }
        unset($variant);

        return $product;
    }

    /**
     * @return array{0: Product, 1: ?string, 2: string}|null
     *         [product, matched variant gid or null, match type]
     */
    private function resolve(string $requestedId): ?array
    {
        if (preg_match('#^gid://magento/(Product|ProductVariant)/(\d+)$#', $requestedId, $m)) {
            $product = $this->loadById((int) $m[2]);
            if ($product === null) {
                return null;
            }

            if ($m[1] === 'Product') {
                return [$product, null, 'featured'];
            }

            // A variant gid: hand back the parent so the agent sees the full
            // option context, flagging this child as the exact match.
            $parent = $this->parentOf($product) ?? $product;

            return [$parent, 'gid://magento/ProductVariant/' . (int) $product->getId(), 'exact'];
        }

        $product = $this->loadBySku($requestedId);
        if ($product === null) {
            return null;
        }

        $parent = $this->parentOf($product);
        if ($parent !== null) {
            return [$parent, 'gid://magento/ProductVariant/' . (int) $product->getId(), 'exact'];
        }

        return [$product, null, 'exact'];
    }

    /**
     * The configurable parent of a simple product, when it has one.
     *
     * Resolved through the product's own type instance rather than a direct
     * dependency on Magento\ConfigurableProduct\*, which is a removable
     * module; an unavailable resolver degrades to "no parent".
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

        $parent = $this->loadById((int) reset($parentIds));

        return $parent !== null && $parent->getTypeId() === 'configurable' ? $parent : null;
    }

    private function loadById(int $id): ?Product
    {
        try {
            $product = $this->productRepository->getById($id);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $product instanceof Product ? $product : null;
    }

    private function loadBySku(string $sku): ?Product
    {
        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $product instanceof Product ? $product : null;
    }
}
