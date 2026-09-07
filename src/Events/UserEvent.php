<?php

namespace Raccount\Sso\Events;

use Raccount\Sso\Webhooks\WebhookPayload;

abstract class UserEvent
{
    public function __construct(
        public readonly WebhookPayload $payload,
    ) {}
}
