# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.2.1] - 2026-07-16
### Changed
- The globally installed command now runs the package source directly instead of a prebuilt phar (the `bin` points to the `site-crawler` entry script). Releasing no longer requires a build step - a new version ships by pushing a git tag.

## [3.2.0] - 2026-07-16
### Added
- The `crawl:url` and `crawl:ddev` commands now crawl in parallel. A new `--concurrency` (`-c`) option (default `10`) controls how many URLs are fetched concurrently per wave.

### Changed
- Per-request timing now uses the actual transfer time reported by the HTTP client, so the "slowest requests" report stays accurate under concurrent crawling.

## [3.1.4] - 2026-07-09
### Fixed
- Updated the contents of the README to reflect the new command signatures.

## [3.1.3] - 2026-04-17
### Changed
- The `--exclude` option of the `crawl:url` and `crawl:ddev` commands now also looks at the query parameters and not just the path segments to exclude a given URL.

## [3.1.2] - 2026-04-01
### Fixed
- Fixed the `crawl:ddev` command not working.

## [3.1.1] - 2026-03-31
### Fixed
- Removed a redundant error message from the `crawl:ddev` command.

## [3.1.0] - 2026-03-31
### Added
- Added the `crawl:csv` command to crawl over a CSV list of URLs.\
  Learn more via `site-crawler crawl:csv --help`

### Changed
- Reworded some command and command option descriptions
- Changed command signatures:
  - `app:crawl` => `crawl:url`
  - `app:crawl-ddev` => `crawl:ddev`

## [3.0.0] - 2026-03-30
### Changed
- Requires PHP 8.5 or higher

### Removed
- Dropped support for PHP versions below 8.5
