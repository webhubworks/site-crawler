# Site Crawler

Use this site crawler as a quick way to crawl any website. This is useful to detect any slow pages or pages with HTTP errors.

Please use this crawler responsibly. Do not use it to crawl websites that you do not own or have permission to crawl.

## Usage
Use the help: `php site-crawler --help`

Example: `php site-crawler https://example.com --limit=50 --basic-auth=user:pass --exclude=action,imprint`

## Installation
`composer global require webhubworks/site-crawler`

## Roadmap
- [ ] Add support for websites containing links in JS generated markup
- [ ] Run requests in parallel
