<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Angeo\Ucp\Model\Config as UcpConfig;

/**
 * Assembles spec-shaped response bodies for the catalog capability
 * (catalog_search.json#search_response, catalog_lookup.json#lookup_response
 * and #get_product_response, at spec tag v2026-08-25).
 *
 * Changes in 2.0.0:
 *  - PROTOCOL_VERSION is no longer a literal duplicated from Angeo_Ucp. It
 *    now references UcpConfig::PROTOCOL_VERSION directly, so the version the
 *    endpoints answer with cannot drift from the version the profile
 *    advertises. A store serving 2026-08-25 responses under a 2026-04-08
 *    profile (or vice versa) is a contradiction an agent has no way to
 *    resolve, and nothing in 1.0.0 prevented it.
 *  - Error bodies now carry `ucp.status: "error"`. types/error_response.json
 *    references ucp.json#/$defs/error, which REQUIRES status === "error";
 *    1.0.0 emitted only `version`, so every error this module returned
 *    failed validation against the schema it claimed to follow.
 *  - Search pagination carries `total_count` when known.
 *  - get_product responses (the new POST /catalog/product operation).
 */
class ResponseBuilder
{
    /**
     * Single source of truth: the protocol version advertised by the profile.
     */
    public const PROTOCOL_VERSION = UcpConfig::PROTOCOL_VERSION;

    /**
     * @param array<int, array<string, mixed>> $products
     * @param array<int, array<string, mixed>> $messages
     */
    public function searchResponse(
        array $products,
        bool $hasNextPage,
        ?string $nextCursor,
        ?int $totalCount = null,
        array $messages = []
    ): array {
        // pagination.response REQUIRES has_next_page, and conditionally
        // REQUIRES cursor whenever has_next_page is true.
        $pagination = ['has_next_page' => $hasNextPage];
        if ($hasNextPage && $nextCursor !== null) {
            $pagination['cursor'] = $nextCursor;
        }
        if ($totalCount !== null && $totalCount >= 0) {
            $pagination['total_count'] = $totalCount;
        }

        $response = [
            'ucp'        => $this->successMeta(),
            'products'   => $products,
            'pagination' => $pagination,
        ];

        if ($messages !== []) {
            $response['messages'] = $messages;
        }

        return $response;
    }

    /**
     * @param array<int, array<string, mixed>> $products products whose
     *        variants already carry `inputs` correlation entries
     * @param array<int, array<string, mixed>> $messages
     */
    public function lookupResponse(array $products, array $messages = []): array
    {
        $response = [
            'ucp'      => $this->successMeta(),
            'products' => $products,
        ];

        if ($messages !== []) {
            $response['messages'] = $messages;
        }

        return $response;
    }

    /**
     * get_product_response — REQUIRES `ucp` and a SINGULAR `product`.
     *
     * @param array<string, mixed> $product a detail_product
     * @param array<int, array<string, mixed>> $messages
     */
    public function getProductResponse(array $product, array $messages = []): array
    {
        $response = [
            'ucp'     => $this->successMeta(),
            'product' => $product,
        ];

        if ($messages !== []) {
            $response['messages'] = $messages;
        }

        return $response;
    }

    /**
     * Error body per types/error_response.json, which sets
     * additionalProperties: false — only `ucp`, `messages` and
     * `continue_url` are permitted.
     *
     * @param array<int, array<string, mixed>> $messages minItems: 1
     */
    public function errorResponse(array $messages): array
    {
        return [
            'ucp'      => ['version' => self::PROTOCOL_VERSION, 'status' => 'error'],
            'messages' => $messages,
        ];
    }

    /**
     * Spec-shaped warning message (types/message_warning.json):
     * REQUIRES type, code, content.
     *
     * @return array<string, string>
     */
    public function warningMessage(string $code, string $content): array
    {
        return ['type' => 'warning', 'code' => $code, 'content' => $content];
    }

    /**
     * Spec-shaped error message (types/message_error.json):
     * REQUIRES type, code, content, severity.
     *
     * @return array<string, string>
     */
    public function errorMessage(
        string $code,
        string $content,
        string $severity = 'unrecoverable'
    ): array {
        return [
            'type'     => 'error',
            'code'     => $code,
            'content'  => $content,
            'severity' => $severity,
        ];
    }

    /**
     * Attach an input-correlation entry to every variant of a mapped product.
     *
     * lookup_variant REQUIRES `inputs` with minItems 1, so this must run on
     * every product in a lookup response.
     *
     * @param array<string, mixed> $product
     * @param string $requestedId identifier from the request `ids` array
     * @param string $match `exact` or `featured` (well-known values)
     * @return array<string, mixed>
     */
    public function withInputCorrelation(array $product, string $requestedId, string $match): array
    {
        foreach ($product['variants'] as &$variant) {
            $variant['inputs'][] = ['id' => $requestedId, 'match' => $match];
        }
        unset($variant);

        return $product;
    }

    /**
     * `status` defaults to "success", but stating it explicitly means an
     * agent never has to infer the outcome from the HTTP code alone.
     *
     * @return array<string, string>
     */
    private function successMeta(): array
    {
        return ['version' => self::PROTOCOL_VERSION, 'status' => 'success'];
    }
}
