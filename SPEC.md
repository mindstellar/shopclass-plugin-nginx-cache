# `nginx-cache` — long page cache with explicit purge

Spec for a **market** plugin that raises the origin cache window from seconds to an hour
or more and keeps it correct by purging entries when the content behind them changes.

Companion to `CACHING.md` (the app↔proxy contract, which this does not change) and
`CLOUDFLARE.md` (the edge layer). `shopclass-plugin-cloudflare` stays a separate plugin
and is **the model for this one** — it already solves purge-on-change for the edge, and
this repeats that shape against the origin. Where they overlap, follow it rather than
invent: same hook set, same URL construction (`Purge::itemUrls()`), same retry queue.

---

## 1. Why

The origin micro-cache holds a page for **30 seconds** (`public_cache_max_age`). That is
the whole staleness budget, and it is short because nothing can invalidate an entry — time
is the only eviction. Raising it without invalidation would mean an edited listing showing
its old title for a day, and a newly posted one missing from the homepage for a day.

Measured on the dev harness, not assumed:

- A request arriving after expiry renders synchronously — `X-Cache: EXPIRED`, not `STALE`.
  `fastcgi_cache_use_stale updating` + `background_update on` only serve stale while
  *another* request is already refreshing. So at 30s, **one visitor per URL per 30 seconds
  pays a full PHP render**. At a day, one per day. On a long tail of listings that is the
  saving, and it is larger than "the site feels faster" — user-facing latency is mostly
  unchanged, origin CPU is not.
- nginx honours the app's `s-maxage` over `fastcgi_cache_valid`. An entry expired after 35s
  with `fastcgi_cache_valid 200 1d` set. **The TTL is app-controlled**, so raising it is a
  filter, not an nginx change.

## 2. Scope

**In:** TTL tiering, purge on the events that change a page, admin configuration with a
help page, Docker preconfiguration, an nginx build carrying the purge module.

**Out:** edge/CDN purge (see `CLOUDFLARE.md`), purging search or category pages (§4),
caching for logged-in visitors (the cookie contract in `CACHING.md` is unchanged).

## 3. Architecture

### 3.0 Prior art: the Cloudflare plugin

`shopclass-plugin-cloudflare` already does the hard half. It purges on `posted_item`,
`edited_item`, `after_delete_item`, the seven state-change hooks (`enable_item`,
`disable_item`, `activate_item`, `deactivate_item`, `item_premium_on`, `item_premium_off`,
`item_expiration_updated`), plus `add_category`, `after_delete_category`, `edit_page` and
`after_delete_page` — all verified to fire in core. `Purge::itemUrls()` already builds the
right URL set for an item: homepage, sitemap, category search URL, the seller's public
profile, the canonical item URL and one per locale, with a non-friendly fallback when the
title is missing. Failed purges retry from `cron_hourly`.

This plugin should mirror all of it and differ only in transport. Two behaviours worth
copying exactly: purging the *nameable* aggregate pages (home, that category, that
profile) rather than trying to enumerate search, and queueing a failed purge for retry
instead of dropping it.

### 3.1 One core change: a hook on the existing invalidation seam

`osc_invalidate_item_cache($itemId)` is **already** wired to every event that makes an
item's rendered page wrong (`hCache.php:296-325`):

| Event | Hook |
|---|---|
| listing edited | `edited_item` |
| image uploaded | `uploaded_file` |
| image deleted | `delete_resource` |
| listing deleted | `after_delete_item` |
| **image offloaded to remote storage** | direct call from `StorageWorker::invalidateOwnerCaches()` |

That last one is why this matters: offload rewrites the image URL, so a cached page keeps
pointing at a local file that no longer exists. It is not a hook today — it is a function
call — so no plugin can observe it. **The Cloudflare plugin cannot either**: it listens to
none of the storage hooks, so an edge-cached listing page keeps its pre-offload image URLs
until something else changes the listing. The core hook below fixes both plugins at once,
which is most of the argument for putting it in core rather than working around it here.

Rather than add five hooks, core fires one **inside** the function:

```php
function osc_invalidate_item_cache($itemId)
{
    // … existing object-cache clearing …
    osc_run_hook('invalidate_item_cache', (int) $itemId);
}
```

One line. Every caller above — offload included — becomes observable, and the hook means
exactly "this item's rendered output is now wrong", which is precisely the purge trigger.

Static pages and categories already have their own hooks (§8); only the offload seam is missing.

### 3.2 The plugin

Slug `nginx-cache`, bundled. Three jobs:

