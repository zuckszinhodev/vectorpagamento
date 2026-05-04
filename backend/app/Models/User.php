<?php

namespace App\Models;

use App\Domain\Auth\Entities\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasUuids, Notifiable, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'account_id',
        'email',
        'password',
        'name',
        'phone',
    ];

    protected $guarded = [
        'role_id',
        'status',
        'block_reason',
        'blocked_at',
        'mfa_enabled',
        'mfa_secret',
        'kyc_status',
        'kyc_submitted_at',
        'kyc_approved_at',
        'kyc_rejection_reason',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'kyc_submitted_at' => 'datetime',
        'kyc_approved_at' => 'datetime',
        'last_login_at' => 'datetime',
        'blocked_at' => 'datetime',
        'mfa_enabled' => 'boolean',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     */
    public function getJWTCustomClaims()
    {
        return [
            'account_id' => $this->account_id,
            'email' => $this->email,
        ];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function kycSubmissions()
    {
        return $this->hasMany(KycSubmission::class);
    }

    public function kycDocuments()
    {
        return $this->hasMany(KycDocument::class);
    }

    public function latestKycSubmission()
    {
        return $this->hasOne(KycSubmission::class)->latest('created_at');
    }

    public function isKycApproved(): bool
    {
        return $this->kyc_status === 'approved';
    }

    public function isKycPending(): bool
    {
        return in_array($this->kyc_status, ['pending', 'submitted', 'under_review']);
    }

    /**
     * Get the role name/slug for the user.
     */
    public function getRoleName(): string
    {
        $legacyRole = $this->getAttribute('role');

        return $this->role?->slug
            ?? $this->role?->name
            ?? (is_string($legacyRole) && $legacyRole !== '' ? $legacyRole : 'user');
    }

    /**
     * Check if user has admin or owner role.
     */
    public function isAdmin(): bool
    {
        $roleName = $this->getRoleName();
        return in_array($roleName, ['admin', 'owner']);
    }

    /**
     * Resolve effective permissions from role relation with safe fallback.
     */
    public function getPermissionsAttribute(): array
    {
        $permissions = $this->role?->permissions;

        if (!is_array($permissions)) {
            return [];
        }

        return array_values(array_filter($permissions, fn ($permission) => is_string($permission) && $permission !== ''));
    }

    /**
     * Check whether user has permission (supports wildcard and dot/colon notation).
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->getRoleName() === 'owner') {
            return true;
        }

        $permissions = $this->permissions;

        if (in_array('*', $permissions, true)) {
            return true;
        }

        $permissionDot = str_replace(':', '.', $permission);
        $permissionColon = str_replace('.', ':', $permission);

        if (in_array($permissionDot, $permissions, true) || in_array($permissionColon, $permissions, true)) {
            return true;
        }

        $dotParts = explode('.', $permissionDot);
        $colonParts = explode(':', $permissionColon);
        $dotCategory = $dotParts[0] ?? '';
        $colonCategory = $colonParts[0] ?? '';

        return in_array($dotCategory . '.*', $permissions, true)
            || in_array($colonCategory . ':*', $permissions, true);
    }
}
