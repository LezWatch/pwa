# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.2] - 2026-11-24

### Added

- Add tests that hold the catalogue and availability sample files to the API contract, and list every way a response breaks it.
- Add an `.nvmrc` so local work uses Node 22, like CI.

### Changed

- Agree the catalogue API contract (v1). Trigger levels are now `low`, `med` and `high`, read the same way the show page reads them.
- Require term dictionaries to list only the terms the shows use.
- Tell people to run the tests on the host, and explain why not inside the container.

## [0.0.1] - 2026-11-24

### Added

- Add the Laravel app on a Docker stack (PHP-FPM, nginx and a scheduler) on port 8069, with SQLite on its own volume and a `lwtv.local` override for local work.
- Sync shows from LezWatch.TV's `lwtv/v1/list/shows` every six hours, and serve them as a page and at `/api/shows`.
- Add npm scripts to build, start, restart and stop the stack, and to install dependencies.
- Add a staff admin with Google sign-in limited to one Google Group, a log of every sync run, and a "Sync now" button.
- Add the 15 filter app design screens and link them from their issues.
- Add the draft catalogue API contract for `lwtv/v2/app/catalogue` and `lwtv/v2/app/availability`, with sample responses.
- Add CI: PHPUnit, Pint, lockfile checks, a Docker build, and a security job (Composer and npm audits, gitleaks).
- Add OSV scans on pull requests and on a schedule, plus Dependabot.
- Add the risk gate: high-risk pull requests can't merge until a listed human labels them `human-reviewed`, and unlisted paths count as high risk.
- Add the `lwtv-release` skill for cutting release pull requests.

### Fixed

- Stop test runs inside the container from wiping the live database.
- Stop public pages from setting cookies.
- Make admin sign-in work behind the proxy and on any Workspace edition.
- Treat a busy SQLite lock as a sync already running, and count stalled runs as failures.
- Stop the container with instructions when `vendor/` is missing.
