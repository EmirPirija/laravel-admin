# Location Hardening Rollout (2026-03-04)

## Implemented in `ApiController@getLocationFromCoordinates`
- Added endpoint-level rate limiting via `RateLimiter` with scope-aware limits (`search`, `coordinates`, `place_id`).
- Added short-lived cache for normalized location lookups.
- Added reverse geocoding fallback (`Nominatim reverse`) when local city/municipality lookup has no match.
- Added structured observability logs:
  - `location.lookup.success`
  - `location.lookup.failed`
  - `location.lookup.cache_hit`
  - `location.lookup.rate_limited`
  - `location.lookup.exception`
- Replaced `500 No nearby city found` path with controlled `404` when no location can be resolved.

## Runtime/Ops knobs (.env)
- `LOCATION_LOOKUP_RATE_LIMIT_MAX` (optional)
- `LOCATION_LOOKUP_RATE_LIMIT_DECAY_SECONDS` (optional)
- `LOCATION_LOOKUP_CACHE_TTL_SECONDS` (optional)

If unset, safe defaults are used in code.

## Post-pull rollout
1. `git fetch origin`
2. `git checkout main`
3. `git pull origin main`
4. `php artisan migrate`
5. `php artisan optimize:clear`

## Smoke checklist (no frontend build required)
1. `GET /api/get-location?lat=43.8563&lng=18.4131&lang=bs`
  - Expected: `200` with resolved payload.
2. `GET /api/get-location?search=Sarajevo&lang=bs`
  - Expected: `200` with non-empty results.
3. Repeat same request twice
  - Expected: second call served from cache (see logs/meta).
4. Burst same endpoint quickly from same IP/token
  - Expected: `429` with retry metadata.
5. Invalid coordinate `lat=999`
  - Expected: `422`.

## Why this matters
- Removes high-volume `500` geolocation regressions seen in frontend logs.
- Protects backend/third-party map APIs from abuse bursts.
- Provides traceable diagnostics for production triage.
