# Site Crawler

Use this site crawler as a quick way to crawl any website. This is useful to detect any slow pages or pages with HTTP errors.

Please use this crawler responsibly. Do not use it to crawl websites that you do not own or have permission to crawl.

## Installation
`composer global require webhubworks/site-crawler`

## Development
- To run the crawler locally (instead of using the globally installed version): `php site-crawler app:crawl URL`
- To build the standalone app, run `php site-crawler app:build site-crawler` and specify the next version.

## Usage
Use the help: `site-crawler --help`

Example: `site-crawler https://example.com --limit=50 --basic-auth=user:pass --exclude=action,imprint`

## Roadmap
- [ ] Add support for websites containing links in JS generated markup
- [ ] Run requests in parallel
