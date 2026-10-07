<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ConfirmNewEmail;
use App\Notifications\ResetPassword;
use App\Support\Access;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // The rights a person's positions carry, kept under a second name: the
    // model's own hasPermissionTo() below shadows the trait's, and a trait
    // method cannot be reached through parent::.
    use HasRoles {
        hasPermissionTo as hasPermissionViaPositions;
    }

    /**
     * Where a letter goes. A new address is confirmed by mail sent to that new
     * address, not to the one on the account: only whoever reads it can prove
     * the address is theirs. Everything else goes to the account's address.
     */
    public function routeNotificationForMail(Notification $notification): string
    {
        return $notification instanceof ConfirmNewEmail ? $notification->email : $this->email;
    }

    /**
     * The reset letter, sent from the queue like every other letter here.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    /**
     * Mirrors the column default, so a freshly created user counts as working
     * before it is reloaded from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'patronymic',
        'avatar',
        'avatar_original',
        'sex',
        'status',
        'status_changed_at',
        'status_note',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Why someone was let go is not for the page payload by default.
        'status_note',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_changed_at' => 'date',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Both columns hold a path on the public disk, but every reader wants a
     * URL, so they hand one out. The path itself is still reachable through
     * getRawOriginal(), which is what deleting the file needs.
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn (?string $path) => self::publicUrl($path));
    }

    protected function avatarOriginal(): Attribute
    {
        return Attribute::get(fn (?string $path) => self::publicUrl($path));
    }

    private static function publicUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * Still working here, as opposed to transferred or fired.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Private data (passport, address, contacts). Only load it for viewers
     * who are allowed to see it.
     */
    public function details(): HasOne
    {
        return $this->hasOne(UserDetail::class);
    }

    /**
     * What the employee does; one or several, public. Access rights are roles.
     */
    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class)->withTimestamps()->orderBy('name');
    }

    /**
     * None, one or several departments (public, like positions).
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withPivot('is_head')->withTimestamps()->orderBy('name');
    }

    /**
     * Languages the employee speaks, each with a level; public, like positions.
     */
    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class)->withPivot('level')->withTimestamps()->orderBy('name');
    }

    /**
     * The countries the employee is a citizen of; private, like the rest of
     * the card's personal lines.
     */
    public function citizenships(): BelongsToMany
    {
        return $this->belongsToMany(Citizenship::class)->withTimestamps()->orderBy('name');
    }

    /**
     * Private, like details.
     */
    public function children(): HasMany
    {
        return $this->hasMany(UserChild::class)->orderBy('birth_date');
    }

    /**
     * Where the employee studied, earliest first; private, like details.
     */
    public function educations(): HasMany
    {
        return $this->hasMany(UserEducation::class)->orderBy('started_year')->orderBy('id');
    }

    /**
     * Previous jobs, the latest first; private, like details.
     */
    public function workExperiences(): HasMany
    {
        return $this->hasMany(UserWorkExperience::class)->orderByDesc('started_year')->orderByDesc('started_month')->orderByDesc('id');
    }

    /**
     * Rights given to, or taken from, this person in particular, whatever their
     * positions carry.
     */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(PermissionOverride::class);
    }

    /**
     * A personal exception beats the positions, both ways.
     *
     * Every check in the application ends up here — the gate, the "can:"
     * middleware and Spatie's own hook all ask the model — so there is one
     * answer to "may this person do that", and it is this one.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $key = match (true) {
            is_string($permission) => $permission,
            $permission instanceof PermissionContract => $permission->name,
            default => null,
        };

        if ($key !== null) {
            $own = $this->permissionOverrides->firstWhere('permission', $key);

            if ($own !== null) {
                return $own->allowed;
            }
        }

        try {
            return $this->hasPermissionViaPositions($permission, $guardName);
        } catch (PermissionDoesNotExist) {
            // What rights exist is decided in code; the database is only told the
            // same list by a seeder. Until it is told, an unknown right answers
            // "no" rather than bringing the page down — the way Spatie's own
            // checkPermissionTo() answers it. A right in the catalogue with no row
            // behind it is caught by PermissionsTest, not by a visitor.
            return false;
        }
    }

    /**
     * Everything this person may do, as the pages read it: every right in the
     * catalogue with a yes or a no, so the UI never has to guess.
     *
     * @return array<string, bool>
     */
    public function accessMap(): array
    {
        return collect(Access::keys())->mapWithKeys(fn (string $key) => [$key => $this->can($key)])->all();
    }

    /**
     * What this person has done to the fleet: the journal's side of it.
     */
    public function equipmentEvents(): HasMany
    {
        return $this->hasMany(EquipmentEvent::class);
    }

    /**
     * Company hardware this person holds right now. The units belong to the
     * company and are handed out from the equipment section; the profile only
     * shows what is currently on them.
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'holder_user_id')->orderBy('equipment_type_id')->orderBy('id');
    }
}
