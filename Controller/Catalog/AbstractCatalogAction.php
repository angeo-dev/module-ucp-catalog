<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Controller\Catalog;

use Angeo\Ucp\Model\Config as UcpConfig;
use Angeo\UcpCatalog\Model\ResponseBuilder;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared plumbing for the UCP catalog REST actions.
 *
 * Spec-driven behavior (services/shopping/rest.openapi.json + hosting rules):
 *  - endpoints are public JSON-over-POST; frontend CSRF/form-key validation
 *    is bypassed (CsrfAwareActionInterface) because callers are AI agents,
 *    not browser sessions;
 *  - 404 when Angeo_Ucp is disabled or the corresponding capability is not
 *    declared in the profile — serving an undeclared capability would
 *    contradict the advertised profile;
 *  - error bodies follow types/error_response.json: `ucp` + `messages`;
 *  - responses are dynamic: Cache-Control: no-store.
 */
abstract class AbstractCatalogAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Reject oversized bodies before decoding them. */
    private const MAX_BODY_BYTES = 262144;

    public function __construct(
        protected readonly ResultFactory    $resultFactory,
        protected readonly UcpConfig        $ucpConfig,
        protected readonly RequestInterface $request,
        protected readonly LoggerInterface  $logger,
        protected readonly ResponseBuilder  $responseBuilder
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->ucpConfig->isEnabled() || !$this->isCapabilityDeclared()) {
            return $this->errorResponse(
                404,
                'This site does not advertise this UCP capability.',
                'not_found'
            );
        }

        $body = $this->request instanceof HttpRequest
            ? (string) $this->request->getContent()
            : '';

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->errorResponse(
                413,
                'Request body is too large.',
                'invalid_request'
            );
        }

        $decoded = json_decode($body === '' ? '{}' : $body, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            // Every catalog request body is a JSON OBJECT. A bare array
            // decodes without error but has no addressable members, and 1.0.0
            // passed it straight through as if it were a valid request.
            return $this->errorResponse(400, 'Request body must be a JSON object.');
        }

        try {
            [$status, $response] = $this->process($decoded);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Angeo_UcpCatalog] ' . static::class . ' failed: ' . $e->getMessage()
            );
            return $this->errorResponse(
                500,
                'Internal error while processing the request.',
                'internal_error'
            );
        }

        return $this->jsonResponse($status, $response);
    }

    /**
     * Validate request preconditions and produce the response.
     *
     * @param array<string, mixed> $request decoded JSON body
     * @return array{0: int, 1: array<string, mixed>} [http status, body]
     */
    abstract protected function process(array $request): array;

    /**
     * Whether the capability served by this action is declared in config.
     */
    abstract protected function isCapabilityDeclared(): bool;

    /**
     * Error body per types/error_response.json.
     *
     * 2.0.0 fix: the body now carries `ucp.status: "error"`. The schema
     * references ucp.json#/$defs/error, which REQUIRES it — so every error
     * 1.0.0 returned failed validation against the schema it advertised.
     */
    protected function errorResponse(
        int $httpCode,
        string $message,
        string $code = 'invalid_request',
        string $severity = 'unrecoverable'
    ): ResultInterface {
        return $this->jsonResponse(
            $httpCode,
            $this->responseBuilder->errorResponse([
                $this->responseBuilder->errorMessage($code, $message, $severity),
            ])
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function jsonResponse(int $httpCode, array $payload): ResultInterface
    {
        return $this->resultFactory
            ->create(ResultFactory::TYPE_RAW)
            ->setHttpResponseCode($httpCode)
            ->setHeader('Content-Type', 'application/json', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setHeader('Access-Control-Allow-Origin', '*', true)
            ->setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHeader('X-UCP-Version', ResponseBuilder::PROTOCOL_VERSION, true)
            ->setContents((string) json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
    }

    // ── CsrfAwareActionInterface ─────────────────────────────────────────

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
