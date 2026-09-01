<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCatalog\Model;

use Magento\Catalog\Model\Product;

/**
 * get_product — POST {endpoint}/catalog/product.
 *
 * New in 2.0.0. The operation exists in the 2026-04-08 REST binding too, but
 * it only becomes worth serving once variants are real (see VariantResolver):
 * its entire purpose is interactive variant narrowing, which is meaningless
 * for a product mapped as a single self-variant.
 *
 * Response shape: get_product_response REQUIRES `ucp` and a SINGULAR
 * `product`, which is a `detail_product` — a product extended with:
 *
 *   `selected`  the effective option selections anchoring the featured
 *               variant. REQUIRED when the product has configurable options.
 *   `options`   option values carrying `available` / `exists` signals
 *               RELATIVE to those selections.
 *
 * The narrowing contract, per catalog_lookup.json#get_product_request:
 *  - `selected` carries partial or full selections from the agent;
 *  - `preferences` lists option names in RELAXATION PRIORITY order, and the
 *    server drops options from the END of that list first when nothing
 *    matches everything. `['Color', 'Size']` therefore keeps Color and
 *    relaxes Size.
 */
class CatalogProductService
{
    public function __construct(
        private readonly ProductLoader     $productLoader,
        private readonly StoreContext      $storeContext,
        private readonly ProductMapper     $productMapper,
        private readonly ResponseBuilder   $responseBuilder,
        private readonly CategoryResolver  $categoryResolver
    ) {
    }

