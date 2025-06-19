# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2025-06-19
### Changed
- Requires PHP 8.4 or higher.

### Fixed
- Improved HTML processing.

### Removed
- Dropped support for PHP versions below 8.4.

## [1.1.0] - 2025-06-19
### Changed
- Updated dependencies.
- Improved error handling to keep crawling despite certain errors.
- Better conversion of document body's encoding to correctly process non-UTF-8 characters.

## [1.0.8] - 2025-03-03
### Fixes
- Include previous commit in binary.

## [1.0.7] - 2025-03-03
### Fixed
- Convert document body's encoding to handle umlauts and other non-UTF-8 characters.

## [1.0.6] - 2024-12-20
### Changed
- Only include successful (HTTP 200) requests in the "slowest requests" statistic.

## [1.0.5] - 2024-11-22
### Added
- Display unsuccessful requests differently for better visual clarity.

## [1.0.4] - 2024-11-21
### Added
- Output on which URL a URL was originally found.

## [1.0.3] - 2024-11-20
### Changed
- Built a standalone binary executable to enable direct execution without `php`.

## [1.0.2] - 2024-11-12
### Fixed
- Configured php executable.

## [1.0.1] - 2024-11-12
### Added
- Built php executable.

## [1.0.0] - 2024-11-12
Initial release
