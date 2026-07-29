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
    public function __construct(
        protected readonly ResultFactory   $resultFactory,
        protected readonly UcpConfig       $ucpConfig,
        protected readonly RequestInterface $request,
        protected readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->ucpConfig->isEnabled() || !$this->isCapabilityDeclared()) {
            return $this->errorResponse(404, 'This site does not advertise this UCP capability.', 'not_found');
        }

        $body = $this->request instanceof HttpRequest
            ? (string) $this->request->getContent()
            : '';

        $decoded = json_decode($body === '' ? '{}' : $body, true);
        if (!is_array($decoded)) {
            return $this->errorResponse(400, 'Request body must be a JSON object.');
        }

        try {
            $response = $this->process($decoded);
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_UcpCatalog] ' . static::class . ' failed: ' . $e->getMessage());
            return $this->errorResponse(500, 'Internal error while processing the request.', 'internal_error');
        }

        return $this->jsonResponse(200, $response);
    }

    /**
     * Validate request preconditions and produce the response body.
     *
     * @param array<string, mixed> $request decoded JSON body
     * @return array<string, mixed> response body
     */
    abstract protected function process(array $request): array;

    /**
     * Whether the capability served by this action is declared in config.
     */
    abstract protected function isCapabilityDeclared(): bool;

    /**
     * Error body per types/error_response.json (REQUIRES ucp + messages)
     * with a message per types/message_error.json (REQUIRES type, code,
     * content, severity).
     */
    protected function errorResponse(
        int $httpCode,
        string $message,
        string $code = 'invalid_request'
    ): ResultInterface {
        return $this->jsonResponse($httpCode, [
            'ucp'      => ['version' => ResponseBuilder::PROTOCOL_VERSION],
            'messages' => [[
                'type'     => 'error',
                'code'     => $code,
                'content'  => $message,
                'severity' => 'unrecoverable',
            ]],
        ]);
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
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setContents(json_encode(
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
