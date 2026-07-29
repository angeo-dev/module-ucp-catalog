<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RouterInterface;

/**
 * Routes the UCP shopping REST paths (spec services/shopping/rest.openapi.json,
 * tag v2026-04-08) onto controllers:
 *
 *   POST {endpoint}/catalog/search -> Catalog\Search
 *   POST {endpoint}/catalog/lookup -> Catalog\Lookup
 *
 * with {endpoint} = https://store.example.com/ucp/v1 (set this URL as
 * "Transport -> REST Endpoint URL" in Angeo_Ucp admin config so the profile
 * advertises exactly what this module serves).
 *
 * Path matching mirrors Angeo_Ucp\Controller\Router: multiple path sources
 * are checked because LiteSpeed leaves PATH_INFO unreliable.
 */
class Router implements RouterInterface
{
    private const ROUTES = [
        '/ucp/v1/catalog/search' => [\Angeo\UcpCatalog\Controller\Catalog\Search::class, 'search'],
        '/ucp/v1/catalog/lookup' => [\Angeo\UcpCatalog\Controller\Catalog\Lookup::class, 'lookup'],
    ];

    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest) {
            return null;
        }

        $matched = $this->matchPath($request);
        if ($matched === null) {
            return null;
        }

        [$actionClass, $actionName] = self::ROUTES[$matched];

        $request->setModuleName('angeo_ucp_catalog')
            ->setControllerName('catalog')
            ->setActionName($actionName);

        return $this->actionFactory->create($actionClass);
    }

    private function matchPath(HttpRequest $request): ?string
    {
        $candidates = [
            (string) $request->getPathInfo(),
            (string) $request->getOriginalPathInfo(),
            (string) $request->getRequestUri(),
        ];

        foreach ($candidates as $raw) {
            if ($raw === '') {
                continue;
            }
            $path = rtrim((string) (parse_url($raw, PHP_URL_PATH) ?: $raw), '/');
            if (isset(self::ROUTES[$path])) {
                return $path;
            }
        }

        return null;
    }
}
