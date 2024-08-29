<?php

namespace App\Models;

use App\Exceptions\DisplayException;
use App\Rules\Username;
use App\Facades\Activity;
use DateTimeZone;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\In;
use Illuminate\Auth\Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Traits\HasAccessTokens;
use Illuminate\Auth\Passwords\CanResetPassword;
use App\Traits\Helpers\AvailableLanguages;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use App\Notifications\SendPasswordReset as ResetPasswordNotification;

/**
 * App\Models\User.
 *
 * @property int $id
 * @property string|null $external_id
 * @property string $uuid
 * @property string $username
 * @property string $email
 * @property string|null $name_first
 * @property string|null $name_last
 * @property string $password
 * @property string|null $remember_token
 * @property string $language
 * @property string $timezone
 * @property bool $root_admin
 * @property bool $use_totp
 * @property string|null $totp_secret
 * @property \Illuminate\Support\Carbon|null $totp_authenticated_at
 * @property array|null $oauth
 * @property bool $gravatar
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\ApiKey[] $apiKeys
 * @property int|null $api_keys_count
 * @property string $name
 * @property \Illuminate\Notifications\DatabaseNotificationCollection|\Illuminate\Notifications\DatabaseNotification[] $notifications
 * @property int|null $notifications_count
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\RecoveryToken[] $recoveryTokens
 * @property int|null $recovery_tokens_count
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\Server[] $servers
 * @property int|null $servers_count
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\UserSSHKey[] $sshKeys
 * @property int|null $ssh_keys_count
 * @property \Illuminate\Database\Eloquent\Collection|\App\Models\ApiKey[] $tokens
 * @property int|null $tokens_count
 *
 * @method static \Database\Factories\UserFactory factory(...$parameters)
 * @method static Builder|User newModelQuery()
 * @method static Builder|User newQuery()
 * @method static Builder|User query()
 * @method static Builder|User whereCreatedAt($value)
 * @method static Builder|User whereEmail($value)
 * @method static Builder|User whereExternalId($value)
 * @method static Builder|User whereGravatar($value)
 * @method static Builder|User whereId($value)
 * @method static Builder|User whereLanguage($value)
 * @method static Builder|User whereTimezone($value)
 * @method static Builder|User whereNameFirst($value)
 * @method static Builder|User whereNameLast($value)
 * @method static Builder|User wherePassword($value)
 * @method static Builder|User whereRememberToken($value)
 * @method static Builder|User whereRootAdmin($value)
 * @method static Builder|User whereTotpAuthenticatedAt($value)
 * @method static Builder|User whereTotpSecret($value)
 * @method static Builder|User whereUpdatedAt($value)
 * @method static Builder|User whereUseTotp($value)
 * @method static Builder|User whereUsername($value)
 * @method static Builder|User whereUuid($value)
 *
 * @mixin \Eloquent
 */
class User extends Model implements AuthenticatableContract, AuthorizableContract, CanResetPasswordContract, FilamentUser, HasAvatar, HasName
{
    use Authenticatable;
    use Authorizable {can as protected canned; }
    use AvailableLanguages;
    use CanResetPassword;
    use HasAccessTokens;
    use Notifiable;

    public const USER_LEVEL_USER = 0;
    public const USER_LEVEL_ADMIN = 1;

    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'user';

    /**
     * Level of servers to display when using access() on a user.
     */
    protected string $accessLevel = 'all';

    /**
     * The table associated with the model.
     */
    protected $table = 'users';

