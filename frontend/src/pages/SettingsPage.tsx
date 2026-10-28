import { useState, useEffect, useRef } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, formatDate } from '@/lib/api';
import { SectionSpinner } from '@/components/ui/Spinner';
import { adminService } from '@/services/api/admin.service';
import { Dialog, Transition, Menu } from '@headlessui/react';
import { useAuthStore } from '@/stores/authStore';
import { Fragment } from 'react';
import { useForm } from 'react-hook-form';
import {
 PlusIcon,
 EllipsisVerticalIcon,
 XMarkIcon,
 TrashIcon,
 ClipboardDocumentIcon,
 ArrowPathIcon,
 KeyIcon,
 ShieldCheckIcon,
 UserIcon,
 EnvelopeIcon,
 PhoneIcon,
 BuildingOfficeIcon,
 CheckCircleIcon,
 Cog6ToothIcon,
 UsersIcon,
 ChartBarIcon,
 DocumentTextIcon,
 BookOpenIcon,
 ArrowTopRightOnSquareIcon,
 CodeBracketIcon,
 CommandLineIcon,
 RocketLaunchIcon,
 GlobeAltIcon,
 SignalIcon,
 EyeIcon,
 EyeSlashIcon,
 LockClosedIcon,
 ClockIcon,
 ExclamationCircleIcon,
 ShieldExclamationIcon,
 SwatchIcon,
 PaintBrushIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import SplitManagement from './SplitManagement';
import { kycService, KycDocumentStatus } from '@/services/api/kyc.service';
import { authService } from '@/services/api/auth.service';
import { useThemeStore } from '@/stores/themeStore';
import { themeList, rgbToHex } from '@/config/themes';

interface UserWebhook {
 id: string;
 url: string;
 events: string[];
 is_active: boolean;
 secret: string;
 created_at: string;
 updated_at?: string;
}

interface ApiKey {
 id: string;
 name: string;
 type: 'public' | 'secret';
 key_prefix: string;
 environment: 'production' | 'sandbox';
 permissions: string[];
 ip_whitelist: string[];
 last_used_at?: string;
 expires_at?: string;
 created_at: string;
}

interface ApiKeyForm {
 name: string;
 type: 'public' | 'secret';
 environment: 'production' | 'sandbox';
 permissions: string[];
 ip_whitelist: string;
}

const availablePermissions = [
 { value: 'charges:read', label: 'Ler cobranças' },
 { value: 'charges:write', label: 'Criar/editar cobranças' },
 { value: 'payments:read', label: 'Ler pagamentos' },
 { value: 'payments:write', label: 'Processar pagamentos' },
 { value: 'products:read', label: 'Ler produtos' },
 { value: 'products:write', label: 'Criar/editar produtos' },
 { value: 'checkouts:read', label: 'Ler checkouts' },
 { value: 'checkouts:write', label: 'Criar/editar checkouts' },
 { value: 'customers:read', label: 'Ler clientes' },
 { value: 'customers:write', label: 'Criar/editar clientes' },
 { value: 'webhooks:read', label: 'Ler webhooks' },
 { value: 'webhooks:write', label: 'Gerenciar webhooks' },
];

function CreateApiKeyModal({
 isOpen,
 onClose,
 onKeyCreated,
 mfaCode,
}: {
 isOpen: boolean;
 onClose: () => void;
 onKeyCreated: (key: string) => void;
 mfaCode: string;
}) {
 const queryClient = useQueryClient();

 const {
 register,
 handleSubmit,
 watch,
 setValue,
 reset,
 formState: { errors },
 } = useForm<ApiKeyForm>({
 defaultValues: {
 type: 'secret',
 environment: 'production',
 permissions: ['*'],
 },
 });

 const selectedPermissions = watch('permissions') || [];
 const selectedType = watch('type');

 const mutation = useMutation({
 mutationFn: async (data: ApiKeyForm) => {
 const payload = {
 ...data,
 mfa_code: mfaCode,
 ip_whitelist: data.ip_whitelist
 ? data.ip_whitelist.split(',').map((ip) => ip.trim())
 : [],
 };
 return api.post('/api-keys', payload);
 },
 onSuccess: (response) => {
 toast.success('Chave API criada!');
 queryClient.invalidateQueries({ queryKey: ['api-keys'] });
 onKeyCreated(response.data.data.key);
 reset();
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao criar chave API');
 },
 });

 const onSubmit = (data: ApiKeyForm) => {
 mutation.mutate(data);
 };

 const togglePermission = (permission: string) => {
 const current = selectedPermissions;
 if (current.includes(permission)) {
 setValue(
 'permissions',
 current.filter((p) => p !== permission)
 );
 } else {
 setValue('permissions', [...current, permission]);
 }
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
 <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
 <form onSubmit={handleSubmit(onSubmit)}>
 <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
 <Dialog.Title className="text-lg font-semibold text-tx-primary">
 Nova Chave API
 </Dialog.Title>
 <button
 type="button"
 onClick={onClose}
 className="text-tx-muted hover:text-tx-muted"
 >
 <XMarkIcon className="h-6 w-6" />
 </button>
 </div>

 <div className="max-h-[60vh] space-y-4 overflow-y-auto px-4 sm:px-6 py-4">
 <input type="hidden" {...register('type')} value="secret" />
 <div className="rounded-lg bg-accent-subtle p-3">
 <p className="text-sm font-medium text-accent-primary">
 Chave Privada (sk_live_ )
 </p>
 <p className="mt-1 text-xs text-accent-primary">
 Acesso completo: criar cobranças, gerenciar checkouts, processar pagamentos.
 </p>
 </div>

 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 Nome da Chave
 </label>
 <input
 {...register('name', { required: 'Nome é obrigatório' })}
 type="text"
 className="input mt-1"
 placeholder="Ex: Integração E-commerce"
 />
 {errors.name && (
 <p className="mt-1 text-sm text-status-error">{errors.name.message}</p>
 )}
 </div>

 <input type="hidden" {...register('environment')} value="production" />

 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 Whitelist de IPs (opcional)
 </label>
 <input
 {...register('ip_whitelist')}
 type="text"
 className="input mt-1"
 placeholder="192.168.1.1, 10.0.0.1"
 />
 <p className="mt-1 text-xs text-tx-muted">
 Separar múltiplos IPs por vírgula. Deixe vazio para permitir todos.
 </p>
 </div>
 </div>

 <div className="flex justify-end gap-3 border-t border-border-DEFAULT px-4 sm:px-6 py-4">
 <button type="button" onClick={onClose} className="btn-secondary">
 Cancelar
 </button>
 <button
 type="submit"
 disabled={mutation.isPending}
 className="btn-primary"
 >
 {mutation.isPending ? 'Criando...' : 'Criar Chave'}
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

function ShowKeyModal({
 apiKey,
 isOpen,
 onClose,
}: {
 apiKey: string | null;
 isOpen: boolean;
 onClose: () => void;
}) {
 const copyKey = () => {
 if (apiKey) {
 navigator.clipboard.writeText(apiKey);
 toast.success('Chave copiada!');
 }
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
 <Dialog.Panel className="relative transform overflow-hidden rounded-xl bg-surface-card text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md">
 <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
 <Dialog.Title className="text-lg font-semibold text-tx-primary">
 Chave API Criada
 </Dialog.Title>
 <button
 onClick={onClose}
 className="text-tx-muted hover:text-tx-muted"
 >
 <XMarkIcon className="h-6 w-6" />
 </button>
 </div>

 <div className="px-4 sm:px-6 py-4">
 <div className="mb-4 rounded-lg bg-status-warning-subtle p-4">
 <p className="text-sm text-status-warning">
 <strong>Atenção:</strong> Esta chave será exibida apenas uma vez. Guarde-a em
 um local seguro.
 </p>
 </div>

 <div className="rounded-element border border-border-DEFAULT bg-surface-page p-4">
 <code className="break-all text-sm text-tx-primary">
 {apiKey}
 </code>
 </div>

 <button
 onClick={copyKey}
 className="btn-primary mt-4 w-full inline-flex items-center justify-center"
 >
 <ClipboardDocumentIcon className="mr-2 h-5 w-5" />
 Copiar Chave
 </button>
 </div>

 <div className="flex justify-end border-t border-border-DEFAULT px-4 sm:px-6 py-4">
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

function MfaCodeModal({
 isOpen,
 onClose,
 onConfirm,
 title,
 description,
 isLoading,
}: {
 isOpen: boolean;
 onClose: () => void;
 onConfirm: (code: string) => void;
 title: string;
 description: string;
 isLoading: boolean;
}) {
 const [digits, setDigits] = useState(['', '', '', '', '', '']);
 const [error, setError] = useState('');
 const inputRefs = useRef<(HTMLInputElement | null)[]>([]);

 useEffect(() => {
 if (isOpen) {
 setDigits(['', '', '', '', '', '']);
 setError('');
 setTimeout(() => inputRefs.current[0]?.focus(), 100);
 }
 }, [isOpen]);

 const handleChange = (index: number, value: string) => {
 if (!/^\d*$/.test(value)) return;
 const newDigits = [...digits];
 newDigits[index] = value.slice(-1);
 setDigits(newDigits);
 setError('');
 if (value && index < 5) {
 inputRefs.current[index + 1]?.focus();
 }
 };

 const handleKeyDown = (index: number, e: React.KeyboardEvent) => {
 if (e.key === 'Backspace' && !digits[index] && index > 0) {
 inputRefs.current[index - 1]?.focus();
 }
 };

 const handlePaste = (e: React.ClipboardEvent) => {
 e.preventDefault();
 const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
 if (pasted.length === 6) {
 setDigits(pasted.split(''));
 inputRefs.current[5]?.focus();
 }
 };

 const handleSubmit = () => {
 const code = digits.join('');
 if (code.length !== 6) {
 setError('Digite o código de 6 dígitos');
 return;
 }
 onConfirm(code);
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
 <Dialog.Panel className="relative transform overflow-hidden rounded-2xl bg-surface-card text-center shadow-2xl ring-1 ring-border-DEFAULT transition-all sm:my-8 sm:w-full sm:max-w-md">
 <div className="px-6 pt-8 pb-6 sm:px-8">
 <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-accent-subtle">
 <LockClosedIcon className="h-7 w-7 text-accent-primary" />
 </div>
 <Dialog.Title className="text-xl font-bold text-tx-primary">
 {title}
 </Dialog.Title>
 <p className="mx-auto mt-1.5 max-w-xs text-sm text-tx-muted">{description}</p>

 <p className="mt-6 mb-4 text-sm font-medium text-tx-secondary">
 Digite o código do Google Authenticator
 </p>

 <div className="flex justify-center gap-2 sm:gap-2.5" onPaste={handlePaste}>
 {digits.map((digit, i) => (
 <input
 key={i}
 ref={(el) => { inputRefs.current[i] = el; }}
 type="text"
 inputMode="numeric"
 maxLength={1}
 value={digit}
 onChange={(e) => handleChange(i, e.target.value)}
 onKeyDown={(e) => handleKeyDown(i, e)}
 className={`h-14 w-11 rounded-xl border-2 bg-surface-page text-center text-2xl font-bold text-tx-primary outline-none transition-all sm:w-12 ${
 digit ? 'border-accent-primary' : 'border-border-DEFAULT'
 } focus:border-accent-primary focus:ring-4 focus:ring-accent-primary/15`}
 />
 ))}
 </div>

 {error ? (
 <p className="mt-4 text-sm font-medium text-status-error">{error}</p>
 ) : (
 <p className="mt-4 text-xs text-tx-muted">Abra o app autenticador e insira os 6 dígitos.</p>
 )}
 </div>

 <div className="flex gap-3 border-t border-border-DEFAULT px-6 py-4 sm:px-8">
 <button
 type="button"
 onClick={onClose}
 disabled={isLoading}
 className="flex-1 rounded-xl border border-border-DEFAULT bg-transparent px-4 py-2.5 text-sm font-semibold text-tx-primary transition-colors hover:bg-surface-hover disabled:opacity-50"
 >
 Cancelar
 </button>
 <button
 onClick={handleSubmit}
 disabled={isLoading || digits.join('').length !== 6}
 className="flex-1 rounded-xl bg-accent-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
 >
 {isLoading ? 'Verificando...' : 'Confirmar'}
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

function WithdrawalIpWhitelist() {
 const queryClient = useQueryClient();
 const [newIp, setNewIp] = useState('');
 const [ipError, setIpError] = useState('');

 const { data, isLoading } = useQuery({
 queryKey: ['withdrawal-ip-whitelist'],
 queryFn: async () => {
 const response = await api.get('/account/withdrawal-ips');
 return response.data;
 },
 });

 const ips: string[] = data?.data?.ips || [];

 const updateMutation = useMutation({
 mutationFn: async (updatedIps: string[]) => {
 return api.put('/account/withdrawal-ips', { ips: updatedIps });
 },
 onSuccess: () => {
 toast.success('IPs autorizados atualizados!');
 queryClient.invalidateQueries({ queryKey: ['withdrawal-ip-whitelist'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao atualizar IPs');
 },
 });

 const isValidIp = (ip: string) => {
 const ipv4 = /^(\d{1,3}\.){3}\d{1,3}$/;
 const ipv6 = /^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}$/;
 if (!ipv4.test(ip) && !ipv6.test(ip)) return false;
 if (ipv4.test(ip)) {
 return ip.split('.').every((n) => parseInt(n) >= 0 && parseInt(n) <= 255);
 }
 return true;
 };

 const handleAddIp = () => {
 const trimmed = newIp.trim();
 if (!trimmed) return;
 if (!isValidIp(trimmed)) {
 setIpError('Endereço IP inválido');
 return;
 }
 if (ips.includes(trimmed)) {
 setIpError('Este IP já está na lista');
 return;
 }
 if (ips.length >= 20) {
 setIpError('Máximo de 20 IPs permitidos');
 return;
 }
 setIpError('');
 setNewIp('');
 updateMutation.mutate([...ips, trimmed]);
 };

 const handleRemoveIp = (ip: string) => {
 updateMutation.mutate(ips.filter((i) => i !== ip));
 };

 const handleKeyDown = (e: React.KeyboardEvent) => {
 if (e.key === 'Enter') {
 e.preventDefault();
 handleAddIp();
 }
 };

 return (
 <div className="card mt-4">
 <div className="border-b border-border-DEFAULT px-4 sm:px-6 py-4">
 <div className="flex items-center gap-3">
 <GlobeAltIcon className="h-5 w-5 text-tx-muted" />
 <div>
 <h2 className="text-lg font-semibold text-tx-primary">IPs Autorizados para Saque via API</h2>
 <p className="text-xs text-tx-muted mt-0.5">
 Apenas servidores com estes IPs poderão solicitar saques pela API. Deixe vazio para permitir qualquer IP.
 </p>
 </div>
 </div>
 </div>

 <div className="px-4 sm:px-6 py-4 space-y-4">
 {/* Add IP input */}
 <div className="flex gap-2">
 <div className="flex-1">
 <input
 type="text"
 value={newIp}
 onChange={(e) => { setNewIp(e.target.value); setIpError(''); }}
 onKeyDown={handleKeyDown}
 placeholder="Ex: 192.168.1.100 ou 2001:db8::1"
 className="input w-full"
 />
 {ipError && <p className="mt-1 text-xs text-status-error">{ipError}</p>}
 </div>
 <button
 onClick={handleAddIp}
 disabled={updateMutation.isPending || !newIp.trim()}
 className="btn-primary inline-flex items-center whitespace-nowrap"
 >
 <PlusIcon className="mr-1.5 h-4 w-4" />
 Adicionar IP
 </button>
 </div>

 {/* IP List */}
 {isLoading ? (
 <div className="py-6 text-center">
 <SectionSpinner />
 </div>
 ) : ips.length === 0 ? (
 <div className="rounded-lg border border-dashed border-border-DEFAULT bg-surface-page p-6 text-center">
 <GlobeAltIcon className="mx-auto h-8 w-8 text-tx-muted mb-2" />
 <p className="text-sm text-tx-muted">
 Nenhum IP configurado. Qualquer IP poderá realizar saques via API.
 </p>
 </div>
 ) : (
 <div className="space-y-2">
 {ips.map((ip) => (
 <div
 key={ip}
 className="flex items-center justify-between rounded-lg border border-border-DEFAULT bg-surface-page px-4 py-2.5"
 >
 <div className="flex items-center gap-3">
 <div className="flex h-8 w-8 items-center justify-center rounded-full bg-accent-subtle">
 <GlobeAltIcon className="h-4 w-4 text-accent-primary" />
 </div>
 <code className="text-sm font-mono text-tx-primary">{ip}</code>
 </div>
 <button
 onClick={() => handleRemoveIp(ip)}
 disabled={updateMutation.isPending}
 className="rounded p-1.5 text-tx-muted hover:text-status-error hover:bg-surface-hover transition-colors"
 title="Remover IP"
 >
 <TrashIcon className="h-4 w-4" />
 </button>
 </div>
 ))}
 </div>
 )}

 {/* Info box */}
 <div className="rounded-lg bg-accent-subtle p-3">
 <p className="text-xs text-accent-primary">
 <strong>Como funciona:</strong> Quando IPs estão configurados, apenas requisições de saque
 (via chave API) vindas desses IPs serão aceitas. Saques pelo painel não são afetados.
 </p>
 </div>
 </div>
 </div>
 );
}

// Dados mock para teste
const mockApiKeys: ApiKey[] = [
 {
 id: 'key_1',
 name: 'Produção - Sistema Principal',
 type: 'secret',
 key_prefix: 'sk_live_abc1',
 environment: 'production',
 permissions: ['*'],
 ip_whitelist: ['192.168.1.100', '10.0.0.50'],
 last_used_at: '2026-01-16T15:30:00Z',
 created_at: '2025-12-01T10:00:00Z',
 },
 {
 id: 'key_2',
 name: 'Sandbox - Desenvolvimento',
 type: 'secret',
 key_prefix: 'sk_test_xyz2',
 environment: 'sandbox',
 permissions: ['charges:read', 'charges:write', 'payments:read', 'payments:write', 'products:read', 'products:write', 'checkouts:read', 'checkouts:write'],
 ip_whitelist: [],
 last_used_at: '2026-01-16T14:20:00Z',
 created_at: '2026-01-05T09:00:00Z',
 },
 {
 id: 'key_3',
 name: 'Webhook Handler',
 type: 'secret',
 key_prefix: 'sk_live_wh01',
 environment: 'production',
 permissions: ['webhooks:read', 'webhooks:write'],
 ip_whitelist: ['54.232.123.45'],
 last_used_at: '2026-01-16T16:45:00Z',
 expires_at: '2026-06-01T00:00:00Z',
 created_at: '2026-01-10T11:00:00Z',
 },
 {
 id: 'key_4',
 name: 'Mobile App - Leitura',
 type: 'public',
 key_prefix: 'pk_live_mob1',
 environment: 'production',
 permissions: ['charges:read', 'products:read', 'checkouts:read'],
 ip_whitelist: [],
 last_used_at: '2026-01-15T20:00:00Z',
 created_at: '2026-01-08T14:30:00Z',
 },
];

export default function SettingsPage() {
 const [searchParams, setSearchParams] = useSearchParams();
 const [showMfaRequiredBanner, setShowMfaRequiredBanner] = useState(false);
 const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
 const [isShowKeyModalOpen, setIsShowKeyModalOpen] = useState(false);
 const [createdKey, setCreatedKey] = useState<string | null>(null);
 const validTabs = ['personal', 'security', 'appearance', 'api', 'webhooks', 'splits'] as const;
 type TabType = typeof validTabs[number];
 const tabFromUrl = searchParams.get('tab') as TabType;
 const initialTab = validTabs.includes(tabFromUrl) ? tabFromUrl : 'personal';
 const [activeTab, setActiveTabState] = useState<TabType>(initialTab);

 const setActiveTab = (tab: TabType) => {
   setActiveTabState(tab);
   setSearchParams({ tab }, { replace: true });
   // Hide MFA banner when user manually changes tabs
   if (tab !== 'security') {
     setShowMfaRequiredBanner(false);
   }
 };

 // Sync tab when URL changes (e.g. navigating from another page)
 useEffect(() => {
   const urlTab = searchParams.get('tab') as TabType;
   const mfaParam = searchParams.get('mfa_required');
   
   // If MFA is required, force security tab and show banner
   if (mfaParam === 'true' || mfaParam === '1') {
     setShowMfaRequiredBanner(true);
     setActiveTabState('security');
     // Remove mfa_required from URL but keep the banner visible
     const nextParams = new URLSearchParams(searchParams);
     nextParams.delete('mfa_required');
     nextParams.set('tab', 'security');
     setSearchParams(nextParams, { replace: true });
   } else if (validTabs.includes(urlTab) && urlTab !== activeTab) {
     // Normal tab sync only if not coming from MFA redirect
     setActiveTabState(urlTab);
   }
 }, [searchParams, activeTab, setSearchParams]);

 // Webhook state
 const [showWebhookModal, setShowWebhookModal] = useState(false);
 const [editingWebhook, setEditingWebhook] = useState<UserWebhook | null>(null);
 const [webhookUrl, setWebhookUrl] = useState('');
 const [webhookEvents, setWebhookEvents] = useState<string[]>([]);
 const [revealedSecrets, setRevealedSecrets] = useState<Set<string>>(new Set());
 const [newlyCreatedSecret, setNewlyCreatedSecret] = useState<string | null>(null);

 // MFA verification state for API key operations
 const [mfaModalOpen, setMfaModalOpen] = useState(false);
 const [mfaAction, setMfaAction] = useState<'create' | 'rotate' | null>(null);
 const [pendingRotateKeyId, setPendingRotateKeyId] = useState<string | null>(null);
 const [mfaLoading, setMfaLoading] = useState(false);
 const [createdMfaCode, setCreatedMfaCode] = useState('');

 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const { user } = useAuthStore();



 // Query para buscar estatísticas do sistema (apenas para admins)
 const { data: systemStats, isLoading: isLoadingStats } = useQuery({
 queryKey: ['admin-system-stats'],
 queryFn: async () => {
 const response = await adminService.getSystemStats();
 return response.data;
 },
 enabled: false, // Desabilitado pois não usamos mais a aba admin aqui
 refetchInterval: 30000, // Atualiza a cada 30 segundos
 });

 // Query para buscar usuários do sistema (apenas para admins)
 const { data: usersData, isLoading: isLoadingUsers } = useQuery({
 queryKey: ['admin-users'],
 queryFn: async () => {
 const response = await adminService.getUsers({ per_page: 50 });
 return response;
 },
 enabled: false, // Desabilitado pois não usamos mais a aba admin aqui
 });

 // Estados para configurações pessoais - carrega do usuário logado
 const [personalInfo, setPersonalInfo] = useState({
 name: user?.name || '',
 email: user?.email || '',
 phone: user?.phone || '',
 company: '',
 document: '',
 });

 const [isEditingPersonal, setIsEditingPersonal] = useState(false);
 const [tempPersonalInfo, setTempPersonalInfo] = useState(personalInfo);
 
 // Sincroniza informações pessoais quando o usuário for carregado
 useEffect(() => {
 if (user) {
 const updatedInfo = {
 name: user.name || '',
 email: user.email || '',
 phone: user.phone || '',
 company: '',
 document: '',
 };
 setPersonalInfo(updatedInfo);
 setTempPersonalInfo(updatedInfo);
 }
 }, [user]);

 const { data, isLoading } = useQuery({
 queryKey: ['api-keys'],
 queryFn: async () => {
 const response = await api.get('/api-keys');
 return response.data;
 },
 });

 const deleteMutation = useMutation({
 mutationFn: async (id: string) => {
 await api.delete(`/api-keys/${id}`);
 },
 onSuccess: () => {
 toast.success('Chave API removida!');
 queryClient.invalidateQueries({ queryKey: ['api-keys'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao remover chave API');
 },
 });

 const rotateMutation = useMutation({
 mutationFn: async ({ id, mfaCode }: { id: string; mfaCode: string }) => {
 return api.post(`/api-keys/${id}/rotate`, { mfa_code: mfaCode });
 },
 onSuccess: (response) => {
 toast.success('Chave API rotacionada!');
 setCreatedKey(response.data.data.key);
 setIsShowKeyModalOpen(true);
 queryClient.invalidateQueries({ queryKey: ['api-keys'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao rotacionar chave API');
 },
 });

 const generatePrivateKeyMutation = useMutation({
 mutationFn: async () => {
 return api.post('/api-keys', {
 name: 'Chave Privada - ' + new Date().toLocaleDateString('pt-BR'),
 type: 'secret',
 environment: 'production',
 permissions: ['*'],
 });
 },
 onSuccess: (response) => {
 toast.success('Chave privada gerada com sucesso!');
 setCreatedKey(response.data.data.key);
 setIsShowKeyModalOpen(true);
 queryClient.invalidateQueries({ queryKey: ['api-keys'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.error?.message || 'Erro ao gerar chave privada');
 },
 });

 const handleGeneratePrivateKey = () => {
 if (confirm('Gerar uma nova chave privada (sk_live_) com acesso completo em produção?')) {
 generatePrivateKeyMutation.mutate();
 }
 };

 // Usar dados da API
 const apiKeys: ApiKey[] = data?.data || [];

 // ===================== Webhooks =====================
 const { data: webhooksData, isLoading: loadingWebhooks } = useQuery({
 queryKey: ['user-webhooks'],
 queryFn: async () => {
 const response = await api.get('/webhooks');
 return response.data;
 },
 enabled: activeTab === 'webhooks',
 });

 const userWebhooks: UserWebhook[] = webhooksData?.data || [];

 // Split Config is now managed by the SplitManagement component

 const availableEvents: string[] = webhooksData?.available_events || [
 'charge.created', 'charge.paid', 'charge.failed', 'charge.refunded', 'charge.disputed',
 'payment.approved', 'payment.rejected', 'payment.refunded',
 'withdrawal.requested', 'withdrawal.completed', '*',
 ];

 const eventLabels: Record<string, string> = {
 'charge.created': 'Cobrança criada',
 'charge.paid': 'Pagamento confirmado',
 'charge.failed': 'Pagamento falhou',
 'charge.refunded': 'Reembolso processado',
 'charge.disputed': 'Disputa aberta',
 'payment.approved': 'Pagamento aprovado',
 'payment.rejected': 'Pagamento rejeitado',
 'payment.refunded': 'Pagamento reembolsado',
 'withdrawal.requested': 'Saque solicitado',
 'withdrawal.completed': 'Saque concluído',
 '*': 'Todos os eventos',
 };

 const createWebhookMutation = useMutation({
 mutationFn: async (data: { url: string; events: string[] }) => {
 return api.post('/webhooks', data);
 },
 onSuccess: (response) => {
 toast.success('Webhook criado com sucesso!');
 setNewlyCreatedSecret(response.data.data.secret);
 setShowWebhookModal(false);
 setWebhookUrl('');
 setWebhookEvents([]);
 queryClient.invalidateQueries({ queryKey: ['user-webhooks'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.message || 'Erro ao criar webhook');
 },
 });

 const updateWebhookMutation = useMutation({
 mutationFn: async ({ id, data }: { id: string; data: Partial<UserWebhook> }) => {
 return api.put(`/webhooks/${id}`, data);
 },
 onSuccess: () => {
 toast.success('Webhook atualizado!');
 setShowWebhookModal(false);
 setEditingWebhook(null);
 setWebhookUrl('');
 setWebhookEvents([]);
 queryClient.invalidateQueries({ queryKey: ['user-webhooks'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.message || 'Erro ao atualizar webhook');
 },
 });

 const deleteWebhookMutation = useMutation({
 mutationFn: async (id: string) => {
 return api.delete(`/webhooks/${id}`);
 },
 onSuccess: () => {
 toast.success('Webhook removido!');
 queryClient.invalidateQueries({ queryKey: ['user-webhooks'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.message || 'Erro ao remover webhook');
 },
 });

 const toggleWebhookMutation = useMutation({
 mutationFn: async ({ id, is_active }: { id: string; is_active: boolean }) => {
 return api.put(`/webhooks/${id}`, { is_active });
 },
 onSuccess: () => {
 toast.success('Webhook atualizado!');
 queryClient.invalidateQueries({ queryKey: ['user-webhooks'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.message || 'Erro ao atualizar webhook');
 },
 });

 const regenerateSecretMutation = useMutation({
 mutationFn: async (id: string) => {
 return api.post(`/webhooks/${id}/regenerate-secret`);
 },
 onSuccess: (response) => {
 toast.success('Secret regenerado! Copie o novo valor.');
 setNewlyCreatedSecret(response.data.data.secret);
 queryClient.invalidateQueries({ queryKey: ['user-webhooks'] });
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.message || 'Erro ao regenerar secret');
 },
 });

 const openEditWebhook = (webhook: UserWebhook) => {
 setEditingWebhook(webhook);
 setWebhookUrl(webhook.url);
 setWebhookEvents(webhook.events);
 setShowWebhookModal(true);
 };

 const openNewWebhook = () => {
 setEditingWebhook(null);
 setWebhookUrl('');
 setWebhookEvents([]);
 setShowWebhookModal(true);
 };

 const handleDelete = (key: ApiKey) => {
 if (confirm('Tem certeza que deseja remover esta chave API?')) {
 deleteMutation.mutate(key.id);
 }
 };

 const handleRotate = (key: ApiKey) => {
 if (confirm('Rotacionar esta chave invalidará a anterior. Continuar?')) {
 setPendingRotateKeyId(key.id);
 setMfaAction('rotate');
 setMfaModalOpen(true);
 }
 };

 const handleMfaConfirm = (code: string) => {
 if (mfaAction === 'create') {
 setMfaModalOpen(false);
 setCreatedMfaCode(code);
 setIsCreateModalOpen(true);
 } else if (mfaAction === 'rotate' && pendingRotateKeyId) {
 setMfaLoading(true);
 rotateMutation.mutate({ id: pendingRotateKeyId, mfaCode: code }, {
 onSettled: () => {
 setMfaLoading(false);
 setMfaModalOpen(false);
 setPendingRotateKeyId(null);
 setMfaAction(null);
 },
 });
 }
 };

 const handleNewKeyClick = () => {
 if (!user?.mfa_enabled) {
 toast.error('Ative a autenticação de dois fatores antes de criar chaves API.');
 return;
 }
 setMfaAction('create');
 setMfaModalOpen(true);
 };

 const handleKeyCreated = (key: string) => {
 setIsCreateModalOpen(false);
 setCreatedKey(key);
 setIsShowKeyModalOpen(true);
 };

 const handleSavePersonalInfo = () => {
 setPersonalInfo(tempPersonalInfo);
 setIsEditingPersonal(false);
 toast.success('Informações atualizadas com sucesso!');
 };

 const handleCancelEdit = () => {
 setTempPersonalInfo(personalInfo);
 setIsEditingPersonal(false);
 };

 return (
 <div className="space-y-4 sm:space-y-6">
 {/* Header */}
 <div>
 <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Configurações</h1>
 <p className="text-sm text-tx-muted mt-1">
 Gerencie suas informações pessoais, chaves API e configurações de integração
 </p>
 </div>
 {showMfaRequiredBanner && (
 <div className="rounded-lg border border-amber-400 bg-amber-50 dark:bg-amber-900/20 p-3 text-amber-900 dark:text-amber-200 mb-4">
 <div className="flex items-start gap-2">
 <ShieldExclamationIcon className="h-5 w-5 mt-0.5 flex-shrink-0 text-amber-600 dark:text-amber-400" />
 <div>
 <p className="text-sm font-semibold mb-1">Autenticação de Dois Fatores Obrigatória</p>
 <p className="text-sm">
 Você precisa ativar a autenticação de dois fatores (2FA) para acessar o painel administrativo. 
 Configure abaixo na seção "Autenticação de Dois Fatores (2FA)".
 </p>
 </div>
 </div>
 </div>
 )}

 {/* Tabs */}
 <div className="border-b border-border-DEFAULT">
 <nav className="-mb-px flex space-x-4 sm:space-x-8 overflow-x-auto">
 <button
 onClick={() => setActiveTab('personal')}
 className={`${
 activeTab === 'personal'
 ? 'border-accent-primary text-accent-primary'
 : 'border-transparent text-tx-muted hover:border-border-DEFAULT hover:text-tx-secondary'
 } flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition-colors`}
 >
 <UserIcon className="h-5 w-5" />
 Informações Pessoais
 </button>
 <button
 onClick={() => setActiveTab('security')}
 className={`${
 activeTab === 'security'
 ? 'border-accent-primary text-accent-primary'
 : 'border-transparent text-tx-muted hover:border-border-DEFAULT hover:text-tx-secondary'
 } flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition-colors`}
 >
 <LockClosedIcon className="h-5 w-5" />
 Segurança
 </button>
 <button
 onClick={() => setActiveTab('appearance')}
 className={`${
 activeTab === 'appearance'
 ? 'border-accent-primary text-accent-primary'
 : 'border-transparent text-tx-muted hover:border-border-DEFAULT hover:text-tx-secondary'
 } flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition-colors`}
 >
 <PaintBrushIcon className="h-5 w-5" />
 Aparência
 </button>
 <button
 onClick={() => setActiveTab('api')}
 className={`${
 activeTab === 'api'
 ? 'border-accent-primary text-accent-primary'
 : 'border-transparent text-tx-muted hover:border-border-DEFAULT hover:text-tx-secondary'
 } flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition-colors`}
 >
 <KeyIcon className="h-5 w-5" />
 Chaves API
 </button>
 <button
 onClick={() => setActiveTab('webhooks')}
 className={`${
 activeTab === 'webhooks'
 ? 'border-accent-primary text-accent-primary'
 : 'border-transparent text-tx-muted hover:border-border-DEFAULT hover:text-tx-secondary'
 } flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition-colors`}
 >
 <ShieldCheckIcon className="h-5 w-5" />
 Webhooks
 </button>
 <button
 onClick={() => setActiveTab('splits')}
 className={`${
 activeTab === 'splits'
 ? 'border-accent-primary text-accent-primary'
 : 'border-transparent text-tx-muted hover:border-border-DEFAULT hover:text-tx-secondary'
 } flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium transition-colors`}
 >
 <ChartBarIcon className="h-5 w-5" />
 Splits
 </button>
 </nav>
 </div>

 {/* Personal Info Tab */}
 {activeTab === 'personal' && (
 <>
 <div className="card">
 <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
 <div className="flex items-center gap-3">
 <UserIcon className="h-5 w-5 text-tx-muted" />
 <h2 className="text-lg font-semibold text-tx-primary">
 Informações Pessoais
 </h2>
 </div>
 {!isEditingPersonal ? (
 <button
 onClick={() => setIsEditingPersonal(true)}
 className="btn-primary"
 >
 Editar
 </button>
 ) : (
 <div className="flex gap-2">
 <button
 onClick={handleCancelEdit}
 className="btn-secondary"
 >
 Cancelar
 </button>
 <button
 onClick={handleSavePersonalInfo}
 className="btn-primary"
 >
 Salvar
 </button>
 </div>
 )}
 </div>

 <div className="p-4 sm:p-6">
 <div className="space-y-4 sm:space-y-6">
 <div className="grid gap-3 sm:gap-6 md:grid-cols-2">
 <div>
 <label className="mb-2 flex items-center gap-2 text-sm font-medium text-tx-secondary">
 <UserIcon className="h-4 w-4" />
 Nome Completo
 </label>
 {isEditingPersonal ? (
 <input
 type="text"
 value={tempPersonalInfo.name}
 onChange={(e) =>
 setTempPersonalInfo({ ...tempPersonalInfo, name: e.target.value })
 }
 className="input"
 />
 ) : (
 <p className="text-tx-primary">{personalInfo.name}</p>
 )}
 </div>

 <div>
 <label className="mb-2 flex items-center gap-2 text-sm font-medium text-tx-secondary">
 <EnvelopeIcon className="h-4 w-4" />
 E-mail
 </label>
 {isEditingPersonal ? (
 <input
 type="email"
 value={tempPersonalInfo.email}
 onChange={(e) =>
 setTempPersonalInfo({ ...tempPersonalInfo, email: e.target.value })
 }
 className="input"
 />
 ) : (
 <p className="text-tx-primary">{personalInfo.email}</p>
 )}
 </div>

 <div>
 <label className="mb-2 flex items-center gap-2 text-sm font-medium text-tx-secondary">
 <PhoneIcon className="h-4 w-4" />
 Telefone
 </label>
 {isEditingPersonal ? (
 <input
 type="tel"
 value={tempPersonalInfo.phone}
 onChange={(e) =>
 setTempPersonalInfo({ ...tempPersonalInfo, phone: e.target.value })
 }
 className="input"
 />
 ) : (
 <p className="text-tx-primary">{personalInfo.phone}</p>
 )}
 </div>

 <div>
 <label className="mb-2 flex items-center gap-2 text-sm font-medium text-tx-secondary">
 <BuildingOfficeIcon className="h-4 w-4" />
 Empresa
 </label>
 {isEditingPersonal ? (
 <input
 type="text"
 value={tempPersonalInfo.company}
 onChange={(e) =>
 setTempPersonalInfo({ ...tempPersonalInfo, company: e.target.value })
 }
 className="input"
 />
 ) : (
 <p className="text-tx-primary">{personalInfo.company}</p>
 )}
 </div>

 <div>
 <label className="mb-2 flex items-center gap-2 text-sm font-medium text-tx-secondary">
 <ShieldCheckIcon className="h-4 w-4" />
 CPF/CNPJ
 </label>
 {isEditingPersonal ? (
 <input
 type="text"
 value={tempPersonalInfo.document}
 onChange={(e) =>
 setTempPersonalInfo({ ...tempPersonalInfo, document: e.target.value })
 }
 className="input"
 />
 ) : (
 <p className="text-tx-primary">{personalInfo.document}</p>
 )}
 </div>
 </div>

 <div className="rounded-lg bg-accent-subtle p-4">
 <p className="text-sm text-accent-primary">
 <strong>Nota:</strong> Mantenha suas informações sempre atualizadas para
 garantir o melhor funcionamento da plataforma e receber notificações
 importantes.
 </p>
 </div>
 </div>
 </div>
 </div>

 {/* KYC Info Section */}
 <KycInfoSection />

 {/* Documents Section */}
 <DocumentsSection />
 </>
 )}

 {/* API Keys Tab */}
 {activeTab === 'api' && (
 <>
 {/* 2FA Required Alert */}
 {user && !user.mfa_enabled && (
 <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-700 rounded-lg px-3 sm:px-4 py-3 mb-4">
 <ShieldExclamationIcon className="h-5 w-5 sm:h-6 sm:w-6 text-amber-500 flex-shrink-0" />
 <div className="flex-1 min-w-0">
 <p className="text-sm font-medium text-amber-800 dark:text-amber-200">
 Autenticação de dois fatores necessária
 </p>
 <p className="text-xs text-amber-600 dark:text-amber-400 mt-0.5">
 Para criar e gerenciar chaves API, você precisa ativar a autenticação de dois fatores na aba Segurança.
 </p>
 </div>
 <button
 onClick={() => setActiveTab('security')}
 className="flex-shrink-0 text-xs font-semibold bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-md transition-colors w-full sm:w-auto text-center"
 >
 Ativar 2FA
 </button>
 </div>
 )}

 <div className={`card overflow-visible ${!user?.mfa_enabled ? 'opacity-60 pointer-events-none' : ''}`}>
 <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-border-DEFAULT px-4 sm:px-6 py-4">
 <div className="flex items-center gap-3">
 <KeyIcon className="h-5 w-5 text-tx-muted" />
 <h2 className="text-base sm:text-lg font-semibold text-tx-primary">Chaves API</h2>
 </div>
 <div className="flex items-center gap-2 sm:gap-3">
 <Link
 data-tour="api-docs-link"
 to="/docs"
 target="_blank"
 rel="noopener noreferrer"
 className="btn-secondary inline-flex items-center text-xs sm:text-sm"
 >
 <BookOpenIcon className="mr-1.5 sm:mr-2 h-4 w-4 sm:h-5 sm:w-5" />
 <span className="hidden sm:inline">Documentação API</span>
 <span className="sm:hidden">Docs</span>
 <ArrowTopRightOnSquareIcon className="ml-1 h-3.5 w-3.5 sm:h-4 sm:w-4" />
 </Link>
 <button
 data-tour="new-api-key"
 onClick={handleNewKeyClick}
 className="btn-primary inline-flex items-center text-xs sm:text-sm"
 >
 <PlusIcon className="mr-1.5 sm:mr-2 h-4 w-4 sm:h-5 sm:w-5" />
 Nova Chave
 </button>
 </div>
 </div>

 {/* Desktop Table */}
 <div className="hidden sm:block overflow-x-auto pb-24">
 <table className="min-w-full divide-y divide-border-DEFAULT">
 <thead className="bg-surface-page">
 <tr>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Nome
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Tipo
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Prefixo
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Ambiente
 </th>
 <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-tx-muted">
 Último uso
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
 <SectionSpinner />
 </td>
 </tr>
 ) : apiKeys.length === 0 ? (
 <tr>
 <td colSpan={7} className="px-6 py-12 text-center text-tx-muted">
 Nenhuma chave API encontrada
 </td>
 </tr>
 ) : (
 apiKeys.map((key) => (
 <tr
 key={key.id}
 className="hover:bg-surface-page"
 >
 <td className="whitespace-nowrap px-6 py-4">
 <div className="flex items-center">
 <ShieldCheckIcon className="mr-2 h-5 w-5 text-tx-muted" />
 <span className="font-medium text-tx-primary">
 {key.name}
 </span>
 </div>
 </td>
 <td className="whitespace-nowrap px-6 py-4">
 <span
 className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
 (key.type || 'public') === 'secret'
 ? 'bg-accent-subtle text-accent-primary0/20'
 : 'bg-accent-subtle text-accent-primary'
 }`}
 >
 {(key.type || 'public') === 'secret' ? 'Secreta (sk_)' : 'Pública (pk_)'}
 </span>
 </td>
 <td className="whitespace-nowrap px-6 py-4">
 <code className="rounded bg-surface-hover px-2 py-1 text-xs font-mono text-tx-primary">
 {key.key_prefix}...
 </code>
 </td>
 <td className="whitespace-nowrap px-6 py-4">
 <span
 className={`badge ${
 key.environment === 'production'
 ? 'badge-success'
 : 'badge-warning'
 }`}
 >
 {key.environment === 'production' ? 'Produção' : 'Sandbox'}
 </span>
 </td>
 <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
 {key.last_used_at ? formatDate(key.last_used_at) : 'Nunca'}
 </td>
 <td className="whitespace-nowrap px-6 py-4 text-sm text-tx-muted">
 {formatDate(key.created_at)}
 </td>
 <td className="whitespace-nowrap px-6 py-4 text-right text-sm font-medium">
 <Menu as="div" className="relative inline-block text-left">
 <Menu.Button className="rounded p-1 hover:bg-surface-hover">
 <EllipsisVerticalIcon className="h-5 w-5 text-tx-muted" />
 </Menu.Button>
 <Transition
 as={Fragment}
 enter="transition ease-out duration-100"
 enterFrom="transform opacity-0 scale-95"
 enterTo="transform opacity-100 scale-100"
 leave="transition ease-in duration-75"
 leaveFrom="transform opacity-100 scale-100"
 leaveTo="transform opacity-0 scale-95"
 >
 <Menu.Items className="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-lg bg-surface-card ring-1 ring-black ring-opacity-5 focus:outline-none">
 <div className="py-1">
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handleRotate(key)}
 className={`${
 active ? 'bg-surface-hover' : ''
 } flex w-full items-center px-4 py-2 text-sm text-tx-secondary`}
 >
 <ArrowPathIcon className="mr-3 h-5 w-5" />
 Rotacionar
 </button>
 )}
 </Menu.Item>
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handleDelete(key)}
 className={`${
 active ? 'bg-surface-hover' : ''
 } flex w-full items-center px-4 py-2 text-sm text-status-error`}
 >
 <TrashIcon className="mr-3 h-5 w-5" />
 Remover
 </button>
 )}
 </Menu.Item>
 </div>
 </Menu.Items>
 </Transition>
 </Menu>
 </td>
 </tr>
 ))
 )}
 </tbody>
 </table>
 </div>

 {/* Mobile Cards */}
 <div className="sm:hidden pb-6">
 {isLoading ? (
 <div className="px-4 py-12 text-center text-tx-muted">
 <SectionSpinner />
 </div>
 ) : apiKeys.length === 0 ? (
 <div className="px-4 py-12 text-center text-tx-muted">
 Nenhuma chave API encontrada
 </div>
 ) : (
 <div className="divide-y divide-border-DEFAULT">
 {apiKeys.map((key) => (
 <div key={key.id} className="p-4 space-y-3">
 <div className="flex items-center justify-between">
 <div className="flex items-center gap-2 min-w-0">
 <ShieldCheckIcon className="h-4 w-4 text-tx-muted flex-shrink-0" />
 <span className="font-medium text-sm text-tx-primary truncate">
 {key.name}
 </span>
 </div>
 <Menu as="div" className="relative">
 <Menu.Button className="rounded p-1 hover:bg-surface-hover">
 <EllipsisVerticalIcon className="h-5 w-5 text-tx-muted" />
 </Menu.Button>
 <Transition
 as={Fragment}
 enter="transition ease-out duration-100"
 enterFrom="transform opacity-0 scale-95"
 enterTo="transform opacity-100 scale-100"
 leave="transition ease-in duration-75"
 leaveFrom="transform opacity-100 scale-100"
 leaveTo="transform opacity-0 scale-95"
 >
 <Menu.Items className="absolute right-0 z-50 mt-2 w-44 origin-top-right rounded-lg bg-surface-card ring-1 ring-black ring-opacity-5 focus:outline-none shadow-float">
 <div className="py-1">
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handleRotate(key)}
 className={`${active ? 'bg-surface-hover' : ''} flex w-full items-center px-4 py-2 text-sm text-tx-secondary`}
 >
 <ArrowPathIcon className="mr-3 h-4 w-4" />
 Rotacionar
 </button>
 )}
 </Menu.Item>
 <Menu.Item>
 {({ active }) => (
 <button
 onClick={() => handleDelete(key)}
 className={`${active ? 'bg-surface-hover' : ''} flex w-full items-center px-4 py-2 text-sm text-status-error`}
 >
 <TrashIcon className="mr-3 h-4 w-4" />
 Remover
 </button>
 )}
 </Menu.Item>
 </div>
 </Menu.Items>
 </Transition>
 </Menu>
 </div>
 <div className="flex flex-wrap items-center gap-2">
 <span
 className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
 (key.type || 'public') === 'secret'
 ? 'bg-accent-subtle text-accent-primary'
 : 'bg-accent-subtle text-accent-primary'
 }`}
 >
 {(key.type || 'public') === 'secret' ? 'Secreta' : 'Pública'}
 </span>
 <span
 className={`badge text-xs ${
 key.environment === 'production' ? 'badge-success' : 'badge-warning'
 }`}
 >
 {key.environment === 'production' ? 'Produção' : 'Sandbox'}
 </span>
 </div>
 <div className="space-y-1.5 text-xs text-tx-muted">
 <div className="flex items-center justify-between">
 <span>Prefixo</span>
 <code className="rounded bg-surface-hover px-1.5 py-0.5 font-mono text-tx-primary">
 {key.key_prefix}...
 </code>
 </div>
 <div className="flex items-center justify-between">
 <span>Último uso</span>
 <span>{key.last_used_at ? formatDate(key.last_used_at) : 'Nunca'}</span>
 </div>
 <div className="flex items-center justify-between">
 <span>Criado em</span>
 <span>{formatDate(key.created_at)}</span>
 </div>
 </div>
 </div>
 ))}
 </div>
 )}
 </div>
 </div>

 {/* Withdrawal IP Whitelist Section */}
 <WithdrawalIpWhitelist />
 </>
 )}

 {/* Webhooks Tab */}
 {activeTab === 'webhooks' && (
 <div className="space-y-4 sm:space-y-6">

 {/* Delivery Logs Banner */}
 <div className="card flex items-center justify-between p-4 sm:p-5 border border-accent-subtle">
 <div className="flex items-center gap-3">
 <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-subtle">
 <SignalIcon className="h-5 w-5 text-accent-primary" />
 </div>
 <div>
 <p className="text-sm font-semibold text-tx-primary">Logs de Entrega</p>
 <p className="text-xs text-tx-muted">Monitore entregas, status e reenvie webhooks com falha</p>
 </div>
 </div>
 <a href="/webhook-logs" className="btn-primary flex items-center gap-2 text-sm">
 Ver Logs
 </a>
 </div>

 <div className="card p-4 sm:p-6">
 <div className="flex items-center justify-between">
 <div>
 <h3 className="text-lg font-semibold text-tx-primary">Webhooks</h3>
 <p className="mt-1 text-sm text-tx-secondary">
 Configure endpoints para receber notificações de eventos em tempo real.
 </p>
 </div>
 <button 
 className="btn-primary flex items-center gap-2"
 onClick={openNewWebhook}
 >
 <PlusIcon className="h-5 w-5" />
 Novo Webhook
 </button>
 </div>

 {loadingWebhooks ? (
 <SectionSpinner />
 ) : userWebhooks.length > 0 ? (
 <div className="mt-6 space-y-4">
 {userWebhooks.map((webhook) => (
 <div 
 key={webhook.id}
 className="rounded-card border border-border-DEFAULT p-5"
 >
 <div className="flex items-start justify-between">
 <div className="flex-1 min-w-0">
 <div className="flex items-center gap-3">
 <GlobeAltIcon className="h-5 w-5 flex-shrink-0 text-tx-muted" />
 <span className="font-medium text-tx-primary truncate">
 {webhook.url}
 </span>
 <span className={`flex-shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${
 webhook.is_active
 ? 'bg-status-success-subtle text-status-success'
 : 'bg-surface-hover text-tx-secondary'
 }`}>
 {webhook.is_active ? 'Ativo' : 'Inativo'}
 </span>
 </div>

 {/* Events */}
 <div className="mt-3 flex flex-wrap gap-1.5">
 {webhook.events.map((event) => (
 <span
 key={event}
 className="rounded-md bg-accent-subtle px-2 py-0.5 text-xs font-medium text-accent-primary"
 >
 {eventLabels[event] || event}
 </span>
 ))}
 </div>

 {webhook.created_at && (
 <p className="mt-2 text-xs text-tx-muted">
 Criado em {formatDate(webhook.created_at)}
 </p>
 )}
 </div>

 {/* Actions */}
 <div className="ml-4 flex items-center gap-1">
 <label className="relative inline-flex cursor-pointer items-center" title={webhook.is_active ? 'Desativar' : 'Ativar'}>
 <input 
 type="checkbox" 
 className="peer sr-only" 
 checked={webhook.is_active}
 onChange={(e) => toggleWebhookMutation.mutate({ 
 id: webhook.id, 
 is_active: e.target.checked 
 })}
 />
 <div className="peer h-5 w-9 rounded-full bg-surface-hover after:absolute after:left-[2px] after:top-[2px] after:h-4 after:w-4 after:rounded-full after:border after:border-border-DEFAULT after:bg-surface-card after:transition-all after:content-[''] peer-checked:bg-accent-primary peer-checked:after:translate-x-full peer-checked:after:border-white "></div>
 </label>
 <button
 className="rounded p-1.5 text-tx-muted hover:bg-surface-hover hover:text-tx-secondary"
 onClick={() => openEditWebhook(webhook)}
 title="Editar"
 >
 <CodeBracketIcon className="h-4 w-4" />
 </button>
 <button
 className="rounded p-1.5 text-status-error hover:bg-status-error-subtle hover:text-status-error"
 onClick={() => {
 if (confirm('Remover este webhook? Ele não receberá mais notificações.')) {
 deleteWebhookMutation.mutate(webhook.id);
 }
 }}
 title="Remover"
 >
 <TrashIcon className="h-4 w-4" />
 </button>
 </div>
 </div>
 </div>
 ))}
 </div>
 ) : (
 <div className="mt-8 flex flex-col items-center justify-center py-12 text-center">
 <GlobeAltIcon className="h-16 w-16 text-tx-muted" />
 <h4 className="mt-4 text-lg font-medium text-tx-primary">
 Nenhum webhook configurado
 </h4>
 <p className="mt-2 max-w-sm text-sm text-tx-muted">
 Crie um webhook para receber notificações de pagamentos, cobranças e outros eventos em tempo real.
 </p>
 <button
 className="btn-primary mt-6 flex items-center gap-2"
 onClick={openNewWebhook}
 >
 <PlusIcon className="h-5 w-5" />
 Criar Primeiro Webhook
 </button>
 </div>
 )}
 </div>

 {/* Events Reference Card */}
 <div className="card p-4 sm:p-6">
 <h4 className="text-sm font-semibold text-tx-primary">Eventos Disponíveis</h4>
 <div className="mt-3 grid gap-2 sm:grid-cols-2">
 {availableEvents.filter(e => e !== '*').map((event) => (
 <div key={event} className="flex items-center gap-2 text-sm">
 <span className="h-1.5 w-1.5 rounded-full bg-accent-primary"></span>
 <code className="text-xs text-tx-secondary">{event}</code>
 <span className="text-tx-muted">—</span>
 <span className="text-tx-secondary">{eventLabels[event] || event}</span>
 </div>
 ))}
 </div>
 </div>

 {/* Webhook Create/Edit Modal */}
 {showWebhookModal && (
 <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
 <div className="w-full max-w-md rounded-2xl bg-surface-card p-4 sm:p-6 shadow-xl ">
 <h3 className="text-lg font-semibold text-tx-primary">
 {editingWebhook ? 'Editar Webhook' : 'Novo Webhook'}
 </h3>
 <div className="mt-4 space-y-4">
 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 URL do Webhook
 </label>
 <input
 type="url"
 value={webhookUrl}
 onChange={(e) => setWebhookUrl(e.target.value)}
 placeholder="https://seu-servidor.com/webhook"
 className="input mt-1 w-full"
 />
 </div>
 <div>
 <label className="block text-sm font-medium text-tx-secondary">
 Eventos
 </label>
 <p className="text-xs text-tx-muted mt-1">
 Selecione os eventos que ativarão este webhook
 </p>
 <div className="mt-2 space-y-2 max-h-52 overflow-y-auto rounded-element border border-border-DEFAULT p-3">
 {/* All events option */}
 <label className="flex items-center gap-2 rounded p-1 hover:bg-surface-page">
 <input
 type="checkbox"
 checked={webhookEvents.includes('*')}
 onChange={(e) => {
 if (e.target.checked) {
 setWebhookEvents(['*']);
 } else {
 setWebhookEvents([]);
 }
 }}
 className="rounded border-border-DEFAULT text-accent-primary"
 />
 <span className="text-sm font-medium text-tx-primary">
 Todos os eventos (*)
 </span>
 </label>
 <hr className="border-border-DEFAULT" />
 {availableEvents.filter(e => e !== '*').map((event) => (
 <label key={event} className="flex items-center gap-2 rounded p-1 hover:bg-surface-page">
 <input
 type="checkbox"
 checked={webhookEvents.includes(event) || webhookEvents.includes('*')}
 disabled={webhookEvents.includes('*')}
 onChange={(e) => {
 if (e.target.checked) {
 setWebhookEvents([...webhookEvents, event]);
 } else {
 setWebhookEvents(webhookEvents.filter(ev => ev !== event));
 }
 }}
 className="rounded border-border-DEFAULT text-accent-primary"
 />
 <span className="text-sm text-tx-secondary">
 {eventLabels[event] || event}
 </span>
 <code className="ml-auto text-xs text-tx-muted">{event}</code>
 </label>
 ))}
 </div>
 </div>
 </div>
 <div className="mt-6 flex justify-end gap-3">
 <button
 className="btn-secondary"
 onClick={() => {
 setShowWebhookModal(false);
 setEditingWebhook(null);
 setWebhookUrl('');
 setWebhookEvents([]);
 }}
 >
 Cancelar
 </button>
 <button
 className="btn-primary"
 disabled={
 !webhookUrl || webhookEvents.length === 0 || 
 createWebhookMutation.isPending || updateWebhookMutation.isPending
 }
 onClick={() => {
 if (editingWebhook) {
 updateWebhookMutation.mutate({
 id: editingWebhook.id,
 data: { url: webhookUrl, events: webhookEvents },
 });
 } else {
 createWebhookMutation.mutate({ url: webhookUrl, events: webhookEvents });
 }
 }}
 >
 {createWebhookMutation.isPending || updateWebhookMutation.isPending
 ? 'Salvando...'
 : editingWebhook ? 'Salvar' : 'Criar Webhook'}
 </button>
 </div>
 </div>
 </div>
 )}
 </div>
 )}

 {/* Appearance Tab */}
 {activeTab === 'appearance' && (
 <AppearanceSection />
 )}

 {/* Splits Tab */}
 {activeTab === 'splits' && (
 <SplitManagement />
 )}

 {/* Security Tab */}
 {activeTab === 'security' && (
 <SecuritySection onMfaEnabled={() => setShowMfaRequiredBanner(false)} />
 )}

 {/* Modals */}
 <CreateApiKeyModal
 isOpen={isCreateModalOpen}
 onClose={() => { setIsCreateModalOpen(false); setCreatedMfaCode(''); }}
 onKeyCreated={handleKeyCreated}
 mfaCode={createdMfaCode}
 />

 <ShowKeyModal
 apiKey={createdKey}
 isOpen={isShowKeyModalOpen}
 onClose={() => {
 setIsShowKeyModalOpen(false);
 setCreatedKey(null);
 }}
 />

 <MfaCodeModal
 isOpen={mfaModalOpen}
 onClose={() => { setMfaModalOpen(false); setMfaAction(null); setPendingRotateKeyId(null); }}
 onConfirm={handleMfaConfirm}
 title={mfaAction === 'create' ? 'Criar Chave API' : 'Rotacionar Chave'}
 description={mfaAction === 'create' ? 'Confirme sua identidade para criar uma nova chave.' : 'Confirme sua identidade para rotacionar a chave.'}
 isLoading={mfaLoading}
 />
 </div>
 );
}

// ── Security Section ────────────────────────────────────────

function SecuritySection({ onMfaEnabled }: { onMfaEnabled?: () => void }) {
 const queryClient = useQueryClient();
 const { user, updateUser, checkAuth } = useAuthStore();

 // Password change state
 const [currentPassword, setCurrentPassword] = useState('');
 const [newPassword, setNewPassword] = useState('');
 const [confirmPassword, setConfirmPassword] = useState('');
 const [showCurrentPassword, setShowCurrentPassword] = useState(false);
 const [showNewPassword, setShowNewPassword] = useState(false);

 // MFA state
 const [mfaStep, setMfaStep] = useState<'idle' | 'setup' | 'confirm_disable'>('idle');
 const [mfaSecret, setMfaSecret] = useState('');
 const [mfaQrUrl, setMfaQrUrl] = useState('');
 const [mfaIssuer, setMfaIssuer] = useState('');
 const [mfaCode, setMfaCode] = useState('');
 const [disableCode, setDisableCode] = useState('');

 const platformName = useThemeStore((s) => s.platformName);
 const mfaEnabled = user?.mfa_enabled ?? false;
 // O emissor vem do backend (branding salvo no banco); o nome do tema é só o fallback.
 const issuerLabel = mfaIssuer || platformName;

 // Change password mutation
 const changePasswordMutation = useMutation({
   mutationFn: (data: { current_password: string; password: string; password_confirmation: string }) =>
     authService.changePassword(data),
   onSuccess: (result) => {
     toast.success(result.data?.message || 'Senha alterada com sucesso');
     setCurrentPassword('');
     setNewPassword('');
     setConfirmPassword('');
   },
   onError: (err: any) => {
     const msg = err.response?.data?.message || err.response?.data?.error?.message || 'Erro ao alterar senha';
     toast.error(msg);
   },
 });

 // Enable MFA mutation
 const enableMfaMutation = useMutation({
   mutationFn: () => authService.enableMfa(),
   onSuccess: (result) => {
     setMfaSecret(result.data.secret);
     setMfaQrUrl(result.data.qr_code_url);
     setMfaIssuer(result.data.issuer || '');
     setMfaStep('setup');
   },
   onError: (err: any) => {
     const message = err.response?.data?.message || 'Erro ao iniciar configuração MFA';

     if (message.toLowerCase().includes('mfa já está ativado') || message.toLowerCase().includes('already enabled')) {
       toast.success('MFA já estava ativado. Sincronizando estado...');
       updateUser({ mfa_enabled: true });
       setMfaStep('idle');
       checkAuth().catch(() => {});
       return;
     }

     toast.error(message);
   },
 });

 // Confirm MFA mutation
 const confirmMfaMutation = useMutation({
   mutationFn: async (code: string) => {
     const response = await authService.confirmMfa(code);
     return response;
   },
   onSuccess: (data) => {
     // Only show success if the response indicates success
     if (data?.success !== false) {
       toast.success('Autenticação de dois fatores ativada!');
       updateUser({ mfa_enabled: true });
       setMfaStep('idle');
       setMfaCode('');
       setMfaSecret('');
       setMfaQrUrl('');
       // Hide MFA required banner when user enables MFA
       onMfaEnabled?.();
       // Sync user state from backend to ensure mfa_enabled persists
       checkAuth().catch(() => {});
     }
   },
   onError: (err: any) => {
     // Only show error toast, don't show success
     const message = err.response?.data?.message || err.response?.data?.error?.message || 'Código inválido';
     toast.error(message);

     // Segredo expirou no servidor: volta ao botão para gerar outro QR code.
     if (err.response?.data?.error?.code === 'mfa_setup_expired') {
       setMfaStep('idle');
       setMfaCode('');
       setMfaSecret('');
       setMfaQrUrl('');
     }
   },
 });

 // Disable MFA mutation
 const disableMfaMutation = useMutation({
   mutationFn: (code: string) => authService.disableMfa(code),
   onSuccess: () => {
     toast.success('Autenticação de dois fatores desativada');
     updateUser({ mfa_enabled: false });
     setMfaStep('idle');
     setDisableCode('');
     checkAuth().catch(() => {});
   },
   onError: (err: any) => {
     toast.error(err.response?.data?.message || 'Código inválido');
   },
 });

 const handleChangePassword = () => {
   if (!currentPassword || !newPassword || !confirmPassword) {
     toast.error('Preencha todos os campos');
     return;
   }
   if (newPassword.length < 8) {
     toast.error('A nova senha deve ter no mínimo 8 caracteres');
     return;
   }
   if (newPassword !== confirmPassword) {
     toast.error('As senhas não coincidem');
     return;
   }
   changePasswordMutation.mutate({
     current_password: currentPassword,
     password: newPassword,
     password_confirmation: confirmPassword,
   });
 };

 return (
   <div className="space-y-4 sm:space-y-6">
     {/* Change Password Card */}
     <div className="card">
       <div className="flex items-center gap-3 border-b border-border-DEFAULT px-4 sm:px-6 py-4">
         <LockClosedIcon className="h-5 w-5 text-tx-muted" />
         <h2 className="text-lg font-semibold text-tx-primary">Alterar Senha</h2>
       </div>
       <div className="p-4 sm:p-6 space-y-4">
         <div>
           <label className="block text-sm font-medium text-tx-secondary mb-1">Senha Atual</label>
           <div className="relative">
             <input
               type={showCurrentPassword ? 'text' : 'password'}
               value={currentPassword}
               onChange={(e) => setCurrentPassword(e.target.value)}
               className="input pr-10"
               placeholder="Digite sua senha atual"
             />
             <button
               type="button"
               onClick={() => setShowCurrentPassword(!showCurrentPassword)}
               className="absolute inset-y-0 right-0 flex items-center pr-3 text-tx-muted hover:text-tx-primary"
             >
               {showCurrentPassword ? <EyeSlashIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
             </button>
           </div>
         </div>
         <div>
           <label className="block text-sm font-medium text-tx-secondary mb-1">Nova Senha</label>
           <div className="relative">
             <input
               type={showNewPassword ? 'text' : 'password'}
               value={newPassword}
               onChange={(e) => setNewPassword(e.target.value)}
               className="input pr-10"
               placeholder="Mínimo 8 caracteres"
             />
             <button
               type="button"
               onClick={() => setShowNewPassword(!showNewPassword)}
               className="absolute inset-y-0 right-0 flex items-center pr-3 text-tx-muted hover:text-tx-primary"
             >
               {showNewPassword ? <EyeSlashIcon className="h-4 w-4" /> : <EyeIcon className="h-4 w-4" />}
             </button>
           </div>
         </div>
         <div>
           <label className="block text-sm font-medium text-tx-secondary mb-1">Confirmar Nova Senha</label>
           <input
             type="password"
             value={confirmPassword}
             onChange={(e) => setConfirmPassword(e.target.value)}
             className="input"
             placeholder="Repita a nova senha"
           />
         </div>
         <div className="pt-2">
           <button
             onClick={handleChangePassword}
             disabled={changePasswordMutation.isPending}
             className="btn-primary"
           >
             {changePasswordMutation.isPending ? 'Alterando...' : 'Alterar Senha'}
           </button>
         </div>
       </div>
     </div>

     {/* Two-Factor Authentication Card */}
     <div className="card">
       <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
         <div className="flex items-center gap-3">
           <ShieldCheckIcon className="h-5 w-5 text-tx-muted" />
           <h2 className="text-lg font-semibold text-tx-primary">Autenticação de Dois Fatores (2FA)</h2>
         </div>
         {mfaEnabled ? (
           <span className="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400">
             <CheckCircleIcon className="h-3.5 w-3.5" />
             Online • Conectado
           </span>
         ) : (
           <span className="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-600 dark:bg-gray-500/20 dark:text-gray-400">
             Desativado
           </span>
         )}
       </div>
       <div className="p-4 sm:p-6">
         <p className="text-sm text-tx-muted mb-4">
           A autenticação de dois fatores adiciona uma camada extra de segurança à sua conta.
           Ao ativar, será necessário informar um código gerado pelo Google Authenticator a cada login.
         </p>

         {/* MFA is OFF — show enable button or setup flow */}
         {!mfaEnabled && mfaStep === 'idle' && (
           <button
             onClick={() => enableMfaMutation.mutate()}
             disabled={enableMfaMutation.isPending}
             className="btn-primary"
           >
             {enableMfaMutation.isPending ? 'Gerando...' : 'Ativar 2FA'}
           </button>
         )}

         {!mfaEnabled && mfaStep === 'setup' && (
           <div className="space-y-4">
             <div className="rounded-lg border border-border-DEFAULT bg-surface-page p-4">
               <p className="text-sm font-medium text-tx-primary mb-3">
                 1. Escaneie o QR Code abaixo com o Google Authenticator:
               </p>
               <div className="flex justify-center mb-3">
                 <img
                   src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(mfaQrUrl)}`}
                   alt="QR Code MFA"
                   className="rounded-lg"
                   width={200}
                   height={200}
                 />
               </div>
               <p className="text-xs text-tx-muted text-center mb-3">
                 No app, a conta aparece como <strong className="text-tx-secondary">{issuerLabel}</strong>.
                 Se já houver uma conta com esse nome de uma tentativa anterior, apague-a antes de escanear.
               </p>
               <p className="text-sm text-tx-muted mb-2">
                 2. Ou insira manualmente a chave secreta:
               </p>
               <div className="flex items-center gap-2">
                 <code className="flex-1 rounded bg-surface-hover px-3 py-2 text-sm font-mono text-tx-primary select-all break-all">
                   {mfaSecret}
                 </code>
                 <button
                   type="button"
                   onClick={() => {
                     navigator.clipboard.writeText(mfaSecret);
                     toast.success('Chave copiada!');
                   }}
                   className="p-2 text-tx-muted hover:text-tx-primary"
                 >
                   <ClipboardDocumentIcon className="h-4 w-4" />
                 </button>
               </div>
             </div>

             <div>
               <label className="block text-sm font-medium text-tx-secondary mb-1">
                 3. Digite o código de 6 dígitos gerado pelo app:
               </label>
               <div className="flex items-center gap-3">
                 <input
                   type="text"
                   value={mfaCode}
                   onChange={(e) => setMfaCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                   className="input w-40 text-center font-mono text-lg tracking-widest"
                   placeholder="000000"
                   maxLength={6}
                 />
                 <button
                   onClick={() => {
                     // Dismiss any existing toasts before attempting
                     toast.dismiss();
                     confirmMfaMutation.mutate(mfaCode);
                   }}
                   disabled={mfaCode.length !== 6 || confirmMfaMutation.isPending}
                   className="btn-primary"
                 >
                   {confirmMfaMutation.isPending ? 'Verificando...' : 'Confirmar e Ativar'}
                 </button>
               </div>
             </div>

             <button
               type="button"
               onClick={() => { setMfaStep('idle'); setMfaCode(''); }}
               className="text-sm text-tx-muted hover:text-tx-primary"
             >
               Cancelar
             </button>
           </div>
         )}

         {/* MFA is ON — show disable option */}
         {mfaEnabled && mfaStep === 'idle' && (
           <button
             onClick={() => setMfaStep('confirm_disable')}
             className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-status-error bg-status-error-subtle border border-status-error/20 rounded-element hover:bg-status-error/20 transition-colors"
           >
             Desativar 2FA
           </button>
         )}

         {mfaEnabled && mfaStep === 'confirm_disable' && (
           <div className="space-y-4">
             <div className="rounded-lg border border-status-error/20 bg-status-error-subtle p-4">
               <p className="text-sm text-status-error font-medium mb-1">
                 Para desativar o 2FA, digite o código atual do Google Authenticator:
               </p>
             </div>
             <div className="flex items-center gap-3">
               <input
                 type="text"
                 value={disableCode}
                 onChange={(e) => setDisableCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                 className="input w-40 text-center font-mono text-lg tracking-widest"
                 placeholder="000000"
                 maxLength={6}
               />
               <button
                 onClick={() => disableMfaMutation.mutate(disableCode)}
                 disabled={disableCode.length !== 6 || disableMfaMutation.isPending}
                 className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-status-error rounded-element hover:bg-red-700 transition-colors"
               >
                 {disableMfaMutation.isPending ? 'Desativando...' : 'Confirmar Desativação'}
               </button>
             </div>
             <button
               type="button"
               onClick={() => { setMfaStep('idle'); setDisableCode(''); }}
               className="text-sm text-tx-muted hover:text-tx-primary"
             >
               Cancelar
             </button>
           </div>
         )}
       </div>
     </div>
   </div>
 );
}

// Document label translations
const documentLabels: Record<string, string> = {
 identity_front: 'Documento de Identidade (Frente)',
 identity_back: 'Documento de Identidade (Verso)',
 selfie: 'Selfie com Documento',
 proof_of_address: 'Comprovante de Endereço',
 company_document: 'Contrato Social / CNPJ',
};

function KycInfoSection() {
 const { data: kycData, isLoading } = useQuery({
   queryKey: ['kyc-status'],
   queryFn: () => kycService.getStatus(),
 });

 const submission = kycData?.data?.submission;

 if (isLoading) {
   return (
     <div className="card mt-6">
       <div className="flex items-center justify-center py-8">
         <ArrowPathIcon className="h-5 w-5 animate-spin text-tx-muted" />
         <span className="ml-2 text-sm text-tx-muted">Carregando dados KYC...</span>
       </div>
     </div>
   );
 }

 if (!submission) {
   return null;
 }

 return (
   <div className="card mt-6">
     <div className="border-b border-border-DEFAULT px-6 py-4">
       <div className="flex items-center gap-3">
         <ShieldCheckIcon className="h-5 w-5 text-tx-muted" />
         <h2 className="text-lg font-semibold text-tx-primary">Informações Registradas no KYC</h2>
       </div>
     </div>

     <div className="p-6">
       <div className="space-y-6">
         {/* Personal/Company Type */}
         <div>
           <p className="text-xs font-medium text-tx-muted mb-1">TIPO DE CONTA</p>
           <p className="text-sm text-tx-primary capitalize">
             {submission.account_type === 'individual' ? 'Pessoa Física' : 'Pessoa Jurídica'}
           </p>
         </div>

         {/* Person Information */}
         <div className="grid gap-6 md:grid-cols-2">
           <div>
             <p className="text-xs font-medium text-tx-muted mb-1">NOME COMPLETO</p>
             <p className="text-sm text-tx-primary">{submission.full_name || '-'}</p>
           </div>

           <div>
             <p className="text-xs font-medium text-tx-muted mb-1">DOCUMENTO</p>
             <p className="text-sm text-tx-primary font-mono">{submission.document_number || '-'}</p>
           </div>

           {submission.birth_date && (
             <div>
               <p className="text-xs font-medium text-tx-muted mb-1">DATA DE NASCIMENTO</p>
               <p className="text-sm text-tx-primary">{formatDate(submission.birth_date)}</p>
             </div>
           )}

           {submission.phone && (
             <div>
               <p className="text-xs font-medium text-tx-muted mb-1">TELEFONE</p>
               <p className="text-sm text-tx-primary">{submission.phone}</p>
             </div>
           )}
         </div>

         {/* Company Information */}
         {submission.account_type === 'company' && (
           <div className="grid gap-6 md:grid-cols-2 pt-4 border-t border-border-subtle">
             {submission.company_name && (
               <div>
                 <p className="text-xs font-medium text-tx-muted mb-1">RAZÃO SOCIAL</p>
                 <p className="text-sm text-tx-primary">{submission.company_name}</p>
               </div>
             )}

             {submission.company_document && (
               <div>
                 <p className="text-xs font-medium text-tx-muted mb-1">CNPJ</p>
                 <p className="text-sm text-tx-primary font-mono">{submission.company_document}</p>
               </div>
             )}
           </div>
         )}

         {/* Address Information */}
         {(submission.address_street || submission.address_city) && (
           <div className="pt-4 border-t border-border-subtle">
             <p className="text-xs font-medium text-tx-muted mb-3">ENDEREÇO</p>
             <div className="grid gap-3 sm:gap-6 md:grid-cols-2">
               {submission.address_street && (
                 <div>
                   <p className="text-xs text-tx-muted">Rua</p>
                   <p className="text-sm text-tx-primary">{submission.address_street}</p>
                 </div>
               )}

               {submission.address_number && (
                 <div>
                   <p className="text-xs text-tx-muted">Número</p>
                   <p className="text-sm text-tx-primary">{submission.address_number}</p>
                 </div>
               )}

               {submission.address_complement && (
                 <div>
                   <p className="text-xs text-tx-muted">Complemento</p>
                   <p className="text-sm text-tx-primary">{submission.address_complement}</p>
                 </div>
               )}

               {submission.address_neighborhood && (
                 <div>
                   <p className="text-xs text-tx-muted">Bairro</p>
                   <p className="text-sm text-tx-primary">{submission.address_neighborhood}</p>
                 </div>
               )}

               {submission.address_city && (
                 <div>
                   <p className="text-xs text-tx-muted">Cidade</p>
                   <p className="text-sm text-tx-primary">{submission.address_city}</p>
                 </div>
               )}

               {submission.address_state && (
                 <div>
                   <p className="text-xs text-tx-muted">Estado</p>
                   <p className="text-sm text-tx-primary">{submission.address_state}</p>
                 </div>
               )}

               {submission.address_zip_code && (
                 <div>
                   <p className="text-xs text-tx-muted">CEP</p>
                   <p className="text-sm text-tx-primary font-mono">{submission.address_zip_code}</p>
                 </div>
               )}
             </div>
           </div>
         )}

         {/* Submission Date */}
         <div className="pt-4 border-t border-border-subtle text-xs text-tx-muted">
           Informações registradas em {formatDate(submission.created_at)}
         </div>
       </div>
     </div>
   </div>
 );
}

function DocumentsSection() {
 const { data: kycData, isLoading } = useQuery({
   queryKey: ['kyc-status'],
   queryFn: () => kycService.getStatus(),
 });

 const kycStatus = kycData?.data?.kyc_status;
 const documents = kycData?.data?.documents || {};
 const rejectionReason = kycData?.data?.rejection_reason;

 const getOverallStatusBadge = () => {
   switch (kycStatus) {
     case 'approved':
       return (
         <span className="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium rounded-full bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400">
           <CheckCircleIcon className="h-3.5 w-3.5" />
           Aprovado
         </span>
       );
     case 'under_review':
     case 'submitted':
       return (
         <span className="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400">
           <ClockIcon className="h-3.5 w-3.5" />
           Em Análise
         </span>
       );
     case 'rejected':
       return (
         <span className="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400">
           <ExclamationCircleIcon className="h-3.5 w-3.5" />
           Recusado
         </span>
       );
     default:
       return (
         <span className="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-600 dark:bg-gray-500/20 dark:text-gray-400">
           <ClockIcon className="h-3.5 w-3.5" />
           Pendente
         </span>
       );
   }
 };

 const getDocStatusIcon = (status: string) => {
   switch (status) {
     case 'approved':
       return <CheckCircleIcon className="h-5 w-5 text-green-500" />;
     case 'rejected':
       return <ExclamationCircleIcon className="h-5 w-5 text-red-500" />;
     case 'pending':
       return <ClockIcon className="h-5 w-5 text-amber-500" />;
     default:
       return <DocumentTextIcon className="h-5 w-5 text-tx-muted" />;
   }
 };

 const getDocStatusLabel = (status: string) => {
   switch (status) {
     case 'approved': return 'Aprovado';
     case 'rejected': return 'Recusado';
     case 'pending': return 'Em Análise';
     case 'not_uploaded': return 'Não Enviado';
     default: return status;
   }
 };

 const getDocStatusColor = (status: string) => {
   switch (status) {
     case 'approved': return 'text-green-600 bg-green-50 dark:text-green-400 dark:bg-green-500/10';
     case 'rejected': return 'text-red-600 bg-red-50 dark:text-red-400 dark:bg-red-500/10';
     case 'pending': return 'text-amber-600 bg-amber-50 dark:text-amber-400 dark:bg-amber-500/10';
     default: return 'text-gray-500 bg-gray-50 dark:text-gray-400 dark:bg-gray-500/10';
   }
 };

 const docEntries = Object.entries(documents);

 return (
   <div className="card mt-6">
     <div className="flex items-center justify-between border-b border-border-DEFAULT px-6 py-4">
       <div className="flex items-center gap-3">
         <DocumentTextIcon className="h-5 w-5 text-tx-muted" />
         <h2 className="text-lg font-semibold text-tx-primary">Documentos</h2>
       </div>
       {getOverallStatusBadge()}
     </div>

     <div className="p-6">
       {isLoading ? (
         <div className="flex items-center justify-center py-8">
           <ArrowPathIcon className="h-5 w-5 animate-spin text-tx-muted" />
           <span className="ml-2 text-sm text-tx-muted">Carregando documentos...</span>
         </div>
       ) : docEntries.length > 0 ? (
         <div className="space-y-3">
           {kycStatus === 'rejected' && rejectionReason && (
             <div className="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 p-4 mb-4">
               <p className="text-sm text-red-700 dark:text-red-400">
                 <strong>Motivo da recusa:</strong> {rejectionReason}
               </p>
             </div>
           )}

           {docEntries.map(([key, doc]) => (
             <div
               key={key}
               className="flex items-center justify-between p-4 rounded-lg border border-border-DEFAULT bg-surface-page"
             >
               <div className="flex items-center gap-3 min-w-0">
                 {getDocStatusIcon(doc.status)}
                 <div className="min-w-0">
                   <p className="text-sm font-medium text-tx-primary truncate">
                     {documentLabels[key] || doc.label || key}
                   </p>
                   {doc.status === 'rejected' && doc.rejection_reason && (
                     <p className="text-xs text-red-500 mt-0.5 truncate">
                       {doc.rejection_reason}
                     </p>
                   )}
                 </div>
               </div>
               <span className={`inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-full ${getDocStatusColor(doc.status)}`}>
                 {getDocStatusLabel(doc.status)}
               </span>
             </div>
           ))}
         </div>
       ) : (
         <div className="text-center py-8">
           <DocumentTextIcon className="mx-auto h-10 w-10 text-tx-muted mb-3" />
           <p className="text-sm font-medium text-tx-primary">Nenhum documento enviado</p>
           <p className="text-xs text-tx-muted mt-1">
             Envie seus documentos na verificação KYC para visualizá-los aqui.
           </p>
         </div>
       )}
     </div>
   </div>
 );
}

// ── Appearance Section ────────────────────────────────────────

function AppearanceSection() {
  const { themeVariant, setThemeVariant, theme } = useThemeStore();

  return (
    <div className="space-y-6">
      {/* Theme Variant */}
      <div className="card">
        <div className="flex items-center justify-between border-b border-border-DEFAULT px-4 sm:px-6 py-4">
          <div className="flex items-center gap-3">
            <PaintBrushIcon className="h-5 w-5 text-tx-muted" />
            <div>
              <h2 className="text-lg font-semibold text-tx-primary">Tema do Gateway</h2>
              <p className="text-sm text-tx-muted">Selecione o design visual do painel administrativo</p>
            </div>
          </div>
        </div>
        <div className="px-4 sm:px-6 py-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
            {themeList.map((t) => {
              const isSelected = themeVariant === t.id;
              const primaryHex = t.preview.primary;
              const accentHex = t.preview.accent;
              const bgHex = t.preview.bg;

              return (
                <button
                  key={t.id}
                  onClick={() => setThemeVariant(t.id)}
                  className={`relative rounded-2xl border-2 overflow-hidden text-left transition-all ${
                    isSelected
                      ? 'border-accent-primary ring-2 ring-accent-primary/20 shadow-lg'
                      : 'border-border-DEFAULT hover:border-border-hover hover:shadow-md'
                  }`}
                >
                  {isSelected && (
                    <div className="absolute top-3 right-3 z-10">
                      <CheckCircleIcon className="h-6 w-6 text-accent-primary" />
                    </div>
                  )}

                  {/* Preview mockup */}
                  <div className="p-4" style={{ backgroundColor: bgHex }}>
                    <div className="flex gap-2 h-28">
                      <div className="w-12 rounded-lg flex flex-col items-center gap-2 py-3" style={{ backgroundColor: theme === 'dark' ? '#1a1a2e' : '#ffffff', border: '1px solid #e5e7eb' }}>
                        <div className="h-3 w-3 rounded" style={{ backgroundColor: primaryHex }} />
                        <div className="h-2 w-6 rounded" style={{ backgroundColor: primaryHex, opacity: 0.3 }} />
                        <div className="h-2 w-6 rounded bg-gray-300" />
                        <div className="h-2 w-6 rounded bg-gray-300" />
                      </div>
                      <div className="flex-1 flex flex-col gap-2">
                        <div className="h-6 rounded-lg flex items-center px-2" style={{ backgroundColor: theme === 'dark' ? '#1a1a2e' : '#ffffff', border: '1px solid #e5e7eb' }}>
                          <div className="h-2 w-16 rounded" style={{ backgroundColor: primaryHex }} />
                        </div>
                        <div className="flex gap-2 flex-1">
                          <div className="flex-1 rounded-lg p-2 flex flex-col justify-between" style={{ backgroundColor: theme === 'dark' ? '#1a1a2e' : '#ffffff', border: '1px solid #e5e7eb' }}>
                            <div className="h-2 w-8 rounded bg-gray-300" />
                            <div className="h-4 w-12 rounded font-bold text-xs flex items-center" style={{ color: primaryHex }}>
                              R$ 1.2k
                            </div>
                          </div>
                          <div className="flex-1 rounded-lg p-2 flex flex-col justify-between" style={{ backgroundColor: theme === 'dark' ? '#1a1a2e' : '#ffffff', border: '1px solid #e5e7eb' }}>
                            <div className="h-2 w-8 rounded bg-gray-300" />
                            <div className="h-4 w-10 rounded font-bold text-xs flex items-center" style={{ color: accentHex }}>
                              42
                            </div>
                          </div>
                        </div>
                        <div className="h-5 rounded-lg flex items-center justify-center" style={{ background: `linear-gradient(135deg, ${primaryHex}, ${accentHex})` }}>
                          <div className="h-2 w-12 rounded bg-white/60" />
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* Label */}
                  <div className="px-4 py-3 bg-surface-primary border-t border-border-DEFAULT">
                    <div className="flex items-center gap-3">
                      <div className="flex -space-x-1">
                        <div className="h-4 w-4 rounded-full border-2 border-white" style={{ backgroundColor: primaryHex }} />
                        <div className="h-4 w-4 rounded-full border-2 border-white" style={{ backgroundColor: accentHex }} />
                      </div>
                      <div>
                        <p className="text-sm font-semibold text-tx-primary">{t.name}</p>
                        <p className="text-xs text-tx-muted">{t.description}</p>
                      </div>
                    </div>
                  </div>
                </button>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
}
