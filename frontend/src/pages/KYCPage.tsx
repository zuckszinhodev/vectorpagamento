import { useState, useEffect, useMemo, useRef } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import QRCode from 'qrcode';
import { 
 DocumentTextIcon, 
 ArrowLeftIcon,
 ArrowRightIcon,
 UserIcon, 
 HomeIcon, 
 BuildingOfficeIcon,
 CheckCircleIcon,
 CloudArrowUpIcon,
 XMarkIcon,
 ClockIcon,
 ShieldCheckIcon,
 SparklesIcon,
 BanknotesIcon,
 IdentificationIcon,
 DevicePhoneMobileIcon,
 QrCodeIcon,
 CameraIcon,
 LockClosedIcon
} from '@heroicons/react/24/outline';
import { 
 CheckCircleIcon as CheckCircleIconSolid 
} from '@heroicons/react/24/solid';
import toast from 'react-hot-toast';
import { kycService } from '@/services/api/kyc.service';
import { useAuthStore } from '@/stores/authStore';
import { PageSpinner } from '@/components/ui/Spinner';

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
type Step = 'type' | 'documents' | 'facial' | 'personal' | 'address' | 'review';

interface DocumentFile {
 name: string;
 file: File;
 preview?: string;
}

function KycPersonIcon({ className = '' }: { className?: string }) {
 return (
 <svg viewBox="0 0 64 64" className={className} fill="none" aria-hidden="true">
 <path d="M32 35c8.3 0 15 5.8 15 13v2.5c0 1.4-1.1 2.5-2.5 2.5h-25c-1.4 0-2.5-1.1-2.5-2.5V48c0-7.2 6.7-13 15-13Z" stroke="currentColor" strokeWidth="4" strokeLinejoin="round" />
 <path d="M32 29c5.5 0 10-4.5 10-10S37.5 9 32 9 22 13.5 22 19s4.5 10 10 10Z" stroke="currentColor" strokeWidth="4" />
 <path d="M47 13h5.5c1.4 0 2.5 1.1 2.5 2.5V24" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
 <path d="M17 13h-5.5C10.1 13 9 14.1 9 15.5V24" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
 <path d="M47 51h5.5c1.4 0 2.5-1.1 2.5-2.5V40" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
 <path d="M17 51h-5.5C10.1 51 9 49.9 9 48.5V40" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
 </svg>
 );
}

function KycCompanyIcon({ className = '' }: { className?: string }) {
 return (
 <svg viewBox="0 0 64 64" className={className} fill="none" aria-hidden="true">
 <path d="M16 54V13.5c0-1.4 1.1-2.5 2.5-2.5h21c1.4 0 2.5 1.1 2.5 2.5V54" stroke="currentColor" strokeWidth="4" strokeLinejoin="round" />
 <path d="M42 25h7.5c1.4 0 2.5 1.1 2.5 2.5V54" stroke="currentColor" strokeWidth="4" strokeLinejoin="round" />
 <path d="M12 54h44" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
 <path d="M25 22h8M25 31h8M25 40h8" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
 <path d="M22 54V43.5c0-1.4 1.1-2.5 2.5-2.5h9c1.4 0 2.5 1.1 2.5 2.5V54" stroke="currentColor" strokeWidth="4" strokeLinejoin="round" />
 <path d="M48 35h-1M48 44h-1" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
 </svg>
 );
}

const FACIAL_INSTRUCTIONS = [
 'Centralize seu rosto no molde',
 'Aproxime um pouco o rosto',
 'Afaste um pouco o rosto',
 'Olhe levemente para a direita',
 'Olhe levemente para a esquerda',
 'Olhe para a camera',
 'Capturando automaticamente...',
];

