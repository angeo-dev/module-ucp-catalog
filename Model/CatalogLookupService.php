<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * catalog.lookup over the Magento product repository.
 *
 * Per catalog_lookup.json#lookup_request, implementations MUST support
 * product ID and variant ID lookups and MAY support secondary identifiers.
 * Supported identifier forms:
 *   gid://magento/Product/{id}         -> match `featured`
 *   gid://magento/ProductVariant/{id}  -> match `exact`
 *   {sku}                              -> match `exact`
 * Unresolvable identifiers produce a warning message; the response omits
 * them (spec: "May contain fewer items if some identifiers not found").
 */
class CatalogLookupService
{
    private const MAX_IDS = 50;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder      $searchCriteriaBuilder,
        private readonly StoreManagerInterface      $storeManager,
        private readonly ProductMapper              $productMapper,
        private readonly ResponseBuilder            $responseBuilder
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

        $store        = $this->storeManager->getStore();
        $currency     = (string) $store->getCurrentCurrencyCode();
        $mediaBaseUrl = (string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

        $products = [];
        $messages = [];

        foreach ($ids as $requestedId) {
            [$product, $match] = $this->resolve($requestedId);

            if ($product === null) {
                // Standard code per error_code.json examples; used here as a
                // warning because partial lookup results are not an error.
                $messages[] = $this->responseBuilder->warningMessage(
                    'not_found',
                    sprintf('Identifier "%s" not found.', $requestedId)
                );
                continue;
            }

            $products[] = $this->responseBuilder->withInputCorrelation(
                $this->productMapper->map($product, $currency, $mediaBaseUrl),
                $requestedId,
                $match
            );
        }

        return $this->responseBuilder->lookupResponse($products, $messages);
    }

    /**
     * @return array{0: ?Product, 1: string} [product or null, match type]
     */
    private function resolve(string $requestedId): array
    {
        if (preg_match('#^gid://magento/(Product|ProductVariant)/(\d+)$#', $requestedId, $m)) {
            $match = $m[1] === 'ProductVariant' ? 'exact' : 'featured';
            try {
                $product = $this->productRepository->getById((int) $m[2]);
                return [$product instanceof Product ? $product : null, $match];
            } catch (NoSuchEntityException) {
                return [null, $match];
            }
        }

        try {
            $product = $this->productRepository->get($requestedId);
            return [$product instanceof Product ? $product : null, 'exact'];
        } catch (NoSuchEntityException) {
            return [null, 'exact'];
        }
    }
}
