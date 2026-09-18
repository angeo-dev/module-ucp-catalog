<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Test\Unit\Model;

use Magento\Catalog\Model\Product;

/** Stands in for Magento's configurable type instance. */
class StubConfigurableType
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
