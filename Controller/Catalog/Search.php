<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Controller\Catalog;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCatalog\Model\CatalogSearchService;
use Angeo\UcpCatalog\Model\ResponseBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * POST {endpoint}/catalog/search — dev.ucp.shopping.catalog.search.
 */
class Search extends AbstractCatalogAction
{
    public function __construct(
        ResultFactory    $resultFactory,
        UcpConfig        $ucpConfig,
        RequestInterface $request,
        LoggerInterface  $logger,
        ResponseBuilder  $responseBuilder,
        private readonly CatalogSearchService $searchService
    ) {
        parent::__construct($resultFactory, $ucpConfig, $request, $logger, $responseBuilder);
    }

    protected function process(array $request): array
    {
        return [200, $this->searchService->search($request)];
    }

    protected function isCapabilityDeclared(): bool
    {
        return $this->ucpConfig->isCatalogSearchDeclared();
    }
}
