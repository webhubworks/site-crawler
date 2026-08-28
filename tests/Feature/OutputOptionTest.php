<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/site-crawler-tests-'.bin2hex(random_bytes(6));
    mkdir($this->home, 0777, true);

    $this->originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->home;
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
 * A single page with no links, so the crawl stops after one request.
 */
function fakeSinglePage(): void
{
    Http::fake([
        'https://example.com' => Http::response('<html><body>no links</body></html>'),
    ]);
}

function filesIn(string $directory): array
{
    return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
}

it('writes no file when --output is not passed', function () {
    fakeSinglePage();

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 2])
        ->assertSuccessful();

    expect(filesIn($this->home))->toBeEmpty();
});

it('writes an auto-named file into the home directory when --output has no value', function () {
    fakeSinglePage();

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 2, '--output' => null])
        ->assertSuccessful();

    $files = filesIn($this->home);

    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch('/^site-crawler-example-com-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/');
});

it('resolves a relative --output value against the home directory', function () {
    fakeSinglePage();

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 2, '--output' => 'report.csv'])
        ->assertSuccessful();

    expect($this->home.'/report.csv')->toBeFile();
});

it('expands a tilde in the --output value', function () {
    fakeSinglePage();

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 2, '--output' => '~/tilde.csv'])
        ->assertSuccessful();

    expect($this->home.'/tilde.csv')->toBeFile();
});

it('honours an absolute --output value', function () {
    fakeSinglePage();

    $absolute = sys_get_temp_dir().'/site-crawler-absolute-'.bin2hex(random_bytes(4)).'.csv';

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 2, '--output' => $absolute])
        ->assertSuccessful();

    expect($absolute)->toBeFile()
        ->and(filesIn($this->home))->toBeEmpty();

    unlink($absolute);
});

it('appends the default file name when --output points at an existing directory', function () {
    fakeSinglePage();

    mkdir($this->home.'/reports');

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 2, '--output' => $this->home.'/reports'])
        ->assertSuccessful();

    expect(filesIn($this->home.'/reports'))->toHaveCount(1)
        ->and(filesIn($this->home.'/reports')[0])->toStartWith('site-crawler-example-com-');
});

it('overwrites an existing file at the destination', function () {
    fakeSinglePage();

    file_put_contents($this->home.'/report.csv', 'stale content');

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 2, '--output' => 'report.csv'])
        ->assertSuccessful();

    expect(file_get_contents($this->home.'/report.csv'))->not->toContain('stale content');
});

it('aborts before crawling when the output directory does not exist', function () {
    fakeSinglePage();

    $this->artisan('crawl:url', [
        'url' => 'https://example.com',
        '--limit' => 2,
        '--output' => '/nonexistent-site-crawler-dir/report.csv',
    ])->assertFailed();

    Http::assertNothingSent();
});

it('aborts before crawling when the output file is not writable', function () {
    fakeSinglePage();

    $path = $this->home.'/readonly.csv';
    touch($path);
    chmod($path, 0444);

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 2, '--output' => $path])
        ->assertFailed();

    Http::assertNothingSent();

    chmod($path, 0644);
});

it('writes the full per-request record for crawl:url', function () {
    // The href is absolute because normalizeUrl() only ever follows absolute links.
    Http::fake([
        'https://example.com/gone' => Http::response('not found', 404),
        'https://example.com' => Http::response('<html><body><a href="https://example.com/gone">gone</a></body></html>'),
    ]);

    $this->artisan('crawl:url', ['url' => 'https://example.com', '--limit' => 10, '--output' => 'report.csv'])
        ->assertSuccessful();

    $rows = array_map('str_getcsv', file($this->home.'/report.csv', FILE_IGNORE_NEW_LINES));

    expect($rows[0])->toBe(['url', 'status', 'success', 'failed', 'time', 'found_on', 'cache_control', 'error']);

    $byUrl = collect($rows)->skip(1)->keyBy(0);

    expect($byUrl)->toHaveCount(2);

    // The start URL succeeded and was not found on any other page.
    expect($byUrl['https://example.com'][1])->toBe('200')
        ->and($byUrl['https://example.com'][2])->toBe('1')
        ->and($byUrl['https://example.com'][3])->toBe('0')
        ->and($byUrl['https://example.com'][5])->toBe('')
        // cache_control stays empty while cache mode is off
        ->and($byUrl['https://example.com'][6])->toBe('');

    // The 404 is recorded as failed and carries where it was linked from.
    expect($byUrl['https://example.com/gone'][1])->toBe('404')
        ->and($byUrl['https://example.com/gone'][2])->toBe('0')
        ->and($byUrl['https://example.com/gone'][3])->toBe('1')
        ->and($byUrl['https://example.com/gone'][5])->toBe('https://example.com');
});

it('records the cache_control column when cache mode is on', function () {
    Http::fake([
        'https://example.com' => Http::response('<html></html>', 200, ['Cache-Control' => 'max-age=3600']),
    ]);

    $this->artisan('crawl:url', [
        'url' => 'https://example.com',
        '--limit' => 1,
        '--modes' => 'cache',
        '--output' => 'report.csv',
    ])->assertSuccessful();

    $rows = array_map('str_getcsv', file($this->home.'/report.csv', FILE_IGNORE_NEW_LINES));

    expect($rows[1][6])->toBe('max-age=3600');
});

it('writes a narrower record for crawl:csv', function () {
    Http::fake(['*' => Http::response('ok')]);

    $source = $this->home.'/urls.csv';
    file_put_contents($source, "https://example.com/one\nhttps://example.com/two\n");

    $this->artisan('crawl:csv', ['file' => $source, '--yes' => true, '--output' => 'report.csv'])
        ->assertSuccessful();

    $rows = array_map('str_getcsv', file($this->home.'/report.csv', FILE_IGNORE_NEW_LINES));

    expect($rows[0])->toBe(['url', 'status', 'success', 'failed', 'time', 'error'])
        ->and($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('https://example.com/one')
        ->and($rows[1][1])->toBe('200')
        ->and($rows[1][2])->toBe('1');
});

it('names the crawl:csv output after the input file', function () {
    Http::fake(['*' => Http::response('ok')]);

    $source = $this->home.'/mvb-5xx-urls.csv';
    file_put_contents($source, "https://example.com/one\n");

    $this->artisan('crawl:csv', ['file' => $source, '--yes' => true, '--output' => null])
        ->assertSuccessful();

    expect(collect(filesIn($this->home))->first(fn ($file) => str_starts_with($file, 'site-crawler-')))
        ->toMatch('/^site-crawler-mvb-5xx-urls-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/');
});

it('aborts crawl:csv before prompting when the destination is not writable', function () {
    Http::fake(['*' => Http::response('ok')]);

    $source = $this->home.'/urls.csv';
    file_put_contents($source, "https://example.com/one\n");

    // No --yes, so reaching the confirmation prompt would hang or fail differently.
    $this->artisan('crawl:csv', ['file' => $source, '--output' => '/nonexistent-site-crawler-dir/report.csv'])
        ->assertFailed();

    Http::assertNothingSent();
});
