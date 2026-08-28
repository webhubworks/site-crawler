<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/site-crawler-ddev-'.bin2hex(random_bytes(6));
    mkdir($this->home.'/project/.ddev', 0777, true);

    file_put_contents(
        $this->home.'/project/.ddev/.ddev-docker-compose-full.yaml',
        "services:\n  web:\n    environment:\n      DDEV_PRIMARY_URL: https://acme.ddev.site\n"
    );

    $this->originalHome = $_SERVER['HOME'] ?? null;
    $this->originalCwd = getcwd();

    $_SERVER['HOME'] = $this->home;
    chdir($this->home.'/project');

    Http::fake(['*' => Http::response('<html><body>no links</body></html>')]);
});

afterEach(function () {
    chdir($this->originalCwd);

    if ($this->originalHome === null) {
        unset($_SERVER['HOME']);
    } else {
        $_SERVER['HOME'] = $this->originalHome;
    }

    exec('rm -rf '.escapeshellarg($this->home));
});

function homeFiles(string $home): array
{
    return array_values(array_diff(scandir($home) ?: [], ['.', '..', 'project']));
}

it('writes no file when crawl:ddev is run without --output', function () {
    $this->artisan('crawl:ddev', ['--limit' => 1])->assertSuccessful();

    expect(homeFiles($this->home))->toBeEmpty();
});

it('forwards a bare --output to crawl:url', function () {
    $this->artisan('crawl:ddev', ['--limit' => 1, '--output' => null])->assertSuccessful();

    $files = homeFiles($this->home);

    expect($files)->toHaveCount(1)
        ->and($files[0])->toMatch('/^site-crawler-acme-ddev-site-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/');
});

it('forwards an --output path to crawl:url', function () {
    $this->artisan('crawl:ddev', ['--limit' => 1, '--output' => 'ddev.csv'])->assertSuccessful();

    expect($this->home.'/ddev.csv')->toBeFile();

    $rows = array_map('str_getcsv', file($this->home.'/ddev.csv', FILE_IGNORE_NEW_LINES));

    expect($rows[0])->toBe(['url', 'status', 'success', 'failed', 'time', 'found_on', 'cache_control', 'error'])
        ->and($rows[1][0])->toBe('https://acme.ddev.site');
});

it('forwards the crawl:url failure exit code when the destination is unwritable', function () {
    $this->artisan('crawl:ddev', ['--limit' => 1, '--output' => '/nonexistent-site-crawler-dir/report.csv'])
        ->assertFailed();

    Http::assertNothingSent();
});

it('forwards the other crawl options', function () {
    $this->artisan('crawl:ddev', ['--limit' => 1, '--concurrency' => 3, '--modes' => 'cache', '--output' => 'ddev.csv'])
        ->assertSuccessful();

    $rows = array_map('str_getcsv', file($this->home.'/ddev.csv', FILE_IGNORE_NEW_LINES));

    // cache mode reaching crawl:url means the cache_control column is populated.
    expect($rows[1][6])->toBe('not set');
});
