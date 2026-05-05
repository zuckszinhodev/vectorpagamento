import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { 
 DocumentTextIcon, 
 UserIcon, 
 HomeIcon, 
 CreditCardIcon,
 BuildingOfficeIcon,
 CheckCircleIcon,
 CloudArrowUpIcon,
 XMarkIcon,
 ClockIcon,
 ShieldCheckIcon,
 SparklesIcon,
 BanknotesIcon,
 IdentificationIcon
} from '@heroicons/react/24/outline';
import { 
 CheckCircleIcon as CheckCircleIconSolid 
} from '@heroicons/react/24/solid';
import toast from 'react-hot-toast';
import { kycService } from '@/services/api/kyc.service';
import { useAuthStore } from '@/stores/authStore';

// Função de validação de CPF
const validateCPF = (cpf: string): boolean => {
 const cleanCPF = cpf.replace(/\D/g, '');
 if (cleanCPF.length !== 11) return false;
 if (/^(\d)\1+$/.test(cleanCPF)) return false; // Todos dígitos iguais
 
 let sum = 0;
 for (let i = 0; i < 9; i++) {
 sum += parseInt(cleanCPF.charAt(i)) * (10 - i);
 }
 let digit = 11 - (sum % 11);
 if (digit > 9) digit = 0;
 if (digit !== parseInt(cleanCPF.charAt(9))) return false;
 
 sum = 0;
 for (let i = 0; i < 10; i++) {
 sum += parseInt(cleanCPF.charAt(i)) * (11 - i);
 }
 digit = 11 - (sum % 11);
 if (digit > 9) digit = 0;
 if (digit !== parseInt(cleanCPF.charAt(10))) return false;
 
 return true;
};

// Função de validação de CNPJ
const validateCNPJ = (cnpj: string): boolean => {
 const cleanCNPJ = cnpj.replace(/\D/g, '');
 if (cleanCNPJ.length !== 14) return false;
 if (/^(\d)\1+$/.test(cleanCNPJ)) return false;
 
 const weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
 const weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
 
 let sum = 0;
 for (let i = 0; i < 12; i++) {
 sum += parseInt(cleanCNPJ.charAt(i)) * weights1[i];
 }
 let digit = sum % 11 < 2 ? 0 : 11 - (sum % 11);
 if (digit !== parseInt(cleanCNPJ.charAt(12))) return false;
 
 sum = 0;
 for (let i = 0; i < 13; i++) {
 sum += parseInt(cleanCNPJ.charAt(i)) * weights2[i];
 }
 digit = sum % 11 < 2 ? 0 : 11 - (sum % 11);
 if (digit !== parseInt(cleanCNPJ.charAt(13))) return false;
 
 return true;
};

// Máscara de CPF
const formatCPF = (value: string): string => {
 const numbers = value.replace(/\D/g, '').slice(0, 11);
 return numbers
 .replace(/(\d{3})(\d)/, '$1.$2')
 .replace(/(\d{3})(\d)/, '$1.$2')
 .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
};

// Máscara de CNPJ
const formatCNPJ = (value: string): string => {
 const numbers = value.replace(/\D/g, '').slice(0, 14);
 return numbers
 .replace(/(\d{2})(\d)/, '$1.$2')
 .replace(/(\d{3})(\d)/, '$1.$2')
 .replace(/(\d{3})(\d)/, '$1/$2')
 .replace(/(\d{4})(\d{1,2})$/, '$1-$2');
};

type PersonType = 'pf' | 'pj';
type Step = 'type' | 'documents' | 'personal' | 'address' | 'financial' | 'review';

interface DocumentFile {
 name: string;
 file: File;
 preview?: string;
}

