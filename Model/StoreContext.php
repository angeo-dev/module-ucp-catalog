<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Presentment currency and media base URL for the current store view.
 *
 * Extracted in 2.0.0: three services were each resolving this inline, and
 * the search service now also needs the currency to decide whether a
 * price filter is applicable at all.
 */
class StoreContext
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function resolve(): StoreContextData
    {
        $store = $this->storeManager->getStore();

        return new StoreContextData(
            strtoupper((string) $store->getCurrentCurrencyCode()),
            (string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
        );
    }
}
