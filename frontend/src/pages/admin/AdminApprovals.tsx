import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowPathIcon,
  CheckCircleIcon,
  ClockIcon,
  DocumentTextIcon,
  FunnelIcon,
  MagnifyingGlassIcon,
  XCircleIcon,
  XMarkIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
} from '@heroicons/react/24/outline';
import { api, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import toast from 'react-hot-toast';

interface AdminApproval {
  id: string;
  account_id: string | null;
  requester_user_id: string;
  reviewer_user_id: string | null;
  action_key: string;
  resource_type: string;
  resource_id: string | null;
  status: 'pending' | 'approved' | 'rejected' | 'expired' | 'consumed';
  request_reason: string | null;
  review_reason: string | null;
  requested_at: string;
  reviewed_at: string | null;
  expires_at: string | null;
  consumed_at: string | null;
}

interface AdminApprovalsResponse {
  success: boolean;
  data: AdminApproval[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

const statusOptions = [
  { value: 'pending', label: 'Pendentes' },
  { value: 'approved', label: 'Aprovadas' },
  { value: 'rejected', label: 'Rejeitadas' },
  { value: 'expired', label: 'Expiradas' },
  { value: 'consumed', label: 'Consumidas' },
  { value: 'all', label: 'Todas' },
] as const;

export default function AdminApprovals() {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<string>('pending');
  const [actionKey, setActionKey] = useState('');
  const [page, setPage] = useState(1);
  const [selectedApproval, setSelectedApproval] = useState<AdminApproval | null>(null);
  const [decisionType, setDecisionType] = useState<'approve' | 'reject'>('approve');
  const [reviewReason, setReviewReason] = useState('');

  const { data, isLoading, refetch } = useQuery({
    queryKey: ['admin-approvals', status, actionKey, page],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      params.append('per_page', '20');
      params.append('status', status);
      if (actionKey.trim()) {
        params.append('action_key', actionKey.trim());
      }

      const response = await api.get<AdminApprovalsResponse>(`/admin/approvals?${params.toString()}`);
      return response.data;
    },
  });

  const { data: statsData } = useQuery({
    queryKey: ['admin-approvals-stats', actionKey],
    queryFn: async () => {
      const statuses = [
        { key: 'pending', status: 'pending' },
        { key: 'approved', status: 'approved' },
        { key: 'rejected', status: 'rejected' },
        { key: 'expired', status: 'expired' },
        { key: 'consumed', status: 'consumed' },
        { key: 'total', status: 'all' },
      ] as const;

      const result = await Promise.all(
        statuses.map(async ({ key, status: statusKey }) => {
          const params = new URLSearchParams();
          params.append('status', statusKey);
          params.append('per_page', '1');
          if (actionKey.trim()) {
            params.append('action_key', actionKey.trim());
          }

          const response = await api.get<AdminApprovalsResponse>(`/admin/approvals?${params.toString()}`);
          return [key, response.data.meta.total] as const;
        })
      );

      return Object.fromEntries(result) as Record<string, number>;
    },
  });

  const { data: topActionKeys } = useQuery({
    queryKey: ['admin-approvals-top-action-keys'],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('status', 'all');
      params.append('per_page', '100');

      const response = await api.get<AdminApprovalsResponse>(`/admin/approvals?${params.toString()}`);
      const counts = response.data.data.reduce<Record<string, number>>((acc, approval) => {
        acc[approval.action_key] = (acc[approval.action_key] ?? 0) + 1;
        return acc;
      }, {});

      return Object.entries(counts)
        .sort((a, b) => b[1] - a[1])
        .slice(0, 6)
        .map(([key, count]) => ({ key, count }));
    },
  });

  const approvals = data?.data ?? [];
  const pagination = data?.meta ?? { current_page: 1, per_page: 20, total: 0, last_page: 1 };

  const approveMutation = useMutation({
    mutationFn: async ({ approvalId, reviewReason }: { approvalId: string; reviewReason: string }) => {
      await api.post(`/admin/approvals/${approvalId}/approve`, { review_reason: reviewReason || null });
    },
    onSuccess: () => {
      toast.success('Aprovacao registrada com sucesso.');
      queryClient.invalidateQueries({ queryKey: ['admin-approvals'] });
    },
    onError: () => {
      toast.error('Nao foi possivel aprovar a solicitacao.');
    },
  });

  const rejectMutation = useMutation({
    mutationFn: async ({ approvalId, reviewReason }: { approvalId: string; reviewReason: string }) => {
      await api.post(`/admin/approvals/${approvalId}/reject`, { review_reason: reviewReason || null });
    },
    onSuccess: () => {
      toast.success('Solicitacao rejeitada com sucesso.');
      queryClient.invalidateQueries({ queryKey: ['admin-approvals'] });
    },
    onError: () => {
      toast.error('Nao foi possivel rejeitar a solicitacao.');
    },
  });

  const openDecisionModal = (approval: AdminApproval, mode: 'approve' | 'reject') => {
    setSelectedApproval(approval);
    setDecisionType(mode);
    setReviewReason(approval.review_reason ?? '');
  };

  const closeDecisionModal = () => {
    setSelectedApproval(null);
    setReviewReason('');
  };

  const confirmDecision = () => {
    if (!selectedApproval) {
      return;
    }

    const payload = {
      approvalId: selectedApproval.id,
      reviewReason: reviewReason.trim(),
    };

    if (decisionType === 'approve') {
      approveMutation.mutate(payload, { onSuccess: () => closeDecisionModal() });
      return;
    }

    rejectMutation.mutate(payload, { onSuccess: () => closeDecisionModal() });
  };

  const stats = {
    total: statsData?.total ?? 0,
    pending: statsData?.pending ?? 0,
    approved: statsData?.approved ?? 0,
    rejected: statsData?.rejected ?? 0,
    expired: statsData?.expired ?? 0,
    consumed: statsData?.consumed ?? 0,
  };

  const getStatusBadge = (value: AdminApproval['status']) => {
    switch (value) {
      case 'pending':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-500">
            <ClockIcon className="h-3.5 w-3.5" />
            Pendente
          </span>
        );
      case 'approved':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-500">
            <CheckCircleIcon className="h-3.5 w-3.5" />
            Aprovada
          </span>
        );
      case 'rejected':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-medium text-red-500">
            <XCircleIcon className="h-3.5 w-3.5" />
            Rejeitada
          </span>
        );
      case 'expired':
        return <span className="inline-flex rounded-full bg-slate-500/10 px-2.5 py-1 text-xs font-medium text-slate-500">Expirada</span>;
      case 'consumed':
        return <span className="inline-flex rounded-full bg-sky-500/10 px-2.5 py-1 text-xs font-medium text-sky-500">Consumida</span>;
      default:
        return <span className="text-xs text-tx-muted">-</span>;
    }
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={DocumentTextIcon}
        title="Fila de Aprovacoes"
        subtitle="Revise e decida acoes sensiveis que exigem dual control"
        actions={
          <button
            onClick={() => refetch()}
            className="flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-4 py-2 text-sm font-medium text-white hover:bg-white/20 transition-colors"
          >
            <ArrowPathIcon className="h-4 w-4" />
            Atualizar
          </button>
        }
      />

      <div className="rounded-2xl bg-surface-card p-4 shadow-sm">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
          <div className="relative flex-1">
            <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
            <input
              type="text"
              placeholder="Filtrar por action key (ex: admin.withdrawal.process_single)"
              value={actionKey}
              onChange={(event) => {
                setActionKey(event.target.value);
                setPage(1);
              }}
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page pl-10 pr-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            />
          </div>

          <div className="flex items-center gap-2">
            <FunnelIcon className="h-4 w-4 text-tx-muted" />
            <select
              value={status}
              onChange={(event) => {
                setStatus(event.target.value);
                setPage(1);
              }}
              className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-emerald-500 focus:outline-none"
            >
              {statusOptions.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        </div>

        {topActionKeys && topActionKeys.length > 0 && (
          <div className="mt-4 flex flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-tx-muted">Filtros rápidos:</span>
            {topActionKeys.map((item) => (
              <button
                key={item.key}
                onClick={() => {
                  setActionKey(item.key);
                  setPage(1);
                }}
                className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                  actionKey === item.key
                    ? 'bg-emerald-600 text-white'
                    : 'bg-surface-page text-tx-secondary hover:bg-surface-hover'
                }`}
                title={item.key}
              >
                {item.key} ({item.count})
              </button>
            ))}

            {actionKey.trim() !== '' && (
              <button
                onClick={() => {
                  setActionKey('');
                  setPage(1);
                }}
                className="rounded-full bg-red-500/10 px-3 py-1 text-xs font-medium text-red-600 hover:bg-red-500/20"
              >
                Limpar action_key
              </button>
            )}
          </div>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
        <button
          onClick={() => {
            setStatus('all');
            setPage(1);
          }}
          className="rounded-xl bg-emerald-500/10 p-4 text-left transition-colors hover:bg-emerald-500/20"
        >
          <p className="text-xs font-medium text-emerald-600">Todas</p>
          <p className="mt-1 text-2xl font-bold text-emerald-600">{stats.total}</p>
        </button>
        <button
          onClick={() => {
            setStatus('pending');
            setPage(1);
          }}
          className="rounded-xl bg-amber-500/10 p-4 text-left transition-colors hover:bg-amber-500/20"
        >
          <p className="text-xs font-medium text-amber-600">Pendentes</p>
          <p className="mt-1 text-2xl font-bold text-amber-600">{stats.pending}</p>
        </button>
        <button
          onClick={() => {
            setStatus('approved');
            setPage(1);
          }}
          className="rounded-xl bg-emerald-500/10 p-4 text-left transition-colors hover:bg-emerald-500/20"
        >
          <p className="text-xs font-medium text-emerald-600">Aprovadas</p>
          <p className="mt-1 text-2xl font-bold text-emerald-600">{stats.approved}</p>
        </button>
        <button
          onClick={() => {
            setStatus('rejected');
            setPage(1);
          }}
          className="rounded-xl bg-red-500/10 p-4 text-left transition-colors hover:bg-red-500/20"
        >
          <p className="text-xs font-medium text-red-600">Rejeitadas</p>
          <p className="mt-1 text-2xl font-bold text-red-600">{stats.rejected}</p>
        </button>
        <button
          onClick={() => {
            setStatus('expired');
            setPage(1);
          }}
          className="rounded-xl bg-slate-500/10 p-4 text-left transition-colors hover:bg-slate-500/20"
        >
          <p className="text-xs font-medium text-slate-600">Expiradas</p>
          <p className="mt-1 text-2xl font-bold text-slate-600">{stats.expired}</p>
        </button>
        <button
          onClick={() => {
            setStatus('consumed');
            setPage(1);
          }}
          className="rounded-xl bg-sky-500/10 p-4 text-left transition-colors hover:bg-sky-500/20"
        >
          <p className="text-xs font-medium text-sky-600">Consumidas</p>
          <p className="mt-1 text-2xl font-bold text-sky-600">{stats.consumed}</p>
        </button>
      </div>

      <div className="rounded-2xl bg-surface-card shadow-sm overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <div className="h-10 w-10 animate-spin rounded-full border-4 border-emerald-500 border-t-transparent" />
          </div>
        ) : approvals.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20">
            <DocumentTextIcon className="h-12 w-12 text-tx-muted" />
            <p className="mt-4 text-sm text-tx-muted">Nenhuma aprovação encontrada.</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border-DEFAULT bg-surface-page">
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">Ação</th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">Recurso</th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">Status</th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">Solicitada em</th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">Expira em</th>
                    <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">Ações</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-DEFAULT">
                  {approvals.map((approval) => (
                    <tr key={approval.id} className="hover:bg-surface-hover transition-colors">
                      <td className="px-6 py-4">
                        <p className="text-sm font-medium text-tx-primary">{approval.action_key}</p>
                        <p className="text-xs text-tx-muted">Solicitante: {approval.requester_user_id}</p>
                      </td>
                      <td className="px-6 py-4">
                        <p className="text-sm text-tx-primary">{approval.resource_type}</p>
                        <p className="text-xs text-tx-muted">{approval.resource_id ?? '-'}</p>
                      </td>
                      <td className="px-6 py-4">{getStatusBadge(approval.status)}</td>
                      <td className="px-6 py-4 text-sm text-tx-muted">{formatDate(approval.requested_at)}</td>
                      <td className="px-6 py-4 text-sm text-tx-muted">{approval.expires_at ? formatDate(approval.expires_at) : '-'}</td>
                      <td className="px-6 py-4">
                        <div className="flex items-center justify-end gap-2">
                          {approval.status === 'pending' ? (
                            <>
                              <button
                                onClick={() => openDecisionModal(approval, 'approve')}
                                className="rounded-lg bg-emerald-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-600 transition-colors"
                                disabled={approveMutation.isPending || rejectMutation.isPending}
                              >
                                Aprovar
                              </button>
                              <button
                                onClick={() => openDecisionModal(approval, 'reject')}
                                className="rounded-lg bg-red-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-600 transition-colors"
                                disabled={approveMutation.isPending || rejectMutation.isPending}
                              >
                                Rejeitar
                              </button>
                            </>
                          ) : (
                            <span className="text-xs text-tx-muted">Sem ação</span>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {pagination.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-border-DEFAULT px-6 py-4">
                <p className="text-sm text-tx-muted">
                  Página {pagination.current_page} de {pagination.last_page}
                </p>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setPage((prev) => prev - 1)}
                    disabled={page === 1}
                    className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
                  >
                    <ChevronLeftIcon className="h-5 w-5" />
                  </button>
                  <button
                    onClick={() => setPage((prev) => prev + 1)}
                    disabled={page >= pagination.last_page}
                    className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
                  >
                    <ChevronRightIcon className="h-5 w-5" />
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {selectedApproval && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-xl rounded-2xl bg-surface-card p-6 shadow-xl">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold text-tx-primary">
                {decisionType === 'approve' ? 'Aprovar solicitação' : 'Rejeitar solicitação'}
              </h2>
              <button onClick={closeDecisionModal} className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover">
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="space-y-3">
              <div className="rounded-xl bg-surface-page p-3">
                <p className="text-xs text-tx-muted">Ação</p>
                <p className="text-sm font-medium text-tx-primary">{selectedApproval.action_key}</p>
                <p className="mt-1 text-xs text-tx-muted">
                  Recurso: {selectedApproval.resource_type} {selectedApproval.resource_id ? `(${selectedApproval.resource_id})` : ''}
                </p>
              </div>

              <div>
                <label className="mb-1 block text-sm font-medium text-tx-secondary">
                  Motivo da revisão (opcional)
                </label>
                <textarea
                  value={reviewReason}
                  onChange={(event) => setReviewReason(event.target.value)}
                  rows={4}
                  placeholder="Descreva o contexto da decisão para auditoria."
                  className="w-full resize-none rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-emerald-500 focus:outline-none"
                />
              </div>
            </div>

            <div className="mt-5 flex gap-3 border-t border-border-DEFAULT pt-5">
              <button
                onClick={closeDecisionModal}
                className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
                disabled={approveMutation.isPending || rejectMutation.isPending}
              >
                Cancelar
              </button>
              <button
                onClick={confirmDecision}
                disabled={approveMutation.isPending || rejectMutation.isPending}
                className={`flex-1 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition-colors ${
                  decisionType === 'approve'
                    ? 'bg-emerald-500 hover:bg-emerald-600'
                    : 'bg-red-500 hover:bg-red-600'
                } disabled:opacity-50`}
              >
                {approveMutation.isPending || rejectMutation.isPending
                  ? 'Processando...'
                  : decisionType === 'approve'
                    ? 'Confirmar aprovação'
                    : 'Confirmar rejeição'}
              </button>
            </div>
          </div>
        </div>
      )}
    </AdminPageShell>
  );
}
