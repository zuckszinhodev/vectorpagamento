import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  UsersIcon,
  MagnifyingGlassIcon,
  CheckCircleIcon,
  XCircleIcon,
  PauseCircleIcon,
  EyeIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  FunnelIcon,
  ArrowPathIcon,
  ShieldCheckIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface Customer {
  id: string;
  name: string;
  email: string;
  status: 'active' | 'suspended' | 'blocked';
  available_balance: number;
  kyc_status: 'pending' | 'approved' | 'rejected';
  created_at: string;
  account_id?: string;
}

export default function AdminCustomers() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [kycFilter, setKycFilter] = useState<string>('all');
  const [page, setPage] = useState(1);

  // Fetch customers
  const { data: customersData, isLoading, refetch } = useQuery({
    queryKey: ['admin-customers', search, statusFilter, kycFilter, page],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      params.append('per_page', '15');
      if (search) params.append('search', search);
      if (statusFilter !== 'all') params.append('status', statusFilter);
      if (kycFilter !== 'all') params.append('kyc_status', kycFilter);
      
      const response = await api.get(`/admin/customers?${params.toString()}`);
      return response.data;
    },
  });

  const customers = customersData?.data || [];
  const pagination = customersData?.meta || { current_page: 1, last_page: 1, total: 0 };

  // Recalculate balance
  const recalculateBalanceMutation = useMutation({
    mutationFn: async (customerId: string) => {
      await api.post(`/admin/customers/${customerId}/recalculate-balance`);
    },
    onSuccess: () => {
      toast.success('Saldo recalculado com sucesso');
      queryClient.invalidateQueries({ queryKey: ['admin-customers'] });
    },
    onError: () => {
      toast.error('Erro ao recalcular saldo');
    },
  });

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'active':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-500">
            <CheckCircleIcon className="h-3.5 w-3.5" />
            Ativo
          </span>
        );
      case 'suspended':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-500">
            <PauseCircleIcon className="h-3.5 w-3.5" />
            Suspenso
          </span>
        );
      case 'blocked':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-medium text-red-500">
            <XCircleIcon className="h-3.5 w-3.5" />
            Bloqueado
          </span>
        );
      default:
        return null;
    }
  };

  const getKycBadge = (status: string) => {
    switch (status) {
      case 'approved':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-500">
            <ShieldCheckIcon className="h-3.5 w-3.5" />
            Aprovado
          </span>
        );
      case 'pending':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-500">
            Pendente
          </span>
        );
      case 'rejected':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-medium text-red-500">
            Rejeitado
          </span>
        );
      default:
        return <span className="text-xs text-tx-muted">-</span>;
    }
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={UsersIcon}
        title="Clientes"
        subtitle="Gerencie todos os clientes da plataforma"
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

      {/* Filters */}
      <div className="rounded-2xl bg-surface-card p-4 shadow-sm">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
          {/* Search */}
          <div className="relative flex-1">
            <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
            <input
              type="text"
              placeholder="Buscar por nome ou email..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page pl-10 pr-4 py-2.5 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none focus:ring-1 focus:ring-green-500"
            />
          </div>

          {/* Status Filter */}
          <div className="flex items-center gap-2">
            <FunnelIcon className="h-4 w-4 text-tx-muted" />
            <select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
              className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
            >
              <option value="all">Todos os status</option>
              <option value="active">Ativos</option>
              <option value="suspended">Suspensos</option>
              <option value="blocked">Bloqueados</option>
            </select>
          </div>

          {/* KYC Filter */}
          <select
            value={kycFilter}
            onChange={(e) => {
              setKycFilter(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-border-DEFAULT bg-surface-page px-3 py-2.5 text-sm text-tx-primary focus:border-green-500 focus:outline-none"
          >
            <option value="all">Todos KYC</option>
            <option value="approved">KYC Aprovado</option>
            <option value="pending">KYC Pendente</option>
            <option value="rejected">KYC Rejeitado</option>
          </select>
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl bg-surface-card shadow-sm overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
          </div>
        ) : customers.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20">
            <UsersIcon className="h-12 w-12 text-tx-muted" />
            <p className="mt-4 text-sm text-tx-muted">Nenhum cliente encontrado</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b border-border-DEFAULT bg-surface-page">
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Cliente
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Status
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      KYC
                    </th>
                    <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Saldo
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Cadastro
                    </th>
                    <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Ações
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-DEFAULT">
                  {customers.map((customer: Customer) => (
                    <tr
                      key={customer.id}
                      className="hover:bg-surface-hover transition-colors"
                    >
                      <td className="px-6 py-4">
                        <div>
                          <p className="text-sm font-medium text-tx-primary">{customer.name}</p>
                          <p className="text-xs text-tx-muted">{customer.email}</p>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        {getStatusBadge(customer.status)}
                      </td>
                      <td className="px-6 py-4">
                        {getKycBadge(customer.kyc_status)}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <span className="text-sm font-semibold text-tx-primary">
                          {formatCurrency(customer.available_balance || 0)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-tx-muted">
                          {formatDate(customer.created_at)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            onClick={() => navigate(`/admin/sellers/${customer.id}`)}
                            className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover hover:text-green-500 transition-colors"
                            title="Ver detalhes"
                          >
                            <EyeIcon className="h-4 w-4" />
                          </button>
                          <button
                            onClick={() => recalculateBalanceMutation.mutate(customer.id)}
                            className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover hover:text-emerald-500 transition-colors"
                            title="Recalcular saldo"
                          >
                            <ArrowPathIcon className="h-4 w-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {pagination.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-border-DEFAULT px-6 py-4">
                <p className="text-sm text-tx-muted">
                  Mostrando página {pagination.current_page} de {pagination.last_page} ({pagination.total} clientes)
                </p>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setPage(page - 1)}
                    disabled={page === 1}
                    className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <ChevronLeftIcon className="h-5 w-5" />
                  </button>
                  <button
                    onClick={() => setPage(page + 1)}
                    disabled={page >= pagination.last_page}
                    className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <ChevronRightIcon className="h-5 w-5" />
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </AdminPageShell>
  );
}


