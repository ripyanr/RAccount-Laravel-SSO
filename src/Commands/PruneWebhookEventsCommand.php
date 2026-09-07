<?php

namespace Raccount\Sso\Commands;

use Illuminate\Console\Command;
use Raccount\Sso\Models\RaccountWebhookEvent;

final class PruneWebhookEventsCommand extends Command
{
    protected $signature = 'raccount:prune-webhooks {--days=30 : Delete events received more than this many days ago}';

    protected $description = 'Prune processed RAccount webhook events';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = RaccountWebhookEvent::query()
            ->where('received_at', '<', now()->subDays($days))
            ->delete();

        $this->components->info("Deleted {$deleted} webhook event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