    /**
     * @param array<string, mixed> $request decoded get_product_request body
     * @return array{0: int, 1: array<string, mixed>} [http status, body]
     */
    public function getProduct(array $request): array
    {
        $id = trim((string) ($request['id'] ?? ''));

        [$product, $requestedVariantId] = $this->productLoader->load($id);

        if ($product === null) {
            return [404, $this->responseBuilder->errorResponse([
                $this->responseBuilder->errorMessage(
                    'not_found',
                    sprintf('No product found for identifier "%s".', $id)
                ),
            ])];
        }

        $context = $this->storeContext->resolve();

        $mapped = $this->productMapper->map(
            $product,
            $context->currency,
            $context->mediaBaseUrl,
            $this->categoryResolver->namesFor($product)
        );

        $selections = $this->requestedSelections($request);

        // A variant identifier in `id` is itself a full selection, and the
        // spec says an explicit id SHOULD win over label matching.
        if ($selections === [] && $requestedVariantId !== null) {
            $selections = $this->selectionsOfVariant($mapped, $requestedVariantId);
        }

        $preferences = array_values(array_filter(
            array_map('strval', (array) ($request['preferences'] ?? []))
        ));

        [$featured, $effective, $messages] =
            $this->narrow($mapped, $selections, $preferences);

        $detail = $mapped;

        // `selected` is REQUIRED when the product has option axes; it may be
        // omitted or empty for products with none.
        if ($effective !== [] || ($mapped['options'] ?? []) !== []) {
            $detail['selected'] = $effective;
        }

        if (isset($detail['options'])) {
            $detail['options'] = $this->annotateOptions(
                $detail['options'],
                $mapped['variants'],
                $effective
            );
        }

        // Put the anchored variant first: the schema calls `selected` the
        // selections that "anchor the featured variant", and the first
        // variant is what an agent treats as featured.
        if ($featured !== null) {
            $detail['variants'] = $this->promote($detail['variants'], $featured);
        }

        return [200, $this->responseBuilder->getProductResponse($detail, $messages)];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<int, array{name: string, label: string, id?: string}>
     */
    private function requestedSelections(array $request): array
    {
        $selections = [];

        foreach ((array) ($request['selected'] ?? []) as $selection) {
            if (!is_array($selection)) {
                continue;
            }
            $name  = trim((string) ($selection['name'] ?? ''));
            $label = trim((string) ($selection['label'] ?? ''));
            if ($name === '' || $label === '') {
                // selected_option REQUIRES both; an incomplete entry cannot
                // be matched against anything.
                continue;
            }
            $entry = ['name' => $name, 'label' => $label];
            if (isset($selection['id']) && (string) $selection['id'] !== '') {
                $entry['id'] = (string) $selection['id'];
            }
            $selections[] = $entry;
        }

        return $selections;
    }

    /**
     * Resolve the effective selections and the variant they anchor,
     * relaxing by `preferences` when nothing matches everything.
     *
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $selections
     * @param array<int, string> $preferences
     * @return array{0: ?array<string, mixed>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     */
    private function narrow(array $product, array $selections, array $preferences): array
    {
        $variants = $product['variants'];
        $messages = [];

        if ($selections === []) {
            // No constraint: feature the first purchasable variant, falling
            // back to the first variant so `selected` still anchors something.
            $featured = $this->firstAvailable($variants) ?? ($variants[0] ?? null);

            return [$featured, $featured !== null ? ($featured['options'] ?? []) : [], []];
        }

        $working = $this->orderForRelaxation($selections, $preferences);

        while ($working !== []) {
            $match = $this->firstMatching($variants, $working);
            if ($match !== null) {
                if (count($working) < count($selections)) {
                    $dropped = array_map(
                        static fn (array $s): string => $s['name'],
                        array_slice($working === [] ? $selections : $selections, count($working))
                    );
                    $messages[] = $this->responseBuilder->warningMessage(
                        'not_found',
                        sprintf(
                            'No variant matched every requested option; %s '
                            . 'was relaxed to return the closest match.',
                            implode(', ', array_unique($dropped))
                        )
                    );
                }
                return [$match, $match['options'] ?? $working, $messages];
            }

            // Drop from the END of the relaxation order, per the spec.
            array_pop($working);
        }

        $featured = $this->firstAvailable($variants) ?? ($variants[0] ?? null);

        $messages[] = $this->responseBuilder->warningMessage(
            'not_found',
            'No variant matched the requested options; all selections were relaxed.'
        );

        return [$featured, $featured !== null ? ($featured['options'] ?? []) : [], $messages];
    }

    /**
     * Reorder selections so that array_pop() drops the LOWEST-priority
     * option first. `preferences` lists names most-important first, so the
     * kept order is: preferred names in order, then anything unlisted.
     *
     * @param array<int, array<string, mixed>> $selections
     * @param array<int, string> $preferences
     * @return array<int, array<string, mixed>>
     */
    private function orderForRelaxation(array $selections, array $preferences): array
    {
        if ($preferences === []) {
            return $selections;
        }

        $rank = [];
        foreach ($preferences as $index => $name) {
            $rank[mb_strtolower($name)] = $index;
        }

        $ordered = $selections;
        usort(
            $ordered,
            static function (array $a, array $b) use ($rank): int {
                $ra = $rank[mb_strtolower((string) $a['name'])] ?? PHP_INT_MAX;
                $rb = $rank[mb_strtolower((string) $b['name'])] ?? PHP_INT_MAX;
                return $ra <=> $rb;
            }
        );

        return $ordered;
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     * @param array<int, array<string, mixed>> $selections
     * @return array<string, mixed>|null
     */
    private function firstMatching(array $variants, array $selections): ?array
    {
        foreach ($variants as $variant) {
            if ($this->variantSatisfies($variant, $selections)) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $variant
     * @param array<int, array<string, mixed>> $selections
     */
    private function variantSatisfies(array $variant, array $selections): bool
    {
        $options = $variant['options'] ?? [];

        foreach ($selections as $selection) {
            $satisfied = false;
            foreach ($options as $option) {
                if (!$this->sameOptionName($option, $selection)) {
                    continue;
                }
                // The spec: when `id` is present the server SHOULD match on
                // it rather than the label, since labels are localised.
                if (isset($selection['id'])) {
                    $satisfied = isset($option['id'])
                        && (string) $option['id'] === (string) $selection['id'];
                } else {
                    $satisfied = $this->sameLabel($option['label'] ?? '', $selection['label']);
                }
                break;
            }
            if (!$satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * Annotate each option value with `available` / `exists` RELATIVE to the
     * effective selections — the difference between "this combination is not
     * sold" and "it is sold but out of stock", which is the whole reason
     * detail_option_value carries both flags.
     *
     * @param array<int, array<string, mixed>> $options
     * @param array<int, array<string, mixed>> $variants
     * @param array<int, array<string, mixed>> $effective
     * @return array<int, array<string, mixed>>
     */
    private function annotateOptions(array $options, array $variants, array $effective): array
    {
        foreach ($options as &$option) {
            $axisName = (string) ($option['name'] ?? '');

            // Hold every selection EXCEPT this axis fixed, then vary it.
            $others = array_values(array_filter(
                $effective,
                fn (array $s): bool => !$this->sameName($s['name'] ?? '', $axisName)
            ));

            foreach ($option['values'] as &$value) {
                $candidate = $others;
                $candidate[] = [
                    'name'  => $axisName,
                    'label' => (string) ($value['label'] ?? ''),
                ] + (isset($value['id']) ? ['id' => (string) $value['id']] : []);

                $exists    = false;
                $available = false;

                foreach ($variants as $variant) {
                    if (!$this->variantSatisfies($variant, $candidate)) {
                        continue;
                    }
                    $exists = true;
                    if (($variant['availability']['available'] ?? false) === true) {
                        $available = true;
                        break;
                    }
                }

                $value['exists']    = $exists;
                $value['available'] = $available;
            }
            unset($value);
        }
        unset($option);

        return $options;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, array<string, mixed>>
     */
    private function selectionsOfVariant(array $product, string $variantId): array
    {
        foreach ($product['variants'] as $variant) {
            if ($variant['id'] === $variantId) {
                return $variant['options'] ?? [];
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     * @param array<string, mixed> $featured
     * @return array<int, array<string, mixed>>
     */
    private function promote(array $variants, array $featured): array
    {
        $rest = array_values(array_filter(
            $variants,
            static fn (array $v): bool => $v['id'] !== $featured['id']
        ));

        return array_merge([$featured], $rest);
    }

    /**
     * @param array<int, array<string, mixed>> $variants
     * @return array<string, mixed>|null
     */
    private function firstAvailable(array $variants): ?array
    {
        foreach ($variants as $variant) {
            if (($variant['availability']['available'] ?? false) === true) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $option
     * @param array<string, mixed> $selection
     */
    private function sameOptionName(array $option, array $selection): bool
    {
        return $this->sameName($option['name'] ?? '', (string) $selection['name']);
    }

    private function sameName(mixed $a, string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim($b));
    }

    private function sameLabel(mixed $a, mixed $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }
}
