<?php

namespace Raccount\Sso\Commands;

use Carbon\CarbonImmutable;
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
        $since = $this->option('since') !== null
            ? CarbonImmutable::parse((string) $this->option('since'))
            : null;

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
