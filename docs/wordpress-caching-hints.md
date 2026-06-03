# WordPress Caching Strategy – Findings and Recommendations

## Current Stack

* WordPress
* Plesk
* Nginx (reverse proxy)
* Apache
* PHP-FPM
* OPcache
* Redis Object Cache
* Plesk Nginx Caching Extension
* VikBooking
* Vik Channel Manager
* Lingua Forge (URL-based multilingual setup)

---

# Cache Layers

## OPcache

Caches compiled PHP bytecode.

Benefits:

* Faster PHP execution
* Reduced CPU usage
* Essential for all production environments

---

## Redis Object Cache

Caches:

* WordPress options
* Transients
* Query results
* Object cache entries

Benefits:

* Reduces MySQL queries
* Speeds up dynamic page generation

---

## Plesk Nginx Cache

Caches complete HTTP responses.

Request flow:

Visitor → Nginx Cache → Apache → PHP → WordPress

On a cache HIT:

Visitor → Nginx Cache

No PHP execution occurs.

No WordPress bootstrap occurs.

No Redis lookup occurs.

No MySQL query occurs.

This provides most of the performance benefit of static HTML caching.

---

# Static HTML Cache Plugins

Examples:

* WP Super Cache
* WP Fastest Cache
* Cache Enabler

These plugins generate HTML files and serve them directly.

However, Nginx cache already serves the same purpose.

Adding another page cache layer introduces:

* Additional complexity
* Additional purge requirements
* Risk of stale content

Recommendation:

Do not add a static HTML cache plugin while Nginx cache is active.

---

# Varnish / Vinyl Cache

Varnish provides:

* Full-page caching
* Advanced cache rules
* Flexible purging

However:

Current stack already contains:

* Nginx page cache
* Redis object cache
* OPcache

For this environment, Varnish would add complexity while providing limited additional benefit.

Recommendation:

Do not deploy Varnish unless specific requirements arise.

---

# Nginx Microcache

Microcache typically uses cache durations of:

* 5 seconds
* 10 seconds
* 30 seconds
* 60 seconds

Benefits:

* Protects PHP from traffic spikes
* Greatly reduces backend load

Current Plesk configuration:

Cache timeout: 1 hour

This is not a microcache (more than 60 seconds) but a traditional page cache.

Both approaches are valid.

---

# Multilingual Caching

Current URL structure:

* /ca/
* /es/
* /en/

Cache key:

$scheme$request_method$host$request_uri

This automatically separates cache entries by language.

No special language-cookie handling is required.

This is an ideal setup for caching. Works also for WP Instance serving subdomains, e.g. de.mydomain.com, fr.mydomain.com

---

# Homepage Cookies Analysis

You can see what cookies your site uses via the browser console entering document.cookie which will return all loaded cookies.
This helps you to identify if your homepage or other content pages are save for caching with the nginx microcache (or other caching solutions.)

Observed cookies:

## Consent Cookies

* cmplz_*
* wp_consent_*

Safe to cache.

---

## Analytics Cookies

* _ga
* *ga**

Safe to cache.

---

## VikBooking Preference Cookies

* vboTFP
* vbConfPt
* vbTagsMode
* vboAovwUleft
* vboAovwStheads
* vbOvwMnum

Appear to store display or preference information.

Safe to cache.

---

## Vik Channel Manager

* vcmChannelData

Contains channel metadata.

Appears safe to cache.

---

# Cookies Not Seen

The following were NOT present:

* PHPSESSID
* wordpress_logged_in_
* wordpress_sec_

This suggests the homepage is cache-friendly.

---

# Cache Bypass Recommendations

Always bypass cache when these cookies are present:

* wordpress_logged_in_
* wordpress_sec_
* PHPSESSID

Additionally inspect booking flow cookies.

Potential VikBooking session cookies should also bypass cache if they appear during reservations.

---

# VikBooking Recommendations

Cache:

* Homepage
* Room pages
* Destination pages
* Blog posts
* Informational content

Do not cache:

* Booking forms
* Reservation process
* Checkout process
* Availability checks
* Admin pages

Examples:

* /wp-admin/
* /booking/
* /reservation/
* /checkout/

---

# Cache Efficiency Verification

Useful checks:

## Response Headers

Run twice:

```bash
curl -I https://example.com/
```

Expected:

First request:

```
MISS
```

Second request:

```
HIT
```

---

## Cache Directory Usage

Locate cache path:

```bash
grep -R proxy_cache_path /etc/nginx
```

Check size:

```bash
du -sh /var/cache/nginx
```

---

## Log Analysis

If configured:

```nginx
$upstream_cache_status
```

Possible values:

* HIT
* MISS
* BYPASS
* EXPIRED
* STALE

A healthy public WordPress site often achieves:

* 70–90% overall hit rate
* 90–99% hit rate on popular landing pages

---

# Final Recommendation

Current architecture is already strong:

* OPcache
* Redis Object Cache
* Plesk Nginx Page Cache

This covers the major WordPress caching requirements.

Recommended checks:

Measure actual cache HIT rate before introducing any additional caching technology.

At present there is no clear need for:

* Static HTML cache plugins
* Varnish / Vinyl Cache

The focus should be on ensuring that the existing Nginx cache is achieving a high HIT ratio and that booking-related pages are correctly excluded from caching.
