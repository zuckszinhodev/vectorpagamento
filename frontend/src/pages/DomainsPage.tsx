import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useThemeStore } from '@/stores/themeStore';
import { toast } from 'react-hot-toast';
import {
  GlobeAltIcon,
  PlusIcon,
  TrashIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  ClockIcon,
  ArrowPathIcon,
  StarIcon,
  ClipboardDocumentIcon,
  ShieldCheckIcon,
  BookOpenIcon,
  ChevronDownIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import { StarIcon as StarSolidIcon } from '@heroicons/react/24/solid';
import clsx from 'clsx';
import { Modal, ModalHeader, ModalContent } from '@/components/ui/Modal';
import DomainSetupGuide from '@/components/domains/DomainSetupGuide';
import { domainsService, CustomDomain } from '@/services/api/domains.service';

const STATUS_META: Record<string, { label: string; icon: any; chip: string; dot: string }> = {
  verified: { label: 'Verificado', icon: CheckCircleIcon, chip: 'bg-status-success-subtle text-status-success', dot: 'bg-status-success' },
  pending: { label: 'Pendente', icon: ClockIcon, chip: 'bg-status-warning-subtle text-status-warning', dot: 'bg-status-warning' },
  failed: { label: 'Falhou', icon: ExclamationTriangleIcon, chip: 'bg-status-error-subtle text-status-error', dot: 'bg-status-error' },
  expired: { label: 'Expirado', icon: ExclamationTriangleIcon, chip: 'bg-status-error-subtle text-status-error', dot: 'bg-status-error' },
};

export default function DomainsPage() {
  const platformName = useThemeStore(s => s.platformName);
  const queryClient = useQueryClient();

  const [showAddForm, setShowAddForm] = useState(false);
  const [showGuide, setShowGuide] = useState(false);
  const [expandedId, setExpandedId] = useState<string | null>(null);

  const [newDomain, setNewDomain] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['domains'],
    queryFn: () => domainsService.list(),
  });

  const domains = data?.data ?? [];

  const closeAddForm = () => {
    setShowAddForm(false);
    setNewDomain('');
  };

  const apiError = (err: any, fallback: string) =>
    err?.response?.data?.error?.message || err?.response?.data?.message || fallback;

  const createMutation = useMutation({
    mutationFn: (domain: string) => domainsService.create(domain),
    onSuccess: result => {
      toast.success(result.message || 'Domínio adicionado');
      queryClient.invalidateQueries({ queryKey: ['domains'] });
      closeAddForm();
      // Abre direto os registros DNS: é o próximo passo obrigatório.
      if (result.data) setExpandedId(result.data.id);
    },
    onError: err => toast.error(apiError(err, 'Erro ao adicionar domínio')),
  });

  const verifyMutation = useMutation({
    mutationFn: (id: string) => domainsService.verify(id),
    onSuccess: result => {
      if (result.success) toast.success(result.message || 'Domínio verificado!');
      else toast.error(result.error?.details?.join(', ') || 'Verificação falhou');
      queryClient.invalidateQueries({ queryKey: ['domains'] });
    },
    onError: (err: any) => {
      const details = err?.response?.data?.error?.details;
      toast.error(Array.isArray(details) ? details.join('\n') : apiError(err, 'Erro na verificação'));
      // O backend grava o motivo em last_check_error; sem recarregar, o card
      // continuaria mostrando o estado anterior à tentativa.
      queryClient.invalidateQueries({ queryKey: ['domains'] });
    },
  });

  const setPrimaryMutation = useMutation({
    mutationFn: (id: string) => domainsService.setPrimary(id),
    onSuccess: () => {
      toast.success('Domínio definido como principal');
      queryClient.invalidateQueries({ queryKey: ['domains'] });
    },
    onError: err => toast.error(apiError(err, 'Erro ao definir como principal')),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => domainsService.remove(id),
    onSuccess: () => {
      toast.success('Domínio removido');
      queryClient.invalidateQueries({ queryKey: ['domains'] });
      setExpandedId(null);
    },
    onError: (err: any) => {
      const error = err?.response?.data?.error;
      if (error?.code === 'domain_has_linked_pages') toast.error(error.message, { duration: 6000 });
      else toast.error(apiError(err, 'Erro ao remover domínio'));
    },
  });

  const handleCreate = () => {
    if (isCreateDisabled) return;
    createMutation.mutate(newDomain);
  };

  const isCreating = createMutation.isPending;
  const isCreateDisabled = isCreating || !newDomain.includes('.');

  const stats = {
    total: domains.length,
    verified: domains.filter(d => d.status === 'verified').length,
    pending: domains.filter(d => d.status !== 'verified').length,
    primary: domains.find(d => d.is_primary)?.domain ?? null,
  };

  return (
    <div className="space-y-6">
      {/* ── Cabeçalho ─────────────────────────────── */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-tx-primary">Domínios</h1>
          <p className="text-sm text-tx-muted mt-1">
            Use seus próprios endereços no checkout, nas páginas de venda e na área de membros.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => setShowGuide(true)}
            className="inline-flex items-center gap-2 rounded-element border border-border-DEFAULT bg-surface-card px-3.5 py-2.5 text-sm font-medium text-tx-secondary hover:bg-surface-hover transition-colors"
          >
            <BookOpenIcon className="h-4 w-4" />
            Como configurar
          </button>
          <button
            data-tour="add-domain"
            onClick={() => (showAddForm ? closeAddForm() : setShowAddForm(true))}
            className="inline-flex items-center gap-2 rounded-element bg-accent-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-accent-hover transition-colors"
          >
            {showAddForm ? <XMarkIcon className="h-4 w-4" /> : <PlusIcon className="h-4 w-4" />}
            {showAddForm ? 'Fechar' : 'Adicionar domínio'}
          </button>
        </div>
      </div>

      {/* ── Adicionar (inline, sem modal) ─────────── */}
      {showAddForm && (
        <div className="rounded-card border border-accent-primary/40 bg-surface-card p-4 sm:p-5 space-y-5">
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 className="text-base font-semibold text-tx-primary">Adicionar domínio</h2>
              <p className="text-xs text-tx-muted mt-0.5">Aponte o seu próprio endereço para a plataforma.</p>
            </div>
            <button
              onClick={closeAddForm}
              title="Fechar"
              className="shrink-0 p-1.5 rounded-element text-tx-muted hover:text-tx-primary hover:bg-surface-hover transition-colors"
            >
              <XMarkIcon className="h-4 w-4" />
            </button>
          </div>

          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-1.5">Domínio ou subdomínio</label>
            <input
              autoFocus
              value={newDomain}
              onChange={e => setNewDomain(e.target.value.toLowerCase().trim())}
              onKeyDown={e => e.key === 'Enter' && handleCreate()}
              placeholder="vendas.meusite.com"
              className="w-full rounded-element border border-border-DEFAULT bg-surface-input px-3.5 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-accent-primary/20"
            />
            <p className="mt-1.5 text-xs text-tx-muted">Ex.: vendas.meusite.com, lp.meusite.com, meusite.com.br</p>
            <div className="mt-2.5 flex items-start gap-2 rounded-element bg-status-warning-subtle border border-status-warning/25 p-2.5">
              <ExclamationTriangleIcon className="h-4 w-4 text-status-warning shrink-0 mt-0.5" />
              <p className="text-xs text-status-warning leading-relaxed">
                Você vai precisar de acesso ao painel de DNS para criar os registros CNAME e TXT.
              </p>
            </div>
          </div>

          <div className="flex gap-3">
            <button
              onClick={closeAddForm}
              className="flex-1 sm:flex-none sm:min-w-[140px] rounded-element border border-border-DEFAULT px-4 py-2.5 text-sm font-medium text-tx-secondary hover:bg-surface-hover transition-colors"
            >
              Cancelar
            </button>
            <button
              onClick={handleCreate}
              disabled={isCreateDisabled}
              className="flex-1 sm:flex-none sm:min-w-[180px] rounded-element bg-accent-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-accent-hover transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {isCreating ? 'Adicionando...' : 'Adicionar domínio'}
            </button>
          </div>
        </div>
      )}

      {/* ── Resumo ────────────────────────────────── */}
      {domains.length > 0 && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <StatTile label="Domínios" value={String(stats.total)} icon={GlobeAltIcon} tone="text-tx-primary" />
          <StatTile label="Verificados" value={String(stats.verified)} icon={CheckCircleIcon} tone="text-status-success" />
          <StatTile label="Aguardando DNS" value={String(stats.pending)} icon={ClockIcon} tone="text-status-warning" />
          <StatTile
            label="Principal"
            value={stats.primary ?? '—'}
            icon={StarIcon}
            tone="text-accent-primary"
            truncate
          />
        </div>
      )}

      {/* ── Lista ─────────────────────────────────── */}
      {isLoading ? (
        <div className="rounded-card border border-border-DEFAULT bg-surface-card py-16 flex justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
        </div>
      ) : domains.length === 0 ? (
        <div className="rounded-card border border-dashed border-border-DEFAULT bg-surface-card py-16 px-6 text-center">
          <div className="mx-auto w-fit rounded-full bg-accent-subtle p-4">
            <GlobeAltIcon className="h-8 w-8 text-accent-primary" />
          </div>
          <h3 className="mt-4 text-base font-semibold text-tx-primary">Nenhum domínio ainda</h3>
          <p className="mt-1.5 text-sm text-tx-muted max-w-md mx-auto">
            Aponte o seu próprio domínio para usar no checkout, nas páginas de venda e na área de membros. É rápido: dois
            registros no painel de DNS.
          </p>
          <div className="mt-5 flex items-center justify-center gap-2">
            <button
              onClick={() => setShowAddForm(true)}
              className="inline-flex items-center gap-2 rounded-element bg-accent-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-accent-hover transition-colors"
            >
              <PlusIcon className="h-4 w-4" />
              Adicionar domínio
            </button>
            <button
              onClick={() => setShowGuide(true)}
              className="inline-flex items-center gap-2 rounded-element border border-border-DEFAULT px-4 py-2.5 text-sm font-medium text-tx-secondary hover:bg-surface-hover transition-colors"
            >
              Ver o guia
            </button>
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          {domains.map(domain => (
            <DomainCard
              key={domain.id}
              domain={domain}
              platformName={platformName}
              expanded={expandedId === domain.id}
              onToggle={() => setExpandedId(expandedId === domain.id ? null : domain.id)}
              onVerify={() => verifyMutation.mutate(domain.id)}
              verifying={verifyMutation.isPending && verifyMutation.variables === domain.id}
              onSetPrimary={() => setPrimaryMutation.mutate(domain.id)}
              onDelete={() => {
                if (confirm(`Remover ${domain.domain}?`)) deleteMutation.mutate(domain.id);
              }}
            />
          ))}
        </div>
      )}

      {/* ── Guia ──────────────────────────────────── */}
      <Modal isOpen={showGuide} onClose={() => setShowGuide(false)} size="full">
        <ModalHeader onClose={() => setShowGuide(false)}>
          <span className="flex items-center gap-2">
            <BookOpenIcon className="h-5 w-5 text-accent-primary" />
            Como configurar seu domínio
          </span>
        </ModalHeader>
        <ModalContent className="max-h-[72vh] overflow-y-auto">
          <DomainSetupGuide platformName={platformName} />
        </ModalContent>
      </Modal>

    </div>
  );
}

