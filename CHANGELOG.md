# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.4.0] - 2026-08-28
- Added the `-r`|`--redirects` option to all `crawl:*` commands to set how many redirects are followed per URL. Defaults to 3 as before. Use 0 to not follow redirects at all and report the `3XX` response itself.
- Added the number of followed redirects and the final URL to the terminal output and to the `redirects` and `final_url` columns of the `CSV` output, to make a redirected URL distinguishable from a direct hit.

## [3.3.1] - 2026-08-28
### Fixed
- Fixed the `crawl:csv` command dropping requests that failed without a response (DNS failure, refused connection, timeout). They were missing from the summary totals, the failed requests table and the `CSV` output file.

## [3.3.0] - 2026-08-28
### Added
- Added the `-o`|`--output` option to all `crawl:*` commands to write the results into a `CSV` file.
- Added the `-y`|`--yes` option to the `crawl:csv` command to skip the confirmation prompt.

### Changed
- Changed all `crawl:*` commands to not clear their output on completion anymore.

### Fixed 
- Fixed the `-l`|`--limit` option of the `crawl:url` and `crawl:ddev` not working as intended.
- Fixed the `crawl:url` and `crawl:ddev` commands silently skipping relative links. Relative links are now resolved against the page they were found on.
- Fixed `#anchor` links being crawled as separate URLs.

## [3.2.4] - 2026-08-26
### Fixed
- Fixed potential autoloader namespace collisions by giving the app a more unique namespace.
 
## [3.2.3] - 2026-07-17
### Fixed
- Fixed the distributed app not working due to missing dependencies.

## [3.2.2] - 2026-07-16
### Changed
- Changed the default of the `-c`|`--concurrency` option to `1` making `crawl:url` and `crawl:ddev` default to sequential crawling.

## [3.2.1] - 2026-07-16
### Changed
- Changed the distributed app to run the package source directly instead of a prebuilt phar.

## [3.2.0] - 2026-07-16
### Added
- Added the  `-c`|`--concurrency` option to the `crawl:url` and `crawl:ddev` commands enabling them to crawl multiple URLs in parallel. By default, 10 URLs are crawled in parallel.

### Changed
- Changed the per-request timing to use the actual transfer time reported by the HTTP client, so the "Slowest Requests" report stays accurate with concurrent crawling.

## [3.1.4] - 2026-07-09
### Fixed
- Fixed the README using the pre `3.1.0` command signatures.

## [3.1.3] - 2026-04-17
### Changed
- Changed the `-e`|`--exclude` option of the `crawl:url` and `crawl:ddev` commands to now also check the query parameters and not just the path segments of a given URL.

## [3.1.2] - 2026-04-01
### Fixed
- Fixed the `crawl:ddev` command not working.

## [3.1.1] - 2026-03-31
### Fixed
- Fixed a redundant error message being output by the `crawl:ddev` command.

## [3.1.0] - 2026-03-31
### Added
- Added the `crawl:csv` command to crawl over a set list of URLs provided via a `CSV` file.\
  Learn more via `site-crawler crawl:csv --help`

### Changed
- Changed descriptions of some commands and their options.
- Changed command signatures:
  - `app:crawl` => `crawl:url`
  - `app:crawl-ddev` => `crawl:ddev`

## [3.0.0] - 2026-03-30
### Changed
- Requires PHP 8.5 or higher

### Removed
- Dropped support for PHP versions below 8.5
