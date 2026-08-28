<?php

use Illuminate\Support\Facades\Http;

/**
 * Run a crawl over the given fake pages and return every URL that was requested.
 */
function crawledUrls(array $pages, string $start = 'https://example.com/docs/guide', int $limit = 25): array
{
    Http::fake(collect($pages)
        ->mapWithKeys(fn (string $body, string $url) => [$url => Http::response($body)])
        ->put('*', Http::response('<html></html>'))
        ->all());

    test()->artisan('crawl:url', ['url' => $start, '--limit' => $limit])->assertSuccessful();

    return collect(Http::recorded())
        ->map(fn ($pair) => (string) $pair[0]->url())
        ->all();
}

it('follows a root relative link', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="/about">about</a>',
    ]);

    expect($urls)->toContain('https://example.com/about');
});

it('follows a document relative link against the current directory', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="sibling">sibling</a>',
    ]);

    expect($urls)->toContain('https://example.com/docs/sibling')
        ->and($urls)->not->toContain('https://example.com/sibling');
});

it('follows a parent relative link', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="../top">top</a>',
    ]);

    expect($urls)->toContain('https://example.com/top');
});

it('follows a nested document relative link', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="sub/page.html">nested</a>',
    ]);

    expect($urls)->toContain('https://example.com/docs/sub/page.html');
});

it('follows a protocol relative link on the same host', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="//example.com/proto">proto</a>',
    ]);

    expect($urls)->toContain('https://example.com/proto');
});

it('follows a query only link', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="?page=2">next</a>',
    ]);

    expect($urls)->toContain('https://example.com/docs/guide?page=2');
});

it('does not crawl fragment links as separate pages', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="#one">1</a><a href="#two">2</a><a href="#three">3</a>',
    ]);

    expect($urls)->toBe(['https://example.com/docs/guide']);
});

it('strips the fragment from an otherwise crawlable link', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="/about#section">about</a>',
    ]);

    expect($urls)->toContain('https://example.com/about')
        ->and($urls)->not->toContain('https://example.com/about#section');
});

it('resolves relative links against a base href when the document declares one', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<head><base href="https://example.com/v2/"></head><body><a href="page">p</a></body>',
    ]);

    expect($urls)->toContain('https://example.com/v2/page')
        ->and($urls)->not->toContain('https://example.com/docs/page');
});

it('still ignores non http schemes', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="mailto:a@b.com">mail</a><a href="tel:+49123">tel</a>'
            .'<a href="javascript:void(0)">js</a><a href="/ok">ok</a>',
    ]);

    expect($urls)->toBe(['https://example.com/docs/guide', 'https://example.com/ok']);
});

it('still stays on the start host', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="//other.com/x">other</a><a href="https://other.com/y">other</a><a href="/ok">ok</a>',
    ]);

    expect($urls)->toBe(['https://example.com/docs/guide', 'https://example.com/ok']);
});

it('still honours --exclude for relative links', function () {
    Http::fake([
        'https://example.com/docs/guide' => Http::response('<a href="/imprint">imprint</a><a href="/ok">ok</a>'),
        '*' => Http::response('<html></html>'),
    ]);

    $this->artisan('crawl:url', [
        'url' => 'https://example.com/docs/guide',
        '--limit' => 25,
        '--exclude' => 'imprint',
    ])->assertSuccessful();

    $urls = collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]->url())->all();

    expect($urls)->toBe(['https://example.com/docs/guide', 'https://example.com/ok']);
});

it('does not request the same page twice through different relative forms', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="/about">a</a><a href="../about">b</a>'
            .'<a href="https://example.com/about">c</a><a href="/about#x">d</a>',
    ]);

    expect(array_count_values($urls)['https://example.com/about'])->toBe(1);
});

it('follows relative links across multiple levels', function () {
    $urls = crawledUrls([
        'https://example.com/docs/guide' => '<a href="a">a</a>',
        'https://example.com/docs/a' => '<a href="b">b</a>',
        'https://example.com/docs/b' => '<a href="/c">c</a>',
    ]);

    expect($urls)->toContain('https://example.com/docs/a')
        ->and($urls)->toContain('https://example.com/docs/b')
        ->and($urls)->toContain('https://example.com/c');
});
