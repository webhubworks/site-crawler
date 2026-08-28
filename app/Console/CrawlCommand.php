<?php

namespace SiteCrawler\Console;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

abstract class CrawlCommand extends Command
{
    /**
     * The option definitions shared by every crawl command. Each is defined once here and
     * appended to a command's signature through sharedOptions().
     */
    public static string $basicAuthOption = '{--basic-auth= : user:password (user must not contain a colon)}';

    public static string $concurrencyOption = '{--c|concurrency=1 : Number of URLs to crawl in parallel per wave (1 = sequential, the default)}'
        .'{--p|parallel= : Alias of --concurrency.}';

    public static string $redirectsOption = '{--r|redirects=3 : Maximum number of redirects to follow per URL. Use 0 to not follow redirects at all and report the 3xx response itself.}';

    public static string $outputOption = '{--o|output= : Write the full per-request results to a CSV file. Without a value, or with a relative path, the file is written to your home directory; absolute paths are used as given. An existing file at that location is overwritten.}';

    /**
     * The header Guzzle fills with the URLs a request was redirected to.
     */
    private const REDIRECT_HISTORY_HEADER = 'X-Guzzle-Redirect-History';

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $requests = [];

    protected int $redirectLimit = 3;

    protected int $concurrency = 1;

    /**
     * @var array{username: string, password: string}|array{}
     */
    protected array $basicAuth = [];

    /**
     * Every option that all crawl commands take, ready to append to a signature.
     */
    public static function sharedOptions(): string
    {
        return self::$concurrencyOption.self::$basicAuthOption.self::$redirectsOption.self::$outputOption;
    }

    /**
     * The headline printed above the summary, e.g. "Crawling completed for …".
     */
    abstract protected function summaryTitle(): string;

    /**
     * The file name used when --output is passed without a value.
     */
    abstract protected function defaultOutputBasename(): string;

    /**
     * The CSV columns as an ordered "header => request key" map.
     *
     * @return array<string, string>
     */
    abstract protected function csvColumns(): array;

    /**
     * Validate --concurrency (or its --parallel alias) and remember the wave size.
     */
    protected function resolveConcurrency(): bool
    {
        /**
         * --parallel is an alias, so it only counts when it was actually passed. Symfony has
         * no long name aliases, hence the second option deferring to the first.
         */
        $usesAlias = $this->input->hasParameterOption(['--parallel', '-p'], true);

        $value = $usesAlias ? $this->option('parallel') : $this->option('concurrency');
        $name = $usesAlias ? '--parallel' : '--concurrency';

        if ($value === null) {
            $this->error('The '.$name.' option requires a value, e.g. '.$name.'=10.');

            return false;
        }

        if (! ctype_digit((string) $value) || (int) $value < 1) {
            $this->error('The '.$name.' option must be a whole number of 1 or more, got "'.$value.'".');

            return false;
        }

        $this->concurrency = (int) $value;

        return true;
    }

    /**
     * Send one wave of URLs in parallel.
     *
     * Every crawl command shares this so a request is always configured the same way, and
     * so a transport failure is always handled the same way: Http::pool() returns the
     * exception rather than throwing it, which is easy to drop on the floor by accident.
     *
     * @param  array<int|string, string>  $urls
     * @return array<string, Response|\Throwable>
     */
    protected function sendBatch(array $urls): array
    {
        return Http::pool(fn (Pool $pool) => collect($urls)
            ->map(function (string $url, int|string $key) use ($pool) {
                $request = $pool->as((string) $key)
                    ->withHeader('x-webhub', 'webhub-site-crawler')
                    ->timeout(15)
                    ->maxRedirects($this->redirectLimit)
                    ->withOptions(['allow_redirects' => ['track_redirects' => true]])
                    ->retry(3, 200, throw: false);

                if (! empty($this->basicAuth)) {
                    $request = $request->withBasicAuth(
                        $this->basicAuth['username'],
                        $this->basicAuth['password'],
                    );
                }

                return $request->get($url);
            })
            ->all());
    }

