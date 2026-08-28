<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/site-crawler-conn-'.bin2hex(random_bytes(6));
    mkdir($this->home, 0777, true);

    $this->originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->home;

    $this->source = $this->home.'/urls.csv';
    file_put_contents($this->source, "https://example.com/ok\nhttps://unreachable.test/\n");

    Http::fake([
        'https://example.com/ok' => Http::response('ok'),
        'https://unreachable.test/' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host'),
    ]);
});

afterEach(function () {
    if ($this->originalHome === null) {
        unset($_SERVER['HOME']);
    } else {
        $_SERVER['HOME'] = $this->originalHome;
    }

    exec('rm -rf '.escapeshellarg($this->home));
});

it('records a connection failure in the CSV with its message in the error column', function () {
    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true, '--output' => 'report.csv'])
        ->assertSuccessful();

    $rows = array_map('str_getcsv', file($this->home.'/report.csv', FILE_IGNORE_NEW_LINES));

    expect($rows)->toHaveCount(3); // header + both URLs

    $failed = collect($rows)->firstWhere(0, 'https://unreachable.test/');

    expect($failed)->not->toBeNull()
        ->and($failed[1])->toBe('')                                  // no status, no response came back
        ->and($failed[2])->toBe('0')                                 // success
        ->and($failed[3])->toBe('1')                                 // failed
        ->and($failed[5])->toContain('Could not resolve host');      // error
});

it('counts a connection failure in the summary totals', function () {
    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true])
        ->expectsOutputToContain('Total requests: 2')
        ->expectsOutputToContain('Total successful request: 1')
        ->expectsOutputToContain('Total failed request: 1')
        ->assertSuccessful();
});

it('still reports the failure reason in the console', function () {
    $this->artisan('crawl:csv', ['file' => $this->source, '--yes' => true])
        ->expectsOutputToContain('Error: cURL error 6: Could not resolve host')
        ->assertSuccessful();
});
