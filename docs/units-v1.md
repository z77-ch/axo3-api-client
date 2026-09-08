# units-v1 — the contract of `GET /api/v1/units`

**Owner:** axo3 (the API carries the version) · **Status:** v1.

> **The principle:** the consumer of the axo3 API does not know where the
> data comes from. There is ONE axo3 data model — Property → Building →
> Unit — maintained on the axo3 side and delivered as is. Whatever changes
> behind the API, the consumer sees the same bundle, the same words, the
> same links.

## Transport

- **Endpoint:** `GET https://api.axo3.ch/api/v1/units`
- **Auth:** `Authorization: Bearer <key>` — the key identifies your
  connection; it is issued in the axo3 backend and can be rotated there.
- **Conditional GET:** send `If-None-Match: "<etag>"` with the hash you
  hold; unchanged stock answers `304` with an empty body.
- **Success answers** (200 and 304) may carry:
  - `X-Axo3-Revalidate-After: <seconds>` — the interval the API dictates
    (also in the bundle as `meta.revalidateAfter` on a 200).
  - `X-Axo3-Retry-After: <seconds>` — the stock is being rebuilt right now;
    the answer is the stand as it lies, ask again shortly.
- **Errors** answer with a JSON body `{"error": {"code": "…", "message": "…"}}`:
  - `401 unauthorized` — key missing, unknown or revoked.
  - `429 throttled` — too many requests; honour `Retry-After`.
  - `503 snapshot_pending` — the stock is being built for the first time;
    honour `Retry-After`.
- **Liveness:** `GET /api/v1/health` with the same Bearer key answers `200`
  — route, header pass-through and key proven in one call.

`Axo3\ApiClient\Client\ApiClient` implements exactly this;
`Axo3\ApiClient\Service\UnitsSnapshotService` adds the snapshot and the
timing rules below.

## The bundle

```json
{
  "meta": {
    "version": 1,
    "revalidateAfter": 900,
    "generatedAt": "2026-09-03T10:00:00+02:00"
  },
  "properties": [
    {
      "id": "p-3f9a2c81b4d0",
      "title": "Sonnengarten",
      "address": { "zip": "1234", "city": "Musterlingen", "state": "ZH" },
      "buildings": [
        {
          "id": "b-7c1e02aa93f4",
          "propertyId": "p-3f9a2c81b4d0",
          "title": "Haus A",
          "street": "Gartenweg",
          "streetNumber": "3",
          "zip": "1234",
          "city": "Musterlingen",
          "yearBuilt": 2024,
          "imageUrl": "https://widget.axo3.ch/frontend/main/media/file?t=…",
          "units": [
            {
              "id": "u-91b0d5e6f2a7",
              "buildingId": "b-7c1e02aa93f4",
              "title": "3.5-Zimmer-Wohnung",
              "name": null,
              "description": "…",
              "reference": "0120024",
              "type": "rent",
              "category": "apartment",
              "rooms": 3.5,
              "floorLabel": "2. OG",
              "floorNumber": 2,
              "livingArea": 84.5,
              "outdoorArea": 12.0,
              "netLivingArea": null,
              "effectiveArea": null,
              "plotArea": null,
              "priceNet": 1850.0,
              "priceGross": 2050.0,
              "additionalCosts": 200.0,
              "sellingPrice": null,
              "status": "free",
              "statusText": "verfügbar",
              "availableFrom": "2026-11-01",
              "contact": { "name": "…", "email": "…", "phone": "…" },
              "imageUrls": ["https://widget.axo3.ch/frontend/main/media/file?t=…"],
              "floorPlanUrl": "https://widget.axo3.ch/frontend/main/media/file?t=…",
              "documentUrls": [
                { "url": "https://widget.axo3.ch/frontend/main/media/file?t=…", "label": "Grundriss", "isFloorPlan": true }
              ],
              "applicationUrl": "https://…"
            }
          ]
        }
      ]
    }
  ]
}
```

Always three levels. A source without properties gets one property per
project on the axo3 side. No `orphanBuildings` — the model has none.

## Fields

### meta

| field | type | meaning |
|---|---|---|
| `version` | int | payload version, `1` |
| `revalidateAfter` | int | seconds until the consumer should ask again — **the API dictates the interval**; the same value rides as header `X-Axo3-Revalidate-After` on 200 and 304 |
| `generatedAt` | ISO 8601 | when this answer was built; not part of the ETag |

### property

| field | type | null when |
|---|---|---|
| `id` | string `p-…` | never — the axo3 id, stable across imports |
| `title` | string | the source has none |
| `address` | `{zip, city, state}` | the source carries no property address |
| `buildings` | building[] | never (may be empty) |

### building

