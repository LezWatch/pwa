# LWTV app catalogue API, schema version 1

**Status:** agreed (see [LezWatch/pwa#35](https://github.com/LezWatch/pwa/issues/35))
**Date:** 2026-11-23, agreed 2026-11-24
**Implements:** [LezWatch/lwtv-underscores#755](https://github.com/LezWatch/lwtv-underscores/issues/755) (server) for the screens in `docs/design/screens/`
**Samples:** `tests/Fixtures/app/catalogue.json`, `tests/Fixtures/app/availability.json`, checked against this doc by `tests/Unit/AppCatalogueContractTest.php`

This is the contract between LezWatch.TV (WordPress) and the PWA's Laravel server. WordPress
builds it; the Laravel sync (`lwtv:sync`) reads it; the Laravel server then serves its own
copy to devices. Both sides build and test against the sample files until the real routes exist.

## Who calls what

```
lezwatchtv.com  --(lwtv:sync, every 6h)-->  PWA server  --(GET /api/...)-->  phones
```

Devices never call WordPress. So a request WordPress sees comes from one server, never
carries a user's choices, and needs no query parameters. Filtering happens on the phone.

## Routes

Both are public `GET` routes with no parameters, cached on the WordPress side with the
data-version pattern `Export_JSON::export_list()` already uses.

| Route | Holds |
|-------|-------|
| `/wp-json/lwtv/v2/app/catalogue` | One record per show, plus the term dictionaries |
| `/wp-json/lwtv/v2/app/availability` | Where each show can be watched, per region |

They are separate because they change at different speeds: availability is refreshed from
TMDB on its own schedule, and it's the bigger file.

## Rules for both

- **Envelope:** every response is an object with `schema_version` (integer), `generated_at`
  (ISO 8601, UTC) and `data_version` (an opaque string that changes whenever the data does).
  The sync can skip work when `data_version` hasn't changed.
- **Versioning:** adding a field is not a breaking change, and the client ignores fields it
  doesn't know. Removing, renaming or changing the meaning of a field bumps `schema_version`,
  and the old version keeps being served at the same route with `?schema=1` until the PWA
  has moved. (That one parameter is about the client build, not the user.)
- **Ids:** WordPress post and term ids, as integers. Object keys are those ids as strings,
  because JSON keys always are.
- **Missing values** are `null`, never `""`, `0` or `false`. A field is always present.
- **Text** is plain UTF-8 with entities already decoded (`’` not `&#8217;`), and no HTML.
- **Order:** `shows` is sorted by `id`. The client sorts for display.

## Catalogue

```json
{
  "schema_version": 1,
  "generated_at": "2026-11-23T18:00:00Z",
  "data_version": "c0ffee12",
  "terms": {
    "tropes":    { "101": { "slug": "law-enforcement", "name": "Law enforcement" } },
    "genres":    { "301": { "slug": "drama", "name": "Drama" } },
    "formats":   { "401": { "slug": "tv-show", "name": "Series" } },
    "countries": { "501": { "slug": "usa", "name": "USA" } },
    "stations":  { "601": { "slug": "max", "name": "Max" } }
  },
  "shows": [
    {
      "id": 90001,
      "slug": "the-lantern-years",
      "title": "The Lantern Years",
      "url": "https://lezwatchtv.com/show/the-lantern-years/",
      "year_start": 2019,
      "year_end": 2023,
      "on_air": false,
      "formats": [401],
      "countries": [501],
      "stations": [601],
      "genres": [301],
      "tropes": [102],
      "queer_death": false,
      "trigger": "low",
      "star": "gold",
      "lwtv_score": 84,
      "viewer_score": null,
      "viewer_votes": null,
      "tmdb_id": 210001,
      "similar": [90002]
    }
  ]
}
```

### Show fields

| Field | Type | Source in WordPress | Notes |
|-------|------|---------------------|-------|
| `id` | int | post ID | Same id `list/shows` uses today, so existing rows carry over. |
| `slug`, `title`, `url` | string | post | `url` is the show's page on lezwatchtv.com. |
| `year_start` | int or null | `Airdates` start | Card meta line ("2019 · Series · Max"). |
| `year_end` | int or null | `Airdates` finish | `null` while on air or unknown. |
| `on_air` | bool | `lezshows_on_air` | |
| `formats`, `countries`, `stations`, `genres`, `tropes` | int[] | `lez_formats`, `lez_country`, `lez_stations`, `lez_genres`, `lez_tropes` | Term ids into `terms`. Empty array when none. |
| `queer_death` | bool | `dead-queers` trope | Drives "Hide shows where a queer character dies". |
| `trigger` | `"low"`, `"med"`, `"high"` or null | `lez_triggers` slug, else `lezshows_triggerwarning` meta, run through `Trigger_Warning::normalize()` | The same lookup the show page uses (`Content_Warning`), so legacy `on` and `medium` arrive as `high` and `med`. normalize()'s `"none"` is sent as `null`. `null` means **no warning listed**, which is not the same as "safe". The trigger filter never hides a `null` show. |
| `star` | `"gold"`, `"silver"`, `"bronze"`, `"anti"` or null | `lez_stars` slug | `anti` is the app's Black star: hidden unless the user turns it on. |
| `lwtv_score` | int 0–100 or null | `lezshows_the_score`, rounded | `null` shows as "N/A". |
| `viewer_score` | number 1–5 or null | viewer score meta (lwtv-underscores#757) | `null` until that exists, and below the minimum vote count. |
| `viewer_votes` | int or null | same | |
| `tmdb_id` | int or null | `lezshows_tmdb_id` | Links availability to TMDB's watch pages. |
| `similar` | int[] | `lezshows_similar_shows` | Curated similar shows, by show id. Ids not in this catalogue are dropped. |

The `dead-queers` trope also appears in `tropes`, so the trope list stays honest; `queer_death`
is a shortcut, so the client doesn't need to know that slug.

### Term dictionaries

Each holds every term used by at least one show in the response, and no others, as `{ "slug", "name" }`.
The phone's trope list (A2, B2) is built from `terms.tropes`, sorted by `name`. Stars and
trigger levels are fixed lists in the app, not dictionaries, because the screens hard-code
their order and wording.

## Availability

```json
{
  "schema_version": 1,
  "generated_at": "2026-11-23T18:00:00Z",
  "data_version": "beef3456",
  "attribution": {
    "text": "Streaming data from JustWatch.",
    "url": "https://www.justwatch.com/"
  },
  "services": {
    "netflix": { "name": "Netflix" }
  },
  "providers": {
    "8":    { "name": "Netflix", "service": "netflix", "logo": "/sample-netflix.jpg" },
    "1796": { "name": "Netflix Standard with Ads", "service": "netflix", "logo": "/sample-netflix-ads.jpg" }
  },
  "regions": {
    "US": { "name": "United States", "services": ["netflix", "max", "prime-video", "hulu"] }
  },
  "shows": {
    "90003": { "US": { "flatrate": [8] } }
  }
}
```

| Field | Notes |
|-------|-------|
| `attribution` | Required by TMDB/JustWatch wherever availability is shown. |
| `services` | What a person picks on A1: one entry per streaming service, keyed by a stable slug. |
| `providers` | TMDB provider ids, each mapped to one `service`. This is the grouping map from #755: ad tiers and variants collapse into one service. A provider with `"service": null` is listed but can't be picked. `logo` is TMDB's `logo_path`. |
| `regions` | ISO 3166-1 alpha-2 codes. `services` is the A1 grid for that country, most-watched first; "Show all services" lists the rest from `services`. |
| `shows` | Show id → region → offer type → provider ids. Offer types are `flatrate`, `free`, `ads`, `rent`, `buy`. Empty types, regions and shows are left out to keep the file small. |

**What counts as "you can stream it" (A1):** a show matches when, in the user's region, any
provider under `flatrate`, `free` or `ads` maps to a service the user picked. `rent` and `buy`
are carried for a later "rent or buy" view but never make a show match.

The per-region TMDB link isn't stored: it is `https://www.themoviedb.org/tv/{tmdb_id}/watch?locale={region}`.

## How the screens use it

| Screen | Fields |
|--------|--------|
| A1 Region and services | `availability.regions`, `services`, `attribution` |
| A2 Tropes | `catalogue.terms.tropes`, `shows[].tropes` |
| A3 Safety filters | `queer_death`, `trigger` |
| A4 Show stars | `star`. The Black-star switch is greyed out when no show has `"anti"`. |
| A5, B1 List and home | `title`, `year_start`, `formats`, the user's matching service, `tropes`, `star`, `trigger`, `lwtv_score` |
| B2, B3 Filter sheet, empty state | All of the above, counted on the phone |
| C1 Show detail | the above, plus `viewer_score` and `viewer_votes` |

The sample files hold the design's made-up shows: the four on the list screens, plus one each
that the default filters hide (a queer death, an excluded trope, a High warning, a service the
user doesn't have), and one anti-star show.

## Decisions

Agreed on [LezWatch/pwa#35](https://github.com/LezWatch/pwa/issues/35), 2026-11-24.

1. **Two routes, no parameters, all regions in one file.** Accepted. If the availability
   file turns out too big, the fallback is one file per region at `/availability/{region}`,
   still requested only by the PWA server.
2. **`queer_death` comes from the `dead-queers` trope,** not `lezshows_dead_count > 0`.
   Accepted. The trope is the editorial call; the count includes characters who died
   off-screen or in a later spin-off.
3. **`trigger: null` stands for "No warning listed".** Accepted. There is no separate "none"
   value, because LezWatch.TV doesn't record "checked, nothing found".
   - Changed in review: the levels are WordPress's canonical slugs, `low`, `med` and `high`
     (the draft said `medium`), read the way the show page reads them. See `trigger` above.
4. **Scores are rounded to whole numbers,** as the screens show them. Accepted.
   `lezshows_the_score` is stored as a float, clamped to 0–100.
5. **`rent` and `buy` never count** toward "shows you can stream". Accepted.
6. **`services` and each region's list are curated** on the WordPress side, probably as
   `lez_watch_urls` terms. Accepted. Who maintains that list is still open on
   [LezWatch/lwtv-underscores#755](https://github.com/LezWatch/lwtv-underscores/issues/755).

## Not in version 1

- Characters per show (gender, sexuality, death cliché), item 4 of #755.
- Deep links into streaming apps.
- Anything about ratings: they go to the PWA's own ratings service, not WordPress.
