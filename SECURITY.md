# Security Policy

## Supported Versions

Before `1.0`, only the latest `0.x` release line receives security fixes.

| Version | Supported |
| --- | --- |
| `0.1.x` | Yes |
| older versions | No |

## Reporting a Vulnerability

Please do not open a public issue for vulnerabilities that may expose tokens,
payload data, file paths, or tenant data.

Send a private report to `780537@gmail.com` with:

- affected version or commit
- clear reproduction steps
- expected and actual impact
- any relevant payload samples, with secrets removed

We will acknowledge valid reports as soon as practical, fix in the active
release line, and publish a changelog entry once users can upgrade safely.

## Scope

Security-sensitive areas in this package include:

- runtime exception payload collection and masking
- slow SQL binding interpolation and masking
- local YAML record storage and pruning
- cloud push token handling
- MCP read and resolve commands

Masking is a defense-in-depth measure, not a reason to intentionally send real
secrets to the monitor. Applications should still avoid placing credentials in
exception messages, SQL values, URLs, and request payloads.
