<?php

namespace App\Commands;

use Illuminate\Support\Collection;
use LaravelZero\Framework\Commands\Command;
use Spatie\Url\Url;

class CrawlCsv extends Command
{
    public $signature = '
    app:crawl-csv
    {file : The path to the CSV file on the system.}
    {--c|url-column=1 : The index1 of the column containing the URLs to crawl.}
    {--H|header-rows=0 : The number of header rows to skip.}
    {--s|separator=, : The separator character used in the CSV file.}
    {--enclosure=" : The enclosure character used in the CSV file.}
    {--escape=\\ : The escape character used in the CSV file.}
    ';

    protected $description = 'Crawls the URls inside a single CSV column.';

    public function handle(): void
    {
        $urls = $this->extractUrlsFromCsv();

        $this->confirm('The following URLs will be crawled: '.PHP_EOL.PHP_EOL.implode(PHP_EOL, $urls->toArray()).PHP_EOL.PHP_EOL.'Proceed?');

        $urls->each(function ($urlString) use ($urls){
            $url = Url::fromString($urlString);
        });
    }

    private function extractUrlsFromCsv(): Collection
    {
        $file = realpath(trim($this->argument('file')));
        $urlColumn = ($this->option('url-column') ?? 1) - 1;
        $headerRows = $this->option('header-rows') ?? 0;
        $separator = $this->option('separator') ?? ',';
        $enclosure = $this->option('enclosure') ?? '"';
        $escape = $this->option('escape') ?? "\\";

        if ($this->option('verbose')) {
            dump($file, $urlColumn, $headerRows, $separator, $enclosure, $escape);
        }

        return collect(file($file, FILE_SKIP_EMPTY_LINES))
            ->skip($headerRows)
            ->map(fn ($line) => str_getcsv($line, $separator, $enclosure, $escape))
            ->pluck($urlColumn);
    }
}
