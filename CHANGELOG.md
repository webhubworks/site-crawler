# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

[2.3.1] - 2026-01-30
### Changed
- Updated changelog.

[2.3.0] - 2026-01-30
### Added
- Added support for crawling modes.
  - For now only the `cache` mode is supported. This mode will output the cache control headers after crawling.

## [2.2.0] - 2026-01-29
### Added
- `app:crawl-ddev` now accepts the `--limit` and `--exclude` options of `app:crawl`.
- `app:crawl` and `app:crawl-ddev` now accept `-l` as an alias for `--limit`.
- `app:crawl` and `app:crawl-ddev` now accept `-e` as an alias for `--exclude`.

### Changed
- Updated command descriptions.
- Updated dependencies.
- Removed example tests.

## [2.1.1] - 2025-10-13
### Fixed
- Fixed an unhandled exception when the `app:crawl-ddev` command failed to find the `DDEV_PRIMARY_URL`.

## [2.1.0] - 2025-10-13
### Added
- Added the `app:crawl-ddev` command as a wrapper for the `app:crawl` command that tries to find the `DDEV_PRIMARY_URL` inside the current working directory and run the `app:crawl` command on that URL.  
This is intended to be used inside the directory of a DDEV project

### Changed
- Updated dependencies.

## [2.0.2] - 2025-07-29
### Fixed
- Fix the `--basic-auth` option not accepting a value.

### Changed
- Updated dependencies.

## [2.0.1] - 2025-07-14
### Fixed
- Fix a type error when the document's body contains non-HTML anchor tags.

## [2.0.0] - 2025-06-19
### Changed
- Requires PHP 8.4 or higher.

### Fixed
- Improved HTML processing.

### Removed
- Dropped support for PHP versions below 8.4.
