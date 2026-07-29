<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Controller\Catalog;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCatalog\Model\CatalogLookupService;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * POST {endpoint}/catalog/lookup — dev.ucp.shopping.catalog.lookup.
 *
 * lookup_request REQUIRES a non-empty `ids` array; a missing/empty array
 * is a 400 per the schema (minItems: 1).
 */
class Lookup extends AbstractCatalogAction
{
    public function __construct(
        ResultFactory    $resultFactory,
        UcpConfig        $ucpConfig,
        RequestInterface $request,
        LoggerInterface  $logger,
        private readonly CatalogLookupService $lookupService
    ) {
        parent::__construct($resultFactory, $ucpConfig, $request, $logger);
    }

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        // Pre-validate the schema-required `ids` before generic processing.
        if ($this->ucpConfig->isEnabled() && $this->isCapabilityDeclared()) {
            $body = $this->request instanceof HttpRequest
                ? (string) $this->request->getContent()
                : '';
            $decoded = json_decode($body === '' ? '{}' : $body, true);
            if (is_array($decoded)
                && (!isset($decoded['ids']) || !is_array($decoded['ids']) || $decoded['ids'] === [])
            ) {
                return $this->errorResponse(
                    400,
                    'lookup_request requires a non-empty "ids" array (catalog_lookup.json).'
                );
            }
        }

        return parent::execute();
    }

    protected function process(array $request): array
    {
        return $this->lookupService->lookup($request);
    }

    protected function isCapabilityDeclared(): bool
    {
        return $this->ucpConfig->isCatalogLookupDeclared();
    }
}