1. **Raise TTLs** through `public_cache_max_age`, per page type (§4).
2. **Purge** on `invalidate_item_cache` and the page hook, by issuing one HTTP request per
   affected URL to nginx's purge location.
3. **Configure and explain** — admin page plus a help page that prints the exact nginx
   stanza an install needs, since a standalone install has to add it by hand.

The transport is behind a filter (`nginx_cache_purge_urls`) so another plugin can send the
same URL list somewhere else — a CDN, Varnish — without this one changing.

### 3.3 The nginx side

The spike proved this works on the current image:

- `nginx-modules/ngx_cache_purge` builds as a **dynamic** module against nginx 1.31.3,
  `--with-compat`, unpatched. ~3s compile, 137 KB `.so`.
- Two-stage Dockerfile: build against the pinned `nginx:<version>-alpine`, copy the `.so`
  into the stock runtime, `load_module`. Not OpenResty; stock nginx plus one module.
- Verified end to end against real php-fpm: `MISS → HIT`, `PURGE → 200` (cache file
  deleted), `MISS`, `HIT`.

## 4. TTL tiers

Long TTLs are only safe where the set of URLs holding the content is **enumerable**, because
that is what can be purged.

The line is not "listing pages vs the rest" but **nameable vs not**. The Cloudflare plugin
already purges the homepage, a category page and a seller's profile by name; only search
with arbitrary parameters cannot be enumerated.

| Pages | Default | Why |
|---|---|---|
| Item pages, static pages | 3600 | nameable, and change rarely |
| Home, category, user profile | 3600 | nameable — purged on every post/edit/delete, as the CF plugin already does |
| Search with parameters | 30s (core default, untouched) | every keyword, filter, sort and page number is its own entry; not enumerable |

**Default 3600, not 86400.** A day maximises the saving, but the damage from a purge gap
nobody notices scales with it, and this is a first release against a module we have run for
an afternoon. An hour still cuts renders per URL by 120×, which is most of the win. The
setting is per-tier, so a site that trusts it can raise it.

Search staying short is what keeps a newly posted listing findable.

## 5. Purge mechanics

### 5.1 The key, and the trap

The cache key is `$scheme$request_method$host$request_uri`. A purge must reproduce it
exactly. In the spike, purging via the container name returned **HTTP 412** because `$host`
was `spike-web` while the entry held `localhost`.

So the plugin must send the purge to the **internal** address (the origin, never the public
URL — that is the hairpin that silently broke auto-cron behind a proxy) while presenting
the **public** hostname in a `Host:` header. Both halves are required, and neither is
obvious. This gets a test, not a comment.

### 5.2 URLs per item

One listing is more than one cache entry:

- the canonical URL, `osc_item_url_from_item($item)`
- one per enabled locale, `osc_item_url_from_item($item, $locale)`, where locale-prefixed
  permalinks are in use
- trailing-slash variant, if the permalink structure allows both
- `?comments-page=N` variants — **not enumerable**; accepted as a known gap, and the
  reason comment pagination should be excluded from the long tier

The list is built by a filter (`nginx_cache_item_urls`) so a theme with extra item routes
can extend it.

### 5.3 Fail-safe: long TTLs require a working purge

The dangerous state is a long TTL with a purge that silently does not work — a day of
staleness instead of 30 seconds. So:

> The plugin serves core's default TTL unless a purge self-test has passed.

The admin page has a **Test purge** button that primes a known URL, purges it, and checks
the entry is gone. Its result is stored; the TTL filter returns the raised value only when
that flag is set, and the flag is cleared whenever the endpoint or host setting changes.
A misconfigured install therefore behaves exactly as it does today rather than worse.

### 5.4 Immediate is the mechanism; the queue is only a fallback

**A purge is sent synchronously, in the request that changed the content.** That is the
whole feature — a listing edit is visible on the next request, not on the next cron tick.
Nothing about correctness may depend on a scheduler.

The URLs are collected through the request and sent once at shutdown, which is coalescing,
not deferral: one save can fire several of the hooks in §3.1 for the same listing, and each
would otherwise be its own round trip. The send still happens in that same request, before
the process ends.

It is cheap enough to do inline. The endpoint is the loopback origin, a purge is a bare
HTTP request with no body, and a listing produces a handful of them — single-digit
milliseconds against a save that already writes rows and processes images. Blocking the
response for that is the right trade: it guarantees the entry is gone before the seller's
next request, and the seller bypasses the cache anyway (`oc_cache_bypass`), so nobody is
waiting on it to see their own change.

