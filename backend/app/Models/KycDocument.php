<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class KycDocument extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_IDENTITY_FRONT = 'identity_front';
    public const TYPE_IDENTITY_BACK = 'identity_back';
    public const TYPE_SELFIE = 'selfie';
    public const TYPE_PROOF_OF_ADDRESS = 'proof_of_address';
    public const TYPE_COMPANY_DOCUMENT = 'company_document';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'id',
        'user_id',
        'document_type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    protected $hidden = [
        'file_path',
    ];

    protected $appends = [
        'file_url',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        // Return a short-lived signed URL (5 minutes) for secure document access
        try {
            return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'kyc.document.view',
                now()->addMinutes(5),
                ['document' => $this->id]
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public static function getRequiredDocumentTypes(string $accountType = 'individual'): array
    {
        $types = [
            self::TYPE_IDENTITY_FRONT,
            self::TYPE_IDENTITY_BACK,
            self::TYPE_SELFIE,
        ];

        if ($accountType === 'company') {
            $types[] = self::TYPE_COMPANY_DOCUMENT;
        }

        return $types;
    }

    public static function getDocumentTypeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_IDENTITY_FRONT => 'Documento de Identidade (Frente)',
            self::TYPE_IDENTITY_BACK => 'Documento de Identidade (Verso)',
            self::TYPE_SELFIE => 'Selfie com Documento',
            self::TYPE_PROOF_OF_ADDRESS => 'Comprovante de Endereço',
            self::TYPE_COMPANY_DOCUMENT => 'Documento da Empresa (CNPJ/Contrato Social)',
            default => $type,
        };
    }
}
