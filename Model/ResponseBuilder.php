<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

/**
 * Assembles spec-shaped response bodies
 * (catalog_search.json#search_response, catalog_lookup.json#lookup_response).
 *
 * Both responses REQUIRE `ucp` (response_catalog_schema: version) and
 * `products`. Search additionally carries `pagination`
 * (required: has_next_page). Lookup variants carry `inputs` correlation
 * entries (required by lookup_variant).
 */
class ResponseBuilder
{
    public const PROTOCOL_VERSION = '2026-04-08';

    /**
     * @param array<int, array<string, mixed>> $products
     */
    public function searchResponse(array $products, bool $hasNextPage, ?string $nextCursor): array
    {
        $pagination = ['has_next_page' => $hasNextPage];
        if ($hasNextPage && $nextCursor !== null) {
            $pagination['cursor'] = $nextCursor;
        }

        return [
            'ucp'        => ['version' => self::PROTOCOL_VERSION],
            'products'   => $products,
            'pagination' => $pagination,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $products products whose
     *        variants already carry `inputs` correlation entries
     * @param array<int, array<string, mixed>> $messages
     */
    public function lookupResponse(array $products, array $messages = []): array
    {
        $response = [
            'ucp'      => ['version' => self::PROTOCOL_VERSION],
            'products' => $products,
        ];

        if ($messages !== []) {
            $response['messages'] = $messages;
        }

        return $response;
    }

    /**
     * Spec-shaped warning message (types/message_warning.json):
     * REQUIRES type, code, content (string).
     *
     * @return array<string, string>
     */
    public function warningMessage(string $code, string $content): array
    {
        return ['type' => 'warning', 'code' => $code, 'content' => $content];
    }

    /**
     * Spec-shaped error message (types/message_error.json):
     * REQUIRES type, code, content (string), severity (enum).
     *
     * @return array<string, string>
     */
    public function errorMessage(string $code, string $content, string $severity = 'unrecoverable'): array
    {
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
     * @param array<string, mixed> $product
     * @param string $requestedId identifier from the request `ids` array
     * @param string $match `exact` or `featured` (spec well-known values)
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
}
