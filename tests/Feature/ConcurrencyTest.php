<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SiteCrawler\Commands\CrawlCsv;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/site-crawler-concurrency-'.bin2hex(random_bytes(6));
    mkdir($this->home, 0777, true);

    $this->originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->home;

    $this->source = $this->home.'/urls.csv';
    file_put_contents($this->source, implode("\n", [
        'https://example.com/1',
        'https://example.com/2',
        'https://example.com/3',
        'https://example.com/4',
        'https://example.com/5',
    ])."\n");
});

afterEach(function () {
    if ($this->originalHome === null) {
        unset($_SERVER['HOME']);
    } else {
        $_SERVER['HOME'] = $this->originalHome;
    }

    exec('rm -rf '.escapeshellarg($this->home));
});

/**
 * Resolve --concurrency the way the command does, and report the wave size it settled on.
 */
function resolvedConcurrency(array $parameters): int|string
{
    $command = new CrawlCsv;
    $command->setLaravel(app());

    // The file argument is required by the signature, so bind a placeholder for it.
    $input = new ArrayInput(['file' => 'urls.csv', ...$parameters], $command->getDefinition());
    $buffer = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return (function () use ($input, $buffer) {
        $this->input = $input;

        // On success report the wave size, otherwise the error the command printed.
        return $this->resolveConcurrency() ? $this->concurrency : trim($buffer->fetch());
    })->call($command);
}

it('crawls every URL regardless of the wave size', function (int $concurrency) {
    Http::fake(['*' => Http::response('ok')]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--concurrency' => (string) $concurrency])
        ->assertSuccessful()
        ->expectsOutputToContain('Total requests: 5');

    expect(Http::recorded())->toHaveCount(5);
})->with([1, 2, 5, 10]);

it('keeps the results in CSV order, not completion order', function () {
    Http::fake(['*' => Http::response('ok')]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--concurrency' => '5', '--output' => 'report.csv'])
        ->assertSuccessful();

    $rows = array_map('str_getcsv', file($this->home.'/report.csv', FILE_IGNORE_NEW_LINES));
    $urls = array_column(array_slice($rows, 1), 0);

    expect($urls)->toBe([
        'https://example.com/1',
        'https://example.com/2',
        'https://example.com/3',
        'https://example.com/4',
        'https://example.com/5',
    ]);
});

it('still records a transport failure when pooling', function () {
    Http::fake([
        'https://example.com/3' => fn () => throw new ConnectionException('Could not resolve host'),
        '*' => Http::response('ok'),
    ]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--concurrency' => '5', '--output' => 'report.csv'])
        ->assertSuccessful()
        ->expectsOutputToContain('Total requests: 5')
        ->expectsOutputToContain('Total failed request: 1');

    $rows = array_map('str_getcsv', file($this->home.'/report.csv', FILE_IGNORE_NEW_LINES));
    $failed = collect($rows)->firstWhere(0, 'https://example.com/3');

    expect($failed[3])->toBe('1')                              // failed
        ->and($failed[5])->toContain('Could not resolve host'); // error
});

it('reads -c as the wave size on crawl:csv, no longer as the column', function () {
    Http::fake(['*' => Http::response('ok')]);

    // Two columns; the URL is still in column 1, so -c must not be read as --url-column.
    file_put_contents($this->source, "https://example.com/1,ignored\nhttps://example.com/2,ignored\n");

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '-c' => '2'])
        ->assertSuccessful();

    expect(collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]->url())->all())
        ->toBe(['https://example.com/1', 'https://example.com/2']);
});

it('still reads the column from the long --url-column form', function () {
    Http::fake(['*' => Http::response('ok')]);

    file_put_contents($this->source, "ignored,https://example.com/second-column\n");

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--url-column' => '2'])
        ->assertSuccessful();

    expect(collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]->url())->all())
        ->toBe(['https://example.com/second-column']);
});

it('accepts -p and --parallel as aliases of --concurrency', function () {
    expect(resolvedConcurrency(['--concurrency' => '4']))->toBe(4)
        ->and(resolvedConcurrency(['--parallel' => '7']))->toBe(7)
        ->and(resolvedConcurrency(['-p' => '9']))->toBe(9);
});

it('defaults to sequential crawling', function () {
    expect(resolvedConcurrency([]))->toBe(1);
});

it('rejects a wave size below 1 or a non numeric one', function () {
    expect(resolvedConcurrency(['--concurrency' => '0']))->toContain('must be a whole number of 1 or more')
        ->and(resolvedConcurrency(['--concurrency' => 'abc']))->toContain('must be a whole number of 1 or more')
        ->and(resolvedConcurrency(['--parallel' => null]))->toContain('The --parallel option requires a value');
});

it('rejects a bad wave size before making any request', function () {
    Http::fake(['*' => Http::response('ok')]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--concurrency' => '0'])
        ->assertFailed();

    Http::assertNothingSent();
});
