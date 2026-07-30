import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  MagnifyingGlassIcon,
  EyeIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ArrowPathIcon,
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

  const statusLabel: Record<string, { text: string; cls: string }> = {
    active: { text: 'Ativo', cls: 'bg-emerald-50 text-emerald-700' },
    suspended: { text: 'Suspenso', cls: 'bg-amber-50 text-amber-700' },
    blocked: { text: 'Bloqueado', cls: 'bg-red-50 text-red-700' },
  };

  const kycLabel: Record<string, { text: string; cls: string }> = {
    approved: { text: 'Aprovado', cls: 'bg-emerald-50 text-emerald-700' },
    pending: { text: 'Pendente', cls: 'bg-amber-50 text-amber-700' },
    rejected: { text: 'Rejeitado', cls: 'bg-red-50 text-red-700' },
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Sellers"
        subtitle="Gerencie todos os clientes da plataforma"
        actions={
          <button
            onClick={() => refetch()}
            className="flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            <ArrowPathIcon className="h-4 w-4" />
            Atualizar
          </button>
        }
      />

      {/* Filters */}
      <div className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-3 sm:flex-row sm:items-center">
        <div className="relative flex-1">
          <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Buscar por nome ou email..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="w-full rounded-md border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none focus:ring-0"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          className="rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 focus:border-gray-400 focus:outline-none"
        >
          <option value="all">Todos os status</option>
          <option value="active">Ativos</option>
          <option value="suspended">Suspensos</option>
          <option value="blocked">Bloqueados</option>
        </select>
        <select
          value={kycFilter}
          onChange={(e) => { setKycFilter(e.target.value); setPage(1); }}
          className="rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 focus:border-gray-400 focus:outline-none"
        >
          <option value="all">Todos KYC</option>
          <option value="approved">KYC Aprovado</option>
          <option value="pending">KYC Pendente</option>
          <option value="rejected">KYC Rejeitado</option>
        </select>
      </div>

      {/* Table */}
      <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <div className="h-6 w-6 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
          </div>
        ) : customers.length === 0 ? (
          <div className="py-16 text-center text-sm text-gray-500">Nenhum cliente encontrado</div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-100 bg-gray-50">
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Cliente</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">KYC</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Saldo</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Cadastro</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Ações</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {customers.map((customer: Customer) => (
                    <tr
                      key={customer.id}
                      onClick={() => navigate(`/admin/sellers/${customer.id}`)}
                      className="cursor-pointer hover:bg-gray-50"
                    >
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-3">
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-gray-100 to-gray-200 text-xs font-semibold text-gray-600">
                            {(customer.name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?'}
                          </div>
                          <div className="min-w-0">
                            <p className="truncate font-medium text-gray-900">{customer.name || 'Sem nome'}</p>
                            <p className="truncate text-xs text-gray-500">{customer.email}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${statusLabel[customer.status]?.cls || 'bg-gray-100 text-gray-600'}`}>
                          {statusLabel[customer.status]?.text || customer.status}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${kycLabel[customer.kyc_status]?.cls || 'bg-gray-100 text-gray-600'}`}>
                          {kycLabel[customer.kyc_status]?.text || customer.kyc_status}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-right font-medium tabular-nums text-gray-900">
                        {formatCurrency(customer.available_balance || 0)}
                      </td>
                      <td className="px-4 py-3 text-gray-500">{formatDate(customer.created_at)}</td>
                      <td className="px-4 py-3">
                        <div className="flex items-center justify-end gap-1">
                          <button
                            onClick={(e) => { e.stopPropagation(); navigate(`/admin/sellers/${customer.id}`); }}
                            className="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                            title="Ver detalhes"
                          >
                            <EyeIcon className="h-4 w-4" />
                          </button>
                          <button
                            onClick={(e) => { e.stopPropagation(); recalculateBalanceMutation.mutate(customer.id); }}
                            className="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
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

            {pagination.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3">
                <p className="text-xs text-gray-500">
                  Página {pagination.current_page} de {pagination.last_page} ({pagination.total} clientes)
                </p>
                <div className="flex items-center gap-1">
                  <button
                    onClick={() => setPage(page - 1)}
                    disabled={page === 1}
                    className="rounded p-1.5 text-gray-400 hover:bg-gray-100 disabled:opacity-40"
                  >
                    <ChevronLeftIcon className="h-4 w-4" />
                  </button>
                  <button
                    onClick={() => setPage(page + 1)}
                    disabled={page >= pagination.last_page}
                    className="rounded p-1.5 text-gray-400 hover:bg-gray-100 disabled:opacity-40"
                  >
                    <ChevronRightIcon className="h-4 w-4" />
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
