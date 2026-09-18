<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Test\Unit\Model;

use Magento\Catalog\Model\Product;

/**
 * A real Magento Product built from plain data, without its constructor
 * dependencies. The getters the catalog code uses read straight from the data
 * array, so tests do not need price models, type factories or URL models.
 */
class TestProduct extends Product
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        // Parent constructor intentionally not called (test double).
        $this->_data = $data;
    }

    /** @inheritDoc */
    public function getId()
    {
        return $this->_data['id'] ?? null;
    }

    /** @inheritDoc */
    public function getSku()
    {
        return $this->_data['sku'] ?? null;
    }

    /** @inheritDoc */
    public function getName()
    {
        return $this->_data['name'] ?? null;
    }

    /** @inheritDoc */
    public function getTypeId()
    {
        return $this->_data['type_id'] ?? 'simple';
    }

    /** @inheritDoc */
    public function getTypeInstance()
    {
        return $this->_data['type_instance'] ?? null;
    }

    /** @inheritDoc */
    public function isSalable()
    {
        return (bool) ($this->_data['is_salable'] ?? false);
    }

    /** @inheritDoc */
    public function getFinalPrice($qty = null)
    {
        return $this->_data['final_price'] ?? null;
    }

    /** @inheritDoc */
    public function getProductUrl($useSid = null)
    {
        return $this->_data['product_url'] ?? '';
    }

    /** @inheritDoc */
    public function getCategoryIds()
    {
        return $this->_data['category_ids'] ?? [];
    }
}