    /**
     * A list of mass-assignable variables.
     */
    protected $fillable = [
        'external_id',
        'username',
        'email',
        'name_first',
        'name_last',
        'password',
        'language',
        'timezone',
        'use_totp',
        'totp_secret',
        'totp_authenticated_at',
        'gravatar',
        'root_admin',
        'oauth',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     */
    protected $hidden = ['password', 'remember_token', 'totp_secret', 'totp_authenticated_at', 'oauth'];

    /**
     * Default values for specific fields in the database.
     */
    protected $attributes = [
        'external_id' => null,
        'root_admin' => false,
        'language' => 'en',
        'timezone' => 'UTC',
        'use_totp' => false,
        'totp_secret' => null,
        'name_first' => '',
        'name_last' => '',
        'oauth' => '[]',
    ];

    /**
     * Rules verifying that the data being stored matches the expectations of the database.
     */
    public static array $validationRules = [
        'uuid' => 'nullable|string|size:36|unique:users,uuid',
        'email' => 'required|email|between:1,255|unique:users,email',
        'external_id' => 'sometimes|nullable|string|max:255|unique:users,external_id',
        'username' => 'required|between:1,255|unique:users,username',
        'name_first' => 'nullable|string|between:0,255',
        'name_last' => 'nullable|string|between:0,255',
        'password' => 'sometimes|nullable|string',
        'root_admin' => 'boolean',
        'language' => 'string',
        'timezone' => 'string',
        'use_totp' => 'boolean',
        'totp_secret' => 'nullable|string',
        'oauth' => 'array|nullable',
    ];

    protected function casts(): array
    {
        return [
            'root_admin' => 'boolean',
            'use_totp' => 'boolean',
            'gravatar' => 'boolean',
            'totp_authenticated_at' => 'datetime',
            'totp_secret' => 'encrypted',
            'oauth' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->uuid = Str::uuid()->toString();

            return true;
        });

        static::deleting(function (self $user) {
            throw_if($user->servers()->count() > 0, new DisplayException(__('admin/user.exceptions.user_has_servers')));

            throw_if(request()->user()?->id === $user->id, new DisplayException(__('admin/user.exceptions.user_is_self')));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Implement language verification by overriding Eloquence's gather
     * rules function.
     */
    public static function getRules(): array
    {
        $rules = parent::getRules();

        $rules['language'][] = new In(array_keys((new self())->getAvailableLanguages()));
        $rules['timezone'][] = new In(array_values(DateTimeZone::listIdentifiers()));
        $rules['username'][] = new Username();

        return $rules;
    }

    /**
     * Return the user model in a format that can be passed over to React templates.
     */
    public function toReactObject(): array
    {
        return collect($this->toArray())->except(['id', 'external_id'])->toArray();
    }

    /**
     * Send the password reset notification.
     *
     * @param string $token
     */
    public function sendPasswordResetNotification($token)
    {
        Activity::event('auth:reset-password')
            ->withRequestMetadata()
            ->subject($this)
            ->log('sending password reset email');

        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Store the username as a lowercase string.
     */
    public function setUsernameAttribute(string $value)
    {
        $this->attributes['username'] = mb_strtolower($value);
    }

    /**
     * Return a concatenated result for the accounts full name.
     */
    public function getNameAttribute(): string
    {
        return trim($this->name_first . ' ' . $this->name_last);
    }

    /**
     * Returns all servers that a user owns.
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'owner_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class)
            ->where('key_type', ApiKey::TYPE_ACCOUNT);
    }

    public function recoveryTokens(): HasMany
    {
        return $this->hasMany(RecoveryToken::class);
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(UserSSHKey::class);
    }

    /**
     * Returns all the activity logs where this user is the subject — not to
     * be confused by activity logs where this user is the _actor_.
     */
    public function activity(): MorphToMany
    {
        return $this->morphToMany(ActivityLog::class, 'subject', 'activity_log_subjects');
    }

    /**
     * Returns all the servers that a user can access by way of being the owner of the
     * server, or because they are assigned as a subuser for that server.
     */
    public function accessibleServers(): Builder
    {
        return Server::query()
            ->select('servers.*')
            ->leftJoin('subusers', 'subusers.server_id', '=', 'servers.id')
            ->where(function (Builder $builder) {
                $builder->where('servers.owner_id', $this->id)->orWhere('subusers.user_id', $this->id);
            })
            ->groupBy('servers.id');
    }

    public function subusers(): HasMany
    {
        return $this->hasMany(Subuser::class);
    }

    protected function checkPermission(Server $server, string $permission = ''): bool
    {
        if ($this->root_admin || $server->owner_id === $this->id) {
            return true;
        }

        $subuser = $server->subusers->where('user_id', $this->id)->first();
        if (!$subuser || empty($permission)) {
            return false;
        }

        $check = in_array($permission, $subuser->permissions);

        return $check;
    }

    /**
     * Laravel's policies strictly check for the existence of a real method,
     * this checks if the ability is one of our permissions and then checks if the user can do it or not
     * Otherwise it calls the Authorizable trait's parent method
     */
    public function can($abilities, mixed $arguments = []): bool
    {
        if (is_string($abilities) && str_contains($abilities, '.')) {
            [$permission, $key] = str($abilities)->explode('.', 2);

            if (isset(Permission::permissions()[$permission]['keys'][$key])) {
                if ($arguments instanceof Server) {
                    return $this->checkPermission($arguments, $abilities);
                }
            }
        }

        return $this->canned($abilities, $arguments);
    }

    public function isLastRootAdmin(): bool
    {
        $rootAdmins = User::query()->where('root_admin', true)->limit(2)->get();

        return once(fn () => $rootAdmins->count() === 1 && $rootAdmins->first()->is($this));
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->root_admin;
    }

    public function getFilamentName(): string
    {
        return $this->name_first ?: $this->username;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return 'https://gravatar.com/avatar/' . md5(strtolower($this->email));
    }
}
