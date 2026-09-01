<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

/**
 * Immutable store presentment context.
 */
final class StoreContextData
{
    public function __construct(
        public readonly string $currency,
        public readonly string $mediaBaseUrl
    ) {
    }
}
