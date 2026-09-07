<?php

namespace Raccount\Sso\Events;

use Raccount\Sso\Webhooks\WebhookPayload;

final class WebhookReceived
{
    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}
