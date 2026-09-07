<?php

namespace Raccount\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed>|null $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
class RaccountWebhookEvent extends Model
{
    protected $table = 'raccount_webhook_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public $timestamps = false;
}
