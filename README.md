# Angeo UCP Catalog for Magento 2 (`angeo/module-ucp-catalog`)

The execution layer behind the UCP business profile advertised by
[`angeo/module-ucp`](https://packagist.org/packages/angeo/module-ucp):
implements `dev.ucp.shopping.catalog.search` and
`dev.ucp.shopping.catalog.lookup` per **UCP spec 2026-04-08**, so AI agents
that discover your store via `/.well-known/ucp` can actually query your
catalog.

## Endpoints

| Method & path | Capability |
| --- | --- |
| `POST /ucp/v1/catalog/search` | `dev.ucp.shopping.catalog.search` |
| `POST /ucp/v1/catalog/lookup` | `dev.ucp.shopping.catalog.lookup` |

Responses are validated in CI against the **official UCP JSON Schemas** at
the pinned spec tag (see `dev/schema-validation/`).

## Setup

1. `composer require angeo/module-ucp-catalog`
2. `bin/magento module:enable Angeo_UcpCatalog && bin/magento setup:upgrade`
3. In **Stores -> Configuration -> Angeo -> UCP**:
   - enable the profile and declare *Catalog Search* / *Catalog Lookup*;
   - set *Transport -> REST Endpoint URL* to
     `https://yourstore.com/ucp/v1` — the profile then advertises exactly
     the paths this module serves.

## Smoke test

```bash
curl -s -X POST https://yourstore.com/ucp/v1/catalog/search \
  -H 'Content-Type: application/json' \
  -d '{"query":"shirt","pagination":{"limit":5}}' | python3 -m json.tool

curl -s -X POST https://yourstore.com/ucp/v1/catalog/lookup \
  -H 'Content-Type: application/json' \
  -d '{"ids":["YOUR-SKU"]}' | python3 -m json.tool
```

Expected: `ucp.version = 2026-04-08`, `products[]` with `price_range` in
minor units, lookup variants carrying `inputs` correlation. With the module
or capability disabled: spec-shaped 404 error body.

## License

MIT — see `LICENSE`.
