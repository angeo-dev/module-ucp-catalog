<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

/**
 * Opaque pagination cursor (spec types/pagination.json): encodes the next
 * page number as base64url JSON. Clients MUST treat it as opaque.
 */
class Cursor
{
    public function encode(int $page): string
    {
        return rtrim(strtr(base64_encode(json_encode(['p' => $page])), '+/', '-_'), '=');
    }

    /**
     * @return int Page number (>= 1); 1 when the cursor is absent/invalid.
     */
    public function decode(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 1;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false) {
            return 1;
        }

        $data = json_decode($decoded, true);
        $page = is_array($data) ? ($data['p'] ?? 1) : 1;

        return is_int($page) && $page >= 1 ? $page : 1;
    }
}
