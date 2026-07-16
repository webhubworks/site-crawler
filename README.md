# Site Crawler

Use this site crawler as a quick way to crawl any website. This is useful to detect any slow pages or pages with HTTP errors.

Please use this crawler responsibly. Do not use it to crawl websites that you do not own or have permission to crawl.

## Installation
Run `composer global require webhubworks/site-crawler -W` in your terminal.
After that, running `site-crawler` should output the version and command list.

## Development
- To run the crawler locally (instead of using the globally installed version): `php site-crawler app:crawl URL`
- To build the standalone app, run `php site-crawler app:build site-crawler` and specify the next version.

## Usage
Run `site-crawler` to get a list of all available crawling commands.

Example: `site-crawler crawl:url https://example.com --limit=50 --concurrency=10 --basic-auth=user:pass --exclude=action,imprint`

The `--concurrency` option (default `10`) controls how many URLs are crawled in parallel per wave. Newly discovered links are gathered wave by wave, so each batch of concurrent requests feeds the next.

## Roadmap
- [ ] Add support for websites containing links in JS generated markup
- [x] Run requests in parallel
