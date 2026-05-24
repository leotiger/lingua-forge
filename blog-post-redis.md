# How Two Innocent WordPress Sites Spent an Afternoon Impersonating Each Other

*A war story about a missing line in wp-config.php, a shared Redis cache, and two hours of increasingly baffling redirects.*

---

## Everything was fine. Until it wasn't.

Two WordPress sites. One server. Completely independent — separate databases, separate file trees, separate domains, one of them even on its own dedicated IP address. Nothing shared. No multisite, no symlinks, no clever tricks. Just two clean WordPress installations minding their own business on the same machine.

And then, out of nowhere, Site A started redirecting to Site B.

Not a soft redirect. Not a cached page that looked like the other site. An actual HTTP 302, issued by WordPress itself, with `x-redirect-by: WordPress` in the headers and Site B's domain in the `Location` field. Site A had apparently decided it was Site B. And then, after we fixed that, Site B decided it was Site A. They were taking turns being each other, and neither of them was wrong exactly — they were just reading from the same poisoned well.

This is the story of that well.

---

## The symptoms were designed to confuse

The first sign was a login URL that looked like this:

```
https://site-a.com/wp-login.php?redirect_to=https%3A%2F%2Fsite-b.com%2Fwp-admin%2F&reauth=1
```

The domain in the address bar was correct. The `redirect_to` parameter was pointing somewhere else entirely. WordPress on Site A was telling the browser: after you log in, go to Site B's admin. Which is not a thing that should happen. Ever.

The obvious suspects lined up immediately. Wrong `siteurl` or `home` in the database. A hardcoded `WP_SITEURL` or `WP_HOME` in `wp-config.php`. A rogue `.htaccess` redirect. A Plesk vhost misconfiguration. We checked them all. The database was clean. The config files were clean. The server configs were clean. Plesk even showed the wrong site in its own preview pane, which meant the confusion was happening before WordPress had a chance to do anything about it.

We ran `curl -I` on Site A. The response came back as a 302 with `x-cache: HIT`. Cached redirect. We cleared the Nginx proxy cache. The redirect disappeared. Victory.

For about forty seconds.

Then it came back. Same redirect, same destination — but this time `x-cache: MISS`. Not cached. Live. WordPress generating it fresh on every single request.

That `MISS` changed everything.

---

## The actual culprit was one missing line

Both sites were using Redis as their WordPress object cache. Redis is fast, reliable, and sensible to use. What we had not done — what it turns out you absolutely must do when two WordPress sites share a Redis instance — was give each site a unique cache key prefix.

WordPress stores everything in the object cache: options, post metadata, transients, user data. Including `siteurl`. Including `home`. The keys it uses look something like `wp_options:siteurl`. Generic. Predictable. Identical across every WordPress installation in the world, unless you tell it otherwise.

With two sites sharing one Redis and no key prefix configured, Site A would write its `siteurl` to the cache under that generic key. Site B would read the cache under that same generic key and get Site A's value. From that moment on, every URL that WordPress on Site B generated — every admin link, every redirect, every `home_url()` call — was built on Site A's domain. The database still had the right value. WordPress never looked at the database. It had a cache hit.

The Plesk preview was wrong because Plesk fetches pages internally over HTTP, going through the same Nginx proxy that was caching the WordPress-generated redirects. By the time we were looking at it, there were two layers of wrong: a poisoned object cache feeding bad URLs to WordPress, and an Nginx proxy cache faithfully storing and replaying the bad redirects that WordPress was generating from those bad URLs.

Clearing the Nginx cache fixed the surface symptom. The Redis object cache was still poisoned, so WordPress immediately generated a new bad redirect, Nginx cached it again, and we were back where we started.

The fix, once we found it, was two lines — one in each `wp-config.php`:

```php
// Site A
define( 'WP_CACHE_KEY_SALT', 'site-a_' );

// Site B
define( 'WP_CACHE_KEY_SALT', 'site-b_' );
```

Then `redis-cli FLUSHALL` to clear the contaminated cache entirely. Then done. Both sites immediately stopped impersonating each other and went back to behaving like the completely independent installations they had always been.

---

## Why this is easy to miss

Neither WordPress nor Redis nor Plesk warns you about this. There is no error message. There is no log entry. There is no indication anywhere in any admin panel that two sites are sharing cache buckets. Everything looks configured correctly because everything *is* configured correctly — the missing safeguard is a configuration that prevents correct configurations from interfering with each other.

`WP_CACHE_KEY_SALT` is documented, but it is not prompted, not defaulted, and not enforced. It sits quietly in the WordPress documentation under object caching, waiting for the day you put a second site on the same Redis instance and forget to read that section.

The really insidious part is the failure mode. You do not get an error. You do not get a warning. You get two sites that work perfectly in isolation and start casually swapping identities the moment one of them caches something the other one reads. The symptoms look exactly like a dozen other problems — wrong database values, bad server config, plugin bugs — and you will check all of those before you think to look at the cache layer.

---

## What we learned, and what we changed

Beyond the obvious lesson about `WP_CACHE_KEY_SALT`, the incident exposed a few other things worth fixing.

Our WordPress plugin, which handles language routing and redirects for multilingual sites, was generating redirects without excluding WordPress's own internal post types. When WordPress's object cache was serving the wrong `siteurl`, the router was dutifully building redirect URLs on top of it and redirecting to things like `site-b.com/wp-global-styles-site-a/` — an internal FSE post that has no business appearing in a frontend redirect. We added guards to make sure internal, non-public post types are excluded from the routing and translation lookup logic entirely.

We also tightened up cookie scoping and translation group queries to be explicitly domain-aware, so that even in a degraded or misconfigured environment, the plugin's behavior is bounded by the site it is actually running on.

None of that was the cause of the problem. The cause was two lines missing from two config files. But the investigation surfaced genuine edge cases, and those are now closed.

---

## The checklist nobody gives you

If you are running more than one WordPress site on a server with a shared Redis instance, before anything else goes wrong:

Open each site's `wp-config.php` and add a unique `WP_CACHE_KEY_SALT`. It can be anything — the site's domain slug works fine. Do it now, not when something breaks, because when something breaks it will not look like a cache key problem and you will spend two hours ruling out everything else first.

That is it. One line. One afternoon saved.

---

*Found this useful? Our multilingual WordPress plugin is free and open-source: [github.com/leotiger/lingua-forge](https://github.com/leotiger/lingua-forge)*
