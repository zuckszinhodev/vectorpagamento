<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomDomain;
use App\Models\LandingPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DomainController extends Controller
{
    /**
     * List all domains for the authenticated user's account.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $domains = CustomDomain::where('account_id', $request->user()->account_id)
                ->orderByDesc('is_primary')
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($domain) {
                    // Precisa vir com os registros DNS: a tela lista os dominios
                    // por aqui, e sem isso quem acabou de criar um dominio proprio
                    // nao ve o que apontar. O formatDomain so anexa para dominio
                    // proprio ainda nao verificado.
                    return $this->formatDomain($domain, true);
                });

            return response()->json([
                'success' => true,
                'data' => $domains,
            ]);
        } catch (\Throwable $e) {
            Log::error('DomainController::index failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    /**
     * Create a new custom domain.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type', 'custom');

            if ($type === 'vectorpagamentos') {
                return $this->storeGateProSubdomain($request);
            }

            $request->validate([
                'domain' => [
                    'required',
                    'string',
                    'max:255',
                    'regex:/^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
                    'unique:custom_domains,domain',
                ],
                'use_for' => ['sometimes', 'array'],
                'use_for.*' => ['string', 'in:checkout,members'],
            ], [
                'domain.required' => 'O domínio é obrigatório',
                'domain.regex' => 'Formato de domínio inválido',
                'domain.unique' => 'Este domínio já está em uso',
            ]);

            // Check domain limit per account (e.g., 5 domains)
            $domainCount = CustomDomain::where('account_id', $request->user()->account_id)->count();
            if ($domainCount >= 5) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'domain_limit_reached',
                        'message' => 'Você atingiu o limite máximo de domínios (5)',
                    ],
                ], 400);
            }

            // Block registration of vectorpagamentos.com subdomains as custom domains
            $domainValue = strtolower($request->input('domain'));
            if (str_ends_with($domainValue, '.' . CustomDomain::getGateProBaseDomain())) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'invalid_domain',
                        'message' => 'Subdomínios Vector Pagamentos devem ser criados usando a opção "Subdomínio Vector Pagamentos"',
                    ],
                ], 400);
            }

            $domain = CustomDomain::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $request->user()->account_id,
                'domain' => $domainValue,
                'type' => CustomDomain::TYPE_CUSTOM,
                'verification_token' => Str::random(32),
                'status' => 'pending',
                'is_primary' => $domainCount === 0,
                'use_for' => $request->input('use_for', ['checkout', 'members']),
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->formatDomain($domain, true),
                'message' => 'Domínio adicionado. Configure os registros DNS para verificar.',
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('DomainController::store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao criar domínio']], 500);
        }
    }

    /**
     * Create a Vector Pagamentos subdomain (e.g., meusite.vectorpagamentos.com).
     * Auto-verified, no DNS configuration needed.
     */
    protected function storeGateProSubdomain(Request $request): JsonResponse
    {
        $request->validate([
            'subdomain' => [
                'required',
                'string',
                'min:3',
                'max:40',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                'unique:custom_domains,subdomain_slug',
            ],
            'use_for' => ['sometimes', 'array'],
            'use_for.*' => ['string', 'in:checkout,members'],
        ], [
            'subdomain.required' => 'O subdomínio é obrigatório',
            'subdomain.min' => 'O subdomínio deve ter pelo menos 3 caracteres',
            'subdomain.max' => 'O subdomínio deve ter no máximo 40 caracteres',
            'subdomain.regex' => 'Apenas letras minúsculas, números e hífens. Não pode começar ou terminar com hífen.',
            'subdomain.unique' => 'Este subdomínio já está em uso',
        ]);

        $slug = strtolower($request->input('subdomain'));

        // Block reserved slugs
        $reserved = ['www', 'api', 'app', 'pay', 'checkout', 'admin', 'members', 'mail', 'ftp', 'smtp', 'pop', 'imap', 'ns1', 'ns2', 'cpanel', 'webmail', 'blog', 'shop', 'store', 'test', 'dev', 'staging', 'demo', 'support', 'help', 'docs', 'status'];
        if (in_array($slug, $reserved)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'subdomain_reserved',
                    'message' => 'Este subdomínio é reservado e não pode ser usado',
                ],
            ], 400);
        }

        // Check domain limit per account
        $domainCount = CustomDomain::where('account_id', $request->user()->account_id)->count();
        if ($domainCount >= 5) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'domain_limit_reached',
                    'message' => 'Você atingiu o limite máximo de domínios (5)',
                ],
            ], 400);
        }

        $fullDomain = CustomDomain::buildSubdomainFull($slug);

        $domain = CustomDomain::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $request->user()->account_id,
            'domain' => $fullDomain,
            'type' => CustomDomain::TYPE_GATEPRO,
            'subdomain_slug' => $slug,
            'verification_token' => Str::random(32),
            'status' => 'verified',
            'verified_at' => now(),
            'is_primary' => $domainCount === 0,
            'ssl_enabled' => true,
            'use_for' => $request->input('use_for', ['checkout', 'members']),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatDomain($domain),
            'message' => 'Subdomínio Vector Pagamentos criado e ativado automaticamente!',
        ], 201);
    }

    /**
     * Get a specific domain with DNS instructions.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
        $domain = CustomDomain::where('account_id', $request->user()->account_id)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->formatDomain($domain, true),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DomainController::show error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Verify domain DNS configuration.
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        try {
        $domain = CustomDomain::where('account_id', $request->user()->account_id)
            ->where('id', $id)
            ->firstOrFail();

        // Vector Pagamentos subdomains are always verified
        if ($domain->isGateProSubdomain()) {
            if (!$domain->isVerified()) {
                $domain->markAsVerified();
            }
            return response()->json([
                'success' => true,
                'data' => $this->formatDomain($domain),
                'message' => 'Subdomínio Vector Pagamentos está verificado automaticamente.',
            ]);
        }

        // Perform DNS verification
        $result = $domain->verifyDns();

        if ($result['cname_valid'] && $result['txt_valid']) {
            $domain->markAsVerified();

            return response()->json([
                'success' => true,
                'data' => $this->formatDomain($domain),
                'message' => 'Domínio verificado com sucesso!',
            ]);
        }

        // Update last check
        $domain->update([
            'last_check_at' => now(),
            'last_check_error' => implode('; ', $result['errors']),
        ]);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'verification_failed',
                'message' => 'Verificação DNS falhou',
                'details' => $result['errors'],
            ],
            'data' => [
                'cname_valid' => $result['cname_valid'],
                'txt_valid' => $result['txt_valid'],
            ],
        ], 400);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DomainController::verify error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Set domain as primary.
     */
    public function setPrimary(Request $request, string $id): JsonResponse
    {
        try {
        $domain = CustomDomain::where('account_id', $request->user()->account_id)
            ->where('id', $id)
            ->firstOrFail();

        if (!$domain->isVerified()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'domain_not_verified',
                    'message' => 'Apenas domínios verificados podem ser definidos como principal',
                ],
            ], 400);
        }

        $domain->setAsPrimary();

        return response()->json([
            'success' => true,
            'data' => $this->formatDomain($domain),
            'message' => 'Domínio definido como principal',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DomainController::setPrimary error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Delete a custom domain.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
        $domain = CustomDomain::where('account_id', $request->user()->account_id)
            ->where('id', $id)
            ->firstOrFail();

        // Check if any landing pages are using this domain
        $linkedPages = LandingPage::where('account_id', $request->user()->account_id)
            ->where('custom_domain', $domain->domain)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'slug']);

        if ($linkedPages->isNotEmpty()) {
            $pageNames = $linkedPages->pluck('name')->implode(', ');
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'domain_has_linked_pages',
                    'message' => 'Não é possível excluir este domínio. Existem páginas de vendas vinculadas a ele. Remova o domínio das seguintes páginas antes de excluí-lo: ' . $pageNames,
                    'linked_pages' => $linkedPages->toArray(),
                ],
            ], 422);
        }

        // If deleting primary, set another verified domain as primary
        if ($domain->is_primary) {
            $newPrimary = CustomDomain::where('account_id', $request->user()->account_id)
                ->where('id', '!=', $id)
                ->where('status', 'verified')
                ->first();

            if ($newPrimary) {
                $newPrimary->setAsPrimary();
            }
        }

        $domain->delete();

        return response()->json([
            'success' => true,
            'message' => 'Domínio removido com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DomainController::destroy error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Update the use_for purposes of a domain.
     */
    public function updateUseFor(Request $request, string $id): JsonResponse
    {
        try {
        $request->validate([
            'use_for' => ['required', 'array', 'min:1'],
            'use_for.*' => ['string', 'in:checkout,members'],
        ], [
            'use_for.required' => 'Selecione pelo menos um uso para o domínio',
            'use_for.min' => 'Selecione pelo menos um uso para o domínio',
        ]);

        $domain = CustomDomain::where('account_id', $request->user()->account_id)
            ->where('id', $id)
            ->firstOrFail();

        $domain->update([
            'use_for' => array_values(array_unique($request->input('use_for'))),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->formatDomain($domain),
            'message' => 'Uso do domínio atualizado',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DomainController::updateUseFor error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get DNS configuration instructions.
     */
    public function getDnsInstructions(Request $request, string $id): JsonResponse
    {
        try {
        $domain = CustomDomain::where('account_id', $request->user()->account_id)
            ->where('id', $id)
            ->firstOrFail();

        $cnameTarget = $domain->getCnameTarget();

        return response()->json([
            'success' => true,
            'data' => [
                'domain' => $domain->domain,
                'instructions' => [
                    [
                        'step' => 1,
                        'title' => 'Configure o CNAME',
                        'description' => 'Adicione um registro CNAME no seu provedor DNS',
                        'record' => [
                            'type' => 'CNAME',
                            'host' => $domain->domain,
                            'value' => $cnameTarget,
                            'ttl' => 3600,
                        ],
                    ],
                    [
                        'step' => 2,
                        'title' => 'Adicione o TXT de verificação',
                        'description' => 'Adicione um registro TXT para verificar a propriedade',
                        'record' => [
                            'type' => 'TXT',
                            'host' => $domain->getTxtRecordName(),
                            'value' => $domain->getTxtRecordValue(),
                            'ttl' => 3600,
                        ],
                    ],
                    [
                        'step' => 3,
                        'title' => 'Verifique o domínio',
                        'description' => 'Após configurar os registros, clique em verificar. A propagação DNS pode levar até 48 horas.',
                    ],
                ],
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('DomainController::getDnsInstructions error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Format domain for API response.
     */
    private function formatDomain(CustomDomain $domain, bool $includeVerificationDetails = false): array
    {
        $data = [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'type' => $domain->type ?? CustomDomain::TYPE_CUSTOM,
            'subdomain_slug' => $domain->subdomain_slug,
            'status' => $domain->status,
            'is_primary' => $domain->is_primary,
            'use_for' => $domain->use_for ?? ['checkout', 'members'],
            'ssl_enabled' => $domain->ssl_enabled,
            'verified_at' => $domain->verified_at?->format('c'),
            'last_check_at' => $domain->last_check_at?->format('c'),
            'last_check_error' => $domain->last_check_error,
            'created_at' => $domain->created_at->format('c'),
        ];

        // Only include DNS config for custom domains that are not yet verified
        if ($includeVerificationDetails && !$domain->isVerified() && $domain->isCustomDomain()) {
            $data['dns_config'] = [
                'cname' => [
                    'type' => 'CNAME',
                    'host' => $domain->domain,
                    'value' => $domain->getCnameTarget(),
                ],
                'txt' => [
                    'type' => 'TXT',
                    'host' => $domain->getTxtRecordName(),
                    'value' => $domain->getTxtRecordValue(),
                ],
            ];
        }

        return $data;
    }
}
