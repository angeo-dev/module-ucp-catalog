<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 *
 * Generates fixture RESPONSE bodies through the real mapping code (no
 * Magento install needed) for validation against the official UCP schemas.
 *
 * Usage: php dev/schema-validation/generate_fixtures.php [output-dir]
 */

declare(strict_types=1);

namespace Magento\Catalog\Model {
    if (!class_exists(Product::class)) {
        class Product
        {
            public function __construct(private array $data = [])
            {
            }
            public function getId() { return $this->data['id'] ?? null; }
            public function getSku() { return $this->data['sku'] ?? ''; }
            public function getName() { return $this->data['name'] ?? ''; }
            public function getData($key = null) { return $key === null ? $this->data : ($this->data[$key] ?? null); }
            public function getFinalPrice() { return $this->data['final_price'] ?? 0.0; }
            public function isSalable() { return $this->data['is_salable'] ?? false; }
            public function getProductUrl() { return $this->data['product_url'] ?? ''; }
        }
    }
}

namespace AngeoUcpCatalogFixtures {

    use Angeo\UcpCatalog\Model\Cursor;
    use Angeo\UcpCatalog\Model\ProductMapper;
    use Angeo\UcpCatalog\Model\ResponseBuilder;
    use Magento\Catalog\Model\Product;

    $moduleDir = dirname(__DIR__, 2);
    spl_autoload_register(function (string $class) use ($moduleDir): void {
        if (str_starts_with($class, 'Angeo\\UcpCatalog\\')) {
            $file = $moduleDir . '/'
                . str_replace('\\', '/', substr($class, strlen('Angeo\\UcpCatalog\\'))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });

    $mapper  = new ProductMapper();
    $builder = new ResponseBuilder();
    $cursor  = new Cursor();
    $media   = 'https://shop.example.com/media/';

    $widget = new Product([
        'id' => 7, 'sku' => 'WID-001', 'name' => 'Test Widget',
        'description' => '<p>A very good widget.</p>', 'final_price' => 19.99,
        'is_salable' => true,
        'product_url' => 'https://shop.example.com/test-widget.html',
        'image' => '/t/w/widget.jpg',
    ]);
    $bare = new Product([
        'id' => 8, 'sku' => 'BARE-002', 'name' => 'Bare Product',
        'description' => '', 'final_price' => 5.0, 'is_salable' => false,
    ]);

    $fixtures = [
        'search_response_page1' => $builder->searchResponse(
            [$mapper->map($widget, 'EUR', $media), $mapper->map($bare, 'EUR', $media)],
            true,
            $cursor->encode(2)
        ),
        'search_response_empty' => $builder->searchResponse([], false, null),
        'lookup_response_mixed' => $builder->lookupResponse(
            [
                $builder->withInputCorrelation($mapper->map($widget, 'EUR', $media), 'WID-001', 'exact'),
                $builder->withInputCorrelation($mapper->map($bare, 'EUR', $media), 'gid://magento/Product/8', 'featured'),
            ],
            [$builder->warningMessage('not_found', 'Identifier "GONE-404" not found.')]
        ),
    ];

    $outDir = $argv[1] ?? __DIR__ . '/fixtures';
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        fwrite(STDERR, "Cannot create {$outDir}\n");
        exit(1);
    }

    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
    foreach ($fixtures as $name => $payload) {
        file_put_contents(rtrim($outDir, '/') . "/{$name}.json", json_encode($payload, $flags) . "\n");
        echo "wrote {$name}.json\n";
    }
}
