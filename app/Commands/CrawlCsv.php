<?php

namespace App\Commands;

use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class CrawlCsv extends Command
{
    public $signature = '
    crawl:csv
    {file : The path to the CSV file on the system.}
    {--c|url-column=1 : The index1 of the column containing the URLs to crawl.}
    {--H|header-rows=0 : The number of header rows to skip.}
    {--s|separator=, : The separator character used in the CSV file.}
    {--enclosure=" : The enclosure character used in the CSV file.}
    {--escape=\\ : The escape character used in the CSV file.}
    {--basic-auth= : user:password (user must not contain a colon)}
    ';

    protected $description = 'Crawls the URls inside a single CSV column. (For the lack of a better word, "crawl" in this context means the app will make one request per URL in the CSV and NOT use each one as the starting point of a separate website crawling process.)';

    private array $requests = [];

    private array $basicAuth = [];

    public function handle(): void
    {
        $urls = $this->extractUrlsFromCsv();
        $totalUrls = $urls->count();

        if (! $this->confirm('Extracted URLs:'.PHP_EOL.PHP_EOL.implode(PHP_EOL, $urls->toArray()).PHP_EOL.PHP_EOL."Proceed to crawl $totalUrls URLs?")) {
            $this->warn('Crawling cancelled.');

            return;
        }

        if ($this->option('basic-auth')) {
            [$username, $password] = explode(':', $this->option('basic-auth'), 2);
            $this->basicAuth = [
                'username' => $username,
                'password' => $password,
            ];
        }

        $urls->each(function ($url) {
            $this->makeRequest(
                url: $url,
                onAfterFetch: function (array $stats) {
                    $message = implode(', ', [
                        'Status: '.$stats['status'] ?? 'N/A',
                        $stats['time'] ?? 'N/A',
                        $stats['url'],
                    ]);

                    match ($stats['status']) {
                        200 => $this->info($message),
                        default => $this->warn($message),
                    };
                },
            );
        });

        $failedRequests = collect($this->requests)->where('failed', true);

        $this->info('Crawling completed for '.$this->argument('file'));
        $this->info('Total requests: '.count($this->requests));
        $this->info('Total successful request: '.collect($this->requests)->where('success', true)->count());
        $this->info('Total failed request: '.$failedRequests->count());
        $this->info('Average request time: '.collect($this->requests)->avg('time').' seconds');

        $this->newLine();
        $this->warn('Slowest requests:');
        $this->table(['URL', 'Status', 'Time'],
            collect($this->requests)
                ->sortByDesc('time')
                ->filter(fn ($request) => $request['status'] === 200)
                ->take(3)
                ->map(fn ($request) => [
                    $request['url'],
                    $request['status'] ?? 'N/A',
                    $request['time'] ?? 'N/A',
                ])
        );

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

    private function extractUrlsFromCsv(): Collection
    {
        $file = realpath(trim($this->argument('file')));
        $urlColumn = ($this->option('url-column') ?? 1) - 1;
        $headerRows = $this->option('header-rows') ?? 0;
        $separator = $this->option('separator') ?? ',';
        $enclosure = $this->option('enclosure') ?? '"';
        $escape = $this->option('escape') ?? '\\';

        return collect(file($file, FILE_SKIP_EMPTY_LINES))
            ->skip($headerRows)
            ->map(fn ($line) => str_getcsv($line, $separator, $enclosure, $escape))
            ->pluck($urlColumn);
    }

    private function makeRequest(string $url, callable $onAfterFetch): void
    {
        try {
            $request = Http::withHeader('x-webhub', 'webhub-site-crawler')
                ->timeout(15)
                ->maxRedirects(3)
                ->retry(3, 200, throw: false);

            if (! empty($this->basicAuth)) {
                $request = $request->withBasicAuth(...$this->basicAuth);
            }

            $start = microtime(true);

            $response = $request->get($url);

            $requestTime = microtime(true) - $start;

            $stats = [
                'url' => $url,
                'status' => $response->status(),
                'success' => $response->successful(),
                'failed' => $response->failed() || $response->serverError() || $response->clientError(),
                'time' => $requestTime,
            ];

            $this->requests[] = $stats;

            if (is_callable($onAfterFetch)) {
                $onAfterFetch($stats);
            }

        } catch (TooManyRedirectsException $e) {
            $stats = [
                'url' => $url,
                'status' => null,
                'success' => false,
                'failed' => true,
                'time' => null,
                'exception' => $e->getMessage(),
            ];

            $this->requests[] = $stats;

            if (is_callable($onAfterFetch)) {
                $onAfterFetch($stats);
            }

        } catch (\Throwable $e) {
            $this->error($e::class.' with message '.$e->getMessage().' on '.$url);
        }
    }
}
