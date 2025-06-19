<?php

namespace App\Commands;

use Dom\HTMLDocument;
use Dom\HTMLElement;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Spatie\Url\Exceptions\InvalidArgument;
use Spatie\Url\Url;

class Crawl extends Command implements PromptsForMissingInput
{
    protected $signature = 'app:crawl {url} {--limit=250} {--exclude= : Exclude URLs from crawling that contain the following paths, separate by comma} {--basic-auth : user:password (User should not contain colon)}';

    protected $description = 'Crawls an entire website on {url} until it reaches {limit}.';

    private int $requestLimit;

    private array $queue;

    private array $requests = [];

    private array $visitedUrls = [];

    private Url $startUrl;

    private array $excludes = [];

    public function handle(): void
    {
        Validator::make($this->arguments(), [
            'url' => 'required|url',
        ])->validate();

        $this->startUrl = Url::fromString($this->argument('url'));

        $this->requestLimit = $this->option('limit');

        $this->queue[] = ['url' => $this->startUrl, 'foundOn' => null];

        $this->excludes = $this->option('exclude') ? explode(',', $this->option('exclude')) : [];

        $this->crawl(
            onAfterFetch: function (array $stats) {
                $message = implode(', ', [
                    'Status: '.$stats['status'] ?? 'N/A',
                    $stats['time'] ?? 'N/A',
                    $stats['url'],
                    $stats['foundOn'] ? 'Found on: '.$stats['foundOn'] : null,
                ]);

                match ($stats['status']) {
                    200 => $this->info($message),
                    default => $this->warn($message),
                };
            }
        );

        system('clear');

        $failedRequests = collect($this->requests)->where('failed', true);

        $this->info('Crawling completed for '.$this->startUrl);
        if (count($this->requests) === $this->option('limit')) {
            $this->warn('Crawling limit of '.$this->option('limit').' reached.');
        }
        $this->info('Total requests: '.count($this->requests));
        $this->info('Total successful request: '.collect($this->requests)->where('success', true)->count());
        $this->info('Total failed request: '.$failedRequests->count());
        $this->info('Average request time: '.collect($this->requests)->avg('time').' seconds');

        $this->newLine();
        $this->warn('Slowest requests:');
        $this->table(['URL', 'Status', 'Time', 'First found on'],
            collect($this->requests)
                ->sortByDesc('time')
                ->filter(fn ($request) => $request['status'] === 200)
                ->take(3)
                ->map(fn ($request) => [
                    $request['url'],
                    $request['status'] ?? 'N/A',
                    $request['time'] ?? 'N/A',
                    $request['foundOn'] ?? 'N/A',
                ])
        );

        if ($failedRequests->isNotEmpty()) {
            $this->warn('Failed requests:');
            $this->table(['URL', 'Status', 'Time', 'Error', 'First found on'], $failedRequests->map(fn ($request) => [
                $request['url'],
                $request['status'] ?? 'N/A',
                $request['time'] ?? 'N/A',
                $request['exception'] ?? 'N/A',
                $request['foundOn'] ?? 'N/A',
            ]));
        }
    }

    public function crawl(?callable $onAfterFetch = null): void
    {
        $count = 0;

        while (! empty($this->queue) && $count < $this->requestLimit) {
            $currentUrlSet = array_shift($this->queue);

            if ($this->isAlreadyVisited($currentUrlSet['url'])) {
                continue;
            }

            try {
                $start = microtime(true);
                $request = Http::withHeader('x-webhub', 'webhub-site-crawler')
                    ->timeout(15)
                    ->maxRedirects(3)
                    ->retry(3, 200, throw: false);

                if ($this->option('basic-auth')) {
                    [$user, $password] = explode(':', $this->option('basic-auth'), 2);
                    $request = $request->withBasicAuth($user, $password);
                }

                $response = $request->get((string) $currentUrlSet['url']);

                $requestTime = microtime(true) - $start;

                $stats = [
                    'url' => (string) $currentUrlSet['url'],
                    'foundOn' => $currentUrlSet['foundOn'],
                    'status' => $response->status(),
                    'success' => $response->successful(),
                    'failed' => $response->failed() || $response->serverError() || $response->clientError(),
                    'time' => $requestTime,
                ];

                $this->requests[] = $stats;

                $this->addToVisited($currentUrlSet['url']);
                $count++;

                if (is_callable($onAfterFetch)) {
                    $onAfterFetch($stats);
                }

                if ($response->successful()) {
                    $links = $this->parseUrlsFromResponseBody($response);
                    $this->enqueueLinks($links, $currentUrlSet['url']);
                }
            } catch (TooManyRedirectsException $e) {
                $stats = [
                    'url' => (string) $currentUrlSet,
                    'foundOn' => $currentUrlSet['foundOn'],
                    'status' => null,
                    'success' => false,
                    'failed' => true,
                    'time' => null,
                    'exception' => $e->getMessage(),
                ];

                $this->requests[] = $stats;

                $this->addToVisited($currentUrlSet['url']);
                $count++;

                if (is_callable($onAfterFetch)) {
                    $onAfterFetch($stats);
                }
            } catch (\Throwable $e) {
                $this->error($e::class.' with message '.$e->getMessage().' on '.$currentUrlSet['url']);
            }
        }
    }

    private function isAlreadyVisited(Url $url): bool
    {
        return collect($this->visitedUrls)->contains(fn (Url $visitedUrl) => $visitedUrl->matches($url));
    }

    private function addToVisited(Url $url): void
    {
        $this->visitedUrls[] = $url;
    }

    private function parseUrlsFromResponseBody(Response $response): array
    {
        $bodyCharset = explode(
            'charset=',
            $response->getHeader('Content-Type')[0] ?? ''
        )[1] ?? 'UTF-8';

        // Use @ to suppress warnings when the response body is not valid HTML5.
        @$dom = HTMLDocument::createFromString($response->body(), overrideEncoding: $bodyCharset);

        return collect($dom->getElementsByTagName('a'))
            ->transform(fn (?HTMLElement $anchor) => $anchor?->getAttribute('href'))
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

    /**
     * Should be crawled if it's from the same domain and not already visited
     */
    private function shouldCrawl(Url $url): bool
    {
        if (! in_array($url->getScheme(), ['http', 'https'], true)) {
            return false;
        }

        foreach ($this->excludes as $exclude) {
            if (Str::contains($url->getPath(), $exclude)) {
                return false;
            }
        }

        if ($url->getHost() !== $this->startUrl->getHost()) {
            return false;
        }

        return ! $this->isAlreadyVisited($url);
    }
}
