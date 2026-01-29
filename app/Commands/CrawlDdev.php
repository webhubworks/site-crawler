<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class CrawlDdev extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:crawl-ddev {--l|limit=250 : Only crawl a certain amount of URLs} {--e|exclude= : Exclude URLs from crawling that contain the following paths, separate by comma}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds the DDEV_PRIMARY_URL inside .ddev/.ddev-docker-compose-full.yaml if that file is accessible from the current working directory. Then runs the app:crawl command on that URL passing all received options.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $url = $this->getDdevUrl();

        if (! $url) {
            return;
        }

        $prefixedOptions = collect($this->options())
            ->mapWithKeys(fn ($value, $key) => ['--'.$key => $value])
            ->toArray();

        $this->call('app:crawl', [
            'url' => $url,
            ...$prefixedOptions,
        ]);
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
