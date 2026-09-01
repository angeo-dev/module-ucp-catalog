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

/**
 * catalog.search over the Magento product repository.
 *
 * Changes in 2.0.0:
 *  - `filters` are now honoured. 1.0.0 accepted and silently ignored them,
 *    which is legal (all optional per search_request) but actively unhelpful:
 *    an agent asking for shoes under EUR 150 got the unfiltered first page
 *    back and no indication its constraint had been dropped. Both filter
 *    types the schema defines are supported — `price` (min/max, in minor
 *    units) and `categories` (OR logic across the listed values).
 *  - `pagination.total_count` is returned; the repository already computed it.
 *  - When a filter cannot be applied — the spec's example being a price
 *    filter denominated in a currency the store cannot convert from — the
 *    response says so via a `messages` entry rather than dropping it
 *    silently. The price_filter description asks for exactly that.
 *
 * Still deliberately out of scope: `signals` and `context.intent` are
 * accepted and ignored. Acting on them means ranking, and a LIKE query
 * dressed up as relevance ranking would be worse than an honest one.
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
        private readonly StoreContext               $storeContext,
        private readonly ProductMapper              $productMapper,
        private readonly ResponseBuilder            $responseBuilder,
        private readonly CategoryResolver           $categoryResolver,
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

        $context  = $this->storeContext->resolve();
        $messages = [];
        $groups   = [];

        if ($query !== '') {
            // Escape LIKE wildcards so a query containing % or _ matches
            // literally instead of turning into a full-catalogue scan.
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

        foreach ($this->priceFilterGroups($request, $context, $messages) as $group) {
            $groups[] = $group;
        }

        $categoryGroup = $this->categoryFilterGroup($request, $messages);
        if ($categoryGroup !== null) {
            $groups[] = $categoryGroup;
        }

        $criteria = $this->searchCriteriaBuilder
            ->setFilterGroups($groups)
            ->setPageSize($limit)
            ->setCurrentPage($page)
            ->create();

        $results    = $this->productRepository->getList($criteria);
        $totalCount = (int) $results->getTotalCount();

        $products = [];
        foreach ($results->getItems() as $product) {
            if ($product instanceof Product) {
                $products[] = $this->productMapper->map(
                    $product,
                    $context->currency,
                    $context->mediaBaseUrl,
                    $this->categoryResolver->namesFor($product)
                );
            }
        }

        $hasNext = ($page * $limit) < $totalCount;

        return $this->responseBuilder->searchResponse(
            $products,
            $hasNext,
            $hasNext ? $this->cursor->encode($page + 1) : null,
            $totalCount,
            $messages
        );
    }

    /**
     * price_filter min/max are in ISO 4217 MINOR units and denominated in
     * `context.currency`. When that currency differs from what the store
     * presents and no conversion is available, the spec says to either
     * convert or ignore the filter AND say so — never to silently compare a
     * minor-unit figure in one currency against a major-unit price in another.
     *
     * @param array<string, mixed> $request
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, mixed>
     */
    private function priceFilterGroups(array $request, StoreContextData $context, array &$messages): array
    {
        $price = $request['filters']['price'] ?? null;
        if (!is_array($price)) {
            return [];
        }

        $requestedCurrency = strtoupper(trim((string) ($request['context']['currency'] ?? '')));
        if ($requestedCurrency !== '' && $requestedCurrency !== $context->currency) {
            $messages[] = $this->responseBuilder->warningMessage(
                'invalid_request',
                sprintf(
                    'The price filter was denominated in %s but this store presents '
                    . 'prices in %s, and no conversion is available. The filter was '
                    . 'not applied.',
                    $requestedCurrency,
                    $context->currency
                )
            );
            return [];
        }

        $groups = [];

        foreach ([['min', 'gteq'], ['max', 'lteq']] as [$bound, $condition]) {
            if (!isset($price[$bound]) || !is_numeric($price[$bound])) {
                continue;
            }
            $groups[] = $this->filterGroupBuilder->setFilters([
                $this->filterBuilder
                    ->setField('price')
                    ->setConditionType($condition)
                    // Minor units back to the major units Magento stores.
                    ->setValue($this->toMajorUnits((int) $price[$bound], $context->currency))
                    ->create(),
            ])->create();
        }

        return $groups;
    }

    /**
     * Categories combine with OR logic among themselves, and AND against
     * everything else — which is exactly what one Magento filter group with
     * several filters expresses.
     *
     * @param array<string, mixed> $request
     * @param array<int, array<string, mixed>> $messages
     */
    private function categoryFilterGroup(array $request, array &$messages): mixed
    {
        $categories = $request['filters']['categories'] ?? null;
        if (!is_array($categories) || $categories === []) {
            return null;
        }

        $ids = $this->categoryResolver->idsForNames(
            array_values(array_filter(array_map('strval', $categories)))
        );

        if ($ids === []) {
            $messages[] = $this->responseBuilder->warningMessage(
                'not_found',
                'None of the requested categories matched a category in this store; '
                . 'the category filter was not applied.'
            );
            return null;
        }

        return $this->filterGroupBuilder->setFilters([
            $this->filterBuilder
                ->setField('category_id')
                ->setConditionType('in')
                ->setValue($ids)
                ->create(),
        ])->create();
    }

    /**
     * Mirror of ProductMapper's minor-unit conversion, inverted.
     */
    private function toMajorUnits(int $minor, string $currency): float
    {
        $zeroDecimal = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX'];

        return in_array(strtoupper($currency), $zeroDecimal, true)
            ? (float) $minor
            : $minor / 100;
    }

    private function clampLimit(mixed $limit): int
    {
        if (!is_int($limit) && !(is_string($limit) && ctype_digit($limit))) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, (int) $limit));
    }
}
