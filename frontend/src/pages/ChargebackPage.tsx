import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatCurrency, formatDate } from '@/lib/api';
import { Dialog, Transition } from '@headlessui/react';
import { Fragment } from 'react';
import toast from 'react-hot-toast';
import {
  MagnifyingGlassIcon,
  ArrowPathIcon,
  XMarkIcon,
  EyeIcon,
  ExclamationTriangleIcon,
  ArrowUturnLeftIcon,
  BanknotesIcon,
  DocumentTextIcon,
  ShieldExclamationIcon,
  PhotoIcon,
  DocumentIcon,
  PaperClipIcon,
  ClockIcon,
} from '@heroicons/react/24/outline';

interface Chargeback {
  id: string;
  charge_id: string;
  account_id: string;
  amount: number;
  status: string;
  reason_code?: string;
  reason?: string;
  acquirer_reference?: string;
  evidence?: any;
  deadline_at?: string;
  resolved_at?: string;
  outcome?: string;
  created_at: string;
  updated_at: string;
  charge?: {
    id: string;
    amount: number;
    currency: string;
    status: string;
    payment_method: string;
    customer: {
      email: string;
      name: string;
    };
    acquirer?: string;
    acquirer_reference?: string;
    description?: string;
    created_at: string;
    paid_at?: string;
  };
}

interface ChargebackFilters {
  status?: string;
  date_from?: string;
  date_to?: string;
  search?: string;
}

function StatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    open: 'bg-amber-100 text-amber-700',
    evidence_sent: 'bg-blue-100 text-blue-700',
    under_review: 'bg-blue-100 text-blue-700',
    won: 'bg-status-success-subtle text-status-success',
    lost: 'bg-status-error-subtle text-status-error',
    expired: 'bg-gray-100 text-gray-700',
  };

  const labels: Record<string, string> = {
    open: 'Aberto',
    evidence_sent: 'Analisando Caso',
    under_review: 'Em Análise',
    won: 'Ganho',
    lost: 'Perdido',
    expired: 'Expirado',
  };

  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${styles[status] || 'bg-surface-hover text-tx-secondary'}`}>
      {status === 'open' && <ExclamationTriangleIcon className="h-3 w-3" />}
      {labels[status] || status}
    </span>
  );
}

function DisputeModal({
  chargeback,
  isOpen,
  onClose,
  onSubmit,
}: {
  chargeback: Chargeback | null;
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (data: any) => void;
}) {
  const [formData, setFormData] = useState({
    description: '',
    tracking_number: '',
    delivery_proof: '',
    images: [] as File[],
  });

  // Reset form when modal closes
  const handleClose = () => {
    setFormData({
      description: '',
      tracking_number: '',
      delivery_proof: '',
      images: [],
    });
    onClose();
  };

  const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      const newFiles = Array.from(e.target.files);
      setFormData({ ...formData, images: [...formData.images, ...newFiles] });
    }
  };

  const removeImage = (index: number) => {
    const newImages = formData.images.filter((_, i) => i !== index);
    setFormData({ ...formData, images: newImages });
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(formData);
  };

  return (
    <Transition.Root show={isOpen} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/50 transition-opacity" />
        </Transition.Child>

        <div className="fixed inset-0 z-10 overflow-y-auto">
          <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
              enterTo="opacity-100 translate-y-0 sm:scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 translate-y-0 sm:scale-100"
              leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
              <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
                  <Dialog.Title className="text-lg font-semibold text-tx-primary">
                    Abrir Disputa
                  </Dialog.Title>
                  <button
                    onClick={handleClose}
                    className="text-tx-muted hover:text-tx-secondary"
                  >
                    <XMarkIcon className="h-6 w-6" />
                  </button>
                </div>

                <form onSubmit={handleSubmit}>
                  <div className="px-4 sm:px-6 py-4 space-y-4 max-h-[60vh] overflow-y-auto">
                    <div className="bg-amber-50 border border-amber-200 rounded-lg p-4">
                      <div className="flex gap-3">
                        <ExclamationTriangleIcon className="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5" />
                        <div>
                          <p className="text-sm font-medium text-amber-900">
                            Importante
                          </p>
                          <p className="text-sm text-amber-700 mt-1">
                            Forneça evidências claras e detalhadas para aumentar suas chances de ganhar a disputa.
                          </p>
                        </div>
                      </div>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-tx-primary mb-2">
                        Descrição da Evidência *
                      </label>
                      <textarea
                        required
                        rows={16}
                        className="input w-full resize-none"
                        placeholder="Descreva detalhadamente o que aconteceu, incluindo informações sobre a entrega, comunicação com o cliente, etc."
                        value={formData.description}
                        onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                      />
                      <p className="text-xs text-tx-muted mt-1">
                        {formData.description.length} caracteres
                      </p>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-tx-primary mb-2">
                        Comprovantes e Evidências (Imagens)
                      </label>
                      <div className="space-y-3">
                        <div className="flex items-center justify-center w-full">
                          <label className="flex flex-col items-center justify-center w-full h-32 border-2 border-border-DEFAULT border-dashed rounded-lg cursor-pointer bg-surface-page hover:bg-surface-hover transition-colors">
                            <div className="flex flex-col items-center justify-center pt-5 pb-6">
                              <PhotoIcon className="w-10 h-10 mb-2 text-tx-muted" />
                              <p className="mb-2 text-sm text-tx-secondary">
                                <span className="font-semibold">Clique para enviar</span> ou arraste e solte
                              </p>
                              <p className="text-xs text-tx-muted">PNG, JPG, JPEG até 5MB cada</p>
                            </div>
                            <input
                              type="file"
                              className="hidden"
                              accept="image/png,image/jpeg,image/jpg"
                              multiple
                              onChange={handleImageChange}
                            />
                          </label>
                        </div>

                        {formData.images.length > 0 && (
                          <div className="grid grid-cols-2 gap-3">
                            {formData.images.map((file, index) => (
                              <div key={index} className="relative group">
                                <div className="aspect-video rounded-lg border border-border-DEFAULT overflow-hidden bg-surface-page">
                                  <img
                                    src={URL.createObjectURL(file)}
                                    alt={`Evidência ${index + 1}`}
                                    className="w-full h-full object-cover"
                                  />
                                </div>
                                <button
                                  type="button"
                                  onClick={() => removeImage(index)}
                                  className="absolute top-2 right-2 p-1 bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
                                >
                                  <XMarkIcon className="h-4 w-4" />
                                </button>
                                <p className="text-xs text-tx-muted mt-1 truncate">{file.name}</p>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-tx-primary mb-2">
                        Código de Rastreamento
                      </label>
                      <input
                        type="text"
                        className="input w-full"
                        placeholder="Ex: BR123456789BR"
                        value={formData.tracking_number}
                        onChange={(e) => setFormData({ ...formData, tracking_number: e.target.value })}
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-tx-primary mb-2">
                        Comprovante de Entrega (URL)
                      </label>
                      <input
                        type="text"
                        className="input w-full"
                        placeholder="URL do comprovante ou número do protocolo"
                        value={formData.delivery_proof}
                        onChange={(e) => setFormData({ ...formData, delivery_proof: e.target.value })}
                      />
                    </div>
                  </div>

                  <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-4 sm:px-6 py-4">
                    <button type="button" onClick={handleClose} className="btn-secondary">
                      Cancelar
                    </button>
                    <button type="submit" className="btn-primary">
                      Enviar Disputa
                    </button>
                  </div>
                </form>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition.Root>
  );
}

function ChargebackDetailModal({
  chargeback,
  isOpen,
  onClose,
  onOpenDispute,
}: {
  chargeback: Chargeback | null;
  isOpen: boolean;
  onClose: () => void;
  onOpenDispute: () => void;
}) {
  const [activeTab, setActiveTab] = useState<'details' | 'evidence' | 'timeline'>('details');
  
  if (!chargeback) return null;

  const charge = chargeback.charge;
  const evidence = chargeback.evidence || {};
  const hasEvidence = evidence.description || evidence.images?.length > 0;

  return (
    <Transition.Root show={isOpen} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/50 transition-opacity" />
        </Transition.Child>

        <div className="fixed inset-0 z-10 overflow-y-auto">
          <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
              enterTo="opacity-100 translate-y-0 sm:scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 translate-y-0 sm:scale-100"
              leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
              <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-4xl">
                <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4 bg-gradient-to-r from-green-50 to-blue-50">
                  <div>
                    <Dialog.Title className="text-lg font-semibold text-tx-primary">
                      Gestão Completa de Disputa
                    </Dialog.Title>
                    <p className="text-sm text-tx-muted mt-0.5">
                      ID: {chargeback.id.slice(0, 13)}...
                    </p>
                  </div>
                  <button
                    onClick={onClose}
                    className="text-tx-muted hover:text-tx-secondary"
                  >
                    <XMarkIcon className="h-6 w-6" />
                  </button>
                </div>

                {/* Tabs */}
                <div className="border-b border-border-DEFAULT px-4 sm:px-6 bg-surface-page">
                  <nav className="flex gap-6">
                    <button
                      onClick={() => setActiveTab('details')}
                      className={`py-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                        activeTab === 'details'
                          ? 'border-accent-primary text-accent-primary'
                          : 'border-transparent text-tx-muted hover:text-tx-secondary'
                      }`}
                    >
                      <DocumentIcon className="h-4 w-4" />
                      Informações
                    </button>
                    <button
                      onClick={() => setActiveTab('evidence')}
                      className={`py-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                        activeTab === 'evidence'
                          ? 'border-accent-primary text-accent-primary'
                          : 'border-transparent text-tx-muted hover:text-tx-secondary'
                      }`}
                    >
                      <PaperClipIcon className="h-4 w-4" />
                      Evidências
                      {hasEvidence && (
                        <span className="ml-2 inline-flex items-center justify-center h-5 w-5 rounded-full bg-accent-primary text-white text-xs">
                          {(evidence.images?.length || 0) + (evidence.description ? 1 : 0)}
                        </span>
                      )}
                    </button>
                    <button
                      onClick={() => setActiveTab('timeline')}
                      className={`py-3 text-sm font-medium border-b-2 transition-colors flex items-center gap-2 ${
                        activeTab === 'timeline'
                          ? 'border-accent-primary text-accent-primary'
                          : 'border-transparent text-tx-muted hover:text-tx-secondary'
                      }`}
                    >
                      <ClockIcon className="h-4 w-4" />
                      Histórico
                    </button>
                  </nav>
                </div>

                <div className="px-4 sm:px-6 py-4 max-h-[60vh] overflow-y-auto">
                  {/* Details Tab */}
                  {activeTab === 'details' && (
                    <div className="space-y-6">
                      {/* Status Card */}
                      <div className={`rounded-xl p-4 border-2 ${
                        chargeback.status === 'open' ? 'bg-amber-50 border-amber-200' :
                        chargeback.status === 'won' ? 'bg-green-50 border-green-200' :
                        chargeback.status === 'lost' ? 'bg-red-50 border-red-200' :
                        'bg-blue-50 border-blue-200'
                      }`}>
                        <div className="flex items-center justify-between">
                          <div>
                            <p className="text-sm font-medium text-tx-secondary">Status Atual</p>
                            <div className="mt-1">
                              <StatusBadge status={chargeback.status} />
                            </div>
                          </div>
                          {chargeback.deadline_at && chargeback.status === 'open' && (
                            <div className="text-right">
                              <p className="text-sm font-medium text-tx-secondary">Prazo para Resposta</p>
                              <p className="text-lg font-bold text-status-warning mt-1">
                                {formatDate(chargeback.deadline_at)}
                              </p>
                            </div>
                          )}
                        </div>
                      </div>

                      {/* Chargeback Info */}
                      <div>
                        <h3 className="text-sm font-semibold text-tx-primary mb-3">Informações do Chargeback</h3>
                        <div className="grid grid-cols-2 gap-4">
                          <div>
                            <p className="text-xs text-tx-muted mb-1">Valor</p>
                            <p className="text-lg font-bold text-status-error">{formatCurrency(chargeback.amount)}</p>
                          </div>
                          <div>
                            <p className="text-xs text-tx-muted mb-1">ID da Cobrança</p>
                            <p className="font-mono text-sm text-tx-primary">{chargeback.charge_id.slice(0, 13)}...</p>
                          </div>
                          <div>
                            <p className="text-xs text-tx-muted mb-1">Criado em</p>
                            <p className="text-sm text-tx-primary">{formatDate(chargeback.created_at)}</p>
                          </div>
                          {chargeback.resolved_at && (
                            <div>
                              <p className="text-xs text-tx-muted mb-1">Resolvido em</p>
                              <p className="text-sm text-tx-primary">{formatDate(chargeback.resolved_at)}</p>
                            </div>
                          )}
                        </div>
                      </div>

                      {/* Reason */}
                      {chargeback.reason && (
                        <div className="rounded-lg bg-amber-50 border border-amber-200 p-4">
                          <div className="flex gap-3">
                            <ExclamationTriangleIcon className="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5" />
                            <div className="flex-1">
                              <p className="text-sm font-semibold text-amber-900 mb-1">Motivo do Chargeback</p>
                              <p className="text-sm text-amber-800">{chargeback.reason}</p>
                              {chargeback.reason_code && (
                                <p className="text-xs text-amber-600 mt-1">Código: {chargeback.reason_code}</p>
                              )}
                            </div>
                          </div>
                        </div>
                      )}

                      {/* Customer & Payment Info */}
                      {charge && (
                        <>
                          <div>
                            <h3 className="text-sm font-semibold text-tx-primary mb-3">Informações do Cliente</h3>
                            <div className="space-y-3 rounded-lg border border-border-DEFAULT p-4">
                              <div className="flex items-center justify-between">
                                <span className="text-sm text-tx-muted">Nome</span>
                                <span className="text-sm font-medium text-tx-primary">{charge.customer?.name || '-'}</span>
                              </div>
                              <div className="flex items-center justify-between">
                                <span className="text-sm text-tx-muted">Email</span>
                                <span className="text-sm font-medium text-tx-primary">{charge.customer?.email || '-'}</span>
                              </div>
                            </div>
                          </div>

                          <div>
                            <h3 className="text-sm font-semibold text-tx-primary mb-3">Informações do Pagamento</h3>
                            <div className="space-y-3 rounded-lg border border-border-DEFAULT p-4">
                              <div className="flex items-center justify-between">
                                <span className="text-sm text-tx-muted">Método de Pagamento</span>
                                <span className="text-sm font-medium text-tx-primary">
                                  {charge.payment_method === 'credit_card' ? 'Cartão de Crédito' :
                                   charge.payment_method === 'pix' ? 'PIX' :
                                   charge.payment_method === 'boleto' ? 'Boleto' : charge.payment_method}
                                </span>
                              </div>
                              <div className="flex items-center justify-between">
                                <span className="text-sm text-tx-muted">Adquirente</span>
                                <span className="text-sm font-medium text-tx-primary">{charge.acquirer || '-'}</span>
                              </div>
                              {charge.acquirer_reference && (
                                <div className="flex items-center justify-between">
                                  <span className="text-sm text-tx-muted">Referência</span>
                                  <span className="text-sm font-mono text-tx-primary">{charge.acquirer_reference}</span>
                                </div>
                              )}
                              <div className="flex items-center justify-between">
                                <span className="text-sm text-tx-muted">Data do Pagamento</span>
                                <span className="text-sm font-medium text-tx-primary">
                                  {charge.paid_at ? formatDate(charge.paid_at) : '-'}
                                </span>
                              </div>
                            </div>
                          </div>
                        </>
                      )}
                    </div>
                  )}

                  {/* Evidence Tab */}
                  {activeTab === 'evidence' && (
                    <div className="space-y-6">
                      {!hasEvidence ? (
                        <div className="text-center py-12">
                          <DocumentTextIcon className="mx-auto h-12 w-12 text-tx-muted" />
                          <p className="mt-4 text-sm font-medium text-tx-primary">Nenhuma evidência enviada</p>
                          <p className="text-sm text-tx-muted mt-1">
                            Envie evidências para contestar este chargeback
                          </p>
                          {chargeback.status === 'open' && (
                            <button
                              onClick={() => {
                                onClose();
                                onOpenDispute();
                              }}
                              className="btn-primary mt-4"
                            >
                              Enviar Evidências
                            </button>
                          )}
                        </div>
                      ) : (
                        <>
                          {/* Description */}
                          {evidence.description && (
                            <div>
                              <h3 className="text-sm font-semibold text-tx-primary mb-3">Descrição</h3>
                              <div className="rounded-lg border border-border-DEFAULT bg-surface-page p-4">
                                <p className="text-sm text-tx-primary whitespace-pre-wrap">{evidence.description}</p>
                              </div>
                            </div>
                          )}

                          {/* Tracking & Delivery */}
                          {(evidence.tracking_number || evidence.delivery_proof) && (
                            <div>
                              <h3 className="text-sm font-semibold text-tx-primary mb-3">Informações de Entrega</h3>
                              <div className="space-y-3 rounded-lg border border-border-DEFAULT p-4">
                                {evidence.tracking_number && (
                                  <div>
                                    <p className="text-xs text-tx-muted mb-1">Código de Rastreamento</p>
                                    <p className="text-sm font-mono text-tx-primary">{evidence.tracking_number}</p>
                                  </div>
                                )}
                                {evidence.delivery_proof && (
                                  <div>
                                    <p className="text-xs text-tx-muted mb-1">Comprovante de Entrega</p>
                                    <p className="text-sm text-tx-primary break-all">{evidence.delivery_proof}</p>
                                  </div>
                                )}
                              </div>
                            </div>
                          )}

                          {/* Images */}
                          {evidence.images && evidence.images.length > 0 && (
                            <div>
                              <h3 className="text-sm font-semibold text-tx-primary mb-3">
                                Comprovantes ({evidence.images.length})
                              </h3>
                              <div className="grid grid-cols-2 gap-4">
                                {evidence.images.map((image: any, index: number) => {
                                  const imageUrl = typeof image === 'string' ? image : image.url || image.path;
                                  const imageName = typeof image === 'string' ? `Evidência ${index + 1}` : image.name || `Evidência ${index + 1}`;

                                  return (
                                    <div
                                      key={index}
                                      className="group relative aspect-video rounded-lg border-2 border-border-DEFAULT overflow-hidden bg-surface-page hover:border-accent-primary transition-colors"
                                    >
                                      <img
                                        src={imageUrl}
                                        alt={imageName}
                                        className="w-full h-full object-cover"
                                        onError={(e) => {
                                          (e.target as HTMLImageElement).src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"%3E%3Cpath stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/%3E%3C/svg%3E';
                                        }}
                                      />
                                      <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                        <a
                                          href={imageUrl}
                                          target="_blank"
                                          rel="noopener noreferrer"
                                          className="text-white text-sm font-medium flex items-center gap-2 hover:scale-110 transition-transform"
                                        >
                                          <EyeIcon className="h-5 w-5" />
                                          Ver imagem
                                        </a>
                                      </div>
                                      <p className="absolute bottom-1 left-1 right-1 text-xs text-white bg-black/50 px-2 py-1 rounded truncate">
                                        {imageName}
                                      </p>
                                    </div>
                                  );
                                })}
                              </div>
                            </div>
                          )}

                          {/* Submitted Info */}
                          <div className="rounded-lg bg-blue-50 border border-blue-200 p-4">
                            <div className="flex items-center gap-2 text-blue-900">
                              <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                              </svg>
                              <p className="text-sm font-medium">
                                Evidências enviadas com sucesso
                              </p>
                            </div>
                            <p className="text-sm text-blue-700 mt-2">
                              Suas evidências foram enviadas para análise. Você será notificado sobre o resultado.
                            </p>
                          </div>
                        </>
                      )}
                    </div>
                  )}

                  {/* Timeline Tab */}
                  {activeTab === 'timeline' && (
                    <div className="space-y-4">
                      <div className="relative">
                        {/* Timeline line */}
                        <div className="absolute left-4 top-0 bottom-0 w-0.5 bg-border-DEFAULT" />
                        
                        {/* Timeline items */}
                        <div className="space-y-6">
                          {/* Created */}
                          <div className="relative flex gap-4">
                            <div className="flex-shrink-0 w-8 h-8 rounded-full bg-status-error-subtle flex items-center justify-center z-10">
                              <ExclamationTriangleIcon className="h-4 w-4 text-status-error" />
                            </div>
                            <div className="flex-1 pb-6">
                              <p className="text-sm font-semibold text-tx-primary">Chargeback Recebido</p>
                              <p className="text-xs text-tx-muted mt-1">{formatDate(chargeback.created_at)}</p>
                              <p className="text-sm text-tx-secondary mt-2">
                                Um chargeback foi iniciado pelo cliente no valor de {formatCurrency(chargeback.amount)}
                              </p>
                            </div>
                          </div>

                          {/* Evidence sent */}
                          {hasEvidence && (
                            <div className="relative flex gap-4">
                              <div className="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center z-10">
                                <DocumentTextIcon className="h-4 w-4 text-blue-600" />
                              </div>
                              <div className="flex-1 pb-6">
                                <p className="text-sm font-semibold text-tx-primary">Evidências Enviadas</p>
                                <p className="text-xs text-tx-muted mt-1">{formatDate(chargeback.updated_at)}</p>
                                <p className="text-sm text-tx-secondary mt-2">
                                  Evidências foram enviadas para análise da adquirente
                                </p>
                              </div>
                            </div>
                          )}

                          {/* Resolved */}
                          {chargeback.resolved_at && (
                            <div className="relative flex gap-4">
                              <div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center z-10 ${
                                chargeback.status === 'won' ? 'bg-status-success-subtle' : 'bg-status-error-subtle'
                              }`}>
                                {chargeback.status === 'won' ? (
                                  <svg className="h-4 w-4 text-status-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                  </svg>
                                ) : (
                                  <XMarkIcon className="h-4 w-4 text-status-error" />
                                )}
                              </div>
                              <div className="flex-1">
                                <p className="text-sm font-semibold text-tx-primary">
                                  {chargeback.status === 'won' ? 'Disputa Ganha' : 'Disputa Perdida'}
                                </p>
                                <p className="text-xs text-tx-muted mt-1">{formatDate(chargeback.resolved_at)}</p>
                                <p className="text-sm text-tx-secondary mt-2">
                                  {chargeback.status === 'won' 
                                    ? 'A disputa foi resolvida a seu favor. O valor será estornado.'
                                    : 'A disputa foi resolvida a favor do cliente. O valor foi debitado.'}
                                </p>
                              </div>
                            </div>
                          )}
                        </div>
                      </div>
                    </div>
                  )}
                </div>

                <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-4 sm:px-6 py-4">
                  {chargeback.status === 'open' && !hasEvidence && (
                    <button
                      onClick={() => {
                        onClose();
                        onOpenDispute();
                      }}
                      className="btn-primary"
                    >
                      Enviar Evidências
                    </button>
                  )}
                  <button onClick={onClose} className="btn-secondary">
                    Fechar
                  </button>
                </div>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition.Root>
  );
}