// ============================================================
//  PEÇAS
// ============================================================
function StatTile({
  label,
  value,
  icon: Icon,
  tone,
  truncate,
}: {
  label: string;
  value: string;
  icon: any;
  tone: string;
  truncate?: boolean;
}) {
  return (
    <div className="rounded-card border border-border-DEFAULT bg-surface-card p-4">
      <div className="flex items-center gap-2 text-tx-muted">
        <Icon className="h-4 w-4" />
        <span className="text-xs font-medium">{label}</span>
      </div>
      <p className={clsx('mt-1.5 text-xl font-bold', tone, truncate && 'text-sm truncate')} title={truncate ? value : undefined}>
        {value}
      </p>
    </div>
  );
}

function DnsRecord({
  label,
  type,
  host,
  value,
}: {
  label: string;
  type: string;
  host: string;
  value: string;
}) {
  const copy = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success('Copiado!');
  };

  return (
    <div className="rounded-element border border-border-DEFAULT bg-surface-page overflow-hidden">
      <div className="flex items-center justify-between gap-2 px-3 py-2 bg-surface-hover">
        <span className="text-[10px] font-semibold text-tx-muted uppercase tracking-wider">{label}</span>
        <span className="inline-flex px-2 py-0.5 rounded text-[10px] font-bold bg-blue-500/10 text-blue-400">{type}</span>
      </div>
      <div className="divide-y divide-border-DEFAULT">
        {[
          { field: 'Host / Nome', text: host },
          { field: 'Valor / Destino', text: value },
        ].map(row => (
          <div key={row.field} className="flex items-start gap-3 px-3 py-2.5">
            <span className="w-28 shrink-0 text-[11px] text-tx-muted pt-0.5">{row.field}</span>
            <code className="flex-1 min-w-0 font-mono text-[11px] text-tx-primary break-all">{row.text}</code>
            <button
              onClick={() => copy(row.text)}
              title="Copiar"
              className="shrink-0 p-1 rounded text-tx-muted hover:text-accent-primary hover:bg-surface-hover transition-colors"
            >
              <ClipboardDocumentIcon className="h-3.5 w-3.5" />
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}

function DomainCard({
  domain,
  platformName,
  expanded,
  onToggle,
  onVerify,
  verifying,
  onSetPrimary,
  onDelete,
}: {
  domain: CustomDomain;
  platformName: string;
  expanded: boolean;
  onToggle: () => void;
  onVerify: () => void;
  verifying: boolean;
  onSetPrimary: () => void;
  onDelete: () => void;
}) {
  const status = STATUS_META[domain.status] ?? {
    label: domain.status,
    icon: ClockIcon,
    chip: 'bg-surface-hover text-tx-secondary',
    dot: 'bg-tx-muted',
  };
  const isManaged = domain.type === 'wazerpay';
  const isVerified = domain.status === 'verified';

  return (
    <div
      className={clsx(
        'rounded-card border bg-surface-card overflow-hidden transition-colors',
        expanded ? 'border-accent-primary/40' : 'border-border-DEFAULT',
      )}
    >
      <div className="p-4 sm:p-5">
        <div className="flex flex-col lg:flex-row lg:items-center gap-4">
          {/* Identidade */}
          <button onClick={onToggle} className="flex items-start gap-3 min-w-0 flex-1 text-left">
            <span className={clsx('rounded-element p-2 shrink-0', isVerified ? 'bg-status-success-subtle' : 'bg-surface-hover')}>
              <GlobeAltIcon className={clsx('h-5 w-5', isVerified ? 'text-status-success' : 'text-tx-muted')} />
            </span>
            <span className="min-w-0">
              <span className="flex items-center gap-2 flex-wrap">
                <span className="font-semibold text-tx-primary break-all">{domain.domain}</span>
                {domain.is_primary && (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-semibold rounded-pill bg-accent-subtle text-accent-primary">
                    <StarSolidIcon className="h-3 w-3" />
                    PRINCIPAL
                  </span>
                )}
                {isManaged && (
                  <span className="px-2 py-0.5 text-[10px] font-semibold rounded-pill bg-surface-hover text-tx-secondary">
                    {platformName.toUpperCase()}
                  </span>
                )}
              </span>
              <span className="mt-1 flex items-center gap-1.5 text-xs text-tx-muted">
                <ChevronDownIcon className={clsx('h-3.5 w-3.5 transition-transform', expanded && 'rotate-180')} />
                {isManaged
                  ? 'Verificado automaticamente'
                  : domain.verified_at
                    ? `Verificado em ${new Date(domain.verified_at).toLocaleDateString('pt-BR')}`
                    : 'Aguardando configuração de DNS'}
              </span>
            </span>
          </button>

          {/* Etiquetas e ações */}
          <div className="flex items-center gap-2 flex-wrap lg:justify-end shrink-0">
            <span className={clsx('inline-flex items-center gap-1.5 px-2.5 py-1 text-[11px] font-medium rounded-pill', status.chip)}>
              <span className={clsx('h-1.5 w-1.5 rounded-full', status.dot)} />
              {status.label}
            </span>

            {!isVerified && !isManaged && (
              <button
                onClick={onVerify}
                disabled={verifying}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-element bg-accent-primary text-white hover:bg-accent-hover transition-colors disabled:opacity-50"
              >
                <ArrowPathIcon className={clsx('h-3.5 w-3.5', verifying && 'animate-spin')} />
                {verifying ? 'Verificando' : 'Verificar'}
              </button>
            )}

            {isVerified && !domain.is_primary && (
              <button
                onClick={onSetPrimary}
                title="Definir como domínio principal"
                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-element border border-border-DEFAULT text-tx-secondary hover:bg-surface-hover transition-colors"
              >
                <StarIcon className="h-3.5 w-3.5" />
                <span className="hidden sm:inline">Tornar principal</span>
              </button>
            )}

            <button
              onClick={onDelete}
              title="Remover domínio"
              className="p-1.5 rounded-element text-tx-muted hover:text-status-error hover:bg-status-error-subtle transition-colors"
            >
              <TrashIcon className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Detalhes */}
      {expanded && (
        <div className="border-t border-border-DEFAULT bg-surface-page/50 p-4 sm:p-5 space-y-4">
          {!isVerified && !isManaged && (
            <>
              <div>
                <h4 className="text-sm font-semibold text-tx-primary">Registros DNS a criar</h4>
                <p className="text-xs text-tx-muted mt-0.5">
                  Adicione os dois no painel do seu provedor e clique em Verificar.
                </p>
              </div>
              {domain.dns_config ? (
                <div className="grid grid-cols-1 xl:grid-cols-2 gap-3">
                  <DnsRecord
                    label="Apontamento"
                    type="CNAME"
                    host={domain.dns_config.cname.host}
                    value={domain.dns_config.cname.value}
                  />
                  <DnsRecord
                    label="Verificação de propriedade"
                    type="TXT"
                    host={domain.dns_config.txt.host}
                    value={domain.dns_config.txt.value}
                  />
                </div>
              ) : (
                // Nunca deixar a área vazia: sem isso a ausência dos registros
                // passa despercebida e o usuário fica sem saber o que apontar.
                <div className="flex items-start gap-2 rounded-element bg-status-warning-subtle border border-status-warning/25 p-3">
                  <ExclamationTriangleIcon className="h-4 w-4 text-status-warning shrink-0 mt-0.5" />
                  <div className="min-w-0">
                    <p className="text-xs font-semibold text-status-warning">Registros indisponíveis no momento</p>
                    <p className="text-[11px] text-tx-secondary mt-0.5">
                      Recarregue a página. Se continuar assim, remova e adicione o domínio novamente.
                    </p>
                  </div>
                </div>
              )}
              {domain.last_check_error && (
                <div className="flex items-start gap-2 rounded-element bg-status-error-subtle border border-status-error/25 p-3">
                  <ExclamationTriangleIcon className="h-4 w-4 text-status-error shrink-0 mt-0.5" />
                  <div className="min-w-0">
                    <p className="text-xs font-semibold text-status-error">Última verificação falhou</p>
                    <p className="text-[11px] text-tx-secondary mt-0.5 break-words">{domain.last_check_error}</p>
                  </div>
                </div>
              )}
              <p className="text-[11px] text-tx-muted">
                A propagação de DNS costuma levar minutos, mas pode chegar a 48 horas.
              </p>
            </>
          )}

          {(isVerified || isManaged) && (
            <div className="flex items-center gap-2 text-sm text-status-success">
              <ShieldCheckIcon className="h-5 w-5 shrink-0" />
              <span>Domínio ativo e pronto para uso.</span>
              {domain.ssl_enabled && <span className="text-xs text-tx-muted">• SSL ativo</span>}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
