<?php

namespace App\Commands;

use DOMDocument;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Contracts\Console\PromptsForMissingInput;
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
            'url' => 'required|url'
        ])->validate();

        $this->startUrl = Url::fromString($this->argument('url'));

        $this->requestLimit = $this->option('limit');

        $this->queue[] = $this->startUrl;

        $this->excludes = $this->option('exclude') ? explode(',', $this->option('exclude')) : [];

        $this->crawl(
            onAfterFetch: fn (array $stats) => $this->info(implode(', ', [
                'Status: ' . $stats['status'] ?? 'N/A',
                $stats['time'] ?? 'N/A',
                $stats['url'],
            ]))
        );

        system('clear');

        $failedRequests = collect($this->requests)->where('failed', true);

        $this->info('Crawling completed for ' . $this->startUrl);
        if (count($this->requests) === $this->option('limit')) {
            $this->warn('Crawling limit of ' . $this->option('limit') . ' reached.');
        }
        $this->info('Total requests: ' . count($this->requests));
        $this->info('Total successful request: ' . collect($this->requests)->where('success', true)->count());
        $this->info('Total failed request: ' . $failedRequests->count());
        $this->info('Average request time: ' . collect($this->requests)->avg('time') . ' seconds');

        $this->newLine();
        $this->warn('Slowest requests:');
        $this->table(['URL', 'Status', 'Time'], collect($this->requests)->sortByDesc('time')->take(3)->map(fn ($request) => [
            $request['url'],
            $request['status'] ?? 'N/A',
            $request['time'] ?? 'N/A',
        ]));

        if ($failedRequests->isNotEmpty()) {
            $this->warn('Failed requests:');
            $this->table(['URL', 'Status', 'Time', 'Error'], $failedRequests->map(fn ($request) => [
                $request['url'],
                $request['status'] ?? 'N/A',
                $request['time'] ?? 'N/A',
                $request['exception'] ?? 'N/A',
            ]));
        }
    }

    public function crawl(?callable $onAfterFetch = null): void
    {
        $count = 0;

        while (! empty($this->queue) && $count < $this->requestLimit) {
            $currentUrl = array_shift($this->queue);

            if ($this->isAlreadyVisited($currentUrl)) {
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

                $response = $request->get((string)$currentUrl);

                $requestTime = microtime(true) - $start;

                $stats = [
                    'url' => (string)$currentUrl,
                    'status' => $response->status(),
                    'success' => $response->successful(),
                    'failed' => $response->failed() || $response->serverError() || $response->clientError(),
                    'time' => $requestTime
                ];

                $this->requests[] = $stats;

                $this->addToVisited($currentUrl);
                $count++;

                if (is_callable($onAfterFetch)) {
                    $onAfterFetch($stats);
                }

                if ($response->successful()) {
                    $links = $this->parseUrlsFromDocumentBody($response->body());
                    $this->enqueueLinks($links);
                }
            } catch (TooManyRedirectsException $e) {
                $stats = [
                    'url' => (string)$currentUrl,
                    'status' => null,
                    'success' => false,
                    'failed' => true,
                    'time' => null,
                    'exception' => $e->getMessage(),
                ];

                $this->requests[] = $stats;

                $this->addToVisited($currentUrl);
                $count++;

                if (is_callable($onAfterFetch)) {
                    $onAfterFetch($stats);
                }
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

    private function parseUrlsFromDocumentBody(string $body): array
    {
        $dom = new DOMDocument();

        // Suppress errors for HTML5 compatibility
        @$dom->loadHTML($body);

        return collect($dom->getElementsByTagName('a'))
            ->transform(fn (?\DOMNode $anchor) => $anchor?->getAttribute('href'))
            ->filter() // Filter empty hrefs
            ->transform(function (string $href) {
                try {
                    return Url::fromString($href, ['http', 'https']);
                } catch (InvalidArgument $e) {
                    return null;
                }
            })
            ->filter() // Filter empty hrefs
            ->transform(fn (Url $url) => $this->normalizeUrl($url))
            ->filter(fn (Url $url) => $this->shouldCrawl($url))
            ->toArray();
    }

    private function enqueueLinks(array $urls): void
    {
        array_push($this->queue, ...$urls);
    }

    private function normalizeUrl(Url $url): Url
    {
        return $url
            ->withScheme($url->getScheme() ?? $this->startUrl->getScheme()) // Add default scheme if missing
            ->withHost($url->getHost() ?? $this->startUrl->getHost()); // Add base domain if missing
    }

    /**
     * Should be crawled if it's from the same domain and not already visited
     *
     * @param Url $url
     * @return bool
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