export default function ChargebackPage() {
  const [filters, setFilters] = useState<ChargebackFilters>({});
  const [selectedChargeback, setSelectedChargeback] = useState<Chargeback | null>(null);
  const [isDetailOpen, setIsDetailOpen] = useState(false);
  const [isDisputeOpen, setIsDisputeOpen] = useState(false);
  const [page, setPage] = useState(1);
  const queryClient = useQueryClient();

  // Fetch chargebacks
  const { data, isLoading, refetch } = useQuery({
    queryKey: ['chargebacks', filters, page],
    queryFn: async () => {
      const response = await api.get('/chargebacks', {
        params: {
          ...filters,
          page,
          per_page: 10,
        },
      });
      return response.data;
    },
  });

  // Fetch totals for summary cards
  const { data: summaryData } = useQuery({
    queryKey: ['chargebacks-summary'],
    queryFn: async () => {
      const response = await api.get('/chargebacks', { params: { per_page: 1 } });
      return {
        total: response.data?.meta?.total || 0,
        open: response.data?.data?.filter((cb: any) => cb.status === 'open').length || 0,
      };
    },
  });

  const chargebacks: Chargeback[] = data?.data || [];
  const pagination = data?.meta || { total: 0, last_page: 1 };

  // Calculate totals
  const totalAmount = chargebacks.reduce((acc, cb) => acc + cb.amount, 0);
  const openCount = summaryData?.open || 0;
  const totalCount = summaryData?.total || 0;

  // Mutation to submit dispute evidence
  const submitDisputeMutation = useMutation({
    mutationFn: async (data: any) => {
      if (!selectedChargeback) throw new Error('No chargeback selected');
      
      // Create FormData to handle file uploads
      const formData = new FormData();
      formData.append('description', data.description);
      
      if (data.tracking_number) {
        formData.append('tracking_number', data.tracking_number);
      }
      
      if (data.delivery_proof) {
        formData.append('delivery_proof', data.delivery_proof);
      }
      
      // Append images
      data.images.forEach((image: File) => {
        formData.append('images[]', image);
      });
      
      // Submit evidence with multipart/form-data
      const response = await api.post(`/chargebacks/${selectedChargeback.id}/evidence`, formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      });
      
      return response.data;
    },
    onSuccess: () => {
      toast.success('Disputa aberta com sucesso!');
      setIsDisputeOpen(false);
      queryClient.invalidateQueries({ queryKey: ['chargebacks'] });
      queryClient.invalidateQueries({ queryKey: ['chargebacks-summary'] });
    },
    onError: (error: any) => {
      const message = error.response?.data?.message || 'Erro ao abrir disputa';
      toast.error(message);
    },
  });

  const handleViewChargeback = (chargeback: Chargeback) => {
    setSelectedChargeback(chargeback);
    setIsDetailOpen(true);
  };

  const handleOpenDispute = () => {
    setIsDisputeOpen(true);
  };

  const handleSubmitDispute = (data: any) => {
    submitDisputeMutation.mutate(data);
  };

  return (
    <div className="space-y-4 lg:space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl lg:text-2xl font-bold text-tx-primary">Chargebacks & Estornos</h1>
          <p className="text-xs lg:text-sm text-tx-muted mt-1">
            Acompanhe cobranças estornadas e chargebacks
          </p>
        </div>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 lg:gap-4">
        {/* Total Amount */}
        <div className="card p-4 lg:p-5">
          <div className="flex items-center gap-3 lg:gap-4">
            <div className="flex h-10 w-10 lg:h-12 lg:w-12 items-center justify-center rounded-xl bg-status-error-subtle">
              <BanknotesIcon className="h-5 w-5 lg:h-6 lg:w-6 text-status-error" />
            </div>
            <div>
              <p className="text-xs lg:text-sm font-medium text-tx-muted">Valor Total</p>
              <p className="text-lg lg:text-2xl font-bold text-status-error">
                {formatCurrency(totalAmount)}
              </p>
              <p className="text-xs text-tx-muted hidden sm:block">desta página</p>
            </div>
          </div>
        </div>

        {/* Total Count */}
        <div className="card p-4 lg:p-5">
          <div className="flex items-center gap-3 lg:gap-4">
            <div className="flex h-10 w-10 lg:h-12 lg:w-12 items-center justify-center rounded-xl bg-accent-subtle">
              <DocumentTextIcon className="h-5 w-5 lg:h-6 lg:w-6 text-accent-primary" />
            </div>
            <div>
              <p className="text-xs lg:text-sm font-medium text-tx-muted">Total</p>
              <p className="text-lg lg:text-2xl font-bold text-tx-primary">
                {totalCount}
              </p>
              <p className="text-xs text-tx-muted hidden sm:block">chargebacks registrados</p>
            </div>
          </div>
        </div>

        {/* Open Chargebacks */}
        <div className="card p-4 lg:p-5">
          <div className="flex items-center gap-3 lg:gap-4">
            <div className="flex h-10 w-10 lg:h-12 lg:w-12 items-center justify-center rounded-xl bg-amber-100">
              <ExclamationTriangleIcon className="h-5 w-5 lg:h-6 lg:w-6 text-status-warning" />
            </div>
            <div>
              <p className="text-xs lg:text-sm font-medium text-tx-muted">Abertos</p>
              <p className="text-lg lg:text-2xl font-bold text-tx-primary">
                {openCount}
              </p>
              <p className="text-xs text-tx-muted hidden sm:block">aguardando ação</p>
            </div>
          </div>
        </div>
      </div>

      {/* Filters */}
      <div className="card p-3 lg:p-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
          <div className="relative flex-1">
            <MagnifyingGlassIcon className="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-tx-muted" />
            <input
              type="text"
              placeholder="Buscar por e-mail, nome, ID..."
              className="input pl-10 text-sm"
              value={filters.search || ''}
              onChange={(e) => { setFilters({ ...filters, search: e.target.value }); setPage(1); }}
            />
          </div>
          <div className="grid grid-cols-2 gap-2 lg:flex lg:gap-3">
            <select
              className="input w-full lg:w-auto text-sm"
              value={filters.status || ''}
              onChange={(e) => { setFilters({ ...filters, status: e.target.value }); setPage(1); }}
            >
              <option value="">Todos os status</option>
              <option value="open">Aberto</option>
              <option value="evidence_sent">Analisando Caso</option>
              <option value="under_review">Em Análise</option>
              <option value="won">Ganho</option>
              <option value="lost">Perdido</option>
              <option value="expired">Expirado</option>
            </select>
          </div>
          <button
            onClick={() => refetch()}
            className="btn-secondary inline-flex items-center justify-center text-sm"
          >
            <ArrowPathIcon className="mr-2 h-4 w-4" />
            Atualizar
          </button>
        </div>
      </div>

      {/* Mobile Card View */}
      <div className="lg:hidden space-y-3">
        {isLoading ? (
          <div className="card p-8 text-center">
            <div className="flex items-center justify-center">
              <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
            </div>
          </div>
        ) : chargebacks.length === 0 ? (
          <div className="card p-8 text-center">
            <DocumentTextIcon className="mx-auto h-10 w-10 text-tx-muted" />
            <p className="mt-2 text-sm font-medium text-tx-primary">Nenhum chargeback encontrado</p>
            <p className="text-xs text-tx-muted">Chargebacks aparecerão aqui</p>
          </div>
        ) : (
          chargebacks.map((chargeback) => (
            <div
              key={chargeback.id}
              className="card p-4 cursor-pointer hover:bg-surface-hover/50 transition-colors"
              onClick={() => handleViewChargeback(chargeback)}
            >
              <div className="flex items-start justify-between mb-3">
                <div className="min-w-0 flex-1">
                  <p className="font-medium text-tx-primary text-sm truncate">
                    {chargeback.charge?.customer?.name || 'Cliente'}
                  </p>
                  <p className="text-xs text-tx-muted truncate">
                    {chargeback.charge?.customer?.email}
                  </p>
                </div>
                <StatusBadge status={chargeback.status} />
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-xs text-tx-muted">Valor</p>
                  <p className="font-semibold text-status-error text-sm">
                    {formatCurrency(chargeback.amount)}
                  </p>
                </div>
                <div className="text-right">
                  <p className="text-xs text-tx-muted">Criado em</p>
                  <p className="font-semibold text-tx-primary text-sm">
                    {formatDate(chargeback.created_at)}
                  </p>
                </div>
              </div>
              {chargeback.deadline_at && (
                <div className="mt-3 pt-3 border-t border-border-DEFAULT">
                  <span className="text-xs text-status-warning">
                    Prazo: {formatDate(chargeback.deadline_at)}
                  </span>
                </div>
              )}
            </div>
          ))
        )}
      </div>

      {/* Desktop Table */}
      <div className="hidden lg:block card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-border-DEFAULT">
            <thead className="bg-surface-page">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
                  Cliente
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
                  Valor
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
                  Método
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
                  Prazo
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
                  Criado em
                </th>
                <th className="relative px-6 py-3">
                  <span className="sr-only">Ações</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT bg-surface-card">
              {isLoading ? (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center text-tx-muted">
                    <div className="flex items-center justify-center">
                      <div className="h-8 w-8 animate-spin rounded-full border-4 border-accent-primary border-t-transparent" />
                    </div>
                  </td>
                </tr>
              ) : chargebacks.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center">
                    <div className="flex flex-col items-center gap-3">
                      <DocumentTextIcon className="h-12 w-12 text-tx-muted" />
                      <div>
                        <p className="text-sm font-medium text-tx-primary">Nenhum chargeback encontrado</p>
                        <p className="text-sm text-tx-muted">
                          Chargebacks aparecerão aqui
                        </p>
                      </div>
                    </div>
                  </td>
                </tr>
              ) : (
                chargebacks.map((chargeback) => (
                  <tr
                    key={chargeback.id}
                    className="hover:bg-surface-page cursor-pointer"
                    onClick={() => handleViewChargeback(chargeback)}
                  >
                    <td className="whitespace-nowrap px-6 py-4">
                      <div>
                        <p className="font-medium text-tx-primary">
                          {chargeback.charge?.customer?.name || 'Cliente'}
                        </p>
                        <p className="text-sm text-tx-muted">
                          {chargeback.charge?.customer?.email}
                        </p>
                      </div>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <span className="font-medium text-status-error">
                        {formatCurrency(chargeback.amount)}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-6 py-4">
                      <StatusBadge status={chargeback.status} />
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
                      {chargeback.charge?.payment_method === 'credit_card'
                        ? 'Cartão'
                        : chargeback.charge?.payment_method === 'pix'
                        ? 'PIX'
                        : chargeback.charge?.payment_method === 'boleto'
                        ? 'Boleto'
                        : chargeback.charge?.payment_method || '-'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
                      {chargeback.deadline_at ? (
                        <span className="text-status-warning">{formatDate(chargeback.deadline_at)}</span>
                      ) : '-'}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
                      {formatDate(chargeback.created_at)}
                    </td>
                    <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
                      <button
                        onClick={(e) => { e.stopPropagation(); handleViewChargeback(chargeback); }}
                        className="rounded p-1 hover:bg-surface-hover text-tx-muted hover:text-tx-secondary"
                      >
                        <EyeIcon className="h-5 w-5" />
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Pagination - Shows for both mobile and desktop */}
      {(pagination.last_page > 1 || pagination.total_pages > 1) && (
        <div className="flex items-center justify-between bg-surface-card rounded-lg border border-border-DEFAULT px-4 py-3 lg:px-6 lg:py-4">
          <div className="flex flex-1 justify-between lg:hidden">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              className="btn-secondary text-sm"
            >
              Anterior
            </button>
            <span className="text-sm text-tx-muted self-center">
              {page} / {pagination.last_page || pagination.total_pages || 1}
            </span>
            <button
              onClick={() => setPage((p) => p + 1)}
              disabled={page >= (pagination.last_page || pagination.total_pages || 1)}
              className="btn-secondary text-sm"
            >
              Próximo
            </button>
          </div>
          <div className="hidden lg:flex lg:flex-1 lg:items-center lg:justify-between">
            <div>
              <p className="text-sm text-tx-secondary">
                Mostrando{' '}
                <span className="font-medium">{chargebacks.length > 0 ? (page - 1) * 10 + 1 : 0}</span>{' '}
                a{' '}
                <span className="font-medium">{Math.min(page * 10, pagination.total || 0)}</span>{' '}
                de{' '}
                <span className="font-medium">{pagination.total || 0}</span> resultados
              </p>
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page === 1}
                className="btn-secondary"
              >
                Anterior
              </button>
              <button
                onClick={() => setPage((p) => p + 1)}
                disabled={page >= (pagination.last_page || pagination.total_pages || 1)}
                className="btn-secondary"
              >
                Próximo
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Detail Modal */}
      <ChargebackDetailModal
        chargeback={selectedChargeback}
        isOpen={isDetailOpen}
        onClose={() => setIsDetailOpen(false)}
        onOpenDispute={handleOpenDispute}
      />

      {/* Dispute Modal */}
      <DisputeModal
        chargeback={selectedChargeback}
        isOpen={isDisputeOpen}
        onClose={() => setIsDisputeOpen(false)}
        onSubmit={handleSubmitDispute}
      />
    </div>
  );
}


