# Changelog

All notable changes to `angeo/module-ucp-catalog` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-08-28

Adopts UCP spec release **2026-08-25** and, with it, turns the catalog
surface into something an agent can actually shop from: configurable
products now expand into real variants, and the `get_product` operation
that depends on them is implemented.

Requires `angeo/module-ucp: ^2.0`. Every response shape below is validated
in CI against the official JSON Schemas at tag `v2026-08-25`.

### Fixed

- **Error responses were schema-invalid.** `types/error_response.json`
  references `ucp.json#/$defs/error`, which REQUIRES `status: "error"`.
  1.0.0 emitted only `{"version": ...}`, so *every* error this module
  returned — the capability-disabled 404, the missing-`ids` 400, the 500
  — failed validation against the schema the module claimed to follow. An
  agent validating responses strictly would have treated all of them as
  malformed rather than as the errors they were.

- **Configurable products were advertised as a single buyable item.** The
  mapper emitted one self-variant at the parent's price with no option
  axes, so a shirt sold in three sizes looked like one product an agent
  could add to a cart directly. There was nothing to select, no way to see
  which combinations existed, and the advertised price was whichever one
  the parent happened to carry. See "Variants" below.

- **A bare JSON array was accepted as a request body.** `json_decode('[]')`
  returns an array, which passed the `is_array()` check and reached the
  service layer as a request with no addressable members. Request bodies
  must be JSON objects, and now have to be.

- **`Lookup` decoded the request body three times.** Its overridden
  `execute()` re-read and re-decoded the raw body to pre-validate `ids`,
  then called the parent, which decoded it again. The override also skipped
  that validation entirely whenever the capability was disabled, relying on
  the parent to 404 first. Validation moved into `process()`, where it
  belongs and runs once.

### Added

- **Real variants for configurable products** (`Model\VariantResolver`).
  Children are emitted as variants carrying their own price, availability,
  media and `options` selections, and `price_range` spans the true min/max
  across them rather than repeating the parent's price twice. Capped at 100
  variants per product, because a four-axis configurable can have thousands
  of children and serialising all of them helps nobody.

  Deliberately duck-typed against the product's type instance rather than
  depending on `Magento\ConfigurableProduct\*` directly: that module is
  removable, and a hard dependency would fatal on a store that removed it.
  A store without it degrades to the 1.0.0 self-variant behaviour.

- **`POST {endpoint}/catalog/product`** — the `get_product` operation
  (`catalog_lookup.json#get_product_response`). The REST binding maps it to
  the `catalog.lookup` capability, so it is gated on that toggle rather than
  a new one. It implements the spec's interactive narrowing contract:

  - `selected` carries partial or full option selections;
  - `preferences` lists option names in **relaxation priority order**, and
    the server drops options from the *end* of that list first when nothing
    matches everything. Asking for Red + Large with `["Color","Size"]` keeps
    Red and relaxes Size; with `["Size","Color"]` it keeps Large instead.
  - Every option value is annotated with `exists` and `available` **relative
    to the effective selections** — the difference between "this combination
    is not sold" and "it is sold but out of stock", which is the entire
    reason `detail_option_value` carries both flags.
  - When a selection has to be relaxed, the response says so via `messages`
    rather than silently returning something else.

- **A variant identifier now resolves to its parent product.** Both
  `catalog/lookup` and `catalog/product` return the configurable parent with
  the named child marked as the matched variant. 1.0.0 returned the child as
  a standalone product with one self-variant and no option axes — so an agent
  that looked up a variant could not tell which options it had been handed,
  which defeats the purpose of variant-level lookup.

- **`inputs` correlation is per-variant.** The matched variant gets the real
  match type (`exact` / `featured`); the product's other variants get
  `featured`, since the server chose to include them. 1.0.0 stamped the same
  match onto every variant, which makes the distinction meaningless.

- **`filters` are honoured** — `price` (min/max, ISO 4217 minor units) and
  `categories` (OR logic, matched case-insensitively against the labels this
  module emits). 1.0.0 accepted and silently ignored them, which is legal but
  unhelpful: an agent asking for shoes under EUR 150 got the unfiltered first
  page and no indication its constraint had been dropped. When a price filter
  is denominated in a currency the store cannot convert from, the filter is
  skipped **and the response says so**, exactly as `price_filter` asks.

- **`pagination.total_count`** — the repository already computed it.

- **`availability.status`** (`in_stock` / `out_of_stock`). Backorder and
  preorder are not claimed, because Magento does not distinguish them at
  this level without stock configuration this module does not read.

- **Product `categories` and `options`**, so `filters.categories` has
  something to match against and agents can see the option axes.

- Request bodies larger than 256 KB are rejected with a spec-shaped 413
  before decoding.

### Changed

- **`ResponseBuilder::PROTOCOL_VERSION` now references
  `Angeo\Ucp\Model\Config::PROTOCOL_VERSION`** instead of duplicating the
  literal. A store answering `2026-08-25` responses under a `2026-04-08`
  profile is a contradiction an agent cannot resolve, and nothing in 1.0.0
  prevented that drift. The CI job asserts the fixture harness's stub
  matches the pinned spec tag.
- Success responses state `ucp.status: "success"` explicitly rather than
  relying on the schema default.
- Store currency and media base URL extracted into `Model\StoreContext`;
  three services were resolving them inline.
- `X-UCP-Version` and `Access-Control-Allow-Methods` on every response.

### Still out of scope

- `signals` and `context.intent` are accepted and ignored. Acting on them
  means relevance ranking, and a `LIKE` query dressed up as ranking would be
  worse than an honest one.
- RFC 9421 response signing (`Signature` / `Signature-Input` /
  `Content-Digest`). `angeo/module-ucp` 2.0.0 publishes the keys for it —
  including Ed25519 for Web Bot Auth — but the signing service itself is
  still to come.
- `policies` and `actions` response members (both new and optional in
  2026-08-25).

### CI

- `UCP_SPEC_TAG` bumped to `v2026-08-25`; `mbstring` added to the PHP setup.
- `dev/schema-validation/validate.py` follows the schema reorganisation:
  `error_response.json` moved from `shopping/types` to `common/types`, and
  the `get_product_response` definition was added. Fixture-to-schema mapping
  now resolves by longest prefix so `get_product_response_*` is not
  swallowed by a shorter neighbour.
- Nine fixtures, up from three: search with a configurable product, empty
  search, a search whose price filter was refused, mixed lookup, three
  `get_product` shapes (plain, variant-anchored, relaxed) and two error
  bodies.

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
