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
    protected $signature = 'app:crawl-ddev';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds the DDEV_PRIMARY_URL inside .ddev/.ddev-docker-compose-full.yaml if that file is accessible from the current working directory. Then runs the app:crawl command with that URL as {url}.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $url = $this->getDdevUrl();

        if (! $url) {
            return;
        }

        $this->call('app:crawl', ['url' => $url]);
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
        }

        return $matches[1];
    }
}
