<?php

namespace App\Models;

use App\Domain\Acquirer\Entities\Acquirer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountAcquirer extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'acquirer_id',
        'credentials',
        'priority',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'priority' => 'integer',
    ];

    protected $hidden = [
        'credentials',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function acquirer()
    {
        return $this->belongsTo(Acquirer::class);
    }

    public function getDecryptedCredentials(): ?array
    {
        if (!$this->credentials) {
            return null;
        }

        try {
            $decrypted = decrypt($this->credentials);
            $result = is_array($decrypted) ? $decrypted : json_decode((string) $decrypted, true);
            if (is_array($result)) {
                foreach ($result as $key => $value) {
                    if (is_string($value) && !empty($value)) {
                        try {
                            $result[$key] = decrypt($value);
                        } catch (\Exception $e) {
                            // Value is not encrypted, keep as is
                        }
                    }
                }
                return $result;
            }
        } catch (\Exception $e) {
            // Try decrypting string-encrypted payloads
            try {
                $decrypted = \Illuminate\Support\Facades\Crypt::decryptString($this->credentials);
                $result = json_decode($decrypted, true);
                if (is_array($result)) {
                    foreach ($result as $key => $value) {
                        if (is_string($value) && !empty($value)) {
                            try {
                                $result[$key] = \Illuminate\Support\Facades\Crypt::decryptString($value);
                            } catch (\Exception $e) {
                                // Value is not encrypted, keep as is
                            }
                        }
                    }
                    return $result;
                }
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }
}
