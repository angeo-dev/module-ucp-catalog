# Angeo UCP Catalog for Magento 2 (`angeo/module-ucp-catalog`)

The execution layer behind the UCP business profile advertised by
[`angeo/module-ucp`](https://packagist.org/packages/angeo/module-ucp):
implements `dev.ucp.shopping.catalog.search` and
`dev.ucp.shopping.catalog.lookup` per **UCP spec 2026-08-25**, so AI agents
that discover your store via `/.well-known/ucp` can actually shop it.

## Endpoints

| Method & path | Operation | Capability |
| --- | --- | --- |
| `POST /ucp/v1/catalog/search` | `search_catalog` | `dev.ucp.shopping.catalog.search` |
| `POST /ucp/v1/catalog/lookup` | `lookup_catalog` | `dev.ucp.shopping.catalog.lookup` |
| `POST /ucp/v1/catalog/product` | `get_product` | `dev.ucp.shopping.catalog.lookup` |

Responses are validated in CI against the **official UCP JSON Schemas** at
the pinned spec tag (see `dev/schema-validation/`).

## Setup

1. `composer require angeo/module-ucp-catalog:^2.0`
2. `bin/magento module:enable Angeo_UcpCatalog && bin/magento setup:upgrade`
3. In **Stores → Configuration → Angeo → UCP**:
   - enable the profile and declare *Catalog Search* / *Catalog Lookup*;
   - set *Transport → REST Endpoint URL* to `https://yourstore.com/ucp/v1`
     so the profile advertises exactly the paths this module serves.
4. `bin/magento angeo:ucp:validate`

## Variants

UCP's model is **product → variants**, where the *variant* is the
purchasable unit carrying its own price, availability and option
selections. Magento's configurable product is exactly that shape, and 2.0.0
maps it as such:

```
Classic Shirt                       price_range 29.00 – 32.50 EUR
├─ SHIRT-BLUE-S   29.00  in_stock       Color: Blue, Size: Small
├─ SHIRT-BLUE-L   32.50  in_stock       Color: Blue, Size: Large
└─ SHIRT-RED-S    29.00  out_of_stock   Color: Red,  Size: Small
```

1.0.0 flattened this into one self-variant at the parent price with no
option axes at all — an agent had nothing to select and no way to know
which combinations existed.

`Magento_ConfigurableProduct` is *suggested*, not required. The resolver
duck-types against the product's type instance, so a store that removed
that module degrades to the old single-self-variant behaviour instead of
failing.

## Interactive narrowing (`get_product`)

`POST /catalog/product` is where option selection happens. `preferences`
lists option names in **relaxation priority order** — the server drops
options from the *end* of that list first:

```bash
curl -s -X POST https://yourstore.com/ucp/v1/catalog/product \
  -H 'Content-Type: application/json' \
  -d '{
        "id": "gid://magento/Product/10",
        "selected": [{"name":"Color","label":"Red"},{"name":"Size","label":"Large"}],
        "preferences": ["Color", "Size"]
      }'
```

Red/Large does not exist in the matrix above, so `Size` relaxes and
`SHIRT-RED-S` comes back — with a `messages` entry saying what was relaxed.
Send `["Size","Color"]` instead and `SHIRT-BLUE-L` comes back.

Each option value is annotated relative to the effective selections:

| | `exists` | `available` |
| --- | --- | --- |
| Red / Small | `true` | `false` — sold, but out of stock |
| Red / Large | `false` | `false` — never built |

Those are different facts, and conflating them is how an agent ends up
telling a shopper something is "unavailable" when it was never a product.

## Filters

Both filter types the schema defines are applied:

- **`price`** — `min`/`max` in ISO 4217 **minor units**, denominated in
  `context.currency`. When that currency differs from what the store
  presents and no conversion is available, the filter is skipped **and the
  response says so** in `messages`, as `price_filter` requires.
- **`categories`** — OR logic, matched case-insensitively against the same
  labels this module emits in `product.categories`.

`signals` and `context.intent` are accepted and ignored: acting on them
means relevance ranking, and a `LIKE` query dressed up as ranking would be
worse than an honest one.

## Smoke test

```bash
curl -s -X POST https://yourstore.com/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' \
  -d '{"query":"shirt","pagination":{"limit":5},"filters":{"price":{"max":15000}}}' \
  | python3 -m json.tool

curl -s -X POST https://yourstore.com/ucp/v1/catalog/lookup \
  -H 'Content-Type: application/json' \
  -d '{"ids":["YOUR-SKU"]}' | python3 -m json.tool
```

Expected: `ucp.version = 2026-08-25`, `ucp.status = success`, `products[]`
with `price_range` in minor units, `pagination.total_count`, and lookup
variants carrying `inputs` correlation. With the module or capability
disabled: a spec-shaped 404 body with `ucp.status = error`.

## Upgrading from 1.x

Requires `angeo/module-ucp:^2.0` — the two move together, since the
protocol version the endpoints answer with is now read from the profile
module rather than duplicated here.

Nothing to configure. Expect these visible changes:

- error bodies gain `ucp.status: "error"` (1.0.0's were schema-invalid);
- configurable products return several variants instead of one, and their
  `price_range` widens to the real span;
- a variant identifier resolves to its parent product rather than to the
  child alone;
- `filters` now actually filter.

## Not implemented yet

RFC 9421 response signing (`Signature` / `Signature-Input` /
`Content-Digest`). `angeo/module-ucp` 2.0.0 publishes the keys for it,
including Ed25519 for Web Bot Auth interop; the signing service is next.
The `policies` and `actions` response members (both new and optional in
2026-08-25) are also not emitted.

## License

MIT — see `LICENSE`.
