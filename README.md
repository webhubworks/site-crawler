# Site Crawler

Use this site crawler as a quick way to crawl any website. This is useful to detect any slow pages or pages with HTTP errors.

Please use this crawler responsibly. Do not use it to crawl websites that you do not own or have permission to crawl.

## Installation
Run `composer global require webhubworks/site-crawler -W` in your terminal.
After that, running `site-crawler` should output the version and command list.

## Development
- To run the crawler locally (instead of using the globally installed version): `php site-crawler crawl:url URL`

## Releasing
The globally installed command runs this package's source directly (the `bin` is the `site-crawler` entry script), so there is no build step. To release a new version, update the `CHANGELOG.md`, commit, and push a matching git tag (e.g. `3.2.1`). Users update with `composer global update webhubworks/site-crawler`.

## Usage
Run `site-crawler` to get a list of all available crawling commands.

Example: `site-crawler crawl:url https://example.com --limit=50 --concurrency=10 --basic-auth=user:pass --exclude=action,imprint`

The `--concurrency` option (default `10`) controls how many URLs are crawled in parallel per wave. Newly discovered links are gathered wave by wave, so each batch of concurrent requests feeds the next.

## Roadmap
- [ ] Add support for websites containing links in JS generated markup
- [x] Run requests in parallel
