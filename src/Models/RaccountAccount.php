<?php

namespace Raccount\Sso\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $raccount_sub
 * @property string $user_type
 * @property string $user_id
 * @property string|null $email
 * @property string|null $name
 * @property string|null $picture_url
 * @property array<string>|null $scopes
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $access_expires_at
 * @property string $status
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RaccountAccount extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DELETED = 'deleted';

    protected $table = 'raccount_accounts';

    protected $guarded = [];

    protected $casts = [
        'scopes' => 'array',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, Authenticatable $user): Builder
    {
        return $query
            ->where('user_type', self::morphTypeFor($user))
            ->where('user_id', $user->getAuthIdentifier());
    }

    public static function morphTypeFor(Authenticatable $user): string
    {
        return $user instanceof Model ? $user->getMorphClass() : $user::class;
    }
}
