<?php

namespace SiteCrawler\Commands;

use LaravelZero\Framework\Commands\Command;
use SiteCrawler\Console\CrawlCommand;

class CrawlDdev extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds the DDEV_PRIMARY_URL inside .ddev/.ddev-docker-compose-full.yaml if that file is accessible from the current working directory. Then runs the crawl:url command on that URL passing all received options.';

    public function __construct()
    {
        /**
         * This command is a thin wrapper around crawl:url, so it takes the exact same
         * options and forwards them verbatim.
         */
        $this->signature = 'crawl:ddev '.CrawlUrl::$options.CrawlCommand::$outputOption;

        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = $this->getDdevUrl();

        if (! $url) {
            return self::FAILURE;
        }

        return $this->call('crawl:url', [
            'url' => $url,
            ...$this->forwardableOptions(),
        ]);
    }

    /**
     * The options to hand to crawl:url.
     *
     * Unset options are dropped so they do not shadow crawl:url's own defaults, and so the
     * global Symfony options this command never received are not forwarded either. --output
     * is re-added by hand because it accepts an optional value: forwarding it as null would
     * make crawl:url see it as passed even when it was not.
     */
    private function forwardableOptions(): array
    {
        $options = collect($this->options())
            ->reject(fn ($value) => $value === null || $value === false)
            ->mapWithKeys(fn ($value, $key) => ['--'.$key => $value])
            ->all();

        unset($options['--output']);

        if ($this->input->hasParameterOption(['--output', '-o'], true)) {
            $options['--output'] = $this->option('output');
        }

        return $options;
    }

    private function getDdevUrl(): string|bool
    {
        $cwd = getcwd();

        if (! $cwd) {
            $this->error('Failed to determine the current working directory.');

            return false;
        }

        try {
            $ddevDockerComposeFullContent = file_get_contents($cwd.DIRECTORY_SEPARATOR.'.ddev'.DIRECTORY_SEPARATOR.'.ddev-docker-compose-full.yaml');

        } catch (\Throwable $e) {
            $this->error('Failed to find or open the file ".ddev/.ddev-docker-compose-full.yaml" from the current working directory.');

            return false;
        }

        $matches = [];
        preg_match('/^\s*DDEV_PRIMARY_URL: (.+)$/m', $ddevDockerComposeFullContent, $matches);

        if (empty($matches[1])) {
            $this->error('Failed to find a DDEV_PRIMARY_URL inside your ".ddev/.ddev-docker-compose-full.yaml".');

            return false;
        }

        return $matches[1];
    }
}