| field | type | null when |
|---|---|---|
| `id` | string `b-…` | never |
| `propertyId` | string | never |
| `title` | string | — |
| `street`, `streetNumber`, `zip`, `city` | string | the source has none |
| `yearBuilt` | int | — |
| `imageUrl` | URL | no building image |
| `units` | unit[] | never (may be empty) |

### unit

| field | type | vocabulary / null when |
|---|---|---|
| `id` | string `u-…` | never |
| `buildingId` | string | never |
| `title`, `name`, `description` | string | — |
| `reference` | string | the marketing number of the source (list display) |
| `type` | `rent` \| `sale` | null when unknown |
| `category` | string | axo3 category, lowercase (`apartment`, `commercial`, `parking_space`, …) |
| `rooms` | float | — |
| `floorLabel` | string | the wording to display (`EG`, `2. OG`) |
| `floorNumber` | int | for sorting; `0` = ground floor |
| `livingArea`, `outdoorArea` | float, m² | — |
| `netLivingArea`, `effectiveArea`, `plotArea` | float, m² | since 2026-09-08. A **commercial** object carries its size as `effectiveArea` (usable area) and has no `livingArea` — show the usable area where the living area is null. Present on every unit, null when the source has none |
| `priceNet`, `priceGross`, `additionalCosts` | float, CHF | **all null = price on request** — the notice is the consumer's wording |
| `sellingPrice` | float, CHF | rent objects |
| `status` | **`free` \| `reserved` \| `taken`** | never — the three words the model allows; a unit no longer on offer is not delivered at all |
| `statusText` | string | the stock's own wording of the finding, for display beside the badge |
| `availableFrom` | `YYYY-MM-DD` | null = immediately / by arrangement |
| `contact` | `{name, email, phone}` | no contact per unit |
| `imageUrls` | URL[] | empty when none; source order |
| `floorPlanUrl` | URL | no floor plan |
| `documentUrls` | `{url, label, isFloorPlan}[]` | empty when none; the floor plan is listed here too |
| `applicationUrl` | URL | no application link |

## Status — no guessing

`status` is the ONE field a consumer colours by. It is decided on the axo3
side and it is one of exactly three words. `statusText` is the human wording,
for people; `status` is for code. A consumer that derives its own status from
prices, dates or texts is doing axo3's job again, differently.

## Media links

Every URL in `imageUrls`, `floorPlanUrl`, `documentUrls[].url` and the
building's `imageUrl` is an **axo3 link**. It stays valid; opening it makes
axo3 resolve the current file and answer with a redirect. A consumer stores
these links as they are and never rewrites, signs or expires them.

## ETag and revalidation

- `ETag` = hash over `properties` as delivered to YOUR connection — so it
  changes with the stock, with a visibility switch, with the connection's
  property selection, and when a media link is renewed. `meta` is excluded.
- The consumer sends `If-None-Match: "<etag>"`; unchanged → `304`, empty body.
- The consumer asks again after `revalidateAfter` seconds, never sooner.
  On a failed fetch it keeps its current snapshot, logs, and asks again after
  the same interval (`UnitsSnapshotService`).
- **`X-Axo3-Retry-After: <seconds>`** on a 200 or a 304: the stock is being
  rebuilt right now; the answer is the stand as it lies. Ask again after that
  many seconds — **at most five times in a row**, then fall back to
  `revalidateAfter` until an answer without the hint resets the count. The
  standard `Retry-After` on a `503 snapshot_pending` or a `429` is honoured
  the same way (`UnitsSnapshotService::nextCheckIn()`).
- **The consumer renders first and asks afterwards.** Every fetch of this
  endpoint is a background call made AFTER the consumer's page is built from
  its own snapshot — never inside the page's render path. The API may take
  seconds on such a call: it refreshes its own stock before answering, so the
  same call already carries the fresh data. A consumer that fetches
  synchronously while rendering breaks this rule and is fixed on its side;
  the API does not adapt to it.

## What is NOT in the bundle

- Fields beyond the model: a field a consumer needs is lifted into the
  model on the axo3 side, not passed through.
- Hidden units, buildings or properties, and units outside the connection's
  property selection: filtered OUT, not flagged. What a consumer may not show
  must not lie at the consumer.
- Units that vanished from the offer.

## Changes

| date | what |
|---|---|
| 2026-09-08 | Additive: `netLivingArea`, `effectiveArea`, `plotArea` on every unit (null when unknown). Offices size themselves through `effectiveArea` |
| 2026-09-05 | `X-Axo3-Retry-After` on 200/304 while a rebuild runs; the consumer honours it (and `Retry-After` on 503/429) at most five times in a row |
| 2026-09-05 | Consumer rule: render first, ask afterwards — every fetch is a background call |
| 2026-09-03 | v1: the axo3 model projection; `meta.revalidateAfter`; axo3 media links |
