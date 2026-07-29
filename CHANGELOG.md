# Changelog

All notable changes to `angeo/module-ucp-catalog` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-07-04

First release: the execution layer behind the profile that
`angeo/module-ucp` advertises. Implements the two read-only capabilities of
the UCP shopping service per spec **2026-04-08**, with responses validated
against the OFFICIAL JSON Schemas
(`catalog_search.json#search_response`, `catalog_lookup.json#lookup_response`,
`types/message_*.json`, `types/error_response.json`).

### Added

- **`POST {endpoint}/catalog/search`** (`dev.ucp.shopping.catalog.search`):
  free-text query matched against product name OR SKU, restricted to enabled
  products visible in search; opaque cursor pagination (default limit 10 per
  spec, clamped to 50); prices in ISO 4217 minor units with zero-decimal
  currency handling (JPY, KRW, VND, CLP, ISK, UGX).
- **`POST {endpoint}/catalog/lookup`** (`dev.ucp.shopping.catalog.lookup`):
  batch resolution of `gid://magento/Product/{id}` (match `featured`),
  `gid://magento/ProductVariant/{id}` and SKU (match `exact`), with
  per-variant `inputs` correlation entries as REQUIRED by `lookup_variant`;
  unresolved identifiers yield spec-shaped `not_found` warning messages.
- Frontend router serving the spec's REST paths under
  `https://store.example.com/ucp/v1/...` — set that URL as *Transport ->
  REST Endpoint URL* in Angeo_Ucp so the profile advertises exactly what
  this module serves. LiteSpeed-safe path matching, CSRF-exempt POST
  actions, `Cache-Control: no-store`, CORS.
- Capability gating: endpoints return spec-shaped 404 error bodies unless
  Angeo_Ucp is enabled AND the corresponding capability is declared —
  the served surface never contradicts the advertised profile.
- CI schema validation (`.github/workflows/schema-validation.yml` +
  `dev/schema-validation/`), same drift-detection approach as
  `angeo/module-ucp`.

### Known limitations (roadmap)

- Every product maps as a single self-variant; configurable-children
  expansion is planned.
- Request `filters`, `context`, and `signals` are accepted but ignored
  (all optional per `search_request`).
- `POST /catalog/product` (get_product detail) and RFC 9421 response
  signing (`Signature`/`Signature-Input`/`Content-Digest` headers) are
  planned alongside a shared SignatureService in `angeo/module-ucp`.
