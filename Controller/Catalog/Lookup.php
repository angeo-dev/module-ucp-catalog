<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Controller\Catalog;

use Angeo\Ucp\Api\SignatureVerifierInterface;
use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCatalog\Model\CatalogLookupService;
use Angeo\UcpCatalog\Model\ResponseBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * POST {endpoint}/catalog/lookup — dev.ucp.shopping.catalog.lookup.
 *
 * lookup_request REQUIRES a non-empty `ids` array (minItems: 1), so a
 * missing or empty array is a 400.
 *
 * 2.0.0 moved that check into process(). Under 1.0.0 it lived in an
 * overridden execute() that re-read and re-decoded the request body before
 * delegating to the parent, which then decoded it a third time — and the
 * override silently skipped the check whenever the capability was disabled,
 * relying on the parent to 404 first.
 */
class Lookup extends AbstractCatalogAction
{
    public function __construct(
        ResultFactory    $resultFactory,
        UcpConfig        $ucpConfig,
        RequestInterface $request,
        LoggerInterface  $logger,
        ResponseBuilder  $responseBuilder,
        SignatureVerifierInterface $signatureVerifier,
        private readonly CatalogLookupService $lookupService
    ) {
        parent::__construct(
            $resultFactory,
            $ucpConfig,
            $request,
            $logger,
            $responseBuilder,
            $signatureVerifier
        );
    }

    protected function process(array $request): array
    {
        $ids = $request['ids'] ?? null;

        if (!is_array($ids) || $ids === []) {
            return [400, $this->responseBuilder->errorResponse([
                $this->responseBuilder->errorMessage(
                    'invalid_request',
                    'lookup_request requires a non-empty "ids" array '
                    . '(catalog_lookup.json#lookup_request, minItems: 1).'
                ),
            ])];
        }

        return [200, $this->lookupService->lookup($request)];
    }

    protected function isCapabilityDeclared(): bool
    {
        return $this->ucpConfig->isCatalogLookupDeclared();
    }
}