    /**
     * Build the recorded stats for one pooled result, whether it came back as a response or
     * as the exception of a request that never produced one.
     *
     * @param  array<string, mixed>  $extra  command specific fields, e.g. where a URL was found
     */
    protected function statsFor(string $url, mixed $result, array $extra = []): array
    {
        if (! $result instanceof Response) {
            return [
                'url' => $url,
                'status' => null,
                'success' => false,
                'failed' => true,
                'time' => null,
                'exception' => $result instanceof \Throwable ? $result->getMessage() : 'Unknown error',
                ...$extra,
            ];
        }

        return [
            'url' => $url,
            'status' => $result->status(),
            'success' => $result->successful(),
            'failed' => $result->failed() || $result->serverError() || $result->clientError(),
            'time' => $result->transferStats?->getTransferTime(),
            ...$this->redirectStats($result),
            ...$extra,
        ];
    }

    /**
     * Validate --redirects and remember the limit, so an unusable value is reported before
     * any request is made rather than silently changing how the crawl behaves.
     */
    protected function resolveRedirectLimit(): bool
    {
        $value = $this->option('redirects');

        /**
         * The option takes an optional value, so a bare `-r` arrives as null. Without this
         * guard `(int) null` would be 0 and quietly mean "do not follow redirects".
         */
        if ($value === null) {
            $this->error('The --redirects option requires a value, e.g. --redirects=10.');

            return false;
        }

        if (! ctype_digit((string) $value)) {
            $this->error('The --redirects option must be a whole number of 0 or more, got "'.$value.'".');

            return false;
        }

        $this->redirectLimit = (int) $value;

        return true;
    }

    /**
     * The URLs a request was redirected to, in order. Empty when it did not redirect.
     *
     * @return array<int, string>
     */
    protected function redirectHistory(Response $response): array
    {
        return $response->getHeader(self::REDIRECT_HISTORY_HEADER);
    }

    /**
     * The redirect fields recorded for every request, ready to merge into its stats.
     *
     * @return array{redirects: int, finalUrl: string|null}
     */
    protected function redirectStats(Response $response): array
    {
        $history = $this->redirectHistory($response);

        return [
            'redirects' => count($history),
            'finalUrl' => end($history) ?: null,
        ];
    }

