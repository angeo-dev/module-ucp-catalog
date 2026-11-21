# Security Policy

## Supported versions

Security fixes go to the latest minor release of the current major
(2.1.x as of 2.1.2). Upgrade within the major line to receive them.

## Reporting a vulnerability

Email **info@angeo.dev** with the subject line `[security] module-ucp-catalog`.

Please include:

- Module version (output of `bin/magento module:status Angeo_UcpCatalog`).
- Magento and PHP version.
- Steps to reproduce, or a proof of concept.
- Whether you believe the issue is publicly known.

I aim to acknowledge within 72 hours and ship a patched release within
14 days for confirmed high-severity issues. Please do not open a public
GitHub issue for security problems.

## What this module exposes

- `POST /ucp/v1/catalog/search`, `/lookup` and `/product` are public by
  design (the UCP catalog capabilities). They return only what the
  storefront already shows: enabled, visible products with store prices.
- Request bodies are capped at 256 KB; `pagination.limit` at 50 and the
  cursor at page 200, so one request cannot ask for a deep SQL offset.
- Inbound RFC 9421 signatures are verified by `angeo/module-ucp`
  (Stores → Configuration → Angeo UCP → Request Security). Failures answer
  with the spec's error codes (`signature_invalid`, `key_not_found`,
  `digest_mismatch`, …) and no detail about which part failed.
- There is no built-in rate limit. Put the `/ucp/` paths behind your WAF or
  CDN rate rules, as for any public API.
