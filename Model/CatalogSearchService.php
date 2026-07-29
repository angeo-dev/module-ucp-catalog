<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;

/**
 * catalog.search over the Magento product repository.
 *
 * v1.0.0 scope: free-text `query` matched against name OR sku (LIKE),
 * limited to enabled products visible in search; cursor pagination
 * (default limit 10 per spec, clamped to 50). `filters`, `context`, and
 * `signals` from the request are accepted but ignored — all optional per
 * catalog_search.json#search_request.
 */
class CatalogSearchService
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT     = 50;

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder      $searchCriteriaBuilder,
        private readonly FilterBuilder              $filterBuilder,
        private readonly FilterGroupBuilder         $filterGroupBuilder,
        private readonly StoreManagerInterface      $storeManager,
        private readonly ProductMapper              $productMapper,
        private readonly ResponseBuilder            $responseBuilder,
        private readonly Cursor                     $cursor
    ) {
    }

    /**
     * @param array<string, mixed> $request decoded search_request body
     * @return array<string, mixed> search_response body
     */
    public function search(array $request): array
    {
        $query = trim((string) ($request['query'] ?? ''));
        $limit = $this->clampLimit($request['pagination']['limit'] ?? null);
        $page  = $this->cursor->decode(
            isset($request['pagination']['cursor']) ? (string) $request['pagination']['cursor'] : null
        );

        $groups = [];

        if ($query !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
            $groups[] = $this->filterGroupBuilder->setFilters([
                $this->filterBuilder->setField('name')->setConditionType('like')->setValue($like)->create(),
                $this->filterBuilder->setField('sku')->setConditionType('like')->setValue($like)->create(),
            ])->create();
        }

        $groups[] = $this->filterGroupBuilder->setFilters([
            $this->filterBuilder->setField('status')
                ->setConditionType('eq')->setValue(Status::STATUS_ENABLED)->create(),
        ])->create();

        $groups[] = $this->filterGroupBuilder->setFilters([
            $this->filterBuilder->setField('visibility')->setConditionType('in')
                ->setValue([Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH])
                ->create(),
        ])->create();

        $criteria = $this->searchCriteriaBuilder
            ->setFilterGroups($groups)
            ->setPageSize($limit)
            ->setCurrentPage($page)
            ->create();

        $results    = $this->productRepository->getList($criteria);
        $totalCount = (int) $results->getTotalCount();

        [$currency, $mediaBaseUrl] = $this->storeContext();

        $products = [];
        foreach ($results->getItems() as $product) {
            if ($product instanceof Product) {
                $products[] = $this->productMapper->map($product, $currency, $mediaBaseUrl);
            }
        }

        $hasNext = ($page * $limit) < $totalCount;

        return $this->responseBuilder->searchResponse(
            $products,
            $hasNext,
            $hasNext ? $this->cursor->encode($page + 1) : null
        );
    }

    private function clampLimit(mixed $limit): int
    {
        if (!is_int($limit) && !(is_string($limit) && ctype_digit($limit))) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int) $limit));
    }

    /**
     * @return array{0: string, 1: string} [currency code, media base URL]
     */
    private function storeContext(): array
    {
        $store = $this->storeManager->getStore();

        return [
            (string) $store->getCurrentCurrencyCode(),
            (string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
        ];
    }
}
