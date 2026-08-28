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

Example: `site-crawler crawl:url https://example.com --limit=50 --concurrency=10 --basic-auth=user:pass --exclude=action,imprint --output`

Crawling is sequential by default (`--concurrency=1`). Pass a higher `--concurrency` to crawl multiple URLs in parallel per wave; newly discovered links are gathered wave by wave, so each batch of concurrent requests feeds the next. Note that parallel crawling only speeds things up when the target server actually handles requests concurrently - a local dev server with a single worker will process them one at a time regardless.

### Writing the results to a CSV file

The terminal summary only shows the three slowest requests and the failures. Pass `-o`|`--output` to additionally write **every** request to a CSV file, which is the full record you can sort, filter and share:

| Option | Where the file is written |
| --- | --- |
| `-o` | `~/site-crawler-example-com-2026-08-28-141530.csv` |
| `-o report.csv` | `~/report.csv` - relative paths resolve against your home directory, not the current one |
| `-o ~/reports/report.csv` | `~/reports/report.csv` |
| `-o /tmp/report.csv` | `/tmp/report.csv` |
| `-o ~/reports` | `~/reports/site-crawler-example-com-2026-08-28-141530.csv` |

> [!WARNING]  
> This will overwrite any existing file at the destination.
 
> [!NOTE]
> - The destination is checked before the first request is made, so a crawl is never wasted on a file that cannot be written.
> - The generated name identifies what was crawled: `crawl:url` and `crawl:ddev` use the host, `crawl:csv` uses the name of the input file.
> - The terminal output is unchanged either way – the CSV is purely additional.

### Following redirects

Up to 3 redirects are followed per URL. Pass `-r`|`--redirects` to change that, which is how you tell a long but legitimate redirect chain from a loop:

| Option | Behaviour |
| --- | --- |
| *(omitted)* | Follow up to 3 redirects |
| `-r 10` | Follow up to 10; a loop reports `Will not follow more than 10 redirects` |
| `-r 0` | Do not follow at all and report the `3xx` response itself |

Every request records how far it was redirected and where it landed, so a redirected URL is never mistaken for a direct hit:

```
Status: 200, 1.499, https://httpbin.org/redirect/6, 6 redirects -> https://httpbin.org/get
```

The CSV carries the same information in its `redirects` and `final_url` columns, both empty-or-zero for a URL that did not redirect.

> [!NOTE]
> - An invalid value is reported before the first request is made, and `-r` without a value is rejected rather than read as `0`.
> - With `-r 0`, a `3xx` is neither a success nor a failure in HTTP terms, so those rows count towards `Total requests` but towards neither of the two totals below it.

## Roadmap
- [ ] Add support for websites containing links in JS generated markup
- [x] Run requests in parallel
