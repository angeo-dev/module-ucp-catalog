<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Test\Unit\Model;

use Angeo\UcpCatalog\Model\ProductLoader;
use Magento\Catalog\Model\Product;

class StubProductLoader extends ProductLoader
{
    /** @param array<string, array{0: ?Product, 1: ?string}> $byId */
    public function __construct(private readonly array $byId)
    {
    }

    public function load(string $identifier): array
    {
        return $this->byId[$identifier] ?? [null, null];
    }
}
