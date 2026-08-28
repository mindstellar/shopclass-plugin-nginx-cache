# Changelog

## 0.2.0

### New
- Purge on every content-change event, sent in the request that made the change.
- Retry queue for purges the origin could not be reached for, drained on `cron`.
- Self-test that primes, purges and re-checks a page before any TTL is lengthened.
- `nginx/shopclass-cache.conf` — the nginx side, verified against 1.31.3.
- `tests/run.sh` — 101 assertions, no database or running site needed.

### Changed
- Only a URL a purge will name is held longer: a query string or permalinks being off
  keeps core's own window.

## 0.1.0

- Scaffold: hook wiring, TTL tiering behind the self-test gate, configuration defaults.
