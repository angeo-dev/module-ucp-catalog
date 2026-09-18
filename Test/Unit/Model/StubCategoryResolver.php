<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Test\Unit\Model;

use Angeo\UcpCatalog\Model\CategoryResolver;

class StubCategoryResolver extends CategoryResolver
{
    public function __construct()
    {
    }

    public function namesFor($product): array
    {
        return [];
    }

    public function idsForNames(array $names): array
    {
        return [];
    }
}
