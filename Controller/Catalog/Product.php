<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Controller\Catalog;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCatalog\Model\CatalogProductService;
use Angeo\UcpCatalog\Model\ResponseBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * POST {endpoint}/catalog/product — get_product.
 *
 * New in 2.0.0. The REST binding maps this operation to the
 * dev.ucp.shopping.catalog.lookup capability (see the endpoint table in
 * specification/shopping/catalog/rest), so it is gated on the same toggle
 * rather than a separate one.
 */
class Product extends AbstractCatalogAction
{
    public function __construct(
        ResultFactory    $resultFactory,
        UcpConfig        $ucpConfig,
        RequestInterface $request,
        LoggerInterface  $logger,
        ResponseBuilder  $responseBuilder,
        private readonly CatalogProductService $productService
    ) {
        parent::__construct($resultFactory, $ucpConfig, $request, $logger, $responseBuilder);
    }

    protected function process(array $request): array
    {
        $id = $request['id'] ?? null;

        if (!is_string($id) || trim($id) === '') {
            return [400, $this->responseBuilder->errorResponse([
                $this->responseBuilder->errorMessage(
                    'invalid_request',
                    'get_product_request requires a non-empty string "id" '
                    . '(catalog_lookup.json#get_product_request).'
                ),
            ])];
        }

        return $this->productService->getProduct($request);
    }

    protected function isCapabilityDeclared(): bool
    {
        return $this->ucpConfig->isCatalogLookupDeclared();
    }
}
