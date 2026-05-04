<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\KycActivityLog;
use App\Models\KycDocument;
use App\Models\KycSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Mail\KycApprovedMail;
use Illuminate\Validation\Rule;

class KycController extends Controller
{
    /**
     * Get current KYC status and submission
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // If authenticated via API Key (stdClass mock), return basic status
            if (!$user instanceof \App\Models\User) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'kyc_status' => 'approved',
                        'kyc_submitted_at' => null,
                        'kyc_approved_at' => null,
                        'rejection_reason' => null,
                        'submission' => null,
                        'documents' => [],
                        'can_submit' => false,
                    ],
                ]);
            }

            // Check if KYC tables/columns exist
            $submission = null;
            $documents = collect();

            try {
                $submission = KycSubmission::where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->first();
                $documents = $user->kycDocuments()->get();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('KYC tables may not exist yet: ' . $e->getMessage());
                // KYC tables/columns may not exist yet (migration not run)
                return response()->json([
                    'success' => true,
                    'data' => [
                        'kyc_status' => $user->getAttribute('kyc_status') ?? 'pending',
                        'kyc_submitted_at' => null,
                        'kyc_approved_at' => null,
                        'rejection_reason' => null,
                        'submission' => null,
                        'documents' => [],
                        'can_submit' => false,
                    ],
                ]);
            }

            $requiredDocuments = KycDocument::getRequiredDocumentTypes(
                $submission?->account_type ?? 'individual'
            );

            $documentStatus = [];
            foreach ($requiredDocuments as $type) {
                $doc = $documents->where('document_type', $type)->first();
                $documentStatus[$type] = [
                    'label' => KycDocument::getDocumentTypeLabel($type),
                    'uploaded' => $doc !== null,
                    'status' => $doc?->status ?? 'not_uploaded',
                    'rejection_reason' => $doc?->rejection_reason,
                ];
            }

            $kycStatus = $user->getAttribute('kyc_status') ?? 'pending';
            $kycSubmittedAt = null;
            $kycApprovedAt = null;

            try {
                $kycSubmittedAt = $user->kyc_submitted_at?->format('c');
                $kycApprovedAt = $user->kyc_approved_at?->format('c');
            } catch (\Throwable $e) {
                // Column might not exist or date parsing failed
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'kyc_status' => $kycStatus,
                    'kyc_submitted_at' => $kycSubmittedAt,
                    'kyc_approved_at' => $kycApprovedAt,
                    'rejection_reason' => $user->getAttribute('kyc_rejection_reason'),
                    'submission' => $submission ? [
                        'id' => $submission->id,
                        'status' => $submission->status,
                        'full_name' => $submission->full_name,
                        'document_number' => $this->maskDocument($submission->document_number ?? ''),
                        'birth_date' => $submission->birth_date?->format('Y-m-d'),
                        'phone' => $submission->phone,
                        'account_type' => $submission->account_type,
                        'address_street' => $submission->address_street,
                        'address_number' => $submission->address_number,
                        'address_complement' => $submission->address_complement,
                        'address_neighborhood' => $submission->address_neighborhood,
                        'address_city' => $submission->address_city,
                        'address_state' => $submission->address_state,
                        'address_zip_code' => $submission->address_zip_code,
                        'company_name' => $submission->company_name,
                        'company_document' => $submission->company_document ? $this->maskDocument($submission->company_document) : null,
                        'created_at' => $submission->created_at?->format('c'),
                    ] : null,
                    'documents' => $documentStatus,
                    'can_submit' => $this->canSubmit($user, $documents, $requiredDocuments),
                ],
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('KYC status error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'kyc_status' => 'pending',
                    'kyc_submitted_at' => null,
                    'kyc_approved_at' => null,
                    'rejection_reason' => null,
                    'submission' => null,
                    'documents' => [],
                    'can_submit' => false,
                ],
            ]);
        }
    }

    /**
     * Create or update KYC submission with personal data
     */
    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof \App\Models\User) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'invalid_user', 'message' => 'Autenticação inválida'],
            ], 400);
        }

        $kycStatus = $user->getAttribute('kyc_status') ?? 'pending';

        // Check if user can submit
        if (!in_array($kycStatus, ['pending', 'rejected'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'kyc_already_submitted',
                    'message' => 'Sua verificação já foi enviada ou aprovada',
                ],
            ], 400);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before:-18 years'],
            'document_number' => ['required', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address_street' => ['required', 'string', 'max:255'],
            'address_number' => ['required', 'string', 'max:20'],
            'address_complement' => ['nullable', 'string', 'max:255'],
            'address_neighborhood' => ['required', 'string', 'max:255'],
            'address_city' => ['required', 'string', 'max:255'],
            'address_state' => ['required', 'string', 'size:2'],
            'address_zip_code' => ['required', 'string', 'max:10'],
            'account_type' => ['required', Rule::in(['individual', 'company'])],
            'company_name' => ['required_if:account_type,company', 'nullable', 'string', 'max:255'],
            'company_document' => ['required_if:account_type,company', 'nullable', 'string', 'max:20'],
            'pix_key' => ['nullable', 'string', 'max:255'],
            'pix_type' => ['nullable', 'in:cpf,cnpj,email,phone,random'],
        ], [
            'full_name.required' => 'O nome completo é obrigatório',
            'birth_date.required' => 'A data de nascimento é obrigatória',
            'birth_date.before' => 'Você deve ter no mínimo 18 anos',
            'document_number.required' => 'O CPF é obrigatório',
            'address_street.required' => 'O endereço é obrigatório',
            'address_number.required' => 'O número é obrigatório',
            'address_neighborhood.required' => 'O bairro é obrigatório',
            'address_city.required' => 'A cidade é obrigatória',
            'address_state.required' => 'O estado é obrigatório',
            'address_zip_code.required' => 'O CEP é obrigatório',
            'company_name.required_if' => 'O nome da empresa é obrigatório para contas PJ',
            'company_document.required_if' => 'O CNPJ é obrigatório para contas PJ',
        ]);

        // Clean document numbers
        $validated['document_number'] = preg_replace('/\D/', '', $validated['document_number']);
        if (isset($validated['company_document'])) {
            $validated['company_document'] = preg_replace('/\D/', '', $validated['company_document']);
        }

        DB::beginTransaction();
        try {
            // Create or update submission
            $submission = KycSubmission::updateOrCreate(
                ['user_id' => $user->id, 'status' => 'pending'],
                array_merge(
                    collect($validated)->except(['pix_key', 'pix_type'])->toArray(),
                    ['user_id' => $user->id]
                )
            );

            // Save PIX data to account settings
            // If user provided explicit pix_key, use it; otherwise auto-create from document (CPF/CNPJ)
            $account = \App\Models\Account::find($user->account_id);
            if ($account) {
                $settings = $account->settings ?? [];
                $existingKeys = $settings['pix_keys'] ?? [];

                // Only auto-save if user has no PIX keys configured yet
                if (empty($existingKeys)) {
                    if (!empty($validated['pix_key'])) {
                        $pixKey = $validated['pix_key'];
                        $pixType = $validated['pix_type'] ?? 'cpf';
                    } else {
                        // Auto-create PIX key from document number (CPF or CNPJ)
                        $isCompany = ($validated['account_type'] ?? 'individual') === 'company';
                        $pixKey = $isCompany
                            ? ($validated['company_document'] ?? $validated['document_number'])
                            : $validated['document_number'];
                        $pixType = $isCompany ? 'cnpj' : 'cpf';
                    }

                    $newPixEntry = [
                        'id' => uniqid('pix_'),
                        'pix_key' => $pixKey,
                        'pix_type' => $pixType,
                        'label' => 'Principal',
                    ];

                    $settings['pix_keys'] = [$newPixEntry];
                    // Also save legacy format for backward compatibility
                    $settings['pix'] = [
                        'pix_key' => $pixKey,
                        'pix_type' => $pixType,
                    ];
                    $account->settings = $settings;
                    $account->save();
                }
            }

            // Log activity
            KycActivityLog::log(
                $user->id,
                KycActivityLog::ACTION_SUBMISSION_CREATED,
                $submission->id,
                ['account_type' => $validated['account_type']],
                $user->id,
                $request->ip()
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'submission_id' => $submission->id,
                    'message' => 'Dados salvos com sucesso. Agora envie os documentos.',
                    'next_step' => 'upload_documents',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'submission_failed',
                    'message' => 'Erro ao salvar dados. Tente novamente.',
                ],
            ], 500);
        }
    }

    /**
     * Upload a KYC document
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof \App\Models\User) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'invalid_user', 'message' => 'Autenticação inválida'],
            ], 400);
        }

        $kycStatus = $user->getAttribute('kyc_status') ?? 'pending';

        if (!in_array($kycStatus, ['pending', 'rejected'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'kyc_not_editable',
                    'message' => 'Você não pode mais alterar sua verificação',
                ],
            ], 400);
        }

        // Log upload attempt details for debugging
        $uploadedFile = $request->file('file');
        if ($uploadedFile) {
            $phpError = $uploadedFile->getError();
            if ($phpError !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'Arquivo excede upload_max_filesize do PHP (' . ini_get('upload_max_filesize') . ')',
                    UPLOAD_ERR_FORM_SIZE => 'Arquivo excede MAX_FILE_SIZE do formulário',
                    UPLOAD_ERR_PARTIAL => 'Upload incompleto - arquivo enviado parcialmente',
                    UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
                    UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado no servidor',
                    UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo no disco do servidor',
                    UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP',
                ];
                $msg = $errorMessages[$phpError] ?? "Erro PHP desconhecido (código {$phpError})";
                Log::error("KYC upload failed: PHP error {$phpError} - {$msg}", [
                    'user_id' => $user->id,
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'file_name' => $uploadedFile->getClientOriginalName(),
                    'file_size' => $uploadedFile->getSize(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'upload_failed',
                        'message' => $msg,
                    ],
                ], 422);
            }
        } else {
            // No file received at all - check if POST body was too large
            $contentLength = $request->header('Content-Length', 0);
            $postMaxSize = $this->parseSize(ini_get('post_max_size'));
            Log::error('KYC upload: no file received', [
                'user_id' => $user->id,
                'content_length' => $contentLength,
                'post_max_size' => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'content_type' => $request->header('Content-Type'),
                'has_file_key' => $request->hasFile('file'),
                'all_files' => array_keys($_FILES),
            ]);
        }

        // Ensure storage directory exists
        $storagePath = storage_path('app/private');
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0775, true);
        }

        $request->validate([
            'document_type' => ['required', Rule::in([
                KycDocument::TYPE_IDENTITY_FRONT,
                KycDocument::TYPE_IDENTITY_BACK,
                KycDocument::TYPE_SELFIE,
                KycDocument::TYPE_PROOF_OF_ADDRESS,
                KycDocument::TYPE_COMPANY_DOCUMENT,
            ])],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'], // 10MB max
        ], [
            'document_type.required' => 'O tipo de documento é obrigatório',
            'document_type.in' => 'Tipo de documento inválido',
            'file.required' => 'O arquivo é obrigatório',
            'file.mimes' => 'O arquivo deve ser JPG, PNG ou PDF',
            'file.max' => 'O arquivo deve ter no máximo 10MB',
        ]);

        $file = $request->file('file');
        $documentType = $request->input('document_type');

        // Delete existing document of same type
        $existingDoc = KycDocument::where('user_id', $user->id)
            ->where('document_type', $documentType)
            ->first();

        if ($existingDoc) {
            Storage::delete($existingDoc->file_path);
            $existingDoc->delete();
        }

        // Store new document
        $path = $file->store("kyc/{$user->id}", 'private');

        $document = KycDocument::create([
            'user_id' => $user->id,
            'document_type' => $documentType,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'status' => KycDocument::STATUS_PENDING,
        ]);

        // Log activity
        KycActivityLog::log(
            $user->id,
            KycActivityLog::ACTION_DOCUMENT_UPLOADED,
            null,
            [
                'document_type' => $documentType,
                'file_name' => $file->getClientOriginalName(),
            ],
            $user->id,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'data' => [
                'document_id' => $document->id,
                'document_type' => $document->document_type,
                'file_name' => $document->file_name,
                'status' => $document->status,
                'message' => 'Documento enviado com sucesso',
            ],
        ]);
    }

    /**
     * Delete a KYC document
     */
    public function deleteDocument(Request $request, string $documentId): JsonResponse
    {
        $user = $request->user();

        $document = KycDocument::where('id', $documentId)
            ->where('user_id', $user->id)
            ->first();

        if (!$document) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'document_not_found',
                    'message' => 'Documento não encontrado',
                ],
            ], 404);
        }

        if (!in_array($user->getAttribute('kyc_status') ?? 'pending', ['pending', 'rejected'])) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'kyc_not_editable',
                    'message' => 'Você não pode mais alterar sua verificação',
                ],
            ], 400);
        }

        Storage::delete($document->file_path);

        KycActivityLog::log(
            $user->id,
            KycActivityLog::ACTION_DOCUMENT_DELETED,
            null,
            ['document_type' => $document->document_type],
            $user->id,
            $request->ip()
        );

        $document->delete();

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Documento removido com sucesso',
            ],
        ]);
    }

    /**
     * Submit KYC for review (final step)
     */
    public function submitForReview(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // If authenticated via API Key (stdClass mock), reject
            if (!$user instanceof \App\Models\User) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'invalid_user',
                        'message' => 'Autenticação por API Key não suporta KYC',
                    ],
                ], 400);
            }

            $kycStatus = $user->getAttribute('kyc_status') ?? 'pending';

            if (!in_array($kycStatus, ['pending', 'rejected'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'kyc_already_submitted',
                        'message' => 'Sua verificação já foi enviada',
                    ],
                ], 400);
            }

            // Direct query to find the latest submission (avoids latestOfMany UUID ordering issues)
            $submission = KycSubmission::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->first();

            if (!$submission) {
                // Fallback: try the Eloquent relation
                try {
                    $submission = $user->latestKycSubmission;
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('KYC submitForReview - error loading relation: ' . $e->getMessage());
                }
            }

            if (!$submission) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'no_submission',
                        'message' => 'Preencha seus dados antes de enviar',
                    ],
                ], 400);
            }

            $documents = collect();
            try {
                $documents = $user->kycDocuments;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('KYC submitForReview - error loading documents: ' . $e->getMessage());
            }

            $requiredDocuments = KycDocument::getRequiredDocumentTypes($submission->account_type ?? 'individual');

            $uploadedTypes = $documents->pluck('document_type')->toArray();
            $missingDocuments = array_diff($requiredDocuments, $uploadedTypes);

            if (!empty($missingDocuments)) {
                $missingLabels = array_map(
                    fn($type) => KycDocument::getDocumentTypeLabel($type),
                    $missingDocuments
                );

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'missing_documents',
                        'message' => 'Documentos obrigatórios faltando: ' . implode(', ', $missingLabels),
                        'missing' => array_values($missingDocuments),
                    ],
                ], 400);
            }

            DB::beginTransaction();
            try {
                $submission->update(['status' => 'under_review']);

                $user->forceFill([
                    'kyc_status' => 'submitted',
                    'kyc_submitted_at' => now(),
                    'kyc_rejection_reason' => null,
                    'phone' => $submission->phone,
                ])->save();

                KycActivityLog::log(
                    $user->id,
                    KycActivityLog::ACTION_SUBMITTED_FOR_REVIEW,
                    $submission->id,
                    null,
                    $user->id,
                    $request->ip()
                );

                DB::commit();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'kyc_status' => 'submitted',
                        'message' => 'Sua verificação foi enviada e será analisada em até 48 horas.',
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                \Illuminate\Support\Facades\Log::error('KYC submitForReview DB error: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'submission_failed',
                        'message' => 'Erro ao enviar verificação. Tente novamente.',
                    ],
                ], 500);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('KYC submitForReview error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Erro interno ao processar verificação: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * View KYC document file
     */
    public function viewDocument(Request $request, string $document)
    {
        // Validate signed URL (signature + expiration)
        if (!$request->hasValidSignature()) {
            abort(403, 'Link expirado ou inválido. Solicite um novo link.');
        }

        $kycDocument = KycDocument::find($document);

        if (!$kycDocument) {
            abort(404, 'Documento não encontrado');
        }

        if (!Storage::disk('private')->exists($kycDocument->file_path)) {
            abort(404, 'Arquivo não encontrado');
        }

        return Storage::disk('private')->response($kycDocument->file_path);
    }

    /**
     * Generate a short-lived signed URL for viewing a KYC document.
     * POST /api/v1/kyc/documents/{document}/signed-url
     */
    public function generateSignedUrl(Request $request, string $document): JsonResponse
    {
        $user = $request->user();

        $kycDocument = KycDocument::find($document);

        if (!$kycDocument) {
            return response()->json(['message' => 'Documento não encontrado'], 404);
        }

        // Only allow user to view their own documents, or admin to view any
        $isAdmin = $user->isAdmin();
        if (!$isAdmin && $kycDocument->user_id !== $user->id) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        // Generate signed URL that expires in 5 minutes
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'kyc.document.view',
            now()->addMinutes(5),
            ['document' => $document]
        );

        return response()->json([
            'url' => $url,
            'expires_in' => 300,
        ]);
    }

    /**
     * Admin: List pending KYC submissions
     */
    public function adminList(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');
        $search = $request->input('search', null) ? addcslashes($request->input('search'), '%_\\') : null;
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = KycSubmission::with(['user:id,name,email'])
            ->when($status !== 'all', function ($q) use ($status) {
                if ($status === 'pending') {
                    // Show both pending and under_review when filtering for pending
                    return $q->whereIn('status', ['pending', 'under_review']);
                }
                return $q->where('status', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($subQuery) use ($search) {
                    $subQuery->where('full_name', 'like', "%{$search}%")
                        ->orWhere('document_number', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('created_at', 'asc');

        $submissions = $query->paginate($perPage);

        $statusCounts = [
            'pending' => KycSubmission::where('status', 'pending')->count(),
            'under_review' => KycSubmission::where('status', 'under_review')->count(),
            'approved' => KycSubmission::where('status', 'approved')->count(),
            'rejected' => KycSubmission::where('status', 'rejected')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $submissions->items(),
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'last_page' => $submissions->lastPage(),
                'per_page' => $submissions->perPage(),
                'total' => $submissions->total(),
                'counts' => $statusCounts,
            ],
        ]);
    }

    /**
     * Admin: Get KYC submission details
     */
    public function adminShow(string $submissionId): JsonResponse
    {
        $submission = KycSubmission::with(['user', 'activityLogs.performer'])
            ->find($submissionId);

        // Fallback: try looking up by user_id (admin may navigate from customer view)
        if (!$submission) {
            $submission = KycSubmission::with(['user', 'activityLogs.performer'])
                ->where('user_id', $submissionId)
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$submission) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'submission_not_found',
                    'message' => 'Submissão não encontrada',
                ],
            ], 404);
        }

        $documents = KycDocument::where('user_id', $submission->user_id)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'submission' => $submission,
                'documents' => $documents,
                'activity_logs' => $submission->activityLogs,
            ],
        ]);
    }

    /**
     * Admin: Get documents for a KYC submission
     */
    public function adminDocuments(string $submissionId): JsonResponse
    {
        $submission = KycSubmission::find($submissionId);

        // Fallback: try looking up by user_id
        if (!$submission) {
            $submission = KycSubmission::where('user_id', $submissionId)
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$submission) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'submission_not_found',
                    'message' => 'Submissão não encontrada',
                ],
            ], 404);
        }

        $documents = KycDocument::where('user_id', $submission->user_id)->get();

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    /**
     * Admin: Approve KYC
     */
    public function adminApprove(Request $request, string $submissionId): JsonResponse
    {
        $admin = $request->user();
        $submission = KycSubmission::with(['user', 'user.account'])->find($submissionId);

        // Fallback: try by user_id
        if (!$submission) {
            $submission = KycSubmission::with(['user', 'user.account'])
                ->where('user_id', $submissionId)
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$submission) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'submission_not_found',
                    'message' => 'Submissão não encontrada',
                ],
            ], 404);
        }

        if (!$submission->user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'user_not_found',
                    'message' => 'Usuário vinculado à submissão não encontrado',
                ],
            ], 404);
        }

        // Resolve real admin user ID for FK constraints (reviewed_by → users.id)
        $adminUserId = null;
        if ($admin instanceof \App\Models\User) {
            $adminUserId = $admin->id;
        } else {
            // API Key auth: find a real admin user from the account
            $realAdmin = \App\Models\User::where('account_id', $admin->id ?? $admin->account_id ?? null)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['admin', 'owner']))
                ->first();
            $adminUserId = $realAdmin?->id;
        }

        DB::beginTransaction();
        try {
            $submission->update([
                'status' => 'approved',
                'reviewed_by' => $adminUserId,
                'reviewed_at' => now(),
                'admin_notes' => $request->input('notes'),
            ]);

            $submission->user->forceFill([
                'kyc_status' => 'approved',
                'kyc_approved_at' => now(),
                'kyc_rejection_reason' => null,
            ])->save();

            // Update account status (if account exists)
            if ($submission->user->account) {
                $updateData = ['status' => 'active'];
                
                // Only update document if it's different and not used by another account
                if ($submission->user->account->document !== $submission->document_number) {
                    // Check if document is already used by another account
                    $existingAccount = \App\Models\Account::where('document', $submission->document_number)
                        ->where('id', '!=', $submission->user->account->id)
                        ->first();
                    
                    if (!$existingAccount) {
                        $updateData['document'] = $submission->document_number;
                    }
                }
                
                $submission->user->account->update($updateData);

                // Auto-save PIX key from KYC document if no PIX keys exist yet
                $account = $submission->user->account;
                $settings = $account->settings ?? [];
                $existingKeys = $settings['pix_keys'] ?? [];
                if (empty($existingKeys) && empty($settings['pix']['pix_key'])) {
                    $isCompany = $submission->account_type === 'company';
                    $pixKey = $isCompany
                        ? ($submission->company_document ?? $submission->document_number)
                        : $submission->document_number;
                    $pixType = $isCompany ? 'cnpj' : 'cpf';

                    $settings['pix_keys'] = [[
                        'id' => uniqid('pix_'),
                        'pix_key' => $pixKey,
                        'pix_type' => $pixType,
                        'label' => 'Principal',
                    ]];
                    $settings['pix'] = [
                        'pix_key' => $pixKey,
                        'pix_type' => $pixType,
                    ];
                    $account->settings = $settings;
                    $account->save();
                }
            }

            // Approve all pending documents
            KycDocument::where('user_id', $submission->user_id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'approved',
                    'reviewed_by' => $adminUserId,
                    'reviewed_at' => now(),
                ]);

            KycActivityLog::log(
                $submission->user_id,
                KycActivityLog::ACTION_APPROVED,
                $submission->id,
                ['admin_notes' => $request->input('notes')],
                $adminUserId,
                $request->ip()
            );

            DB::commit();

            // Send approval email notification
            try {
                Mail::to($submission->user->email)->send(new KycApprovedMail($submission->user));
            } catch (\Exception $mailException) {
                Log::warning('Failed to send KYC approved email', [
                    'user_id' => $submission->user_id,
                    'error' => $mailException->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'KYC aprovado com sucesso',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('KYC approval failed', [
                'submission_id' => $submissionId,
                'admin_id' => $adminUserId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'approval_failed',
                    'message' => 'Erro ao aprovar KYC: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Admin: Reject KYC
     */
    public function adminReject(Request $request, string $submissionId): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'O motivo da rejeição é obrigatório',
        ]);

        $admin = $request->user();
        $submission = KycSubmission::with('user')->find($submissionId);

        // Fallback: try by user_id
        if (!$submission) {
            $submission = KycSubmission::with('user')
                ->where('user_id', $submissionId)
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$submission) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'submission_not_found',
                    'message' => 'Submissão não encontrada',
                ],
            ], 404);
        }

        if (!$submission->user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'user_not_found',
                    'message' => 'Usuário vinculado à submissão não encontrado',
                ],
            ], 404);
        }

        // Resolve real admin user ID for FK constraints
        $adminUserId = null;
        if ($admin instanceof \App\Models\User) {
            $adminUserId = $admin->id;
        } else {
            $realAdmin = \App\Models\User::where('account_id', $admin->id ?? $admin->account_id ?? null)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['admin', 'owner']))
                ->first();
            $adminUserId = $realAdmin?->id;
        }

        DB::beginTransaction();
        try {
            $submission->update([
                'status' => 'rejected',
                'reviewed_by' => $adminUserId,
                'reviewed_at' => now(),
                'rejection_reason' => $request->input('reason'),
                'admin_notes' => $request->input('notes'),
            ]);

            $submission->user->forceFill([
                'kyc_status' => 'rejected',
                'kyc_rejection_reason' => $request->input('reason'),
            ])->save();

            KycActivityLog::log(
                $submission->user_id,
                KycActivityLog::ACTION_REJECTED,
                $submission->id,
                [
                    'reason' => $request->input('reason'),
                    'admin_notes' => $request->input('notes'),
                ],
                $adminUserId,
                $request->ip()
            );

            DB::commit();

            // Send rejection email notification
            try {
                \Illuminate\Support\Facades\Mail::to($submission->user->email)
                    ->queue(new \App\Mail\KycRejectedMail(
                        $submission->user,
                        $request->input('reason')
                    ));
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to send KYC rejection email', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'KYC rejeitado',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('KYC rejection failed', [
                'submission_id' => $submissionId,
                'admin_id' => $adminUserId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'rejection_failed',
                    'message' => 'Erro ao rejeitar KYC: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Helper: Check if user can submit KYC for review
     */
    private function canSubmit($user, $documents, array $requiredDocuments): bool
    {
        try {
            if (!$user->latestKycSubmission) {
                return false;
            }

            $uploadedTypes = $documents->pluck('document_type')->toArray();
            return empty(array_diff($requiredDocuments, $uploadedTypes));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Helper: Mask document number for display
     */
    private function maskDocument(string $document): string
    {
        if (empty($document)) {
            return '';
        }
        $len = strlen($document);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . substr($document, -4);
    }

    /**
     * Helper: Parse PHP ini size string (e.g. '2M') to bytes
     */
    private function parseSize(string $size): int
    {
        $unit = strtolower(substr($size, -1));
        $value = (int) $size;
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
