<?php

namespace SiteCrawler\Commands;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\HTMLElement;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use SiteCrawler\Console\CrawlCommand;
use Spatie\Url\Exceptions\InvalidArgument;
use Spatie\Url\Url;

class CrawlUrl extends CrawlCommand implements PromptsForMissingInput
{
    /**
     * The options shared with crawl:ddev, which wraps this command.
     */
    public static string $options = '{--l|limit=250 : Only crawl a certain amount of URLs}'
        .'{--c|concurrency=1 : Number of URLs to crawl in parallel per wave (1 = sequential, the default)}'
        .'{--e|exclude= : Exclude URLs from crawling that contain the following paths, separate by comma}'
        .'{--basic-auth= : user:password (user must not contain a colon)}'
        .'{--m|modes= : Comma-separated list of modes to enable (e.g. cache)}';

    protected $description = 'Crawls an entire website starting on {url} until it reaches {limit} excluding URLs that contain any of these strings: {exclude}.';

    private int $requestLimit;

    private int $concurrency;

    /**
     * @var array<int, array{url: Url, foundOn: string|null}>
     */
    private array $queue = [];

    /**
     * @var Collection<int,Url>|null
     */
    private ?Collection $visitedUrls;

    private Url $startUrl;

    private array $excludes = [];

    private array $modes = [];

    public function __construct()
    {
        $this->signature = 'crawl:url {url} '.self::$options.self::$outputOption;

        parent::__construct();

        $this->visitedUrls = collect();
    }

    public function handle(): int
    {
        Validator::make($this->arguments(), [
            'url' => 'required|url',
        ])->validate();

        $this->startUrl = Url::fromString($this->argument('url'));

        $this->requestLimit = (int) $this->option('limit');

        $this->concurrency = max(1, (int) $this->option('concurrency'));

        $this->queue[] = ['url' => $this->startUrl, 'foundOn' => null];

        $this->excludes = $this->option('exclude') ? explode(',', $this->option('exclude')) : [];

        $this->modes = $this->option('modes') ? explode(',', $this->option('modes')) : [];

        $this->resolveBasicAuth();

        /**
         * Resolve and check the destination up front so a completed crawl is never
         * thrown away because its results cannot be written.
         */
        $outputPath = $this->outputRequested() ? $this->resolveOutputPath() : null;

        if ($outputPath && ! $this->assertOutputWritable($outputPath)) {
            return self::FAILURE;
        }

        $this->crawl(withBasicAuth: ! empty($this->basicAuth));

        $this->renderSummary();

        if ($outputPath) {
            $this->writeOutputCsv($outputPath);
        }

        return self::SUCCESS;
    }

    public function crawl(bool $withBasicAuth = false): void
    {
        $count = 0;

        while (! empty($this->queue) && $count < $this->requestLimit) {
            /**
             * Pull the next wave of URLs. New links discovered while crawling are
             * appended to the queue below, so each wave feeds the next one.
             */
            $batch = $this->takeBatch(min($this->concurrency, $this->requestLimit - $count));

            if (empty($batch)) {
                continue;
            }

            $responses = Http::pool(fn (Pool $pool) => collect($batch)
                ->map(function (array $urlSet, int $key) use ($pool, $withBasicAuth) {
                    $request = $pool->as((string) $key)
                        ->withHeader('x-webhub', 'webhub-site-crawler')
                        ->timeout(15)
                        ->maxRedirects(3)
                        ->retry(3, 200, throw: false);

                    if ($withBasicAuth && ! empty($this->basicAuth)) {
                        $request = $request->withBasicAuth(
                            $this->basicAuth['username'],
                            $this->basicAuth['password'],
                        );
                    }

                    return $request->get((string) $urlSet['url']);
                })
                ->all());

            foreach ($batch as $key => $urlSet) {
                $result = $responses[(string) $key] ?? null;
                $count++;

                if (! $result instanceof Response) {
                    $this->recordRequest([
                        'url' => (string) $urlSet['url'],
                        'foundOn' => $urlSet['foundOn'],
                        'status' => null,
                        'success' => false,
                        'failed' => true,
                        'time' => null,
                        'exception' => $result instanceof \Throwable ? $result->getMessage() : 'Unknown error',
                    ]);

                    continue;
                }

                $this->recordRequest([
                    'url' => (string) $urlSet['url'],
                    'foundOn' => $urlSet['foundOn'],
                    'status' => $result->status(),
                    'success' => $result->successful(),
                    'failed' => $result->failed() || $result->serverError() || $result->clientError(),
                    'time' => $result->transferStats?->getTransferTime(),
                    'cacheControl' => $this->hasCacheMode() ? ($result->header('Cache-Control') ?: 'not set') : null,
                ]);

                if ($result->successful()) {
                    $links = $this->parseUrlsFromResponseBody($result);
                    $this->enqueueLinks($links, $urlSet['url']);
                }
            }
        }
    }

    protected function summaryTitle(): string
    {
        return 'Crawling completed for '.$this->startUrl;
    }

    protected function summaryNotices(): array
    {
        if (count($this->requests) < $this->requestLimit) {
            return [];
        }

        return ['Crawling limit of '.$this->requestLimit.' reached.'];
    }

