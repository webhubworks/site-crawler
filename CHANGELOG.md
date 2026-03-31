# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
