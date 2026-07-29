<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Test\Unit\Model;

use Angeo\UcpCatalog\Model\Cursor;
use Angeo\UcpCatalog\Model\ProductMapper;
use Angeo\UcpCatalog\Model\ResponseBuilder;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductMapper::class)]
#[CoversClass(ResponseBuilder::class)]
#[CoversClass(Cursor::class)]
final class ProductMapperTest extends TestCase
{
    private const MEDIA = 'https://shop.example.com/media/';

    #[Test]
    public function mapped_product_carries_all_schema_required_fields(): void
    {
        // product.json required: id, title, description, price_range, variants;
        // variant required: id, title, description, price.
        $mapped = (new ProductMapper())->map($this->product(), 'EUR', self::MEDIA);

        foreach (['id', 'title', 'description', 'price_range', 'variants'] as $key) {
            self::assertArrayHasKey($key, $mapped);
        }
        foreach (['id', 'title', 'description', 'price'] as $key) {
            self::assertArrayHasKey($key, $mapped['variants'][0]);
        }
    }

    #[Test]
    public function price_amount_is_in_minor_units(): void
    {
        $mapped = (new ProductMapper())->map(
            $this->product(['final_price' => 19.99]),
            'EUR',
            self::MEDIA
        );

        self::assertSame(1999, $mapped['price_range']['min']['amount']);
        self::assertSame('EUR', $mapped['price_range']['min']['currency']);
        self::assertSame($mapped['price_range']['min'], $mapped['variants'][0]['price']);
    }

    #[Test]
    public function zero_decimal_currency_is_not_multiplied(): void
    {
        $mapped = (new ProductMapper())->map(
            $this->product(['final_price' => 1500.0]),
            'JPY',
            self::MEDIA
        );

        self::assertSame(1500, $mapped['price_range']['min']['amount']);
    }

    #[Test]
    public function empty_description_falls_back_to_title(): void
    {
        // description is REQUIRED on product and variant; empty catalogs
        // must still produce a schema-valid object.
        $mapped = (new ProductMapper())->map(
            $this->product(['description' => '', 'short_description' => null]),
            'EUR',
            self::MEDIA
        );

        self::assertSame('Test Widget', $mapped['description']['plain']);
    }

    #[Test]
    public function html_description_is_stripped_to_plain(): void
    {
        $mapped = (new ProductMapper())->map(
            $this->product(['description' => '<p>Great &amp; sturdy</p>']),
            'EUR',
            self::MEDIA
        );

        self::assertSame('Great & sturdy', $mapped['description']['plain']);
    }

    #[Test]
    public function gids_and_availability_are_mapped(): void
    {
        $mapped = (new ProductMapper())->map(
            $this->product(['id' => 42, 'is_salable' => true]),
            'EUR',
            self::MEDIA
        );

        self::assertSame('gid://magento/Product/42', $mapped['id']);
        self::assertSame('gid://magento/ProductVariant/42', $mapped['variants'][0]['id']);
        self::assertTrue($mapped['variants'][0]['availability']['available']);
    }

    #[Test]
    public function media_entry_is_built_from_image_and_omitted_when_absent(): void
    {
        $with = (new ProductMapper())->map(
            $this->product(['image' => '/t/w/widget.jpg']),
            'EUR',
            self::MEDIA
        );
        self::assertSame(
            'https://shop.example.com/media/catalog/product/t/w/widget.jpg',
            $with['media'][0]['url']
        );
        self::assertSame('image', $with['media'][0]['type']);

        $without = (new ProductMapper())->map(
            $this->product(['image' => 'no_selection']),
            'EUR',
            self::MEDIA
        );
        self::assertArrayNotHasKey('media', $without);
    }

    #[Test]
    public function search_response_has_required_keys_and_cursor_only_with_next_page(): void
    {
        $builder = new ResponseBuilder();

        $withNext = $builder->searchResponse([], true, 'CURSOR');
        self::assertSame('2026-04-08', $withNext['ucp']['version']);
        self::assertTrue($withNext['pagination']['has_next_page']);
        self::assertSame('CURSOR', $withNext['pagination']['cursor']);

        $lastPage = $builder->searchResponse([], false, null);
        self::assertFalse($lastPage['pagination']['has_next_page']);
        self::assertArrayNotHasKey('cursor', $lastPage['pagination']);
    }

    #[Test]
    public function input_correlation_is_attached_to_every_variant(): void
    {
        $builder = new ResponseBuilder();
        $product = (new ProductMapper())->map($this->product(), 'EUR', self::MEDIA);

        $correlated = $builder->withInputCorrelation($product, 'WID-001', 'exact');

        self::assertSame(
            [['id' => 'WID-001', 'match' => 'exact']],
            $correlated['variants'][0]['inputs']
        );
    }

    #[Test]
    public function messages_carry_all_schema_required_fields(): void
    {
        // message_warning.json requires type+code+content;
        // message_error.json additionally requires severity.
        $builder = new ResponseBuilder();

        $warning = $builder->warningMessage('not_found', 'Missing.');
        self::assertSame(
            ['type' => 'warning', 'code' => 'not_found', 'content' => 'Missing.'],
            $warning
        );

        $error = $builder->errorMessage('invalid_request', 'Bad body.');
        self::assertSame('error', $error['type']);
        self::assertSame('unrecoverable', $error['severity']);
        foreach (['type', 'code', 'content', 'severity'] as $key) {
            self::assertArrayHasKey($key, $error);
        }
    }

    #[Test]
    public function cursor_round_trips_and_tolerates_garbage(): void
    {
        $cursor = new Cursor();

        self::assertSame(7, $cursor->decode($cursor->encode(7)));
        self::assertSame(1, $cursor->decode(null));
        self::assertSame(1, $cursor->decode('!!!not-base64!!!'));
        self::assertSame(1, $cursor->decode(base64_encode('{"p":-3}')));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return new Product($overrides + [
            'id'          => 7,
            'sku'         => 'WID-001',
            'name'        => 'Test Widget',
            'description' => 'A very good widget.',
            'final_price' => 10.0,
            'is_salable'  => true,
            'product_url' => 'https://shop.example.com/test-widget.html',
            'image'       => '/t/w/widget.jpg',
        ]);
    }
}
