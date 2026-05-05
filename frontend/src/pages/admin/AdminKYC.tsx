import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatDate } from '@/lib/api';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import {
  DocumentCheckIcon,
  MagnifyingGlassIcon,
  CheckCircleIcon,
  XCircleIcon,
  ClockIcon,
  EyeIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  FunnelIcon,
  ArrowPathIcon,
  XMarkIcon,
  DocumentTextIcon,
  UserIcon,
  MapPinIcon,
  PhoneIcon,
  BuildingOfficeIcon,
  IdentificationIcon,
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
  user: {
    id: string;
    name: string;
    email: string;
  };
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

  // Fetch KYC submissions
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

  const statusCounts = {
    pending: kycData?.meta?.counts?.pending ?? submissions.filter((s: KYCSubmission) => s.status === 'pending').length,
    under_review: kycData?.meta?.counts?.under_review ?? submissions.filter((s: KYCSubmission) => s.status === 'under_review').length,
    approved: kycData?.meta?.counts?.approved ?? submissions.filter((s: KYCSubmission) => s.status === 'approved').length,
    rejected: kycData?.meta?.counts?.rejected ?? submissions.filter((s: KYCSubmission) => s.status === 'rejected').length,
  };

  // Fetch submission detail with documents
  const fetchSubmissionDetail = async (submission: KYCSubmission) => {
    try {
      const response = await api.get(`/admin/kyc/${submission.id}/documents`);
      setDocuments(response.data?.data || []);
      setSelectedSubmission(submission);
      setShowDetailModal(true);
    } catch (error) {
      toast.error('Erro ao carregar documentos');
    }
  };

  // Approve KYC
  const approveMutation = useMutation({
    mutationFn: async (submissionId: string) => {
      await api.post(`/admin/kyc/${submissionId}/approve`);
    },
    onSuccess: () => {
      toast.success('KYC aprovado com sucesso');
      queryClient.invalidateQueries({ queryKey: ['admin-kyc'] });
      setShowDetailModal(false);
    },
    onError: () => {
      toast.error('Erro ao aprovar KYC');
    },
  });

  // Reject KYC
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
    onError: () => {
      toast.error('Erro ao rejeitar KYC');
    },
  });

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'approved':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-500">
            <CheckCircleIcon className="h-3.5 w-3.5" />
            Aprovado
          </span>
        );
      case 'pending':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-500">
            <ClockIcon className="h-3.5 w-3.5" />
            Pendente
          </span>
        );
      case 'under_review':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-2.5 py-1 text-xs font-medium text-blue-500">
            <EyeIcon className="h-3.5 w-3.5" />
            Em Análise
          </span>
        );
      case 'rejected':
        return (
          <span className="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2.5 py-1 text-xs font-medium text-red-500">
            <XCircleIcon className="h-3.5 w-3.5" />
            Rejeitado
          </span>
        );
      default:
        return null;
    }
  };

  const getDocumentTypeName = (type: string) => {
    const types: Record<string, string> = {
      document_front: 'Documento (Frente)',
      document_back: 'Documento (Verso)',
      selfie: 'Selfie',
      proof_of_address: 'Comprovante de Endereço',
      cnpj_card: 'Cartão CNPJ',
      social_contract: 'Contrato Social',
    };
    return types[type] || type;
  };

  // Generate signed URL for document
  const openDocument = async (document: KYCDocument) => {
    try {
      const response = await api.post(`/admin/kyc/documents/${document.id}/signed-url`);
      const url = response.data?.data?.url || response.data?.url || document.file_url;
      window.open(url, '_blank');
    } catch {
      window.open(document.file_url, '_blank');
    }
  };

  return (
    <AdminPageShell>
      <AdminPageHeader
        icon={DocumentCheckIcon}
        title="Documentos KYC"
        subtitle="Analise e aprove documentos de verificacao"
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

      {/* Stats Cards */}
      <div className="grid gap-4 sm:grid-cols-4">
        {[
          { label: 'Pendentes', value: statusCounts.pending, color: 'amber' },
          { label: 'Em Análise', value: statusCounts.under_review, color: 'blue' },
          { label: 'Aprovados', value: statusCounts.approved, color: 'emerald' },
          { label: 'Rejeitados', value: statusCounts.rejected, color: 'red' },
        ].map((stat) => (
          <div
            key={stat.label}
            className={`rounded-xl bg-${stat.color}-500/10 p-4`}
          >
            <p className={`text-xs text-${stat.color}-600 mb-1`}>{stat.label}</p>
            <p className={`text-2xl font-bold text-${stat.color}-600`}>{stat.value}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="rounded-2xl bg-surface-card p-4 shadow-sm">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center">
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
              <option value="pending">Pendentes</option>
              <option value="under_review">Em Análise</option>
              <option value="approved">Aprovados</option>
              <option value="rejected">Rejeitados</option>
            </select>
          </div>
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl bg-surface-card shadow-sm overflow-hidden">
        {isLoading ? (
          <div className="flex items-center justify-center py-20">
            <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent" />
          </div>
        ) : submissions.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20">
            <DocumentCheckIcon className="h-12 w-12 text-tx-muted" />
            <p className="mt-4 text-sm text-tx-muted">Nenhuma submissão encontrada</p>
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
                      Tipo
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Documento
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Status
                    </th>
                    <th className="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Enviado
                    </th>
                    <th className="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-tx-muted">
                      Ações
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border-DEFAULT">
                  {submissions.map((submission: KYCSubmission) => (
                    <tr
                      key={submission.id}
                      className="hover:bg-surface-hover transition-colors"
                    >
                      <td className="px-6 py-4">
                        <div>
                          <p className="text-sm font-medium text-tx-primary">{submission.full_name}</p>
                          <p className="text-xs text-tx-muted">{submission.user?.email}</p>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${
                          submission.account_type === 'company'
                            ? 'bg-blue-500/10 text-blue-500'
                            : 'bg-green-500/10 text-green-500'
                        }`}>
                          {submission.account_type === 'company' ? 'Empresa' : 'Pessoa Física'}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-tx-primary font-mono">
                          {submission.document_number}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        {getStatusBadge(submission.status)}
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-sm text-tx-muted">
                          {formatDate(submission.created_at)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center justify-end gap-2">
                          <button
                            onClick={() => fetchSubmissionDetail(submission)}
                            className="rounded-lg bg-green-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-600 transition-colors"
                          >
                            Analisar
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
                  Página {pagination.current_page} de {pagination.last_page}
                </p>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setPage(page - 1)}
                    disabled={page === 1}
                    className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover disabled:opacity-50"
                  >
                    <ChevronLeftIcon className="h-5 w-5" />
                  </button>
                  <button
                    onClick={() => setPage(page + 1)}
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

      {/* Detail Modal */}
      {showDetailModal && selectedSubmission && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
          <div className="w-full max-w-4xl rounded-2xl bg-surface-card p-6 shadow-xl my-8">
            <div className="flex items-center justify-between mb-6">
              <div>
                <h2 className="text-xl font-bold text-tx-primary">Análise KYC</h2>
                <p className="text-sm text-tx-muted">{selectedSubmission.user?.email}</p>
              </div>
              <button
                onClick={() => setShowDetailModal(false)}
                className="rounded-lg p-2 text-tx-muted hover:bg-surface-hover"
              >
                <XMarkIcon className="h-5 w-5" />
              </button>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
              {/* Personal Info */}
              <div className="space-y-4">
                <h3 className="text-sm font-semibold text-tx-primary flex items-center gap-2">
                  <UserIcon className="h-4 w-4" />
                  Dados Pessoais
                </h3>
                <div className="space-y-3 rounded-xl bg-surface-page p-4">
                  <div>
                    <p className="text-xs text-tx-muted">Nome Completo</p>
                    <p className="text-sm font-medium text-tx-primary">{selectedSubmission.full_name}</p>
                  </div>
                  <div>
                    <p className="text-xs text-tx-muted">CPF/CNPJ</p>
                    <p className="text-sm font-medium text-tx-primary font-mono">{selectedSubmission.document_number}</p>
                  </div>
                  <div>
                    <p className="text-xs text-tx-muted">Data de Nascimento</p>
                    <p className="text-sm font-medium text-tx-primary">{formatDate(selectedSubmission.birth_date)}</p>
                  </div>
                  {selectedSubmission.phone && (
                    <div>
                      <p className="text-xs text-tx-muted">Telefone</p>
                      <div className="flex items-center gap-2">
                        <p className="text-sm font-medium text-tx-primary">{selectedSubmission.phone}</p>
                        <a
                          href={`https://wa.me/55${selectedSubmission.phone.replace(/\D/g, '')}?text=${encodeURIComponent(
`Olá, ${selectedSubmission.full_name?.split(' ')[0]}! 👋

Sua conta foi *aprovada com sucesso* na *Vector Pagamentos*! ✅

Gostaríamos de agradecer seu interesse em utilizar nossa plataforma de pagamentos. Estamos muito felizes em ter você conosco!

🚀 *O que você pode fazer agora:*
• Criar seus produtos e checkouts
• Receber pagamentos via PIX e Cartão
• Acompanhar suas vendas em tempo real

Oferecemos *suporte exclusivo de integração* com nossos desenvolvedores para ajudá-lo a configurar tudo da melhor forma.

Se precisar de qualquer ajuda, estamos à disposição! 💜

— Equipe Vector Pagamentos`)}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1 rounded-lg bg-green-500 px-2 py-1 text-[10px] font-semibold text-white hover:bg-green-600 transition-colors"
                          title="Enviar mensagem no WhatsApp"
                        >
                          <svg className="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                          </svg>
                          WhatsApp
                        </a>
                      </div>
                    </div>
                  )}
                </div>

                {selectedSubmission.account_type === 'company' && (
                  <>
                    <h3 className="text-sm font-semibold text-tx-primary flex items-center gap-2">
                      <BuildingOfficeIcon className="h-4 w-4" />
                      Dados da Empresa
                    </h3>
                    <div className="space-y-3 rounded-xl bg-surface-page p-4">
                      <div>
                        <p className="text-xs text-tx-muted">Razão Social</p>
                        <p className="text-sm font-medium text-tx-primary">{selectedSubmission.company_name}</p>
                      </div>
                      <div>
                        <p className="text-xs text-tx-muted">CNPJ</p>
                        <p className="text-sm font-medium text-tx-primary font-mono">{selectedSubmission.company_document}</p>
                      </div>
                    </div>
                  </>
                )}

                <h3 className="text-sm font-semibold text-tx-primary flex items-center gap-2">
                  <MapPinIcon className="h-4 w-4" />
                  Endereço
                </h3>
                <div className="space-y-3 rounded-xl bg-surface-page p-4">
                  <p className="text-sm text-tx-primary">
                    {selectedSubmission.address_street}, {selectedSubmission.address_number}
                    {selectedSubmission.address_complement && ` - ${selectedSubmission.address_complement}`}
                  </p>
                  <p className="text-sm text-tx-primary">
                    {selectedSubmission.address_neighborhood}
                  </p>
                  <p className="text-sm text-tx-primary">
                    {selectedSubmission.address_city} - {selectedSubmission.address_state}
                  </p>
                  <p className="text-sm text-tx-muted font-mono">
                    CEP: {selectedSubmission.address_zip_code}
                  </p>
                </div>
              </div>

              {/* Documents */}
              <div className="space-y-4">
                <h3 className="text-sm font-semibold text-tx-primary flex items-center gap-2">
                  <DocumentTextIcon className="h-4 w-4" />
                  Documentos Enviados
                </h3>
                <div className="space-y-3">
                  {documents.length === 0 ? (
                    <div className="rounded-xl bg-surface-page p-8 text-center">
                      <DocumentTextIcon className="mx-auto h-10 w-10 text-tx-muted" />
                      <p className="mt-2 text-sm text-tx-muted">Nenhum documento enviado</p>
                    </div>
                  ) : (
                    documents.map((doc) => (
                      <div
                        key={doc.id}
                        className="flex items-center justify-between rounded-xl bg-surface-page p-4"
                      >
                        <div className="flex items-center gap-3">
                          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-green-500/10">
                            <IdentificationIcon className="h-5 w-5 text-green-500" />
                          </div>
                          <div>
                            <p className="text-sm font-medium text-tx-primary">
                              {getDocumentTypeName(doc.document_type)}
                            </p>
                            <p className="text-xs text-tx-muted">{doc.file_name}</p>
                          </div>
                        </div>
                        <button
                          onClick={() => openDocument(doc)}
                          className="flex items-center gap-1 rounded-lg bg-surface-hover px-3 py-1.5 text-xs font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
                        >
                          <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                          Ver
                        </button>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>

            {/* Actions */}
            {(selectedSubmission.status === 'pending' || selectedSubmission.status === 'under_review') && (
              <div className="flex gap-3 pt-6 mt-6 border-t border-border-DEFAULT">
                <button
                  onClick={() => approveMutation.mutate(selectedSubmission.id)}
                  disabled={approveMutation.isPending}
                  className="flex-1 rounded-lg bg-emerald-500 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-600 disabled:opacity-50 transition-colors"
                >
                  {approveMutation.isPending ? 'Aprovando...' : 'Aprovar KYC'}
                </button>
                <button
                  onClick={() => setShowRejectModal(true)}
                  className="flex-1 rounded-lg bg-red-500 px-4 py-3 text-sm font-semibold text-white hover:bg-red-600 transition-colors"
                >
                  Rejeitar KYC
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Reject Modal */}
      {showRejectModal && selectedSubmission && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-md rounded-2xl bg-surface-card p-6 shadow-xl">
            <h3 className="text-lg font-bold text-tx-primary mb-4">Rejeitar KYC</h3>
            <p className="text-sm text-tx-muted mb-4">
              Informe o motivo da rejeição. O cliente será notificado.
            </p>
            <textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              placeholder="Motivo da rejeição..."
              rows={4}
              className="w-full rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-3 text-sm text-tx-primary placeholder:text-tx-muted focus:border-green-500 focus:outline-none focus:ring-1 focus:ring-green-500"
            />
            <div className="flex gap-3 mt-4">
              <button
                onClick={() => {
                  setShowRejectModal(false);
                  setRejectReason('');
                }}
                className="flex-1 rounded-lg bg-surface-hover px-4 py-2.5 text-sm font-medium text-tx-primary hover:bg-border-DEFAULT transition-colors"
              >
                Cancelar
              </button>
              <button
                onClick={() => rejectMutation.mutate({ submissionId: selectedSubmission.id, reason: rejectReason })}
                disabled={!rejectReason.trim() || rejectMutation.isPending}
                className="flex-1 rounded-lg bg-red-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-600 disabled:opacity-50 transition-colors"
              >
                {rejectMutation.isPending ? 'Rejeitando...' : 'Confirmar Rejeição'}
              </button>
            </div>
          </div>
        </div>
      )}
    </AdminPageShell>
  );
}