    protected function resolveBasicAuth(): void
    {
        if (! $this->option('basic-auth')) {
            return;
        }

        [$username, $password] = explode(':', $this->option('basic-auth'), 2);

        $this->basicAuth = [
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * Keep the request for the summary and the CSV, and report it as it happens.
     */
    protected function recordRequest(array $stats): void
    {
        $this->requests[] = $stats;

        $this->logRequest($stats);
    }

    protected function logRequest(array $stats): void
    {
        $message = implode(', ', array_filter($this->logSegments($stats)));

        match (true) {
            $stats['status'] === 200 => $this->info($message),
            /**
             * No status means no response came back at all, which is an error rather than
             * a page that merely answered with an error code.
             */
            $stats['status'] === null => $this->error($message),
            default => $this->warn($message),
        };
    }

    /**
     * @return array<int, string|null>
     */
    protected function logSegments(array $stats): array
    {
        return [
            'Status: '.($stats['status'] ?? 'N/A'),
            $stats['time'] ?? 'N/A',
            $stats['url'],
            empty($stats['redirects']) ? null : $stats['redirects'].' redirects -> '.$stats['finalUrl'],
            isset($stats['exception']) ? 'Error: '.$stats['exception'] : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function slowestTableColumns(): array
    {
        return [
            'URL' => 'url',
            'Status' => 'status',
            'Time' => 'time',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function failedTableColumns(): array
    {
        return [
            'URL' => 'url',
            'Status' => 'status',
            'Time' => 'time',
            'Error' => 'exception',
        ];
    }

    protected function renderSummary(): void
    {
        $requests = collect($this->requests);
        $failedRequests = $requests->where('failed', true);

        $this->newLine();
        $this->info($this->summaryTitle());

        foreach ($this->summaryNotices() as $notice) {
            $this->warn($notice);
        }

        $this->info('Total requests: '.$requests->count());
        $this->info('Total successful request: '.$requests->where('success', true)->count());
        $this->info('Total failed request: '.$failedRequests->count());
        $this->info('Average request time: '.$requests->avg('time').' seconds');

        $this->newLine();
        $this->warn('Slowest requests:');
        $this->table(
            array_keys($this->slowestTableColumns()),
            $this->rowsFor(
                $this->slowestTableColumns(),
                $requests->filter(fn (array $request) => $request['status'] === 200)
                    ->sortByDesc('time')
                    ->take(3)
            )
        );

        if ($failedRequests->isNotEmpty()) {
            $this->warn('Failed requests:');
            $this->table(
                array_keys($this->failedTableColumns()),
                $this->rowsFor($this->failedTableColumns(), $failedRequests)
            );
        }

        $this->renderAdditionalSummary();
    }

    /**
     * Warnings printed directly below the summary title.
     *
     * @return array<int, string>
     */
    protected function summaryNotices(): array
    {
        return [];
    }

    /**
     * Extension point for command specific reporting below the shared summary.
     */
    protected function renderAdditionalSummary(): void {}

    /**
     * Map requests onto the given columns, filling in the keys a command does not collect.
     *
     * @param  array<string, string>  $columns
     * @param  Collection<int, array<string, mixed>>  $requests
     */
    protected function rowsFor(array $columns, Collection $requests, string $placeholder = 'N/A'): array
    {
        return $requests
            ->map(fn (array $request) => collect($columns)
                ->map(fn (string $key) => $request[$key] ?? $placeholder)
                ->values()
                ->all())
            ->values()
            ->all();
    }

    /**
     * Whether --output was passed at all.
     *
     * The option accepts an optional value, so `option('output')` returns null both when it is
     * absent and when it is passed bare. Only the raw input can tell those two apart.
     */
    protected function outputRequested(): bool
    {
        return $this->input->hasParameterOption(['--output', '-o'], true);
    }

    /**
     * Resolve --output to an absolute path: bare and relative values land in the home
     * directory, absolute ones are used as given, and a directory gets the default file name.
     */
    protected function resolveOutputPath(): string
    {
        $home = $this->homeDirectory();
        $value = trim((string) $this->option('output'));

        if ($value === '') {
            return $home.DIRECTORY_SEPARATOR.$this->defaultOutputBasename();
        }

        if (str_starts_with($value, '~')) {
            $value = $home.substr($value, 1);
        }

        $path = str_starts_with($value, DIRECTORY_SEPARATOR)
            ? $value
            : $home.DIRECTORY_SEPARATOR.$value;

        if (is_dir($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->defaultOutputBasename();
        }

        return $path;
    }

    /**
     * Check the destination before crawling, so a full crawl is never thrown away
     * because its results cannot be written.
     */
    protected function assertOutputWritable(string $path): bool
    {
        if (file_exists($path)) {
            if (is_dir($path) || ! is_writable($path)) {
                $this->error('The output file "'.$path.'" is not writable.');

                return false;
            }

            return true;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            $this->error('The output directory "'.$directory.'" does not exist.');

            return false;
        }

        if (! is_writable($directory)) {
            $this->error('The output directory "'.$directory.'" is not writable.');

            return false;
        }

        return true;
    }

    protected function writeOutputCsv(string $path): void
    {
        $columns = $this->csvColumns();
        $handle = fopen($path, 'w');

        if ($handle === false) {
            $this->error('Failed to open the output file "'.$path.'" for writing.');

            return;
        }

        fputcsv($handle, array_keys($columns), escape: '');

        foreach ($this->rowsFor($columns, collect($this->requests), placeholder: '') as $row) {
            // fputcsv() writes false as an empty cell, so booleans become explicit 1/0.
            fputcsv($handle, array_map(
                fn (mixed $value) => is_bool($value) ? (int) $value : $value,
                $row
            ), escape: '');
        }

        fclose($handle);

        $this->newLine();
        $this->info('Results written to '.$path);
    }

    protected function homeDirectory(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: getenv('USERPROFILE');

        return rtrim((string) $home, DIRECTORY_SEPARATOR);
    }

    protected function outputTimestamp(): string
    {
        return now()->format('Y-m-d-His');
    }

    /**
     * Build the auto-generated file name, e.g. "site-crawler-example-com-2026-08-28-141530.csv".
     *
     * Dots become hyphens before slugging, so example.com reads as "example-com" rather than
     * the "examplecom" Str::slug() would produce on its own.
     */
    protected function outputBasenameFor(string $subject): string
    {
        return 'site-crawler-'.Str::slug(str_replace('.', '-', $subject)).'-'.$this->outputTimestamp().'.csv';
    }
}
