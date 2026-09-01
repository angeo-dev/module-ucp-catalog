<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves category names for products, and category names back to ids for
 * `filters.categories`.
 *
 * The search_filters schema says category filter values "match against the
 * value field in product category entries" — i.e. against exactly the labels
 * this module emits in product.categories. Names are matched
 * case-insensitively so an agent echoing a label back with different casing
 * still filters correctly.
 *
 * Lookups are memoised per request: a page of 50 products would otherwise
 * hammer the category repository for the same handful of ids.
 */
class CategoryResolver
{
    /** @var array<int, string> */
    private array $namesById = [];

    /** @var array<string, array<int, int>>|null */
    private ?array $idsByLowercaseName = null;

    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryCollectionFactory   $categoryCollectionFactory,
        private readonly LoggerInterface             $logger
    ) {
    }

    /**
     * Category labels for a product, for product.categories.
     *
     * @return array<int, string>
     */
    public function namesFor(Product $product): array
    {
        $ids = $product->getCategoryIds();
        if (!is_array($ids) || $ids === []) {
            return [];
        }

        $names = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (!array_key_exists($id, $this->namesById)) {
                $this->namesById[$id] = $this->loadName($id);
            }
            if ($this->namesById[$id] !== '') {
                $names[] = $this->namesById[$id];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Category ids matching any of the given names (OR logic).
     *
     * @param array<int, string> $names
     * @return array<int, int>
     */
    public function idsForNames(array $names): array
    {
        $index = $this->nameIndex();

        $ids = [];
        foreach ($names as $name) {
            $key = mb_strtolower(trim($name));
            foreach ($index[$key] ?? [] as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function loadName(int $id): string
    {
        try {
            $category = $this->categoryRepository->get($id);
            return $category instanceof CategoryInterface ? (string) $category->getName() : '';
        } catch (\Throwable) {
            // A product referencing a deleted category is a data problem, not
            // a request problem — omit the label and carry on.
            return '';
        }
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function nameIndex(): array
    {
        if ($this->idsByLowercaseName !== null) {
            return $this->idsByLowercaseName;
        }

        $index = [];

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect('name');
            foreach ($collection as $category) {
                $name = mb_strtolower(trim((string) $category->getName()));
                if ($name !== '') {
                    $index[$name][] = (int) $category->getId();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Angeo_UcpCatalog] Could not build the category name index; '
                . 'category filters will not match. ' . $e->getMessage()
            );
        }

        return $this->idsByLowercaseName = $index;
    }
}
