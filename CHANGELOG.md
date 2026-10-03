# Changelog

## 1.0.1 — 2026-10-03

Removed the `curl_close()` call in `ApiClient`. It has been a no-op since
PHP 8.0 and is deprecated since PHP 8.5, where the notice could land in front
of a caller's own JSON response when `display_errors` is on.

## 1.0.0 — 2026-09-06

First public release: `ApiClient` (transport, conditional GET),
`UnitsSnapshotService` (local snapshot, API-dictated revalidation, bounded
retry hints), file-backed `SnapshotStore`, and the units-v1 contract
(`docs/units-v1.md`).