export default function KYCPage() {
 const navigate = useNavigate();
 const { user, updateUser } = useAuthStore();
 const [currentStep, setCurrentStep] = useState<Step>('type');
 const [personType, setPersonType] = useState<PersonType>('pf');
 const [isSubmitted, setIsSubmitted] = useState(false);
 const [isSubmitting, setIsSubmitting] = useState(false);
 const [redirectCountdown, setRedirectCountdown] = useState(10);

 // Auto-redirect to dashboard after 10 seconds when submitted
 useEffect(() => {
 if (!isSubmitted) return;
 
 setRedirectCountdown(10);
 const interval = setInterval(() => {
 setRedirectCountdown(prev => {
 if (prev <= 1) {
 clearInterval(interval);
 navigate('/dashboard');
 return 0;
 }
 return prev - 1;
 });
 }, 1000);
 
 return () => clearInterval(interval);
 }, [isSubmitted, navigate]);

 // Força modo claro apenas nesta página
 useEffect(() => {
 const html = document.documentElement;
 const hadDarkMode = html.classList.contains('dark');
 html.classList.remove('dark');
 
 return () => {
 // Restaura o modo anterior ao sair da página
 if (hadDarkMode) {
 html.classList.add('dark');
 }
 };
 }, []);
 
 // Fetch KYC status
 const { data: kycStatus, isLoading: isLoadingStatus } = useQuery({
 queryKey: ['kyc-status'],
 queryFn: () => kycService.getStatus(),
 staleTime: 0,
 });

 // Initialize form from existing data
 useEffect(() => {
 if (kycStatus?.data) {
 const { kyc_status, submission } = kycStatus.data;
 
 // If already approved, redirect to dashboard
 if (kyc_status === 'approved') {
 updateUser({ kyc_status: 'approved' });
 navigate('/dashboard');
 return;
 }

 // If submitted or under_review, show waiting screen
 if (kyc_status === 'submitted' || kyc_status === 'under_review') {
 setIsSubmitted(true);
 }

 // Pre-populate data from existing submission
 if (submission) {
 setPersonType(submission.account_type === 'company' ? 'pj' : 'pf');
 setPersonalData(prev => ({
 ...prev,
 fullName: submission.full_name || '',
 cpf: submission.document_number || '',
 birthDate: submission.birth_date || '',
 phone: submission.phone || '',
 companyName: submission.company_name || '',
 cnpj: submission.company_document || '',
 }));
 setAddressData(prev => ({
 ...prev,
 street: submission.address_street || '',
 number: submission.address_number || '',
 complement: submission.address_complement || '',
 neighborhood: submission.address_neighborhood || '',
 city: submission.address_city || '',
 state: submission.address_state || '',
 zipCode: submission.address_zip_code || '',
 }));
 
 // Skip to review if we have submission data
 if (kyc_status === 'pending' || kyc_status === 'rejected') {
 // Check what step to resume from based on available data
 if (submission.address_city) {
 setCurrentStep('financial');
 } else if (submission.full_name) {
 setCurrentStep('address');
 }
 }
 }
 }
 }, [kycStatus, navigate, updateUser]);

 // Documents
 const [documents, setDocuments] = useState<{
 identity: DocumentFile | null;
 identityBack: DocumentFile | null;
 cnpjCard: DocumentFile | null;
 socialContract: DocumentFile | null;
 }>({
 identity: null,
 identityBack: null,
 cnpjCard: null,
 socialContract: null
 });

 // Personal Data (PF)
 const [personalData, setPersonalData] = useState({
 fullName: user?.name || '',
 cpf: '',
 cnpj: '',
 companyName: '',
 tradeName: '',
 birthDate: '',
 phone: '',
 email: '',
 occupation: ''
 });

 // Address Data
 const [addressData, setAddressData] = useState({
 zipCode: '',
 street: '',
 number: '',
 complement: '',
 neighborhood: '',
 city: '',
 state: '',
 country: 'Brasil'
 });

 // Financial Data
 const [financialData, setFinancialData] = useState({
 pixType: 'cpf',
 pixKey: '',
 });

 const steps = [
 { id: 'type', name: 'Tipo de Pessoa', icon: UserIcon },
 { id: 'documents', name: 'Documentos', icon: DocumentTextIcon },
 { id: 'personal', name: 'Dados Pessoais', icon: UserIcon },
 { id: 'address', name: 'Endereço', icon: HomeIcon },
 { id: 'financial', name: 'Dados Financeiros', icon: CreditCardIcon },
 { id: 'review', name: 'Revisar', icon: CheckCircleIcon }
 ];

 const currentStepIndex = steps.findIndex(s => s.id === currentStep);

 const handleFileUpload = (field: keyof typeof documents, file: File) => {
 if (file.size > 5 * 1024 * 1024) {
 toast.error('Arquivo muito grande. Máximo 5MB');
 return;
 }

 const reader = new FileReader();
 reader.onloadend = () => {
 setDocuments(prev => ({
 ...prev,
 [field]: {
 name: file.name,
 file,
 preview: reader.result as string
 }
 }));
 };
 reader.readAsDataURL(file);
 toast.success('Arquivo carregado com sucesso');
 };

 const removeFile = (field: keyof typeof documents) => {
 setDocuments(prev => ({ ...prev, [field]: null }));
 };

 const handleNext = () => {
 const stepOrder: Step[] = ['type', 'documents', 'personal', 'address', 'financial', 'review'];
 const currentIndex = stepOrder.indexOf(currentStep);
 
 // Validate documents step
 if (currentStep === 'documents') {
 // PF requires: identity front, identity back
 // PJ requires: identity front, identity back, CNPJ card, social contract
 if (!documents.identity || !documents.identityBack) {
 toast.error('Envie a frente e o verso do documento de identidade');
 return;
 }
 
 if (personType === 'pj') {
 if (!documents.cnpjCard) {
 toast.error('Envie o cartão CNPJ');
 return;
 }
 if (!documents.socialContract) {
 toast.error('Envie o contrato social');
 return;
 }
 }
 }
 
 // Validate personal data step
 if (currentStep === 'personal') {
 if (personType === 'pf') {
 if (!personalData.fullName || !personalData.cpf || !personalData.birthDate || !personalData.phone) {
 toast.error('Preencha todos os campos obrigatórios');
 return;
 }
 if (!validateCPF(personalData.cpf)) {
 toast.error('CPF inválido. Verifique e tente novamente.');
 return;
 }
 } else {
 if (!personalData.companyName || !personalData.cnpj || !personalData.tradeName || !personalData.fullName || !personalData.cpf || !personalData.birthDate || !personalData.phone) {
 toast.error('Preencha todos os campos obrigatórios');
 return;
 }
 if (!validateCNPJ(personalData.cnpj)) {
 toast.error('CNPJ inválido. Verifique e tente novamente.');
 return;
 }
 if (!validateCPF(personalData.cpf)) {
 toast.error('CPF do representante legal inválido.');
 return;
 }
 }
 }
 
 // Validate address step
 if (currentStep === 'address') {
 if (!addressData.zipCode || !addressData.street || !addressData.number || !addressData.neighborhood || !addressData.city || !addressData.state) {
 toast.error('Preencha todos os campos de endereço obrigatórios');
 return;
 }
 }
 
 // Validate financial data step
 if (currentStep === 'financial') {
 if (!financialData.pixKey) {
 toast.error('Preencha a chave PIX');
 return;
 }
 }
 
 if (currentIndex < stepOrder.length - 1) {
 setCurrentStep(stepOrder[currentIndex + 1]);
 }
 };

 const handleBack = () => {
 const stepOrder: Step[] = ['type', 'documents', 'personal', 'address', 'financial', 'review'];
 const currentIndex = stepOrder.indexOf(currentStep);
 if (currentIndex > 0) {
 setCurrentStep(stepOrder[currentIndex - 1]);
 }
 };

 const handleSubmit = async () => {
 // Verify user is still authenticated before submitting
 const token = localStorage.getItem('auth_token');
 if (!token) {
 toast.error('Sessão expirada. Faça login novamente.');
 return;
 }

 setIsSubmitting(true);
 try {
 // 1. Upload documents
 const documentMapping: Record<string, string> = {
 identity: 'identity_front',
 identityBack: 'identity_back',
 cnpjCard: 'company_document',
 socialContract: 'company_document',
 };

 for (const [field, docType] of Object.entries(documentMapping)) {
 const doc = documents[field as keyof typeof documents];
 if (doc?.file) {
 await kycService.uploadDocument(docType, doc.file);
 }
 }

 // 2. Submit personal data
 await kycService.submit({
 full_name: personalData.fullName,
 birth_date: personalData.birthDate,
 document_number: personalData.cpf.replace(/\D/g, ''),
 phone: personalData.phone,
 address_street: addressData.street,
 address_number: addressData.number,
 address_complement: addressData.complement,
 address_neighborhood: addressData.neighborhood,
 address_city: addressData.city,
 address_state: addressData.state,
 address_zip_code: addressData.zipCode.replace(/\D/g, ''),
 account_type: personType === 'pf' ? 'individual' : 'company',
 company_name: personalData.companyName,
 company_document: personalData.cnpj?.replace(/\D/g, ''),
 pix_key: financialData.pixKey?.trim() || undefined,
 pix_type: financialData.pixType || undefined,
 });

 // 3. Submit for review
 await kycService.submitForReview();

 // Update user status
 updateUser({ kyc_status: 'submitted' });
 setIsSubmitted(true);
 toast.success('Verificação enviada com sucesso!');
 } catch (error: any) {
 const message = error?.response?.data?.error?.message || 'Erro ao enviar verificação';
 toast.error(message);
 } finally {
 setIsSubmitting(false);
 }
 };

 const searchZipCode = async (zipCode: string) => {
 if (zipCode.replace(/\D/g, '').length === 8) {
 try {
 const response = await fetch(`https://viacep.com.br/ws/${zipCode}/json/`);
 const data = await response.json();
 if (!data.erro) {
 setAddressData(prev => ({
 ...prev,
 street: data.logradouro,
 neighborhood: data.bairro,
 city: data.localidade,
 state: data.uf
 }));
 toast.success('CEP encontrado!');
 }
 } catch (error) {
 toast.error('Erro ao buscar CEP');
 }
 }
 };

 return (
 <div className="min-h-screen bg-gradient-to-br from-slate-50 via-white to-green-50 py-3 sm:py-5 px-3 sm:px-4">
 <div className="mx-auto max-w-2xl">
 {isLoadingStatus ? (
 <div className="flex flex-col items-center justify-center py-12">
 <div className="h-10 w-10 animate-spin rounded-full border-3 border-green-500 border-t-transparent" />
 <p className="mt-4 text-sm text-gray-500">Carregando...</p>
 </div>
 ) : isSubmitted ? (
 /* Success Screen */
 <div className="flex min-h-[50vh] items-center justify-center">
 <div className="text-center max-w-sm mx-auto p-4">
 <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500">
 <CheckCircleIconSolid className="h-8 w-8 text-white" />
 </div>
 
 <h1 className="mb-2 text-lg font-bold text-gray-900">
 Documentos Enviados!
 </h1>
 
 <p className="text-sm text-gray-600 mb-4">
 Estamos analisando suas informações.
 </p>
 
 <p className="text-xs text-gray-400">
 Redirecionando em {redirectCountdown}s...
 </p>

 <button
 onClick={() => navigate('/dashboard')}
 className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700"
 >
 Ir para Dashboard
 </button>
 </div>
 </div>
 ) : (
 <>
 {/* Header */}
 <div className="mb-4 sm:mb-6 text-center">
 <img src="/img/logo.png" alt="Vector Pagamentos" className="mx-auto mb-3 h-10 w-auto" />
 <h1 className="text-xl sm:text-2xl font-bold bg-gradient-to-r from-gray-900 via-green-800 to-green-900 bg-clip-text text-transparent mb-1.5">
 Verificação de Identidade
 </h1>
 <p className="text-xs sm:text-sm text-gray-600 max-w-md mx-auto">
 Complete seu cadastro para desbloquear todos os recursos
 </p>
 </div>

 {/* Progress Steps */}
 <div className="mb-4 sm:mb-6 overflow-x-auto pb-1">
 <div className="relative min-w-[400px] sm:min-w-0">
 {/* Progress Bar Background */}
 <div className="absolute top-4 left-[8%] right-[8%] h-0.5 bg-gray-200 rounded-full" style={{ zIndex: 0 }} />
 {/* Active Progress Bar */}
 <div 
 className="absolute top-4 left-[8%] h-0.5 bg-gradient-to-r from-green-500 to-green-500 rounded-full transition-all duration-500"
 style={{ 
 width: `${(currentStepIndex / (steps.length - 1)) * 84}%`,
 zIndex: 1
 }}
 />
 
 <div className="relative flex items-center justify-between px-1" style={{ zIndex: 2 }}>
 {steps.map((step, index) => {
 const Icon = step.icon;
 const isCompleted = index < currentStepIndex;
 const isCurrent = step.id === currentStep;
 
 return (
 <div key={step.id} className="flex flex-col items-center">
 <div
 className={`flex h-8 w-8 items-center justify-center rounded-full border-2 transition-all duration-300 ${
 isCompleted
 ? 'border-emerald-500 bg-emerald-500 text-white'
 : isCurrent
 ? 'border-green-500 bg-green-500 text-white ring-2 ring-green-500/20'
 : 'border-gray-300 bg-white text-gray-400'
 }`}
 >
 {isCompleted ? (
 <CheckCircleIconSolid className="h-4 w-4" />
 ) : (
 <Icon className="h-3.5 w-3.5" />
 )}
 </div>
 <span className={`mt-1.5 text-[9px] sm:text-[10px] font-medium text-center max-w-[50px] leading-tight ${
 isCurrent 
 ? 'text-green-700' 
 : isCompleted
 ? 'text-emerald-600'
 : 'text-gray-400'
 }`}>
 {step.name}
 </span>
 </div>
 );
 })}
 </div>
 </div>
 </div>

 {/* Content Card */}
 <div className="rounded-xl bg-white p-3 sm:p-4 md:p-5 shadow-lg border border-gray-100">
 {/* Step: Type Selection */}
 {currentStep === 'type' && (
 <div className="animate-fadeIn">
 <div className="text-center mb-4">
 <div className="inline-flex p-2 rounded-xl bg-gradient-to-br from-green-100 to-green-100 mb-2">
 <IdentificationIcon className="h-6 w-6 text-green-600" />
 </div>
 <h2 className="text-lg font-bold text-gray-900 mb-1">
 Qual o tipo de cadastro?
 </h2>
 <p className="text-xs text-gray-500">
 Selecione a opção que melhor se aplica
 </p>
 </div>

 <div className="grid grid-cols-2 gap-3">
 <button
 onClick={() => setPersonType('pf')}
 className={`group relative rounded-xl border-2 p-3 sm:p-4 text-center transition-all duration-200 ${
 personType === 'pf'
 ? 'border-green-500 bg-green-50 shadow-md'
 : 'border-gray-200 hover:border-green-300 hover:bg-green-50/30'
 }`}
 >
 {personType === 'pf' && (
 <div className="absolute top-2 right-2">
 <CheckCircleIconSolid className="h-4 w-4 text-green-500" />
 </div>
 )}
 <div className={`mx-auto mb-2 inline-flex p-2.5 rounded-xl transition-all ${
 personType === 'pf' 
 ? 'bg-green-500' 
 : 'bg-gray-100 group-hover:bg-green-100'
 }`}>
 <UserIcon className={`h-6 w-6 ${
 personType === 'pf' ? 'text-white' : 'text-gray-500 group-hover:text-green-600'
 }`} />
 </div>
 <h3 className="text-sm font-bold text-gray-900 mb-0.5">
 Pessoa Física
 </h3>
 <p className="text-xs text-gray-500 mb-2">
 Cadastro com CPF
 </p>
 <div className="text-[10px] text-gray-500 space-y-1 hidden sm:block">
 <div className="flex items-center gap-1 justify-center">
 <CheckCircleIcon className="h-3 w-3 text-emerald-500" />
 <span>Rápido</span>
 </div>
 </div>
 </button>

 <button
 onClick={() => setPersonType('pj')}
 className={`group relative rounded-xl border-2 p-3 sm:p-4 text-center transition-all duration-200 ${
 personType === 'pj'
 ? 'border-green-500 bg-green-50 shadow-md'
 : 'border-gray-200 hover:border-green-300 hover:bg-green-50/30'
 }`}
 >
 {personType === 'pj' && (
 <div className="absolute top-2 right-2">
 <CheckCircleIconSolid className="h-4 w-4 text-green-500" />
 </div>
 )}
 <div className={`mx-auto mb-2 inline-flex p-2.5 rounded-xl transition-all ${
 personType === 'pj' 
 ? 'bg-green-500' 
 : 'bg-gray-100 group-hover:bg-green-100'
 }`}>
 <BuildingOfficeIcon className={`h-6 w-6 ${
 personType === 'pj' ? 'text-white' : 'text-gray-500 group-hover:text-green-600'
 }`} />
 </div>
 <h3 className="text-sm font-bold text-gray-900 mb-0.5">
 Pessoa Jurídica
 </h3>
 <p className="text-xs text-gray-500 mb-2">
 Cadastro com CNPJ
 </p>
 <div className="text-[10px] text-gray-500 space-y-1 hidden sm:block">
 <div className="flex items-center gap-1 justify-center">
 <CheckCircleIcon className="h-3 w-3 text-emerald-500" />
 <span>Maiores limites</span>
 </div>
 </div>
 </button>
 </div>

 <div className="mt-4 p-3 rounded-lg bg-blue-50 border border-blue-100">
 <div className="flex items-start gap-2">
 <ShieldCheckIcon className="h-4 w-4 text-blue-600 mt-0.5 flex-shrink-0" />
 <p className="text-xs text-blue-700">
 Dados criptografados e protegidos (LGPD/PCI DSS)
 </p>
 </div>
 </div>
 </div>
 )}

 {/* Step: Documents */}
 {currentStep === 'documents' && (
 <div>
 <div className="text-center mb-4">
 <div className="inline-flex p-2 rounded-lg bg-blue-100 mb-2">
 <DocumentTextIcon className="h-5 w-5 text-blue-600" />
 </div>
 <h2 className="text-lg font-bold text-gray-900">
 Envio de Documentos
 </h2>
 </div>
 
 {personType === 'pf' ? (
 <div className="space-y-3">
 {/* Identity Front */}
 <FileUpload
 label="RG ou CNH (Frente)"
 description="Envie a frente do seu documento com foto"
 file={documents.identity}
 onUpload={(file) => handleFileUpload('identity', file)}
 onRemove={() => removeFile('identity')}
 required
 />

 {/* Identity Back */}
 <FileUpload
 label="RG ou CNH (Verso)"
 description="Envie o verso do seu documento"
 file={documents.identityBack}
 onUpload={(file) => handleFileUpload('identityBack', file)}
 onRemove={() => removeFile('identityBack')}
 required
 />
 </div>
 ) : (
 <div className="space-y-3">
 {/* CNPJ Card */}
 <FileUpload
 label="Cartão CNPJ"
 description="Documento oficial da Receita Federal"
 file={documents.cnpjCard}
 onUpload={(file) => handleFileUpload('cnpjCard', file)}
 onRemove={() => removeFile('cnpjCard')}
 required
 />

 {/* Social Contract */}
 <FileUpload
 label="Contrato Social"
 description="Documento de constituição da empresa"
 file={documents.socialContract}
 onUpload={(file) => handleFileUpload('socialContract', file)}
 onRemove={() => removeFile('socialContract')}
 required
 />

 {/* Identity of Representative */}
 <FileUpload
 label="RG ou CNH do Representante Legal (Frente)"
 description="Documento com foto do responsável"
 file={documents.identity}
 onUpload={(file) => handleFileUpload('identity', file)}
 onRemove={() => removeFile('identity')}
 required
 />

 <FileUpload
 label="RG ou CNH do Representante Legal (Verso)"
 description="Verso do documento do responsável"
 file={documents.identityBack}
 onUpload={(file) => handleFileUpload('identityBack', file)}
 onRemove={() => removeFile('identityBack')}
 required
 />
 </div>
 )}
 </div>
 )}

 {/* Step: Personal Data */}
 {currentStep === 'personal' && (
 <div>
 <h2 className="mb-3 text-lg font-bold text-gray-900">
 {personType === 'pf' ? 'Dados Pessoais' : 'Dados da Empresa'}
 </h2>
 
 <div className="space-y-3">
 {personType === 'pf' ? (
 <>
 <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 CPF *
 </label>
 <input
 type="text"
 value={personalData.cpf}
 onChange={(e) => setPersonalData({ ...personalData, cpf: formatCPF(e.target.value) })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 placeholder="000.000.000-00"
 maxLength={14}
 />
 </div>

 <div>
 <label className="block text-sm font-medium text-gray-600 mb-1">
 Data de Nascimento *
 </label>
 <input
 type="date"
 value={personalData.birthDate}
 onChange={(e) => setPersonalData({ ...personalData, birthDate: e.target.value })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 />
 </div>
 </div>


 </>
 ) : (
 <>
 <div>
 <label className="block text-sm font-medium text-gray-600 mb-1">
 Razão Social *
 </label>
 <input
 type="text"
 value={personalData.companyName}
 onChange={(e) => setPersonalData({ ...personalData, companyName: e.target.value })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 placeholder="Nome empresarial registrado"
 />
 </div>

 <div>
 <label className="block text-sm font-medium text-gray-600 mb-1">
 Nome Fantasia
 </label>
 <input
 type="text"
 value={personalData.tradeName}
 onChange={(e) => setPersonalData({ ...personalData, tradeName: e.target.value })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 placeholder="Nome comercial da empresa"
 />
 </div>

 <div>
 <label className="block text-sm font-medium text-gray-600 mb-1">
 CNPJ *
 </label>
 <input
 type="text"
 value={personalData.cnpj}
 onChange={(e) => setPersonalData({ ...personalData, cnpj: formatCNPJ(e.target.value) })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 placeholder="00.000.000/0000-00"
 maxLength={18}
 />
 </div>

 <div>
 <label className="block text-sm font-medium text-gray-600 mb-1">
 Nome do Representante Legal *
 </label>
 <input
 type="text"
 value={personalData.fullName}
 onChange={(e) => setPersonalData({ ...personalData, fullName: e.target.value })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 placeholder="Nome completo do responsável"
 />
 </div>

 <div>
 <label className="block text-sm font-medium text-gray-600 mb-1">
 CPF do Representante Legal *
 </label>
 <input
 type="text"
 value={personalData.cpf}
 onChange={(e) => setPersonalData({ ...personalData, cpf: formatCPF(e.target.value) })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 placeholder="000.000.000-00"
 maxLength={14}
 />
 </div>

 <div>
 <label className="block text-sm font-medium text-gray-600 mb-1">
 Data de Nascimento do Representante *
 </label>
 <input
 type="date"
 value={personalData.birthDate}
 onChange={(e) => setPersonalData({ ...personalData, birthDate: e.target.value })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 />
 </div>
 </>
 )}

 <div>
 <label className="block text-sm font-medium text-gray-600 mb-1">
 Telefone *
 </label>
 <input
 type="tel"
 value={personalData.phone}
 onChange={(e) => setPersonalData({ ...personalData, phone: e.target.value })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-green-500 focus:ring-2 focus:ring-green-500/20 transition-all placeholder:text-gray-400"
 placeholder="(00) 00000-0000"
 />
 </div>
 </div>
 </div>
 )}

 {/* Step: Address */}
 {currentStep === 'address' && (
 <div>
 <h2 className="mb-3 text-lg font-bold text-gray-900">
 Endereço {personType === 'pj' && 'da Empresa'}
 </h2>
 
 <div className="space-y-3">
 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 CEP *
 </label>
 <input
 type="text"
 value={addressData.zipCode}
 onChange={(e) => {
 setAddressData({ ...addressData, zipCode: e.target.value });
 searchZipCode(e.target.value);
 }}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20 placeholder:text-gray-400"
 placeholder="00000-000"
 />
 </div>

 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 Logradouro *
 </label>
 <input
 type="text"
 value={addressData.street}
 onChange={(e) => setAddressData({ ...addressData, street: e.target.value })}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20 placeholder:text-gray-400"
 placeholder="Rua, Avenida, etc"
 />
 </div>

 <div className="grid grid-cols-3 gap-3">
 <div className="col-span-1">
 <label className="block text-xs font-medium text-gray-600 mb-1">
 Número *
 </label>
 <input
 type="text"
 value={addressData.number}
 onChange={(e) => setAddressData({ ...addressData, number: e.target.value })}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20 placeholder:text-gray-400"
 placeholder="123"
 />
 </div>

 <div className="col-span-2">
 <label className="block text-xs font-medium text-gray-600 mb-1">
 Complemento
 </label>
 <input
 type="text"
 value={addressData.complement}
 onChange={(e) => setAddressData({ ...addressData, complement: e.target.value })}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20 placeholder:text-gray-400"
 placeholder="Apto, Bloco"
 />
 </div>
 </div>

 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 Bairro *
 </label>
 <input
 type="text"
 value={addressData.neighborhood}
 onChange={(e) => setAddressData({ ...addressData, neighborhood: e.target.value })}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20 placeholder:text-gray-400"
 placeholder="Nome do bairro"
 />
 </div>

 <div className="grid grid-cols-3 gap-3">
 <div className="col-span-2">
 <label className="block text-xs font-medium text-gray-600 mb-1">
 Cidade *
 </label>
 <input
 type="text"
 value={addressData.city}
 onChange={(e) => setAddressData({ ...addressData, city: e.target.value })}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20 placeholder:text-gray-400"
 placeholder="Nome da cidade"
 />
 </div>

 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 UF *
 </label>
 <input
 type="text"
 value={addressData.state}
 onChange={(e) => setAddressData({ ...addressData, state: e.target.value })}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20 placeholder:text-gray-400"
 placeholder="SP"
 maxLength={2}
 />
 </div>
 </div>
 </div>
 </div>
 )}

 {/* Step: Financial Data */}
 {currentStep === 'financial' && (
 <div>
 <h2 className="mb-3 text-lg font-bold text-gray-900">
 Dados Financeiros
 </h2>
 
 <div className="rounded-lg border border-gray-200 p-3 bg-white">
 <h3 className="mb-3 text-sm font-medium text-gray-900">
 Chave PIX para Recebimento
 </h3>
 
 <div className="space-y-3">
 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 Tipo de Chave *
 </label>
 <select
 value={financialData.pixType}
 onChange={(e) => setFinancialData({ ...financialData, pixType: e.target.value })}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20"
 >
 <option value="cpf">CPF</option>
 <option value="cnpj">CNPJ</option>
 <option value="email">E-mail</option>
 <option value="phone">Telefone</option>
 <option value="random">Chave Aleatória</option>
 </select>
 </div>

 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 Chave PIX *
 </label>
 <input
 type="text"
 value={financialData.pixKey}
 onChange={(e) => setFinancialData({ ...financialData, pixKey: e.target.value })}
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-green-500 focus:ring-1 focus:ring-green-500/20 placeholder:text-gray-400"
 placeholder="Digite sua chave PIX"
 />
 </div>
 </div>
 </div>
 </div>
 )}

 {/* Step: Review */}
 {currentStep === 'review' && (
 <div>
 <h2 className="mb-3 text-lg font-bold text-gray-900">
 Revise suas Informações
 </h2>
 
 <div className="space-y-3">
 {/* Review Summary */}
 <div className="rounded-lg bg-emerald-50 border border-emerald-200 p-3">
 <div className="flex items-center gap-2">
 <CheckCircleIcon className="h-5 w-5 text-emerald-600" />
 <div>
 <h3 className="text-sm font-medium text-emerald-900">
 Pronto para enviar!
 </h3>
 <p className="text-xs text-emerald-600">
 Revise as informações antes de finalizar
 </p>
 </div>
 </div>
 </div>

 {/* Summary Cards */}
 <div className="grid grid-cols-2 gap-2">
 <div className="rounded-lg border border-gray-200 p-2.5 bg-white">
 <h4 className="text-xs font-medium text-gray-500 mb-1">Tipo</h4>
 <p className="text-sm text-gray-900">
 {personType === 'pf' ? 'PF' : 'PJ'}
 </p>
 </div>

 <div className="rounded-lg border border-gray-200 p-2.5 bg-white">
 <h4 className="text-xs font-medium text-gray-500 mb-1">Docs</h4>
 <p className="text-sm text-gray-900">
 {Object.values(documents).filter(Boolean).length} enviados
 </p>
 </div>

 <div className="rounded-lg border border-gray-200 p-2.5 bg-white col-span-2">
 <h4 className="text-xs font-medium text-gray-500 mb-1">Chave PIX</h4>
 <p className="text-sm text-gray-900 truncate">
 {financialData.pixType}: {financialData.pixKey}
 </p>
 </div>
 </div>

 {/* Terms */}
 <div className="rounded-lg bg-gray-50 border border-gray-200 p-3">
 <label className="flex items-start gap-2 cursor-pointer">
 <input
 type="checkbox"
 className="mt-0.5 h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
 />
 <span className="text-xs text-gray-600">
 Declaro que as informações são verdadeiras.
 </span>
 </label>
 </div>
 </div>
 </div>
 )}

 {/* Navigation Buttons */}
 <div className="mt-5 flex flex-col-reverse sm:flex-row justify-between items-center gap-2 pt-4 border-t border-gray-100">
 <button
 onClick={handleBack}
 disabled={currentStep === 'type'}
 className="w-full sm:w-auto flex items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
 >
 <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
 </svg>
 Voltar
 </button>

 {currentStep === 'review' ? (
 <button
 onClick={handleSubmit}
 disabled={isSubmitting}
 className="w-full sm:w-auto flex items-center justify-center gap-1.5 rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-600 transition-all disabled:opacity-50"
 >
 {isSubmitting ? (
 <>
 <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
 Enviando...
 </>
 ) : (
 <>
 <CheckCircleIcon className="h-4 w-4" />
 Enviar
 </>
 )}
 </button>
 ) : (
 <button
 onClick={handleNext}
 className="w-full sm:w-auto flex items-center justify-center gap-1.5 rounded-lg bg-green-600 px-5 py-2 text-sm font-semibold text-white hover:bg-green-700 transition-all"
 >
 Continuar
 <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
 </svg>
 </button>
 )}
 </div>
 </div>
 </>
 )}
 </div>
 
 {/* CSS Animations */}
 <style>{`
 @keyframes fadeIn {
 from { opacity: 0; transform: translateY(10px); }
 to { opacity: 1; transform: translateY(0); }
 }
 .animate-fadeIn {
 animation: fadeIn 0.4s ease-out;
 }
 `}</style>
 </div>
 );
}

// File Upload Component
function FileUpload({ 
 label, 
 description, 
 file, 
 onUpload, 
 onRemove,
 required = false 
}: { 
 label: string;
 description: string;
 file: DocumentFile | null;
 onUpload: (file: File) => void;
 onRemove: () => void;
 required?: boolean;
}) {
 return (
 <div className="group rounded-lg border border-dashed border-gray-200 p-3 transition-all hover:border-green-400 hover:bg-green-50/30">
 <div className="flex items-start justify-between mb-2">
 <div>
 <h4 className="text-xs font-semibold text-gray-900 flex items-center gap-1.5">
 {label} 
 {required && (
 <span className="text-red-500 text-xs">*</span>
 )}
 </h4>
 <p className="text-[10px] text-gray-500 mt-0.5">
 {description}
 </p>
 </div>
 </div>

 {file ? (
 <div className="mt-2 flex items-center justify-between rounded-lg bg-emerald-50 p-2 border border-emerald-200">
 <div className="flex items-center gap-2 min-w-0">
 {file.preview && (
 <img src={file.preview} alt="" className="h-8 w-8 rounded object-cover flex-shrink-0" />
 )}
 <div className="min-w-0">
 <p className="text-xs font-medium text-gray-900 truncate">
 {file.name}
 </p>
 <p className="text-[10px] text-emerald-600">
 Enviado
 </p>
 </div>
 </div>
 <button
 onClick={onRemove}
 className="text-red-500 p-1 hover:bg-red-100 rounded"
 >
 <XMarkIcon className="h-4 w-4" />
 </button>
 </div>
 ) : (
 <label className="mt-2 flex cursor-pointer flex-col items-center gap-2 rounded-lg bg-gray-50 p-4 hover:bg-green-50 transition-all border border-transparent hover:border-green-200">
 <CloudArrowUpIcon className="h-6 w-6 text-green-500" />
 <span className="text-xs font-medium text-gray-700">Clique para upload</span>
 <span className="text-[10px] text-gray-400">PDF, JPG, PNG • 5MB máx</span>
 <input
 type="file"
 className="hidden"
 accept=".pdf,.jpg,.jpeg,.png"
 onChange={(e) => {
 const file = e.target.files?.[0];
 if (file) onUpload(file);
 }}
 />
 </label>
 )}
 </div>
 );
}


