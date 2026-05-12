import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  MagnifyingGlassIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ArrowPathIcon,
  XMarkIcon,
  ArrowTopRightOnSquareIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';

interface KYCSubmission {
  id: string;
  user_id: string;
  full_name: string;
  document_number: string;
  birth_date: string;
  phone: string | null;
  address_street: string;
  address_number: string;
  address_complement: string | null;
  address_neighborhood: string;
  address_city: string;
  address_state: string;
  address_zip_code: string;
  account_type: 'individual' | 'company';
  company_name: string | null;
  company_document: string | null;
  status: 'pending' | 'approved' | 'rejected' | 'under_review';
  created_at: string;
  reviewed_at: string | null;
  user: { id: string; name: string; email: string };
}

interface KYCDocument {
  id: string;
  user_id: string;
  document_type: string;
  file_path: string;
  file_name: string;
  file_url: string;
  mime_type: string;
  status: 'pending' | 'approved' | 'rejected';
  created_at: string;
}

export default function AdminKYC() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('pending');
  const [page, setPage] = useState(1);
  const [selectedSubmission, setSelectedSubmission] = useState<KYCSubmission | null>(null);
  const [documents, setDocuments] = useState<KYCDocument[]>([]);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const [showRejectModal, setShowRejectModal] = useState(false);

  const { data: kycData, isLoading, refetch } = useQuery({
    queryKey: ['admin-kyc', search, statusFilter, page],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.append('page', page.toString());
      params.append('per_page', '15');
      if (search) params.append('search', search);
      if (statusFilter !== 'all') params.append('status', statusFilter);
      const response = await api.get(`/admin/kyc?${params.toString()}`);
      return response.data;
    },
  });

  const submissions = kycData?.data || [];
  const pagination = kycData?.meta || { current_page: 1, last_page: 1, total: 0 };

  const fetchSubmissionDetail = async (submission: KYCSubmission) => {
    try {
      const response = await api.get(`/admin/kyc/${submission.id}/documents`);
      setDocuments(response.data?.data || []);
      setSelectedSubmission(submission);
      setShowDetailModal(true);
    } catch {
      toast.error('Erro ao carregar documentos');
    }
  };

  const approveMutation = useMutation({
    mutationFn: async (submissionId: string) => {
      await api.post(`/admin/kyc/${submissionId}/approve`);
    },
    onSuccess: () => {
      toast.success('KYC aprovado');
      queryClient.invalidateQueries({ queryKey: ['admin-kyc'] });
      setShowDetailModal(false);
    },
    onError: () => toast.error('Erro ao aprovar KYC'),
  });

  const rejectMutation = useMutation({
    mutationFn: async ({ submissionId, reason }: { submissionId: string; reason: string }) => {
      await api.post(`/admin/kyc/${submissionId}/reject`, { reason });
    },
    onSuccess: () => {
      toast.success('KYC rejeitado');
      queryClient.invalidateQueries({ queryKey: ['admin-kyc'] });
      setShowDetailModal(false);
      setShowRejectModal(false);
      setRejectReason('');
    },
    onError: () => toast.error('Erro ao rejeitar KYC'),
  });

  const statusBadge: Record<string, { text: string; cls: string }> = {
    approved: { text: 'Aprovado', cls: 'bg-emerald-50 text-emerald-700' },
    pending: { text: 'Pendente', cls: 'bg-amber-50 text-amber-700' },
    under_review: { text: 'Em Análise', cls: 'bg-blue-50 text-blue-700' },
    rejected: { text: 'Rejeitado', cls: 'bg-red-50 text-red-700' },
  };

  const docTypeNames: Record<string, string> = {
    document_front: 'Documento (Frente)',
    document_back: 'Documento (Verso)',
    selfie: 'Selfie',
    proof_of_address: 'Comprovante de Endereço',
    cnpj_card: 'Cartão CNPJ',
    social_contract: 'Contrato Social',
  };

  const openDocument = async (doc: KYCDocument) => {
    try {
      const response = await api.post(`/admin/kyc/documents/${doc.id}/signed-url`);
      const url = response.data?.data?.url || response.data?.url || doc.file_url;
      window.open(url, '_blank');
    } catch {
      window.open(doc.file_url, '_blank');
    }
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="KYC"
        subtitle="Análise e aprovação de documentos de verificação"
        actions={
          <button onClick={() => refetch()} className="flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <ArrowPathIcon className="h-4 w-4" /> Atualizar
          </button>
        }
      />

      {/* Filters */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="relative flex-1">
          <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Buscar por nome ou email..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="w-full rounded-md border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          className="rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 focus:border-gray-400 focus:outline-none"
        >
          <option value="all">Todos</option>
          <option value="pending">Pendentes</option>
          <option value="under_review">Em Análise</option>
          <option value="approved">Aprovados</option>
          <option value="rejected">Rejeitados</option>
        </select>
      </div>

      {/* Table */}
      <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
        {isLoading ? (
          <div className="flex items-center justify-center py-16">
            <div className="h-6 w-6 animate-spin rounded-full border-2 border-gray-900 border-t-transparent" />
          </div>
        ) : submissions.length === 0 ? (
          <div className="py-16 text-center text-sm text-gray-500">Nenhuma submissão encontrada</div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-gray-100 bg-gray-50">
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Cliente</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Tipo</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Documento</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Status</th>
                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500">Enviado</th>
                    <th className="px-4 py-3 text-right text-xs font-medium text-gray-500">Ação</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {submissions.map((s: KYCSubmission) => (
                    <tr key={s.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <p className="font-medium text-gray-900">{s.full_name}</p>
                        <p className="text-xs text-gray-500">{s.user?.email}</p>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${s.account_type === 'company' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-700'}`}>
                          {s.account_type === 'company' ? 'PJ' : 'PF'}
                        </span>
                      </td>
                      <td className="px-4 py-3 font-mono text-xs text-gray-700">{s.document_number}</td>
                      <td className="px-4 py-3">
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusBadge[s.status]?.cls || 'bg-gray-100 text-gray-600'}`}>
                          {statusBadge[s.status]?.text || s.status}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-gray-500">{formatDate(s.created_at)}</td>
                      <td className="px-4 py-3 text-right">
                        <button
                          onClick={() => fetchSubmissionDetail(s)}
                          className="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-800"
                        >
                          Analisar
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {pagination.last_page > 1 && (
              <div className="flex items-center justify-between border-t border-gray-100 px-4 py-3">
                <p className="text-xs text-gray-500">Página {pagination.current_page} de {pagination.last_page}</p>
                <div className="flex gap-1">
                  <button onClick={() => setPage(page - 1)} disabled={page === 1} className="rounded p-1.5 text-gray-400 hover:bg-gray-100 disabled:opacity-40">
                    <ChevronLeftIcon className="h-4 w-4" />
                  </button>
                  <button onClick={() => setPage(page + 1)} disabled={page >= pagination.last_page} className="rounded p-1.5 text-gray-400 hover:bg-gray-100 disabled:opacity-40">
                    <ChevronRightIcon className="h-4 w-4" />
                  </button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* Detail Modal */}
      {showDetailModal && selectedSubmission && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
          <div className="w-full max-w-2xl rounded-lg bg-white shadow-xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
              <h2 className="text-lg font-semibold text-gray-900">Análise KYC</h2>
              <button onClick={() => setShowDetailModal(false)} className="rounded p-1 text-gray-400 hover:text-gray-600">
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="p-6 space-y-6">
              {/* Info */}
              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <p className="text-xs text-gray-500">Nome</p>
                  <p className="text-sm font-medium text-gray-900">{selectedSubmission.full_name}</p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Documento</p>
                  <p className="text-sm font-mono text-gray-900">{selectedSubmission.document_number}</p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Telefone</p>
                  <p className="text-sm text-gray-900">{selectedSubmission.phone || '-'}</p>
                </div>
                <div>
                  <p className="text-xs text-gray-500">Tipo</p>
                  <p className="text-sm text-gray-900">{selectedSubmission.account_type === 'company' ? 'Pessoa Jurídica' : 'Pessoa Física'}</p>
                </div>
                <div className="sm:col-span-2">
                  <p className="text-xs text-gray-500">Endereço</p>
                  <p className="text-sm text-gray-900">
                    {selectedSubmission.address_street}, {selectedSubmission.address_number}
                    {selectedSubmission.address_complement ? ` - ${selectedSubmission.address_complement}` : ''}, {selectedSubmission.address_neighborhood}, {selectedSubmission.address_city}/{selectedSubmission.address_state} - {selectedSubmission.address_zip_code}
                  </p>
                </div>
              </div>

              {/* Documents */}
              <div>
                <h3 className="text-sm font-medium text-gray-900 mb-3">Documentos</h3>
                {documents.length === 0 ? (
                  <p className="text-sm text-gray-500">Nenhum documento enviado</p>
                ) : (
                  <div className="space-y-2">
                    {documents.map((doc) => (
                      <div key={doc.id} className="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2">
                        <div>
                          <p className="text-sm font-medium text-gray-900">{docTypeNames[doc.document_type] || doc.document_type}</p>
                          <p className="text-xs text-gray-500">{doc.file_name}</p>
                        </div>
                        <button onClick={() => openDocument(doc)} className="rounded p-1.5 text-gray-400 hover:text-gray-700">
                          <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              {/* Actions */}
              {(selectedSubmission.status === 'pending' || selectedSubmission.status === 'under_review') && (
                <div className="flex gap-3 border-t border-gray-200 pt-4">
                  <button
                    onClick={() => approveMutation.mutate(selectedSubmission.id)}
                    disabled={approveMutation.isPending}
                    className="flex-1 rounded-md bg-emerald-600 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                  >
                    Aprovar
                  </button>
                  <button
                    onClick={() => setShowRejectModal(true)}
                    className="flex-1 rounded-md border border-red-200 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                  >
                    Rejeitar
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Reject Modal */}
      {showRejectModal && selectedSubmission && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/30 p-4">
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h3 className="text-lg font-semibold text-gray-900">Motivo da rejeição</h3>
            <textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              placeholder="Descreva o motivo..."
              rows={3}
              className="mt-3 w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none"
            />
            <div className="mt-4 flex gap-3">
              <button
                onClick={() => { setShowRejectModal(false); setRejectReason(''); }}
                className="flex-1 rounded-md border border-gray-200 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                Cancelar
              </button>
              <button
                onClick={() => rejectMutation.mutate({ submissionId: selectedSubmission.id, reason: rejectReason })}
                disabled={!rejectReason.trim() || rejectMutation.isPending}
                className="flex-1 rounded-md bg-red-600 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
              >
                Confirmar Rejeição
              </button>
            </div>
          </div>
        </div>
      )}
    </AdminPageShell>
  );
}
