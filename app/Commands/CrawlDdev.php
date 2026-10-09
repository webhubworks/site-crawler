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
        $this->signature = 'crawl:ddev '
            .CrawlUrl::$options
            .CrawlCommand::$sharedOptions;

        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $forwardableOptions = $this->forwardableOptions();
        $ddevHostnames = $this->parseDdevHostnames();
        $returnCodes = [];

        if (blank($ddevHostnames)) {
            return self::FAILURE;
        }

        $this->info("Crawling the following DDEV hostnames: \n- ".implode("\n- ", $ddevHostnames));
        $this->newLine();

        foreach ($ddevHostnames as $hostname) {
            $returnCodes[] = $this->call('crawl:url', [
                'url' => 'https://'.$hostname,
                ...$forwardableOptions,
            ]);
        }

        return array_any($returnCodes, fn ($code) => $code === self::FAILURE)
            ? self::FAILURE
            : self::SUCCESS;
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

    /**
     * @return string[]
     */
    private function parseDdevHostnames(): array
    {
        $cwd = getcwd();
        $ddcfyPath = $cwd.DIRECTORY_SEPARATOR.'.ddev'.DIRECTORY_SEPARATOR.'.ddev-docker-compose-full.yaml';

        if (! $cwd) {
            $this->error('Failed to determine the current working directory.');

            return [];
        }

        if (! is_dir($cwd.DIRECTORY_SEPARATOR.'.ddev')) {
            $this->error("Failed to find a '.ddev' directory in the current working directory.");

            return [];
        }

        if (! is_file($ddcfyPath)) {
            $this->error("Failed to find the expected file at '$ddcfyPath'");

            return [];
        }

        $ddevDockerComposeFullYaml = yaml_parse_file($ddcfyPath);

        if (! $ddevDockerComposeFullYaml) {
            $this->error("Failed to parse the yaml file at '$ddcfyPath'");

            return [];
        }

        if (! isset($ddevDockerComposeFullYaml['services']['web']['environment']['DDEV_HOSTNAME'])) {
            $this->error("Failed to find the expected environment variable 'services.web.environment.DDEV_HOSTNAME' in the yaml file at '$ddcfyPath'");

            return [];
        }

        return explode(',', $ddevDockerComposeFullYaml['services']['web']['environment']['DDEV_HOSTNAME']);
    }
}
