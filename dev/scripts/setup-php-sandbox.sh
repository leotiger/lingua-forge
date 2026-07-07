#!/usr/bin/env bash
# =============================================================================
# setup-php-sandbox.sh — install a native PHP 8.1 CLI user-space, no root.
#
# For restricted environments that have no `php` on PATH but DO allow
# `apt-get download` + `dpkg-deb -x` (the Cowork / AI sandbox this project is
# developed in). After running it, `php -l`, `phpcs`, `phpstan`, and
# single-file `phpunit` run exactly as on a developer machine.
#
# The sandbox is wiped between sessions, so re-run this once per new session
# (~3 min). It is idempotent: if a working `php` is already on PATH it exits
# immediately.
#
# Usage:
#   bash dev/scripts/setup-php-sandbox.sh        # install
#   eval "$(bash dev/scripts/setup-php-sandbox.sh --print-path)"   # add to PATH in current shell
#
# Then either `export PATH="$HOME/.local/bin:$PATH"` (each independent shell
# call needs it) or call "$HOME/.local/bin/php" directly.
#
# Ubuntu 22.04 ships PHP 8.1 (the project's declared floor). The full toolchain
# runs in-sandbox: `php -l`, phpcs, phpstan, and the whole unit suite —
#     WP_TESTS_DIR='' WP_PHPUNIT__DIR='' php vendor/bin/phpunit --testsuite=unit --no-coverage
# (--no-coverage matters: the <coverage> block in phpcs.xml.dist/phpunit's
# config makes PHPUnit try to collect coverage even without a --coverage-*
# flag whenever a driver is available, which can make a plain run hang far
# past a reasonable timeout — see project memory "PHPUnit sandbox hang".)
# Integration tests still need wp-env + Docker, which the sandbox does not have.
#
# --- Why this still installs 8.1, not 8.3 (investigated 2026-07-06) ---------
# Production hosting and wp-env (dev/.wp-env.json) have both moved to PHP 8.3.
# Bumping this script to match was investigated and shelved: Ubuntu 22.04
# (jammy, this sandbox's base image) doesn't ship php8.3 in its own archive,
# and the two standard third-party sources for it are both unreachable here —
# the sandbox has no root (`sudo` is hard-blocked by "no new privileges", so
# `add-apt-repository` for ondrej/php is out) and the outbound proxy's
# allowlist returns 403 for both ppa.launchpadcontent.net and
# packages.sury.org (deb.sury.org). Ubuntu 24.04 (noble) *does* ship php8.3,
# and its package pool is reachable at ports.ubuntu.com (already-allowlisted,
# since jammy's own packages come from there) — but noble's .debs are linked
# against a newer glibc (2.39 vs jammy's 2.35) and largely renamed for the
# 64-bit time_t transition (e.g. libssl3 → libssl3t64), so cleanly extracting
# and running them here would mean also bundling a foreign glibc userland
# (invoking it via its own ld.so with an explicit --library-path) — doable in
# principle, but fragile enough that it wasn't pursued. If this sandbox ever
# gets a newer base image (or a wider network allowlist), re-attempt this
# rather than the noble/glibc route.
# =============================================================================
set -euo pipefail

ROOT="$HOME/.local/php-root"
BIN="$HOME/.local/bin"
PHP_API="20210902"   # PHP 8.1 zend module api

# --- --print-path mode: just emit the export line and exit -------------------
if [ "${1:-}" = "--print-path" ]; then
    echo "export PATH=\"$BIN:\$PATH\""
    exit 0
fi

# --- already installed? ------------------------------------------------------
if "$BIN/php" -v >/dev/null 2>&1; then
    echo "✓ user-space php already installed:"
    "$BIN/php" -v | head -1
    echo "  add to PATH:  export PATH=\"$BIN:\$PATH\""
    exit 0
fi
if command -v php >/dev/null 2>&1; then
    echo "✓ php already on PATH: $(php -v | head -1)"
    exit 0
fi

# --- arch detection ----------------------------------------------------------
case "$(uname -m)" in
    aarch64|arm64) TRIPLET="aarch64-linux-gnu" ;;
    x86_64|amd64)  TRIPLET="x86_64-linux-gnu" ;;
    *) echo "Unsupported arch: $(uname -m)" >&2; exit 1 ;;
esac
echo "Arch: $(uname -m) → $TRIPLET"

# --- 1) download .debs (no root) ---------------------------------------------
TMP="$(mktemp -d)"
echo "Downloading PHP 8.1 .debs into $TMP ..."
( cd "$TMP" && apt-get download \
    php8.1-cli php8.1-common php8.1-opcache \
    php8.1-xml php8.1-mbstring php8.1-curl php8.1-zip \
    php-common libssl3 libxml2 libzip4 libonig5 libargon2-1 \
    libgmp10 libgmpxx4ldbl libtidy5deb1 libsodium23 libffi8 \
    libsqlite3-0 libreadline8 zlib1g >/dev/null )

# --- 2) extract --------------------------------------------------------------
mkdir -p "$ROOT"
for d in "$TMP"/*.deb; do dpkg-deb -x "$d" "$ROOT"; done
rm -rf "$TMP"

# --- 3) extension_dir override -----------------------------------------------
CONF_D="$ROOT/etc/php/8.1/cli/conf.d"
mkdir -p "$CONF_D"
cat > "$CONF_D/00-extension-dir.ini" <<EOF
extension_dir = "$ROOT/usr/lib/php/$PHP_API"
EOF

# --- 4) load extension .ini files --------------------------------------------
for ini in "$ROOT"/usr/share/php8.1-*/*/*.ini; do
    [ -f "$ini" ] && cp "$ini" "$CONF_D/20-$(basename "$ini")"
done

# --- 5) wrapper on ~/.local/bin ----------------------------------------------
mkdir -p "$BIN"
cat > "$BIN/php" <<EOF
#!/usr/bin/env bash
export LD_LIBRARY_PATH="$ROOT/usr/lib/$TRIPLET:$ROOT/lib/$TRIPLET\${LD_LIBRARY_PATH:+:\$LD_LIBRARY_PATH}"
export PHP_INI_SCAN_DIR="$CONF_D"
exec "$ROOT/usr/bin/php8.1" "\$@"
EOF
chmod +x "$BIN/php"

# --- 6) verify ---------------------------------------------------------------
echo
"$BIN/php" -v | head -1
echo "✓ installed. Add to PATH for this shell:"
echo "    export PATH=\"$BIN:\$PATH\""
