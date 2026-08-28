<?php

namespace SiteCrawler\Console;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

abstract class CrawlCommand extends Command
{
    /**
     * The option definition shared by every crawl command.
     */
    public static string $outputOption = '{--o|output= : Write the full per-request results to a CSV file. Without a value, or with a relative path, the file is written to your home directory; absolute paths are used as given. An existing file at that location is overwritten.}';

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $requests = [];

    /**
     * @var array{username: string, password: string}|array{}
     */
    protected array $basicAuth = [];

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
