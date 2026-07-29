<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReserveHold extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'account_id',
        'charge_id',
        'amount',
        'status',
        'release_at',
        'released_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'release_at' => 'datetime',
        'released_at' => 'datetime',
    ];
}