function KycSubmittedScreen({
 redirectCountdown,
 onDashboard,
}: {
 redirectCountdown: number;
 onDashboard: () => void;
}) {
 const timeline = [
 ['Recebido', 'Documentos e selfie enviados'],
 ['Analise', 'Validacao de identidade em andamento'],
 ['Liberacao', 'Avisaremos quando estiver aprovado'],
 ];

 return (
 <div className="flex min-h-[72vh] items-center justify-center">
 <div className="w-full max-w-md rounded-[28px] border border-gray-200 bg-white p-6 text-center shadow-[0_24px_70px_rgba(15,23,42,0.10)] sm:p-8">
 <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-3xl bg-emerald-50 text-emerald-600 ring-8 ring-emerald-50/60">
 <CheckCircleIcon className="h-9 w-9" />
 </div>
 <p className="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-700">KYC em analise</p>
 <h1 className="mt-3 text-2xl font-semibold tracking-tight text-gray-950">Documentos enviados</h1>
 <p className="mx-auto mt-3 max-w-xs text-sm leading-6 text-gray-500">
 Recebemos suas informacoes com seguranca. Nossa equipe vai revisar os dados e liberar a conta assim que a validacao for concluida.
 </p>
 <div className="mt-6 flex flex-col gap-3">
 <button
 onClick={onDashboard}
 className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-gray-950 px-5 text-sm font-semibold text-white transition hover:bg-gray-800"
 >
 Ir para Dashboard
 <ArrowRightIcon className="h-4 w-4" />
 </button>
 <p className="text-xs text-gray-400">Redirecionando automaticamente em {redirectCountdown}s</p>
 </div>
 </div>
 </div>
 );
}

