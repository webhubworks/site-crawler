<?php

namespace SiteCrawler\Commands;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use SiteCrawler\Console\CrawlCommand;

class CrawlCsv extends CrawlCommand
{
    protected $description = 'Crawls the URls inside a single CSV column. (For the lack of a better word, "crawl" in this context means the app will make one request per URL in the CSV and NOT use each one as the starting point of a separate website crawling process.)';

    public function __construct()
    {
        $this->signature = 'crawl:csv '
            .'{file : The path to the CSV file on the system.}'
            .'{--c|url-column=1 : The index1 of the column containing the URLs to crawl.}'
            .'{--H|header-rows=0 : The number of header rows to skip.}'
            .'{--s|separator=, : The separator character used in the CSV file.}'
            .'{--enclosure=" : The enclosure character used in the CSV file.}'
            .'{--escape=\\ : The escape character used in the CSV file.}'
            .'{--basic-auth= : user:password (user must not contain a colon)}'
            .'{--y|yes : Skip the confirmation prompt.}'
            .self::$outputOption;

        parent::__construct();
    }

    public function handle(): int
    {
        /**
         * Resolve and check the destination before anything else, so the crawl is neither
         * confirmed nor started when its results could not be written afterwards.
         */
        $outputPath = $this->outputRequested() ? $this->resolveOutputPath() : null;

        if ($outputPath && ! $this->assertOutputWritable($outputPath)) {
            return self::FAILURE;
        }

        $urls = $this->extractUrlsFromCsv();
        $totalUrls = $urls->count();

        if (! $this->option('yes') && ! $this->confirm('Extracted URLs:'.PHP_EOL.PHP_EOL.implode(PHP_EOL, $urls->toArray()).PHP_EOL.PHP_EOL."Proceed to crawl $totalUrls URLs?")) {
            $this->warn('Crawling cancelled.');

            return self::SUCCESS;
        }

        $this->resolveBasicAuth();

        $urls->each(fn ($url) => $this->makeRequest($url));

        $this->renderSummary();

        if ($outputPath) {
            $this->writeOutputCsv($outputPath);
        }

        return self::SUCCESS;
    }

    protected function summaryTitle(): string
    {
        return 'Crawling completed for '.$this->argument('file');
    }

    protected function defaultOutputBasename(): string
    {
        return $this->outputBasenameFor(pathinfo(trim($this->argument('file')), PATHINFO_FILENAME));
    }

    protected function csvColumns(): array
    {
        return [
            'url' => 'url',
            'status' => 'status',
            'success' => 'success',
            'failed' => 'failed',
            'time' => 'time',
            'error' => 'exception',
        ];
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

    private function makeRequest(string $url): void
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

            $this->recordRequest([
                'url' => $url,
                'status' => $response->status(),
                'success' => $response->successful(),
                'failed' => $response->failed() || $response->serverError() || $response->clientError(),
                'time' => $requestTime,
            ]);

        } catch (\Throwable $e) {
            /**
             * The request never produced a response (DNS failure, refused connection,
             * timeout, too many redirects). Record it like any other failure so it reaches
             * the summary totals and the CSV instead of only scrolling past in the console.
             */
            $this->recordRequest([
                'url' => $url,
                'status' => null,
                'success' => false,
                'failed' => true,
                'time' => null,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
