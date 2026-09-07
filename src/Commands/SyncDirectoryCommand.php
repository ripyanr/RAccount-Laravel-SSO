<?php

namespace Raccount\Sso\Commands;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Raccount\Sso\Directory\DirectorySyncService;
use Raccount\Sso\Exceptions\RaccountException;

final class SyncDirectoryCommand extends Command
{
    protected $signature = 'raccount:directory:sync
                            {--since= : ISO-8601 timestamp; only sync records updated at or after it}';

    protected $description = 'Walk the RAccount directory (M2M) and fire DirectoryUserRetrieved per record';

    public function handle(DirectorySyncService $service): int
    {
        $since = null;

        if ($this->option('since') !== null) {
            try {
                $since = CarbonImmutable::parse((string) $this->option('since'));
            } catch (InvalidFormatException) {
                $this->components->error('Invalid --since value; use an ISO-8601 timestamp, e.g. 2026-09-01T00:00:00Z.');

                return self::FAILURE;
            }
        }

        $count = 0;
        $startedAt = microtime(true);

        try {
            $service->users($since)->each(static function () use (&$count): void {
                $count++;
            });
        } catch (RaccountException $exception) {
            $this->components->error('Directory sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Processed %d directory record(s) in %.1fs.',
            $count,
            microtime(true) - $startedAt,
        ));

        return self::SUCCESS;
    }
}
