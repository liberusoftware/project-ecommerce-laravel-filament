# Security policy

## Reporting a Vulnerability

Private vulnerability reporting is enabled for [liberusoftware/project-ecommerce-laravel-filament](https://github.com/liberusoftware/project-ecommerce-laravel-filament).

[**Report a vulnerability privately**](https://github.com/liberusoftware/project-ecommerce-laravel-filament/security/advisories/new).

Sign in to GitHub and use the link above, or open **Security → Advisories → Report a vulnerability** in this repository. Reports are handled privately through GitHub Security Advisories; maintainers can invite collaborators when needed to investigate and prepare a fix.

**Do not disclose suspected vulnerabilities in public issues, pull requests, or discussions.**

Include the affected release or commit, package versions, relevant deployment configuration, reproduction steps or a minimal proof of concept, and the security impact. Redact credentials, tokens, personal data, and other sensitive information. Test only systems you own or have permission to assess.

## What to expect

This is a volunteer-maintained open-source project. There is no guaranteed response time or bug
bounty. Maintainers assess reports privately and coordinate remediation and disclosure with the
reporter. You can request anonymity instead of credit in an advisory.

Please give maintainers a reasonable chance to ship a fix before disclosing publicly.

## Scope

In scope is anything in this repository that ships and runs: `app/`, `routes/`, `database/`,
`bootstrap/`, `config/`, the Blade and Livewire views under `resources/`, the Filament panels, the
`Dockerfile` and `.docker/`, and the GitHub Actions workflows.

Out of scope:

- Vulnerabilities confined to third-party dependencies: report them to their maintainers.
  First-party integration flaws and application-specific security impact remain in scope here.
- Findings that only apply with `APP_DEBUG=true`, default `.env.example` credentials, or a
  deliberately misconfigured install. `.env.example` and `.env.testing` hold development values by
  design and are not secrets.
- Actions that only exercise an administrator’s intended permissions without crossing a security
  boundary. Privilege escalation and cross-tenant access remain in scope.
- Automated scanner output with no demonstrated impact.

## Known Findings

Previously recorded findings and remediation are documented in [`docs/CONFORMANCE.md` §6](docs/CONFORMANCE.md#6-security-findings) and [`docs/MIGRATION_PLAN.md`](docs/MIGRATION_PLAN.md). Check their current status before submitting a duplicate. Additional impact or affected versions should still be reported privately.

## Supported Versions

Security fixes target the default branch (`main`) and the latest release, when a release is available. Older versions may require an upgrade; backports are not guaranteed. Include the affected version or commit in your report even if you are not running the latest release.

Legacy releases under the former `liberu-eccommerce` vendor name are unsupported; see [ADR 0009](docs/adr/0009-vendor-rename-to-liberusoftware.md).
