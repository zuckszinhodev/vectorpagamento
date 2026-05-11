<?php

namespace App\Http\Middleware;

use App\Models\CustomDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para resolver domínios personalizados.
 * 
 * Quando um usuário acessa pay.seudominio.com, este middleware:
 * 1. Identifica o domínio da requisição
 * 2. Busca o domínio verificado no banco
 * 3. Injeta o account_id na requisição para uso no checkout
 */
class ResolveCustomDomain
{
    /**
     * Domínios que pertencem ao próprio Vector Pagamentos (não são personalizados)
     */
    protected array $systemDomains = [
        'localhost',
        '127.0.0.1',
        'vectorpagamentos.com',
        'www.vectorpagamentos.com',
        'api.vectorpagamentos.com',
        'checkout.vectorpagamentos.com',
        'pay.vectorpagamentos.com',
        'vectorpagamentos.com',
        'www.vectorpagamentos.com',
        'api.vectorpagamentos.com',
        'app.vectorpagamentos.com',
        'checkout.vectorpagamentos.com',
        'pay.vectorpagamentos.com',
        'members.vectorpagamentos.com',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        
        // Check if this is a Vector Pagamentos subdomain (e.g., meusite.vectorpagamentos.com)
        $wazerpaySlug = $this->extractGateProSubdomain($host);
        if ($wazerpaySlug) {
            return $this->resolveGateProSubdomain($request, $next, $wazerpaySlug, $host);
        }

        // Ignora domínios do sistema
        if ($this->isSystemDomain($host)) {
            return $next($request);
        }

        // Extrai o domínio base (remove subdomínio 'pay.')
        $baseDomain = $this->extractBaseDomain($host);
        
        // Busca o domínio personalizado verificado
        $customDomain = CustomDomain::where('domain', $baseDomain)
            ->where('status', 'verified')
            ->first();

        if (!$customDomain) {
            // Domínio não encontrado ou não verificado
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'domain_not_found',
                    'message' => 'Domínio não configurado ou não verificado',
                    'domain' => $host,
                ],
            ], 404);
        }

        // Injeta informações do domínio na requisição
        $request->merge([
            'custom_domain_id' => $customDomain->id,
            'custom_domain_account_id' => $customDomain->account_id,
            'custom_domain' => $customDomain->domain,
        ]);

        // Também disponibiliza como atributo para controllers
        $request->attributes->set('custom_domain', $customDomain);

        return $next($request);
    }

    /**
     * Resolve a Vector Pagamentos subdomain (e.g., meusite.vectorpagamentos.com).
     */
    protected function resolveGateProSubdomain(Request $request, Closure $next, string $slug, string $host): Response
    {
        $customDomain = CustomDomain::where('subdomain_slug', $slug)
            ->where('type', CustomDomain::TYPE_GATEPRO)
            ->where('status', 'verified')
            ->first();

        if (!$customDomain) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'subdomain_not_found',
                    'message' => 'Subdomínio Vector Pagamentos não encontrado',
                    'domain' => $host,
                ],
            ], 404);
        }

        $request->merge([
            'custom_domain_id' => $customDomain->id,
            'custom_domain_account_id' => $customDomain->account_id,
            'custom_domain' => $customDomain->domain,
        ]);

        $request->attributes->set('custom_domain', $customDomain);

        return $next($request);
    }

    /**
     * Extract the subdomain slug from a Vector Pagamentos subdomain.
     * Returns null if the host is not a Vector Pagamentos user subdomain.
     *
     * Examples:
     * - meusite.vectorpagamentos.com -> meusite
     * - api.vectorpagamentos.com -> null (system)
     * - app.vectorpagamentos.com -> null (system)
     */
    protected function extractGateProSubdomain(string $host): ?string
    {
        $host = preg_replace('/:\d+$/', '', $host);
        $baseDomain = CustomDomain::getGateProBaseDomain();

        if (!str_ends_with($host, '.' . $baseDomain)) {
            return null;
        }

        $subdomain = substr($host, 0, -(strlen($baseDomain) + 1));

        // Ignore system subdomains
        $systemSubs = ['api', 'app', 'www', 'checkout', 'pay', 'members', 'mail', 'ftp', 'admin'];
        if (in_array($subdomain, $systemSubs) || str_contains($subdomain, '.')) {
            return null;
        }

        return $subdomain;
    }

    /**
     * Verifica se é um domínio do sistema Vector Pagamentos.
     */
    protected function isSystemDomain(string $host): bool
    {
        // Remove porta se existir
        $host = preg_replace('/:\d+$/', '', $host);
        
        foreach ($this->systemDomains as $systemDomain) {
            if ($host === $systemDomain || str_ends_with($host, '.' . $systemDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrai o domínio base removendo o subdomínio 'pay.'.
     * 
     * Exemplos:
     * - pay.meusite.com -> meusite.com
     * - checkout.meusite.com -> meusite.com
     * - meusite.com -> meusite.com
     */
    protected function extractBaseDomain(string $host): string
    {
        // Remove porta se existir
        $host = preg_replace('/:\d+$/', '', $host);
        
        // Remove subdomínios comuns de checkout
        $prefixes = ['pay.', 'checkout.', 'pagamento.'];
        
        foreach ($prefixes as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return substr($host, strlen($prefix));
            }
        }

        return $host;
    }
}
