<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/site-crawler-redirects-'.bin2hex(random_bytes(6));
    mkdir($this->home, 0777, true);

    $this->originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->home;

    $this->source = $this->home.'/urls.csv';
    file_put_contents($this->source, "https://example.com/old\n");
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
 * Fake a response that reports the given redirect hops.
 *
 * Guzzle records the URLs it was redirected to in the X-Guzzle-Redirect-History header, so
 * faking that header drives the same code path a real chain would. The returned object
 * collects the options the crawler handed to the client, which is the only place the
 * allow_redirects settings are observable.
 */
function fakeRedirectChain(array $hops): ArrayObject
{
    $sentOptions = new ArrayObject;

    Http::fake(function ($request, array $options) use ($hops, $sentOptions) {
        $sentOptions[] = $options['allow_redirects'] ?? [];

        return Http::response('ok', 200, ['X-Guzzle-Redirect-History' => $hops]);
    });

    return $sentOptions;
}

function csvColumns(string $path): array
{
    $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));

    return array_combine($rows[0], $rows[1]);
}

it('defaults to 3 redirects when the option is not passed', function () {
    $sentOptions = fakeRedirectChain([]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true])->assertSuccessful();

    expect($sentOptions[0]['max'])->toBe(3);
});

it('passes the requested limit and enables tracking', function () {
    $sentOptions = fakeRedirectChain([]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--redirects' => '10'])
        ->assertSuccessful();

    expect($sentOptions[0]['max'])->toBe(10)
        ->and($sentOptions[0]['track_redirects'])->toBeTrue();
});

it('passes 0 through so redirects are not followed at all', function () {
    $sentOptions = fakeRedirectChain([]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--redirects' => '0'])
        ->assertSuccessful();

    // Guzzle skips its redirect middleware entirely when max is 0.
    expect($sentOptions[0]['max'])->toBe(0);
});

it('records the hop count and the final url in the CSV', function () {
    fakeRedirectChain([
        'https://example.com/step-1',
        'https://example.com/step-2',
        'https://example.com/new',
    ]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--redirects' => '10', '--output' => 'report.csv'])
        ->assertSuccessful();

    $columns = csvColumns($this->home.'/report.csv');

    expect($columns['url'])->toBe('https://example.com/old')
        ->and($columns['redirects'])->toBe('3')
        ->and($columns['final_url'])->toBe('https://example.com/new');
});

it('leaves the redirect columns at 0 and empty for a direct hit', function () {
    fakeRedirectChain([]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--output' => 'report.csv'])
        ->assertSuccessful();

    $columns = csvColumns($this->home.'/report.csv');

    expect($columns['redirects'])->toBe('0')
        ->and($columns['final_url'])->toBe('');
});

it('reports the chain on the live output line', function () {
    fakeRedirectChain(['https://example.com/step-1', 'https://example.com/new']);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true])
        ->expectsOutputToContain('2 redirects -> https://example.com/new')
        ->assertSuccessful();
});

it('says nothing about redirects when there were none', function () {
    fakeRedirectChain([]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true])
        ->doesntExpectOutputToContain('redirects ->')
        ->assertSuccessful();
});

it('records an exceeded limit as a failed request with the reason', function () {
    Http::fake([
        '*' => fn () => throw new ConnectionException('Will not follow more than 10 redirects'),
    ]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--redirects' => '10', '--output' => 'report.csv'])
        ->expectsOutputToContain('Error: Will not follow more than 10 redirects')
        ->assertSuccessful();

    $columns = csvColumns($this->home.'/report.csv');

    expect($columns['failed'])->toBe('1')
        ->and($columns['error'])->toContain('Will not follow more than 10 redirects');
});

it('rejects a bare -r before making any request', function () {
    fakeRedirectChain([]);

    // A bare -r arrives as null, which must not be read as "0 redirects".
    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--redirects' => null])
        ->expectsOutputToContain('The --redirects option requires a value')
        ->assertFailed();

    Http::assertNothingSent();
});

it('rejects a non numeric value before making any request', function () {
    fakeRedirectChain([]);

    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--redirects' => 'abc'])
        ->expectsOutputToContain('must be a whole number of 0 or more')
        ->assertFailed();

    Http::assertNothingSent();
});

it('applies the option to crawl:url as well', function () {
    $sentOptions = fakeRedirectChain(['https://example.com/new']);

    $this->artisan('crawl:url', ['url' => 'https://example.com/old', '--limit' => 1, '--redirects' => '7', '--output' => 'report.csv'])
        ->expectsOutputToContain('1 redirects -> https://example.com/new')
        ->assertSuccessful();

    expect($sentOptions[0]['max'])->toBe(7);

    $columns = csvColumns($this->home.'/report.csv');

    expect($columns['redirects'])->toBe('1')
        ->and($columns['final_url'])->toBe('https://example.com/new');
});
