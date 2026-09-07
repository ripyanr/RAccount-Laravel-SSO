<?php

namespace Raccount\Sso\Directory;

use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\LazyCollection;
use Raccount\Sso\Client\Dto\DirectoryUser;
use Raccount\Sso\Client\RaccountClient;
use Raccount\Sso\Events\DirectoryUserRetrieved;

class DirectorySyncService
{
    public function __construct(
        private readonly RaccountClient $client,
        private readonly ClientCredentialsManager $credentials,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Walk the directory (cursor pagination) lazily. Each retrieved record
     * emits DirectoryUserRetrieved so applications can provision users.
     *
     * @return LazyCollection<int, DirectoryUser>
     */
    public function users(?DateTimeInterface $updatedSince = null): LazyCollection
    {
        $limit = (int) config('raccount-sso.directory.page_limit', 200);

        return LazyCollection::make(function () use ($updatedSince, $limit): \Generator {
            $cursor = null;

            do {
                $page = $this->client->directoryPage(
                    $this->credentials->token(),
                    $cursor,
                    $updatedSince,
                    $limit,
                );

                foreach ($page->data as $record) {
                    $this->events->dispatch(new DirectoryUserRetrieved($record));

                    yield $record;
                }

                $cursor = $page->nextCursor;
            } while ($cursor !== null);
        });
    }
}