**The queue catches only what that attempt could not deliver** — the origin down, a reload
mid-request, a refused connection. It is a fallback, and it should be sized like one: a
bounded list, retried on the next cron tick. It does not need `StorageQueue`'s worker
locking, eight-step exponential backoff or dead-letter ceiling, because it is not a work
pipeline; it is a short list of things that briefly failed.

One consequence to get right: **the retry must run more often than the shortest TTL it
protects.** Retrying on `cron_hourly` against a 3600s window means the entry usually
expires on its own before the retry fires, which makes the fallback pointless. Hang it on
the generic `cron` hook (auto-cron fires at most every five minutes) rather than the hourly
tier.

## 6. Configuration

Preferences under section `nginx_cache`:

| Key | Type | Default | Meaning |
|---|---|---|---|
| `purge_endpoint` | STRING | env, else `http://127.0.0.1/purge` | internal base URL of the purge location |
| `purge_host` | STRING | env, else host from `WEB_PATH` | `Host:` header the key was built with |
| `ttl_item` | INTEGER | 3600 | item pages |
| `ttl_page` | INTEGER | 3600 | static pages |
| `ttl_aggregate` | INTEGER | 3600 | home, category, profile |
| `verified` | BOOLEAN | 0 | set by the self-test; gates the raised TTLs |

**Docker** presets these from environment variables at install, so the bundled image comes
up working with no admin visit. **Standalone** installs get the defaults plus a help page
that prints the nginx stanza to add and the module to build; until the self-test passes
they keep today's behaviour.

## 7. Failure modes to handle explicitly

| Case | Behaviour |
|---|---|
| purge endpoint unreachable | log once per request, keep serving; TTL already gated on the self-test |
| purge returns 412 (key mismatch) | surface it in the admin page as a *configuration* error, not a transient one — it means `purge_host` is wrong |
| module absent (`unknown directive`) | nginx will not start, so this is caught at deploy, not runtime; the help page says so |
| multi-domain install | one purge per configured host; out of scope for v1, documented as a limitation |
| purge storm on bulk edit | coalesce per request — collect URLs, de-duplicate, send once at shutdown |
| origin refused or unreachable | queue for retry (§5.4); the page is wrong until then, which is the cost of the longer window |

## 8. Core changes required

`osc_run_hook('invalidate_item_cache', $itemId)` inside `osc_invalidate_item_cache()` —
that is the **only** core change required. Every other hook this plugin needs already
exists and fires — all fourteen the Cloudflare plugin listens on were checked, including
`edit_page`, `after_delete_page`, `add_category` and `after_delete_category`. (`add_page`
and `edit_category` have no hook, but neither is needed: a new page is not yet cached, and
a renamed category is covered by `add_category`'s sibling paths.)

Verified: `osc_invalidate_item_cache()` fires no hook today, and `hCache.php` fires none at
all.

## 9. Testing

- **Unit**: URL list for an item across locales; key composition; TTL filter returns core's
  default while `verified` is 0 and the raised value when 1.
- **Integration** (harness, the shape the spike used): prime → HIT → edit a listing →
  assert MISS on the next request. Same for an image upload, a delete, and an **offload**,
  which is the case with no test coverage anywhere today.
- **Guard**: the purge must carry a `Host:` header — a scan test, since dropping it fails
  silently with a 412 that nothing surfaces.

## 10. Phasing

1. **Core: the one hook.** Independently useful — it is what lets the *existing* Cloudflare
   plugin notice a storage offload, which it cannot today. Ships in core, no plugin needed.
2. **Plugin skeleton**: TTL tiering behind the self-test gate, purge transport, the hook
   set copied from the Cloudflare plugin, retry queue on `cron_hourly`.
3. **Admin**: settings page, help page printing the nginx stanza, Test purge button.
4. **Image**: two-stage Dockerfile carrying the module; compose wiring; env presets.
5. **Docs**: a `docs/site/` page for the standalone setup path.

Step 1 lands in this repo; steps 2-5 in a new `shopclass-plugin-nginx-cache` repo,
registered in `shopclass-plugins` the way the Cloudflare plugin is.

## 11. Decisions taken

- **Default TTL 3600**, per tier, raisable. Conservative first ship; the self-test gate
  (§5.3) is what would make a longer default defensible later.
- **Market plugin**, not bundled — core stays lean, and an install with no purge module has
  no reason to carry it. Distributed like the storage adapters and the Cloudflare plugin.
- **Cloudflare stays separate.** `shopclass-plugin-cloudflare` already owns the edge; this
  owns the origin. They compose: a site can run both, edge purge and origin purge firing
  from the same core hooks. No shared code beyond those hooks.
