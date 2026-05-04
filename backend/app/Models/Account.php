<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Domain\Acquirer\Entities\Acquirer;

class Account extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'document',
        'document_type',
        'email',
        'phone',
        'status',
        'is_demo',
        'settings',
        'metadata',
    ];

    protected $casts = [
        'settings' => 'array',
        'metadata' => 'array',
        'is_demo' => 'boolean',
    ];

    protected $attributes = [
        'status' => 'pending',
        'is_demo' => false,
        'settings' => '{}',
        'metadata' => '{}',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function charges()
    {
        return $this->hasMany(Charge::class);
    }

    public function acquirers()
    {
        return $this->belongsToMany(Acquirer::class, 'account_acquirers')
            ->withPivot(['id', 'priority', 'is_active', 'settings'])
            ->withTimestamps()
            ->orderBy('account_acquirers.priority');
    }

    public function accountAcquirers()
    {
        return $this->hasMany(AccountAcquirer::class);
    }
}
