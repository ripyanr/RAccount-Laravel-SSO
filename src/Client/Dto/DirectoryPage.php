<?php

namespace Raccount\Sso\Client\Dto;

final class DirectoryPage
{
    /**
     * @param  list<DirectoryUser>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $nextCursor,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        return new self(
            data: array_map(
                static fn (array $record): DirectoryUser => DirectoryUser::fromRecord($record),
                array_values((array) ($body['data'] ?? [])),
            ),
            nextCursor: isset($body['next_cursor'])
                ? (string) $body['next_cursor']
                : null,
        );
    }
}
