# G-01 — Server setup for subdomain routing

This guide is for site owners and server administrators who want to run Lingua Forge in **subdomain mode**, where each language gets its own subdomain (`es.example.com`, `ca.example.com`) rather than a path prefix (`example.com/es/`).

Subdomain mode requires three things the server must provide before you enable it in the plugin: a **wildcard DNS record**, a **wildcard SSL certificate**, and a **web server configuration** that routes all subdomains to your WordPress install. None of these changes touch WordPress itself — they are all done at the infrastructure level.

> **Path-prefix mode needs none of this.** If you are running `example.com/es/` and do not plan to switch, you can skip this guide entirely.

---

## Chapters

1. [How subdomain routing works](#1-how-subdomain-routing-works)
2. [DNS — wildcard records](#2-dns--wildcard-records)
3. [SSL/TLS — wildcard certificates](#3-ssltls--wildcard-certificates)
4. [nginx configuration](#4-nginx-configuration)
5. [Apache configuration](#5-apache-configuration)
6. [WordPress and plugin settings](#6-wordpress-and-plugin-settings)
7. [Verifying the setup](#7-verifying-the-setup)
8. [Troubleshooting](#8-troubleshooting)

---

## 1. How subdomain routing works

In path-prefix mode, all traffic goes to `example.com` and WordPress rewrites the URL internally. The server has nothing special to do.

In subdomain mode, a visitor's browser makes a DNS lookup for `es.example.com` — a hostname that is separate from `example.com`. The server must accept that request, terminate SSL for it, and hand it to the same WordPress installation as the main domain. The plugin then reads the subdomain, identifies the language, and serves the correct content.

```
Browser                   DNS                    Server              WordPress
  │                        │                        │                    │
  ├─ es.example.com? ─────►│                        │                    │
  │◄─ 1.2.3.4 ─────────────┤                        │                    │
  │                        │                        │                    │
  ├─ GET / ────────────────────────────────────────►│                    │
  │                        │                        ├─ WordPress ────────►│
  │                        │                        │◄─ lang=es ──────────┤
  │◄─ Spanish content ──────────────────────────────┤                    │
```

The plugin handles everything inside WordPress. Your job is to make sure the first two steps — DNS and SSL — succeed before traffic reaches the server.

---

## 2. DNS — wildcard records

A **wildcard A record** tells DNS to resolve any subdomain of your domain to your server's IP address. One record covers every language you add, now or in the future.

### Adding a wildcard record

In your DNS provider's control panel, add a record with these values:

| Field | Value |
|-------|-------|
| Type | `A` |
| Name / Host | `*` |
| Value / Points to | your server's IPv4 address |
| TTL | 3600 (or your provider's default) |

If your server has an IPv6 address and you want to support it, add a matching `AAAA` record with the same `*` name.

**Example** — what this looks like in a zone file:

```
; Apex domain
example.com.      3600  IN  A     1.2.3.4

; Wildcard — catches es.example.com, ca.example.com, etc.
*.example.com.    3600  IN  A     1.2.3.4
```

### Individual records instead of a wildcard

If your DNS provider does not support wildcard records, or you prefer to be explicit, you can add one A record per language subdomain:

```
es.example.com.   3600  IN  A     1.2.3.4
ca.example.com.   3600  IN  A     1.2.3.4
de.example.com.   3600  IN  A     1.2.3.4
```

This works identically but requires a new record every time you add a language.

### DNS propagation

DNS changes take time to reach resolvers worldwide — anywhere from a few minutes to 48 hours depending on the previous TTL. You can check propagation with:

```bash
dig es.example.com A +short
# Expected output: your server's IP address
```

Do not proceed to SSL setup until the wildcard record resolves correctly.

---

## 3. SSL/TLS — wildcard certificates

A standard single-domain certificate issued for `example.com` does not cover `es.example.com`. You need either a **wildcard certificate** that covers all subdomains or a **multi-domain (SAN) certificate** that lists each subdomain explicitly.

### Option A — Wildcard certificate (recommended)

A wildcard certificate for `*.example.com` covers every subdomain automatically. It also covers languages you add later without re-issuing the certificate.

**Let's Encrypt wildcard with Certbot** requires the DNS-01 challenge, because Let's Encrypt cannot verify `*.example.com` over HTTP. You must be able to add a TXT record to your DNS zone, either manually or through a DNS provider plugin.

**Manual DNS-01 challenge:**

```bash
certbot certonly \
  --manual \
  --preferred-challenges dns \
  -d "example.com" \
  -d "*.example.com"
```

Certbot will ask you to add a `_acme-challenge.example.com` TXT record. Add it in your DNS panel, wait a minute, then press Enter to continue. The resulting certificate covers both the apex domain and all subdomains.

**Automated DNS-01 challenge** (easier for renewals): many DNS providers have Certbot plugins — for example `certbot-dns-cloudflare`, `certbot-dns-route53`, `certbot-dns-digitalocean`. Install the relevant plugin and Certbot can add and remove the TXT record automatically:

```bash
# Example with Cloudflare
pip install certbot-dns-cloudflare
certbot certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials ~/.secrets/certbot/cloudflare.ini \
  -d "example.com" \
  -d "*.example.com"
```

Certificate files are saved to `/etc/letsencrypt/live/example.com/`. Reference them in your web server config:

```
/etc/letsencrypt/live/example.com/fullchain.pem   ← certificate + chain
/etc/letsencrypt/live/example.com/privkey.pem      ← private key
```

### Option B — Multi-domain (SAN) certificate

If you cannot complete a DNS-01 challenge, you can request a certificate that names each subdomain explicitly. This uses the standard HTTP-01 challenge, which only requires port 80 to be reachable.

```bash
certbot --nginx \
  -d example.com \
  -d es.example.com \
  -d ca.example.com \
  -d de.example.com
```

The drawback is that you must re-issue the certificate every time you add a language. For sites with a stable small set of languages this is fine; for sites where languages are added frequently, the wildcard approach is easier to maintain.

---

## 4. nginx configuration

The only change needed in nginx is to add `*.example.com` to the `server_name` directive of your existing WordPress server block. Everything else — the PHP handler, the WordPress rewrite rule — stays the same.

### Minimal change to an existing block

```nginx
server {
    listen 443 ssl;
    server_name example.com *.example.com;   # <-- add *.example.com

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

If you have a separate HTTP-to-HTTPS redirect block, add the wildcard there too:

```nginx
server {
    listen 80;
    server_name example.com *.example.com;   # <-- add *.example.com
    return 301 https://$host$request_uri;
}
```

### Testing and reloading

Always test the configuration before reloading:

```bash
nginx -t
# nginx: configuration file /etc/nginx/nginx.conf test is successful

systemctl reload nginx
```

---

## 5. Apache configuration

In Apache, add `ServerAlias *.example.com` to your existing `VirtualHost` block. The WordPress `.htaccess` rewrite rules already in your document root handle the rest.

### Minimal change to an existing VirtualHost

```apache
<VirtualHost *:443>
    ServerName  example.com
    ServerAlias *.example.com          <!-- add this line -->
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/example.com/privkey.pem

    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

For the HTTP redirect block:

```apache
<VirtualHost *:80>
    ServerName  example.com
    ServerAlias *.example.com          <!-- add this line -->
    Redirect permanent / https://example.com/
</VirtualHost>
```

> **Note on the HTTP redirect:** redirecting all subdomains to the apex domain will break subdomain routing. The redirect block should either redirect to `https://$SERVER_NAME$REQUEST_URI` (preserving the host) or be removed entirely in favour of handling HTTPS in the SSL VirtualHost.

Correct HTTP-to-HTTPS redirect that preserves the subdomain:

```apache
<VirtualHost *:80>
    ServerName  example.com
    ServerAlias *.example.com
    RewriteEngine On
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>
```

### Testing and reloading

```bash
apachectl configtest
# Syntax OK

systemctl reload apache2   # or: systemctl reload httpd
```

---

## 6. WordPress and plugin settings

Once DNS and SSL are working, the configuration inside WordPress is minimal.

### Lingua Forge — enable subdomain mode

Go to **Lingua Forge → Router → URL structure** and select **Subdomain**. Save. The plugin will immediately start redirecting language traffic to the correct subdomains.

The plugin automatically sets the cookie domain to the apex domain (`.example.com`) so that the language preference cookie is shared across all subdomains. You do not need to add `COOKIE_DOMAIN` to `wp-config.php`.

### wp-config.php — nothing to change for most installs

`WP_HOME` and `WP_SITEURL` should remain set to the apex domain (`https://example.com`). The plugin handles subdomain URLs internally; those constants should not be changed to a subdomain.

If your `wp-config.php` contains a hardcoded `COOKIE_DOMAIN` constant pointing to a specific subdomain, remove or update it — a subdomain value will prevent the language cookie from working across all language subdomains:

```php
// Remove or update this if it points to a single subdomain:
// define( 'COOKIE_DOMAIN', 'es.example.com' );  ← wrong for subdomain routing

// Correct — apex domain with leading dot so all subdomains inherit it:
define( 'COOKIE_DOMAIN', '.example.com' );
```

In most WordPress installs `COOKIE_DOMAIN` is not set at all, which is fine — the plugin's own cookie logic will handle it correctly.

---

## 7. Verifying the setup

Work through these checks in order. Each step confirms the layer below it before moving on.

**1. DNS resolves**

```bash
dig es.example.com A +short
# → 1.2.3.4  (your server IP)
```

**2. SSL is valid**

```bash
curl -I https://es.example.com
# → HTTP/2 200 (or 301 redirect — not an SSL error)
```

If you see `SSL_ERROR_RX_RECORD_TOO_LONG` or a certificate warning, the wildcard cert is not yet in place or nginx/Apache has not been reloaded after installing it.

**3. WordPress loads on the subdomain**

Visit `https://es.example.com` in a browser. You should see your site in the language mapped to `es`. If you see the default language instead, check that Lingua Forge has subdomain mode enabled and that the language is assigned the correct subdomain prefix in **Lingua Forge → Languages**.

**4. Language switcher works**

Click the language switcher on the front end. Switching languages should change the subdomain in the address bar and the page content should change accordingly.

**5. Cookie persists across subdomains**

Switch to a language, then navigate back to the apex domain (`example.com`). The site should remain in the selected language. If it reverts, check the `COOKIE_DOMAIN` note in [Chapter 6](#6-wordpress-and-plugin-settings).

---

## 8. Troubleshooting

### Subdomain returns "server not found" or times out

The DNS wildcard record has not propagated yet, or is missing. Run `dig es.example.com A +short` from a machine outside your network (or use an online DNS lookup tool). If the result is empty or wrong, return to [Chapter 2](#2-dns--wildcard-records).

### Browser shows a certificate error on the subdomain

Your SSL certificate does not cover `*.example.com`. Either the wildcard certificate was not issued correctly, or nginx/Apache is still serving the old single-domain certificate. Check that:

- The certificate file at `/etc/letsencrypt/live/example.com/fullchain.pem` actually covers `*.example.com` — run `openssl x509 -noout -text -in fullchain.pem | grep DNS`
- Your web server config references this certificate file and has been reloaded since the certificate was installed.

### Subdomain loads but shows the wrong language (or the default language)

The DNS and server layers are working but the plugin is not detecting the language from the subdomain. Check:

- Lingua Forge → Router → URL structure is set to **Subdomain** (not path prefix).
- The language in question has a subdomain prefix configured in **Lingua Forge → Languages**.
- There are no caching plugins serving a cached response from the apex domain.

### Redirect loop on the subdomain

This typically means the HTTP redirect VirtualHost (Apache) or `return 301` block (nginx) is redirecting subdomain traffic back to the apex domain, which then redirects again. Review [Chapter 4](#4-nginx-configuration) or [Chapter 5](#5-apache-configuration) and make sure the redirect preserves the original `$host` / `%{HTTP_HOST}`.

### Language cookie resets when navigating between subdomains

The cookie domain is set to a specific subdomain rather than the apex domain. See the `COOKIE_DOMAIN` note in [Chapter 6](#6-wordpress-and-plugin-settings). Remove any hardcoded `COOKIE_DOMAIN` definition that points to a single subdomain.

### `www` stops working after adding the wildcard record

A wildcard record (`*.example.com`) only matches subdomains that are not already defined. If you have an explicit `www` A record, it takes priority — `www` is unaffected. If `www` was previously handled by a CNAME to the apex domain, it continues to work. No conflict exists.

---

*Back to [Documentation index](index.md)*