export default function KYCPage() {
 const navigate = useNavigate();
 const location = useLocation();
 const queryClient = useQueryClient();
 const { user, updateUser } = useAuthStore();
 const [currentStep, setCurrentStep] = useState<Step>('type');
 const [personType, setPersonType] = useState<PersonType>('pf');
 const [isSubmitted, setIsSubmitted] = useState(false);
 const [isSubmitting, setIsSubmitting] = useState(false);
 const [redirectCountdown, setRedirectCountdown] = useState(10);
 const [qrCodeUrl, setQrCodeUrl] = useState('');
 const [mobileSessionUrl, setMobileSessionUrl] = useState('');
 const [mobileSessionCode, setMobileSessionCode] = useState('');
 const [publicSessionName, setPublicSessionName] = useState('');
 const [isMobileViewport, setIsMobileViewport] = useState(false);
 const [isCameraOpen, setIsCameraOpen] = useState(false);
 const [isCameraLoading, setIsCameraLoading] = useState(false);
 const [facialInstructionIndex, setFacialInstructionIndex] = useState(0);
 const [facialCountdown, setFacialCountdown] = useState(FACIAL_INSTRUCTIONS.length);
 const videoRef = useRef<HTMLVideoElement | null>(null);
 const streamRef = useRef<MediaStream | null>(null);

 const isMobileKycLink = useMemo(() => {
 return new URLSearchParams(location.search).get('mobile') === '1';
 }, [location.search]);

 const mobileKycCode = useMemo(() => {
 return new URLSearchParams(location.search).get('kyc_code') || '';
 }, [location.search]);

 const shouldUseMobileFlow = isMobileKycLink || isMobileViewport;
 const isPublicMobileKyc = shouldUseMobileFlow && Boolean(mobileKycCode);

 const fallbackMobileKycUrl = useMemo(() => {
 if (typeof window === 'undefined') return '/kyc?mobile=1';
 return `${window.location.origin}/kyc?mobile=1`;
 }, []);

 const mobileKycUrl = mobileSessionUrl || fallbackMobileKycUrl;

 useEffect(() => {
 const updateViewport = () => {
 setIsMobileViewport(window.matchMedia('(max-width: 768px), (pointer: coarse)').matches);
 };

 updateViewport();
 window.addEventListener('resize', updateViewport);
 return () => window.removeEventListener('resize', updateViewport);
 }, []);

 useEffect(() => {
 QRCode.toDataURL(mobileKycUrl, {
 width: 232,
 margin: 1,
 color: {
 dark: '#111827',
 light: '#ffffff',
 },
 })
 .then(setQrCodeUrl)
 .catch(() => setQrCodeUrl(''));
 }, [mobileKycUrl]);

 useEffect(() => {
 if (shouldUseMobileFlow || isSubmitted) return;

 kycService.createMobileSession()
 .then((response) => {
 const rawUrl = response.data.url;
 const url = new URL(rawUrl);
 setMobileSessionCode(response.data.code);
 setMobileSessionUrl(`${window.location.origin}${url.pathname}${url.search}`);
 })
 .catch(() => {
 toast.error('Nao foi possivel gerar o codigo do KYC');
 });
 }, [shouldUseMobileFlow, isSubmitted]);

 useEffect(() => {
 if (!isPublicMobileKyc) return;

 kycService.getMobileSession(mobileKycCode)
 .then((response) => {
 const sessionName = response.data.user_name || '';
 setPublicSessionName(sessionName);
 if (sessionName) {
 setPersonalData(prev => ({
 ...prev,
 fullName: prev.fullName || sessionName,
 }));
 }
 })
 .catch(() => {
 toast.error('Codigo de KYC expirado ou invalido');
 });
 }, [isPublicMobileKyc, mobileKycCode]);

 const stopCamera = () => {
 streamRef.current?.getTracks().forEach(track => track.stop());
 streamRef.current = null;
 setIsCameraOpen(false);
 setFacialInstructionIndex(0);
 setFacialCountdown(FACIAL_INSTRUCTIONS.length);
 };

 useEffect(() => {
 return () => {
 streamRef.current?.getTracks().forEach(track => track.stop());
 streamRef.current = null;
 };
 }, []);

 // Auto-redirect to dashboard after 10 seconds when submitted
 useEffect(() => {
 if (!isSubmitted) return;
 
 setRedirectCountdown(10);
 const interval = setInterval(() => {
 setRedirectCountdown(prev => Math.max(prev - 1, 0));
 }, 1000);
 
 return () => clearInterval(interval);
 }, [isSubmitted]);

 useEffect(() => {
 if (!isSubmitted || redirectCountdown > 0) return;
 navigate('/dashboard');
 }, [isSubmitted, redirectCountdown, navigate]);

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
 enabled: !isPublicMobileKyc,
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
 setCurrentStep('review');
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
 selfie: DocumentFile | null;
 }>({
 identity: null,
 identityBack: null,
 cnpjCard: null,
 socialContract: null,
 selfie: null
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

 const steps = [
 { id: 'type', name: 'Tipo de Pessoa', icon: UserIcon },
 { id: 'documents', name: 'Documentos', icon: DocumentTextIcon },
 { id: 'facial', name: 'Facial', icon: CameraIcon },
 { id: 'personal', name: 'Dados Pessoais', icon: UserIcon },
 { id: 'address', name: 'Endereço', icon: HomeIcon },
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

 const startFacialCapture = async () => {
 const isLocalHost = ['localhost', '127.0.0.1'].includes(window.location.hostname);
 if (!window.isSecureContext && !isLocalHost) {
 toast.error('Para usar a camera no celular, abra o KYC por HTTPS');
 return;
 }

 if (!navigator.mediaDevices?.getUserMedia) {
 toast.error('Camera nao disponivel neste dispositivo');
 return;
 }

 setIsCameraLoading(true);
 try {
 const stream = await navigator.mediaDevices.getUserMedia({
 video: {
 facingMode: 'user',
 width: { ideal: 1280 },
 height: { ideal: 1280 },
 },
 audio: false,
 });

 streamRef.current = stream;
 setIsCameraOpen(true);
 } catch (error) {
 toast.error('Nao foi possivel acessar a camera');
 } finally {
 setIsCameraLoading(false);
 }
 };

 useEffect(() => {
 if (!isCameraOpen || !videoRef.current || !streamRef.current) return;

 const video = videoRef.current;
 video.srcObject = streamRef.current;
 video.play().catch(() => {
 toast.error('Toque em iniciar camera novamente para liberar o preview');
 });
 }, [isCameraOpen]);

 const captureFacialPhoto = () => {
 const video = videoRef.current;
 if (!video) return;
 if (!video.videoWidth || !video.videoHeight) {
 toast.error('Aguarde a camera carregar');
 return;
 }

 const canvas = document.createElement('canvas');
 canvas.width = video.videoWidth;
 canvas.height = video.videoHeight;
 const context = canvas.getContext('2d');
 if (!context) return;

 context.drawImage(video, 0, 0, canvas.width, canvas.height);
 canvas.toBlob((blob) => {
 if (!blob) {
 toast.error('Nao foi possivel capturar a foto');
 return;
 }

 const file = new File([blob], `selfie-kyc-${Date.now()}.jpg`, { type: 'image/jpeg' });
 handleFileUpload('selfie', file);
 stopCamera();
 toast.success('Verificacao facial capturada');
 }, 'image/jpeg', 0.86);
 };

 useEffect(() => {
 if (!isCameraOpen || documents.selfie) return;

 setFacialInstructionIndex(0);
 setFacialCountdown(FACIAL_INSTRUCTIONS.length);

 let captureTimeout: number | undefined;
 const interval = window.setInterval(() => {
 setFacialInstructionIndex((current) => {
 const next = Math.min(current + 1, FACIAL_INSTRUCTIONS.length - 1);
 return next;
 });

 setFacialCountdown((current) => {
 const next = Math.max(current - 1, 0);
 if (next === 0) {
 window.clearInterval(interval);
 captureTimeout = window.setTimeout(() => {
 captureFacialPhoto();
 }, 500);
 }
 return next;
 });
 }, 1200);

 return () => {
 window.clearInterval(interval);
 if (captureTimeout) window.clearTimeout(captureTimeout);
 };
 }, [isCameraOpen, documents.selfie]);

 const handleNext = () => {
 const stepOrder: Step[] = ['type', 'documents', 'facial', 'personal', 'address', 'review'];
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

 // Validate facial step
 if (currentStep === 'facial') {
 if (!documents.selfie) {
 toast.error('Faca a verificacao facial pelo celular');
 return;
 }
 }
 }
 
 // Validate personal data step
 if (currentStep === 'personal') {
 if (personType === 'pf') {
 if (!personalData.fullName?.trim()) {
 toast.error('Informe seu nome completo');
 return;
 }
 if (!personalData.cpf || !personalData.birthDate || !personalData.phone) {
 toast.error('Preencha CPF, nascimento e telefone');
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
 
 if (currentIndex < stepOrder.length - 1) {
 setCurrentStep(stepOrder[currentIndex + 1]);
 }
 };

 const handleBack = () => {
 const stepOrder: Step[] = ['type', 'documents', 'facial', 'personal', 'address', 'review'];
 const currentIndex = stepOrder.indexOf(currentStep);
 if (currentIndex > 0) {
 setCurrentStep(stepOrder[currentIndex - 1]);
 }
 };

 const handleSubmit = async () => {
 // Verify user is still authenticated before submitting
 const token = localStorage.getItem('auth_token');
 if (!token && !isPublicMobileKyc) {
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
 selfie: 'selfie',
 };

 for (const [field, docType] of Object.entries(documentMapping)) {
 const doc = documents[field as keyof typeof documents];
 if (doc?.file) {
 if (isPublicMobileKyc) {
 await kycService.uploadDocumentWithCode(mobileKycCode, docType, doc.file);
 } else {
 await kycService.uploadDocument(docType, doc.file);
 }
 }
 }

 // 2. Submit personal data
 const submitPayload = {
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
 };

 if (isPublicMobileKyc) {
 await kycService.submitWithCode(mobileKycCode, submitPayload);
 } else {
 await kycService.submit(submitPayload);
 }

 // 3. Submit for review
 if (isPublicMobileKyc) {
 await kycService.submitForReviewWithCode(mobileKycCode);
 } else {
 await kycService.submitForReview();
 }

 // Update user status
 updateUser({ kyc_status: 'submitted' });
 queryClient.invalidateQueries({ queryKey: ['notifications'] });
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

 const remoteKycStatus = kycStatus?.data?.kyc_status;
 const isWaitingReview = remoteKycStatus === 'submitted' || remoteKycStatus === 'under_review';

 if (!isLoadingStatus && !isSubmitted && !isWaitingReview && !shouldUseMobileFlow) {
 return (
 <div className="h-screen overflow-hidden bg-[#f6faf8] px-5 py-4 text-gray-950">
 <div className="mx-auto flex h-full max-w-5xl items-center">
 <div className="grid w-full gap-4 lg:grid-cols-[minmax(0,1fr)_390px]">
 <section className="rounded-3xl border border-emerald-950/10 bg-[#07120d] p-5 text-white shadow-xl shadow-emerald-950/10">
 <div className="flex h-full min-h-[360px] flex-col justify-between gap-8">
 <div>
 <div className="flex items-center justify-between">
 <span className="rounded-full border border-white/10 px-3 py-1 text-xs text-white/60">Etapa obrigatoria</span>
 </div>

 <div className="mt-6 max-w-2xl">
 <p className="text-sm font-medium text-emerald-200">Verificacao de identidade</p>
 <h1 className="mt-2 text-3xl font-semibold tracking-tight text-white lg:text-[38px]">
 Continue pelo celular
 </h1>
 <p className="mt-3 max-w-xl text-sm leading-6 text-white/70">
 Escaneie o QR Code para fotografar documentos e concluir a selfie facial com mais nitidez.
 </p>
 </div>
 </div>

 <div className="grid gap-3 sm:grid-cols-3">
 {[
 { number: '01', title: 'Escanear QR', desc: 'Abra o fluxo seguro', icon: QrCodeIcon },
 { number: '02', title: 'Documentos', desc: 'Fotografe frente e verso', icon: DocumentTextIcon },
 { number: '03', title: 'Selfie facial', desc: 'Confirme sua identidade', icon: CameraIcon },
 ].map((item) => {
 const Icon = item.icon;
 return (
 <div key={item.number} className="group relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.055] p-3 transition duration-300 hover:-translate-y-0.5 hover:bg-white/[0.08]">
 <div className="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-white/[0.05]" />
 <div className="relative flex items-start justify-between">
 <Icon className="h-8 w-8 text-white stroke-[1.6]" />
 <span className="rounded-full border border-white/10 bg-white/[0.07] px-2 py-1 text-[10px] font-semibold tracking-wider text-emerald-100">
 {item.number}
 </span>
 </div>
 <div className="relative mt-3">
 <p className="text-sm font-semibold text-white">{item.title}</p>
 <p className="mt-1 text-[11px] leading-4 text-white/52">{item.desc}</p>
 </div>
 </div>
 );
 })}
 </div>
 </div>
 </section>

 <aside className="rounded-3xl border border-gray-200 bg-white p-4 shadow-xl shadow-emerald-950/8">
 <div className="flex h-full min-h-[360px] flex-col">
 <div className="flex items-center justify-between">
 <div>
 <p className="text-xs font-semibold tracking-[0.18em] text-emerald-700">Use seu Celular</p>
 <h2 className="mt-1 text-lg font-semibold text-gray-950">Escaneie o QR Code</h2>
 </div>
 <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
 <QrCodeIcon className="h-5 w-5" />
 </div>
 </div>

 <div className="mt-3 rounded-3xl border border-gray-100 bg-gray-50 p-3">
 <div className="mx-auto flex h-36 w-36 items-center justify-center rounded-2xl bg-white p-2 shadow-inner">
 {qrCodeUrl && mobileSessionCode ? (
 <img src={qrCodeUrl} alt="QR Code para abrir o KYC no celular" className="h-full w-full" />
 ) : (
 <div className="h-9 w-9 animate-spin rounded-full border-2 border-emerald-600 border-t-transparent" />
 )}
 </div>
 </div>

 {mobileSessionCode && (
 <div className="mt-3 rounded-2xl border border-gray-100 bg-gray-50 px-4 py-2.5 text-center">
 <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400">Codigo da verificacao</p>
 <p className="mt-1 font-mono text-lg font-semibold tracking-[0.22em] text-gray-950">{mobileSessionCode}</p>
 </div>
 )}

 <div className="mt-3 grid gap-2">
 <div className="flex items-start gap-3 rounded-2xl border border-gray-100 bg-white p-2">
 <DevicePhoneMobileIcon className="mt-0.5 h-5 w-5 text-emerald-600" />
 <div>
 <p className="text-sm font-semibold text-gray-900">Use a camera do telefone</p>
 <p className="mt-1 text-xs leading-4 text-gray-500">Documentos e selfie facial em uma unica etapa.</p>
 </div>
 </div>
 <div className="flex items-start gap-3 rounded-2xl border border-gray-100 bg-white p-2">
 <LockClosedIcon className="mt-0.5 h-5 w-5 text-emerald-600" />
 <div>
 <p className="text-sm font-semibold text-gray-900">Sessao protegida</p>
 <p className="mt-1 text-xs leading-4 text-gray-500">Entre na mesma conta caso o celular solicite login.</p>
 </div>
 </div>
 </div>

 <button
 type="button"
 onClick={() => {
 navigator.clipboard?.writeText(mobileKycUrl);
 toast.success('Link copiado');
 }}
 className="mt-auto w-full rounded-2xl bg-gray-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700"
 >
 Copiar link
 </button>
 <button
 type="button"
 onClick={() => navigate('/dashboard')}
 className="mt-2 flex w-full items-center justify-center gap-2 rounded-2xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:border-emerald-200 hover:text-emerald-700"
 >
 <ArrowLeftIcon className="h-4 w-4" />
 Voltar
 </button>
 <button
 type="button"
 onClick={() => navigate('/kyc/privacy')}
 className="mt-3 text-center text-xs font-semibold text-gray-500 transition hover:text-emerald-700"
 >
 Politica de privacidade do KYC
 </button>
 </div>
 </aside>
 </div>
 </div>
 </div>
 );
 }

 return (
 <div className={shouldUseMobileFlow ? 'min-h-screen bg-[#f6f8f7] px-4 py-4 text-gray-950' : 'min-h-screen bg-gradient-to-br from-slate-50 via-white to-emerald-50 py-3 sm:py-5 px-3 sm:px-4'}>
 <div className={shouldUseMobileFlow ? 'mx-auto max-w-lg' : 'mx-auto max-w-2xl'}>
 {isLoadingStatus ? (
 <PageSpinner text="Carregando KYC..." />
 ) : isSubmitted ? (
 <KycSubmittedScreen redirectCountdown={redirectCountdown} onDashboard={() => navigate('/dashboard')} />
 ) : (
 <>
 {/* Header */}
 {shouldUseMobileFlow ? (
 <div className="mb-4 border-b border-gray-200 pb-4">
 <div className="flex items-start justify-between gap-3">
 <div>
 <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">Vector KYC</p>
 <h1 className="mt-1 text-2xl font-semibold tracking-tight text-gray-950">Verificacao de identidade</h1>
 <p className="mt-1 text-sm leading-5 text-gray-500">
 {publicSessionName || 'Complete a validacao para liberar sua conta.'}
 </p>
 </div>
 <div className="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5">
 <p className="text-xs font-semibold text-emerald-700">{currentStepIndex + 1}/{steps.length}</p>
 </div>
 </div>
 {(mobileKycCode || mobileSessionCode) && (
 <div className="mt-4 flex items-center justify-between rounded-2xl border border-gray-200 bg-white px-4 py-3">
 <span className="text-xs font-medium text-gray-500">Codigo da verificacao</span>
 <span className="font-mono text-sm font-semibold tracking-[0.18em] text-gray-950">{mobileKycCode || mobileSessionCode}</span>
 </div>
 )}
 </div>
 ) : (
 <div className="mb-4 sm:mb-6 text-center">
 <h1 className="text-xl sm:text-2xl font-bold bg-gradient-to-r from-gray-900 via-emerald-800 to-emerald-900 bg-clip-text text-transparent mb-1.5">
 Verificação de Identidade
 </h1>
 <p className="text-xs sm:text-sm text-gray-600 max-w-md mx-auto">
 Complete seu cadastro para desbloquear todos os recursos
 </p>
 </div>
 )}

 {/* Progress Steps */}
 {shouldUseMobileFlow ? (
 <div className="mb-4 rounded-2xl border border-gray-200 bg-white px-4 py-3">
 <div className="flex items-center justify-between">
 <div>
 <p className="text-xs font-medium text-gray-500">Etapa atual</p>
 <p className="mt-0.5 text-sm font-semibold text-gray-950">{steps[currentStepIndex]?.name}</p>
 </div>
 <p className="text-xs font-semibold text-emerald-700">{Math.round(((currentStepIndex + 1) / steps.length) * 100)}%</p>
 </div>
 <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100">
 <div
 className="h-full rounded-full bg-emerald-600 transition-all duration-500"
 style={{ width: `${((currentStepIndex + 1) / steps.length) * 100}%` }}
 />
 </div>
 </div>
 ) : (
 <div className="mb-4 sm:mb-6 overflow-x-auto pb-1">
 <div className="relative min-w-[400px] sm:min-w-0">
 {/* Progress Bar Background */}
 <div className="absolute top-4 left-[8%] right-[8%] h-0.5 bg-gray-200 rounded-full" style={{ zIndex: 0 }} />
 {/* Active Progress Bar */}
 <div 
 className="absolute top-4 left-[8%] h-0.5 bg-gradient-to-r from-emerald-500 to-emerald-500 rounded-full transition-all duration-500"
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
 ? 'border-emerald-500 bg-emerald-500 text-white ring-2 ring-emerald-500/20'
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
 ? 'text-emerald-700' 
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
 )}

 {/* Content Card */}
 <div className={shouldUseMobileFlow ? 'rounded-2xl border border-gray-200 bg-white p-5 shadow-sm' : 'rounded-xl bg-white p-3 sm:p-4 md:p-5 shadow-lg border border-gray-100'}>
 {/* Step: Type Selection */}
 {currentStep === 'type' && (
 <div className="animate-fadeIn">
 <div className="text-center mb-4">
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
 ? 'border-emerald-500 bg-emerald-50 shadow-md'
 : 'border-gray-200 hover:border-emerald-300 hover:bg-emerald-50/30'
 }`}
 >
 {personType === 'pf' && (
 <div className="absolute top-2 right-2">
 <CheckCircleIconSolid className="h-4 w-4 text-emerald-500" />
 </div>
 )}
 <div className={`mx-auto mb-2 inline-flex p-2.5 rounded-xl transition-all ${
 personType === 'pf' 
 ? 'bg-emerald-500' 
 : 'bg-gray-100 group-hover:bg-emerald-100'
 }`}>
 <KycPersonIcon className={`h-7 w-7 ${
 personType === 'pf' ? 'text-white' : 'text-gray-500 group-hover:text-emerald-600'
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
 ? 'border-emerald-500 bg-emerald-50 shadow-md'
 : 'border-gray-200 hover:border-emerald-300 hover:bg-emerald-50/30'
 }`}
 >
 {personType === 'pj' && (
 <div className="absolute top-2 right-2">
 <CheckCircleIconSolid className="h-4 w-4 text-emerald-500" />
 </div>
 )}
 <div className={`mx-auto mb-2 inline-flex p-2.5 rounded-xl transition-all ${
 personType === 'pj' 
 ? 'bg-emerald-500' 
 : 'bg-gray-100 group-hover:bg-emerald-100'
 }`}>
 <KycCompanyIcon className={`h-7 w-7 ${
 personType === 'pj' ? 'text-white' : 'text-gray-500 group-hover:text-emerald-600'
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

 {/* Step: Facial Recognition */}
 {currentStep === 'facial' && (
 <div>
 <div className="text-center mb-4">
 <div className="inline-flex p-2 rounded-lg bg-emerald-100 mb-2">
 <CameraIcon className="h-5 w-5 text-emerald-700" />
 </div>
 <h2 className="text-lg font-bold text-gray-900">
 Reconhecimento facial
 </h2>
 <p className="mt-1 text-xs text-gray-500">
 Use a camera frontal e mantenha o rosto centralizado.
 </p>
 </div>
 <div className="mt-4 rounded-xl border border-emerald-100 bg-emerald-50/60 p-3 sm:p-4">
 <div className="flex items-start justify-between gap-3">
 <div className="flex items-start gap-3">
 <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-white text-emerald-700 shadow-sm">
 <CameraIcon className="h-5 w-5" />
 </div>
 <div>
 <h3 className="text-sm font-bold text-gray-950">Reconhecimento facial</h3>
 <p className="mt-1 text-xs leading-5 text-gray-600">
 Capture uma selfie em ambiente iluminado, sem oculos escuros e com o rosto centralizado.
 </p>
 </div>
 </div>
 {documents.selfie && (
 <span className="rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">
 Capturado
 </span>
 )}
 </div>

 {isCameraOpen ? (
 <div className="mt-3 overflow-hidden rounded-2xl border border-emerald-200 bg-gray-950">
 <div className="relative aspect-square w-full overflow-hidden bg-gray-950">
 <video ref={videoRef} className="h-full w-full object-cover" playsInline muted autoPlay />
 <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
 <div
 className="h-[62%] w-[48%] rounded-[48%] border-2 border-emerald-300/95 shadow-[0_0_0_9999px_rgba(0,0,0,0.42),0_0_30px_rgba(110,231,183,0.35)]"
 />
 </div>
 <div className="pointer-events-none absolute left-3 right-3 top-3 rounded-2xl bg-gray-950/70 px-3 py-2 text-center backdrop-blur">
 <p className="text-xs font-semibold text-emerald-200">
 {FACIAL_INSTRUCTIONS[facialInstructionIndex]}
 </p>
 <p className="mt-0.5 text-[10px] text-white/60">
 Mantenha boa iluminacao e rosto dentro do molde
 </p>
 </div>
 <div className="pointer-events-none absolute bottom-3 left-3 right-3">
 <div className="overflow-hidden rounded-full bg-white/20">
 <div
 className="h-1.5 rounded-full bg-emerald-300 transition-all duration-500"
 style={{ width: `${((FACIAL_INSTRUCTIONS.length - facialCountdown) / FACIAL_INSTRUCTIONS.length) * 100}%` }}
 />
 </div>
 <p className="mt-2 text-center text-[10px] font-semibold uppercase tracking-[0.16em] text-white/70">
 Captura automatica
 </p>
 </div>
 </div>
 <div className="flex gap-2 bg-white p-2">
 <button
 type="button"
 onClick={stopCamera}
 className="flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700"
 >
 Cancelar
 </button>
 <button
 type="button"
 onClick={captureFacialPhoto}
 className="flex-1 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white"
 >
 Capturar agora
 </button>
 </div>
 </div>
 ) : documents.selfie ? (
 <div className="mt-3 flex items-center justify-between rounded-2xl border border-emerald-200 bg-white p-3">
 <div className="flex items-center gap-3">
 {documents.selfie.preview && (
 <img src={documents.selfie.preview} alt="" className="h-14 w-14 rounded-2xl object-cover" />
 )}
 <div>
 <p className="text-sm font-semibold text-gray-900">Selfie facial enviada</p>
 <p className="text-xs text-emerald-700">Pronta para analise KYC</p>
 </div>
 </div>
 <button
 type="button"
 onClick={() => removeFile('selfie')}
 className="rounded-xl p-2 text-red-500 hover:bg-red-50"
 >
 <XMarkIcon className="h-5 w-5" />
 </button>
 </div>
 ) : (
 <button
 type="button"
 onClick={startFacialCapture}
 disabled={isCameraLoading}
 className="mt-3 flex w-full items-center justify-center gap-2 rounded-2xl bg-gray-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
 >
 {isCameraLoading ? (
 <span className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
 ) : (
 <CameraIcon className="h-5 w-5" />
 )}
 Fazer verificacao facial
 </button>
 )}
 </div>
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
 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 Nome Completo *
 </label>
 <input
 type="text"
 value={personalData.fullName}
 onChange={(e) => setPersonalData({ ...personalData, fullName: e.target.value })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
 placeholder="Seu nome completo"
 />
 </div>

 <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
 <div>
 <label className="block text-xs font-medium text-gray-600 mb-1">
 CPF *
 </label>
 <input
 type="text"
 value={personalData.cpf}
 onChange={(e) => setPersonalData({ ...personalData, cpf: formatCPF(e.target.value) })}
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-xl border border-gray-200 px-3 py-2 text-gray-900 bg-white focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all placeholder:text-gray-400"
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
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 placeholder:text-gray-400"
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
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 placeholder:text-gray-400"
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
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 placeholder:text-gray-400"
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
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 placeholder:text-gray-400"
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
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 placeholder:text-gray-400"
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
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 placeholder:text-gray-400"
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
 className="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 bg-white focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 placeholder:text-gray-400"
 placeholder="SP"
 maxLength={2}
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
 </div>

 {/* Terms */}
 <div className="rounded-lg bg-gray-50 border border-gray-200 p-3">
 <label className="flex items-start gap-2 cursor-pointer">
 <input
 type="checkbox"
 className="mt-0.5 h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
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
 <div className={shouldUseMobileFlow ? 'mt-5 grid grid-cols-[1fr_1.35fr] items-center gap-2 pt-4 border-t border-gray-100' : 'mt-5 flex flex-col-reverse sm:flex-row justify-between items-center gap-2 pt-4 border-t border-gray-100'}>
 <button
 onClick={handleBack}
 disabled={currentStep === 'type'}
 className={shouldUseMobileFlow ? 'flex w-full items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 disabled:opacity-30 disabled:cursor-not-allowed transition-all' : 'w-full sm:w-auto flex items-center justify-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-30 disabled:cursor-not-allowed transition-all'}
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
 className={shouldUseMobileFlow ? 'flex w-full items-center justify-center gap-1.5 rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition-all disabled:opacity-50' : 'w-full sm:w-auto flex items-center justify-center gap-1.5 rounded-lg bg-emerald-500 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-600 transition-all disabled:opacity-50'}
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
 className={shouldUseMobileFlow ? 'flex w-full items-center justify-center gap-1.5 rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white transition-all' : 'w-full sm:w-auto flex items-center justify-center gap-1.5 rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 transition-all'}
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
 <div className="group rounded-lg border border-dashed border-gray-200 p-3 transition-all hover:border-emerald-400 hover:bg-emerald-50/30">
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
 <label className="mt-2 flex cursor-pointer flex-col items-center gap-2 rounded-lg bg-gray-50 p-4 hover:bg-emerald-50 transition-all border border-transparent hover:border-emerald-200">
 <CloudArrowUpIcon className="h-6 w-6 text-emerald-500" />
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