    protected function defaultOutputBasename(): string
    {
        return $this->outputBasenameFor($this->startUrl->getHost());
    }

    protected function csvColumns(): array
    {
        return [
            'url' => 'url',
            'status' => 'status',
            'success' => 'success',
            'failed' => 'failed',
            'time' => 'time',
            'found_on' => 'foundOn',
            'cache_control' => 'cacheControl',
            'error' => 'exception',
        ];
    }

    protected function logSegments(array $stats): array
    {
        return [
            ...parent::logSegments($stats),
            $stats['foundOn'] ? 'Found on: '.$stats['foundOn'] : null,
        ];
    }

    protected function slowestTableColumns(): array
    {
        return [
            ...parent::slowestTableColumns(),
            'First found on' => 'foundOn',
        ];
    }

    protected function failedTableColumns(): array
    {
        return [
            ...parent::failedTableColumns(),
            'First found on' => 'foundOn',
        ];
    }

    protected function renderAdditionalSummary(): void
    {
        if (! $this->hasCacheMode()) {
            return;
        }

        $this->newLine();
        $this->info('Cache-Control headers:');

        collect($this->requests)
            ->whereNotNull('cacheControl')
            ->groupBy('cacheControl')
            ->sortKeys()
            ->each(function (Collection $requests, string $cacheControl) {
                $this->newLine();
                $this->warn($cacheControl);
                $this->table(['URL'], $requests->map(fn (array $request) => [$request['url']]));
            });
    }

    /**
     * Take up to $max unvisited URLs off the queue for the next concurrent wave.
     *
     * URLs are marked visited the moment they are reserved (before dispatch) so
     * the same URL is never fetched twice within or across concurrent waves.
     *
     * @return array<int, array{url: Url, foundOn: string|null}>
     */
    private function takeBatch(int $max): array
    {
        $batch = [];

        while (count($batch) < $max && ! empty($this->queue)) {
            $urlSet = array_shift($this->queue);

            if ($this->isAlreadyVisited($urlSet['url'])) {
                continue;
            }

            $this->addToVisited($urlSet['url']);
            $batch[] = $urlSet;
        }

        return $batch;
    }

    private function isAlreadyVisited(Url $url): bool
    {
        return $this->visitedUrls->contains(fn (Url $visitedUrl) => $visitedUrl->matches($url));
    }

    private function addToVisited(Url $url): void
    {
        $this->visitedUrls[] = $url;
    }

    private function parseUrlsFromResponseBody(Response $response): array
    {
        // Extract the body's charset from the Content-Type header.
        $bodyCharset = explode(
            'charset=',
            $response->getHeader('Content-Type')[0] ?? ''
        )[1] ?? 'UTF-8';

        // Use @ to suppress warnings when the response body is not valid HTML5.
        @$dom = HTMLDocument::createFromString($response->body(), overrideEncoding: $bodyCharset);

        return collect($dom->getElementsByTagName('a'))
            ->transform(fn (HTMLElement|Element|null $anchor) => $anchor?->getAttribute('href'))
            ->filter() // Filter empty hrefs
            ->transform(function (string $href) use ($bodyCharset) {
                try {
                    /**
                     * Convert `$href` encoding to `ISO-8859-1` (`Latin-1`) because `parse_url()` expects that.
                     */
                    $url = Url::fromString(mb_convert_encoding($href, 'ISO-8859-1', $bodyCharset), ['http', 'https']);

                    /**
                     * Convert the URL paths encoding to `UTF-8` because `Illuminate\Support\Facades\Http::get()` expects that.
                     */
                    return $url->withPath(mb_convert_encoding($url->getPath(), 'UTF-8', 'ISO-8859-1'));
                } catch (InvalidArgument $e) {
                    return null;
                }
            })
            ->filter() // Filter empty hrefs
            ->transform(fn (Url $url) => $this->normalizeUrl($url))
            ->filter(fn (Url $url) => $this->shouldCrawl($url))
            ->toArray();
    }

    private function enqueueLinks(array $urls, Url $foundOn): void
    {
        $urlSets = collect($urls)->map(fn (Url $url) => ['url' => $url, 'foundOn' => $foundOn])->toArray();
        array_push($this->queue, ...$urlSets);
    }

    private function normalizeUrl(Url $url): Url
    {
        return $url
            ->withScheme($url->getScheme() ?? $this->startUrl->getScheme()) // Add the scheme if missing
            ->withHost($url->getHost() ?? $this->startUrl->getHost()); // Add the base domain if missing
    }

    private function hasCacheMode(): bool
    {
        return in_array('cache', $this->modes, true);
    }

    /**
     * Should be crawled if it's from the same domain and not excluded and not already visited.
     */
    private function shouldCrawl(Url $url): bool
    {
        if (! in_array($url->getScheme(), ['http', 'https'], true)) {
            return false;
        }

        if (! empty($this->excludes)) {
            if (Str::contains($url->getPath(), $this->excludes)) {
                return false;
            }

            $queryParameters = $url->getAllQueryParameters();
            if (array_any($this->excludes, fn ($exclude) => isset($queryParameters[$exclude]))) {
                return false;
            }
        }

        if ($url->getHost() !== $this->startUrl->getHost()) {
            return false;
        }

        return ! $this->isAlreadyVisited($url);
    }
}
