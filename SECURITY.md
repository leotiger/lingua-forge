# Security Policy

## Supported Versions

Only the latest minor release receives security fixes.

| Version | Supported |
|---------|-----------|
| 2.3.x (latest) | ✅ |
| < 2.3 | ❌ |

Patch releases are issued for confirmed security issues regardless of severity.
Feature releases (minor bumps) may also carry security fixes — always update to
the latest release before reporting a vulnerability.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Use one of the two private channels below:

1. **GitHub Security Advisories (preferred)** — go to the
   [Security tab](https://github.com/leotiger/lingua-forge/security/advisories)
   of this repository and click **"Report a vulnerability"**. This opens a
   private draft advisory visible only to maintainers.

2. **Email** — send a description to **security@lingua-forge.com** with the
   subject line `[SECURITY] Lingua Forge — <short title>`. Encrypt with the
   PGP key published at https://lingua-forge.com/security-pgp.asc if the
   report contains sensitive proof-of-concept details.

### What to include

- Plugin version and WordPress version where you confirmed the issue.
- A clear description of the vulnerability and its impact.
- Steps to reproduce or a proof-of-concept (private attachment or encrypted
  email is fine).
- Any proposed fix if you have one — it is always welcome.

### What to expect

- **Acknowledgement within 48 hours** of receipt.
- **Triage response within 7 days** — we will confirm whether the issue is
  accepted, request clarification, or explain why it is out of scope.
- **Fix timeline** — critical issues targeting authenticated or unauthenticated
  RCE/SQLi/auth-bypass will be patched within 7 days of confirmation. High and
  medium severity issues within 30 days. We will keep you informed throughout.
- **Credit** — reporters are credited in the release notes and CHANGELOG unless
  they prefer to remain anonymous.

### Disclosure policy

We follow **coordinated disclosure**: please give us at least 14 days after
the fix is released before publishing details publicly. For critical issues we
may request up to 30 days to allow site owners time to update. We will always
agree a disclosure date with you before publishing anything ourselves.

## Scope

In scope: the Lingua Forge plugin codebase (all PHP, JS, and CSS shipped in
the release ZIP). Out of scope: third-party AI providers (Anthropic, OpenAI,
Google Gemini), WordPress core, WooCommerce, the lingua-forge.com hosting
infrastructure.
