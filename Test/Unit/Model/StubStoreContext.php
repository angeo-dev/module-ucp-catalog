<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Test\Unit\Model;

use Angeo\UcpCatalog\Model\StoreContext;
use Angeo\UcpCatalog\Model\StoreContextData;

class StubStoreContext extends StoreContext
{
    public function __construct()
    {
    }

    public function resolve(): StoreContextData
    {
        return new StoreContextData('EUR', 'https://shop.example.com/media/');
    }
}
