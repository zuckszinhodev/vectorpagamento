import { useState, useCallback, useEffect, useRef } from 'react';
import { DndContext, closestCenter, KeyboardSensor, PointerSensor, useSensor, useSensors, DragOverlay, DragStartEvent, DragEndEvent } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Switch, Tab } from '@headlessui/react';
import {
  DevicePhoneMobileIcon,
  ComputerDesktopIcon,
  ArrowLeftIcon,
  CubeIcon,
  DocumentTextIcon,
  CreditCardIcon,
  ClockIcon,
  SparklesIcon,
  Bars3Icon,
  TrashIcon,
  PlusIcon,
  ShieldCheckIcon,
  StarIcon,
  ChatBubbleBottomCenterTextIcon,
  GiftIcon,
  QuestionMarkCircleIcon,
  VideoCameraIcon,
  UserGroupIcon,
  BoltIcon,
  HeartIcon,
  TruckIcon,
  TagIcon,
  Cog6ToothIcon,
  CloudArrowUpIcon,
  SwatchIcon,
  PhotoIcon,
} from '@heroicons/react/24/outline';
import { StarIcon as StarSolidIcon } from '@heroicons/react/24/solid';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { api } from '@/lib/api';
import OrderBumpsPanel from '@/components/checkout-builder/OrderBumpsPanel';
import UpsellsPanel from '@/components/checkout-builder/UpsellsPanel';
import { ModernSplitCheckout } from '@/components/checkout/ModernSplitCheckout';
import { ClassicCheckoutPreview } from '@/components/checkout/ClassicCheckoutPreview';

// Types
interface CheckoutBlock {
  id: string;
  type: BlockType;
  enabled: boolean;
  settings: Record<string, any>;
}

type BlockType = 
  | 'header' 
  | 'product' 
  | 'testimonials' 
  | 'timer' 
  | 'guarantee' 
  | 'payment' 
  | 'benefits'
  | 'faq'
  | 'video'
  | 'social_proof'
  | 'bonus'
  | 'urgency'
  | 'trust_badges'
  | 'shipping'
  | 'coupon'
  | 'custom_html'
  | 'banner';

interface CheckoutSettings {
  name: string;
  theme: {
    template?: 'classic' | 'modern-split';
    primaryColor: string;
    secondaryColor: string;
    backgroundColor: string;
    cardBackground: string;
    textColor: string;
    mutedColor: string;
    borderRadius: 'none' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'full';
    fontFamily: string;
    buttonStyle: 'solid' | 'gradient' | 'outline';
    shadow: 'none' | 'sm' | 'md' | 'lg' | 'xl';
  };
  product: {
    id: string;
    name: string;
    description: string;
    price: number;
    originalPrice?: number;
    image_url?: string;
    type?: 'physical' | 'digital' | 'service';
    shipping_required?: boolean;
  } | null;
  payment: {
    methods: string[];
    maxInstallments: number;
    pixDiscount: number;
    boletoDiscount: number;
  };
  conversion: {
    timerEnabled: boolean;
    timerMinutes: number;
    timerMessage: string;
    exitPopupEnabled: boolean;
    exitPopupTitle: string;
    exitPopupMessage: string;
    testimonialsEnabled: boolean;
    guaranteeDays: number;
    socialProofEnabled: boolean;
    socialProofInterval: number;
  };
  blocks: CheckoutBlock[];
}

// Block Configuration
const blockConfig: Record<BlockType, { name: string; icon: any; description: string; defaultSettings: Record<string, any> }> = {
  header: { 
    name: 'Cabeçalho', 
    icon: DocumentTextIcon, 
    description: 'Logo e badge de segurança',
    defaultSettings: { showLogo: true, showSecureBadge: true, logoUrl: '', title: '' }
  },
  product: { 
    name: 'Produto', 
    icon: CubeIcon, 
    description: 'Imagem, nome e preço',
    defaultSettings: { showImage: true, showDescription: true, showOriginalPrice: true }
  },
  timer: { 
    name: 'Contador', 
    icon: ClockIcon, 
    description: 'Timer de urgência',
    defaultSettings: { 
      message: 'Oferta expira em:', 
      style: 'banner',
      hours: 0,
      minutes: 15,
      seconds: 0
    }
  },
  payment: { 
    name: 'Pagamento', 
    icon: CreditCardIcon, 
    description: 'Formulário de pagamento',
    defaultSettings: { showInstallments: true, highlightPix: true }
  },
  testimonials: { 
    name: 'Depoimentos', 
    icon: ChatBubbleBottomCenterTextIcon, 
    description: 'Avaliações de clientes',
    defaultSettings: { 
      count: 3, 
      style: 'cards',
      items: [
        { name: 'Maria Silva', avatar: '', rating: 5, text: 'Produto incrível! Superou todas as expectativas. Recomendo muito!' },
        { name: 'João Santos', avatar: '', rating: 5, text: 'Melhor investimento que fiz. Conteúdo de altíssima qualidade.' },
        { name: 'Ana Oliveira', avatar: '', rating: 5, text: 'Suporte excelente e entrega rápida. Estou muito satisfeita!' },
      ]
    }
  },
  guarantee: { 
    name: 'Garantia', 
    icon: ShieldCheckIcon, 
    description: 'Selo de garantia',
    defaultSettings: { days: 7, title: 'Garantia Incondicional', description: 'Se não gostar, devolvemos 100% do seu dinheiro' }
  },
  benefits: {
    name: 'Benefícios',
    icon: StarIcon,
    description: 'Lista de benefícios',
    defaultSettings: {
      title: 'O que você vai receber:',
      items: [
        { icon: 'check', text: 'Acesso vitalício ao conteúdo' },
        { icon: 'check', text: 'Suporte prioritário por 1 ano' },
        { icon: 'check', text: 'Certificado de conclusão' },
        { icon: 'check', text: 'Comunidade exclusiva de alunos' },
      ]
    }
  },
  faq: {
    name: 'FAQ',
    icon: QuestionMarkCircleIcon,
    description: 'Perguntas frequentes',
    defaultSettings: {
      title: 'Perguntas Frequentes',
      items: [
        { question: 'Como funciona o acesso?', answer: 'Após a confirmação do pagamento, você receberá um e-mail com os dados de acesso.' },
        { question: 'Posso parcelar?', answer: 'Sim! Aceitamos parcelamento em até 12x no cartão de crédito.' },
        { question: 'Tem garantia?', answer: 'Sim, oferecemos garantia incondicional de 7 dias.' },
      ]
    }
  },
  video: {
    name: 'Vídeo',
    icon: VideoCameraIcon,
    description: 'Vídeo de apresentação',
    defaultSettings: { videoUrl: '', thumbnail: '', autoplay: false }
  },
  social_proof: {
    name: 'Prova Social',
    icon: UserGroupIcon,
    description: 'Notificações de compra',
    defaultSettings: { 
      enabled: true,
      displayTime: 5,
      intervalTime: 8,
      position: 'bottom-left',
      showPaymentMethod: true,
      paymentMethods: ['PIX', 'Cartão de Crédito'],
      names: ['João Silva', 'Maria Santos', 'Pedro Oliveira', 'Ana Costa', 'Carlos Souza', 'Juliana Lima', 'Lucas Pereira', 'Fernanda Alves']
    }
  },
  bonus: {
    name: 'Bônus',
    icon: GiftIcon,
    description: 'Bônus exclusivos',
    defaultSettings: {
      title: 'Bônus Exclusivos',
      items: [
        { name: 'E-book Exclusivo', value: 'R$ 97,00' },
        { name: 'Planilha de Controle', value: 'R$ 47,00' },
        { name: 'Acesso ao Grupo VIP', value: 'R$ 197,00' },
      ]
    }
  },
  urgency: {
    name: 'Urgência',
    icon: BoltIcon,
    description: 'Mensagem de urgência',
    defaultSettings: { 
      style: 'banner',
      message: '🚨 Últimas vagas! Apenas 7 restantes',
      backgroundColor: '#fef3c7',
      textColor: '#92400e'
    }
  },
  trust_badges: {
    name: 'Selos de Confiança',
    icon: ShieldCheckIcon,
    description: 'Badges de segurança',
    defaultSettings: {
      badges: ['compra_segura', 'ssl', 'garantia', 'satisfacao']
    }
  },
  shipping: {
    name: 'Frete',
    icon: TruckIcon,
    description: 'Informações de entrega',
    defaultSettings: { 
      type: 'digital',
      message: '📧 Entrega imediata por e-mail após confirmação',
      freeShipping: false,
      shippingPrice: 0
    }
  },
  coupon: {
    name: 'Cupom',
    icon: TagIcon,
    description: 'Campo de cupom',
    defaultSettings: { enabled: true, placeholder: 'Digite seu cupom' }
  },
  custom_html: {
    name: 'HTML Customizado',
    icon: Cog6ToothIcon,
    description: 'Código HTML personalizado',
    defaultSettings: { html: '' }
  },
  banner: {
    name: 'Banner',
    icon: PhotoIcon,
    description: 'Imagem de destaque',
    defaultSettings: {
      imageUrl: '',
      altText: 'Banner',
      linkUrl: '',
      openInNewTab: true,
      borderRadius: 'lg',
      maxHeight: 300,
    }
  },
};

// Default settings
const defaultSettings: CheckoutSettings = {
  name: 'Checkout Principal',
  theme: {
    template: 'classic',
    primaryColor: '#16a34a',
    secondaryColor: '#6366f1',
    backgroundColor: '#f8fafc',
    cardBackground: '#ffffff',
    textColor: '#1e293b',
    mutedColor: '#64748b',
    borderRadius: 'xl',
    fontFamily: 'Inter',
    buttonStyle: 'gradient',
    shadow: 'lg',
  },
  product: {
    id: 'demo',
    name: 'Curso Completo de Marketing Digital',
    description: 'Aprenda as melhores estratégias para alavancar seu negócio online',
    price: 49700,
    originalPrice: 99700,
    image_url: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=600',
  },
  payment: {
    methods: ['credit_card', 'pix'],
    maxInstallments: 12,
    pixDiscount: 0,
    boletoDiscount: 0,
  },
  conversion: {
    timerEnabled: true,
    timerMinutes: 15,
    timerMessage: 'Oferta expira em:',
    exitPopupEnabled: true,
    exitPopupTitle: 'Espere! Não vá embora ainda!',
    exitPopupMessage: 'Ganhe 10% de desconto extra usando o cupom: VOLTE10',
    testimonialsEnabled: true,
    guaranteeDays: 7,
    socialProofEnabled: true,
    socialProofInterval: 8,
  },
  blocks: [
    { id: 'header', type: 'header', enabled: true, settings: blockConfig.header.defaultSettings },
    { id: 'product', type: 'product', enabled: true, settings: blockConfig.product.defaultSettings },
    { id: 'urgency', type: 'urgency', enabled: true, settings: blockConfig.urgency.defaultSettings },
    { id: 'timer', type: 'timer', enabled: true, settings: blockConfig.timer.defaultSettings },
    { id: 'benefits', type: 'benefits', enabled: true, settings: blockConfig.benefits.defaultSettings },
    { id: 'bonus', type: 'bonus', enabled: true, settings: blockConfig.bonus.defaultSettings },
    { id: 'payment', type: 'payment', enabled: true, settings: blockConfig.payment.defaultSettings },
    { id: 'guarantee', type: 'guarantee', enabled: true, settings: blockConfig.guarantee.defaultSettings },
    { id: 'testimonials', type: 'testimonials', enabled: true, settings: blockConfig.testimonials.defaultSettings },
    { id: 'faq', type: 'faq', enabled: true, settings: blockConfig.faq.defaultSettings },
    { id: 'trust_badges', type: 'trust_badges', enabled: true, settings: blockConfig.trust_badges.defaultSettings },
  ],
};

// Utility functions
const formatCurrency = (value: number) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(value / 100);
};

// Sortable Block Component
function SortableBlock({
  block,
  isSelected,
  onSelect,
  onToggle,
  onRemove,
}: {
  block: CheckoutBlock;
  isSelected: boolean;
  onSelect: () => void;
  onToggle: () => void;
  onRemove: () => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: block.id,
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  const config = blockConfig[block.type];
  const Icon = config.icon;

  return (
    <div
      ref={setNodeRef}
      style={style}
      onClick={onSelect}
      className={`group flex items-center justify-between rounded-card border-2 p-3 transition-all cursor-pointer ${
        isSelected
          ? 'border-green-500 bg-accent-subtle0/10'
          : block.enabled
          ? 'border-border-DEFAULT bg-surface-card hover:border-green-300'
          : 'border-border-DEFAULT bg-surface-page opacity-60'
      }`}
    >
      <div className="flex items-center gap-3">
        <button
          {...attributes}
          {...listeners}
          className="cursor-grab text-tx-muted hover:text-tx-secondary active:cursor-grabbing"
        >
          <Bars3Icon className="h-5 w-5" />
        </button>
        <div className={`rounded-lg p-2 ${isSelected ? 'bg-accent-subtle0/20' : 'bg-surface-hover'}`}>
          <Icon className={`h-4 w-4 ${isSelected ? 'text-accent-primary' : 'text-tx-muted'}`} />
        </div>
        <div>
          <span className={`font-medium ${isSelected ? 'text-accent-primary' : 'text-tx-primary'}`}>
            {config.name}
          </span>
          <p className="text-xs text-tx-muted">{config.description}</p>
        </div>
      </div>
      <div className="flex items-center gap-2">
        <Switch
          checked={block.enabled}
          onChange={onToggle}
          className={`${
            block.enabled 
              ? 'bg-accent-primary' 
              : 'bg-gray-300'
          } relative inline-flex h-5 w-9 items-center rounded-full transition-colors`}
        >
          <span
            className={`${
              block.enabled ? 'translate-x-5' : 'translate-x-1'
            } inline-block h-3 w-3 transform rounded-full bg-surface-card transition-transform`}
          />
        </Switch>
        {block.type !== 'payment' && (
          <button
            onClick={(e) => {
              e.stopPropagation();
              onRemove();
            }}
            className="rounded p-1 text-tx-muted opacity-0 transition-opacity hover:bg-surface-hover hover:text-status-error group-hover:opacity-100"
          >
            <TrashIcon className="h-4 w-4" />
          </button>
        )}
      </div>
    </div>
  );
}

// Draggable New Block Component
function AvailableBlock({ type, onAdd }: { type: BlockType; onAdd: () => void }) {
  const config = blockConfig[type];
  const Icon = config.icon;

  return (
    <button
      onClick={onAdd}
      className="flex items-center gap-2 rounded-element border border-dashed border-border-DEFAULT p-2 text-left transition-all hover:border-green-400 hover:bg-accent-subtle"
    >
      <div className="rounded bg-surface-hover p-1.5">
        <Icon className="h-4 w-4 text-tx-muted" />
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium text-tx-secondary truncate">{config.name}</p>
      </div>
      <PlusIcon className="h-4 w-4 text-tx-muted" />
    </button>
  );
}

// Builder-classic placeholder (matches previous behavior)
function ClassicBuilderPlaceholder() {
  return (
    <div className="text-center py-12 px-10 bg-surface-card rounded-xl border-2 border-dashed border-border-DEFAULT max-w-[760px] w-full">
      <div className="mb-4">
        <svg className="mx-auto h-16 w-16 text-tx-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
      </div>
      <h3 className="text-lg font-semibold text-tx-primary mb-2">Template Clássico</h3>
      <p className="text-sm text-tx-muted max-w-md mx-auto">
        O checkout clássico será exibido na página final. Use o botão "Visualizar" no topo para ver o checkout completo em uma nova aba.
      </p>
    </div>
  );
}

// Checkout Preview Component
function CheckoutPreview({
  settings,
  viewMode,
}: {
  settings: CheckoutSettings;
  viewMode: 'desktop' | 'mobile';
}) {
  const { theme, product, blocks } = settings;
  const [selectedBumps] = useState<Set<string>>(new Set());

  // Check if template is modern-split
  const template = (theme as any).template || 'classic';

  // Use the actual checkout component for preview
  if (template === 'modern-split' && product) {
    return (
      <div
        className={`transition-all ${
          viewMode === 'mobile' ? 'max-w-[375px]' : 'w-full'
        }`}
        style={{
          transform: viewMode === 'mobile' ? 'scale(0.8)' : 'scale(0.7)',
          transformOrigin: 'top center',
        }}
      >
        <ModernSplitCheckout
          product={product}
          theme={theme}
          onSubmit={() => {}}
          isLoading={false}
          orderBumps={[]}
          selectedBumps={selectedBumps}
          onToggleBump={() => {}}
          blocks={blocks}
        />
      </div>
    );
  }

  return (
    <div
      className={`transition-all ${
        viewMode === 'mobile' ? 'max-w-[375px]' : 'max-w-[900px] w-full'
      }`}
      style={{
        transform: viewMode === 'mobile' ? 'scale(0.8)' : 'scale(0.7)',
        transformOrigin: 'top center',
      }}
    >
      <ClassicCheckoutPreview
        theme={theme}
        product={product}
        blocks={blocks}
      />
    </div>
  );
}

// Block Settings Panel
function BlockSettingsPanel({ 
  block, 
  onUpdateBlock 
}: { 
  block: CheckoutBlock;
  onUpdateBlock: (settings: Record<string, any>) => void;
}) {
  const config = blockConfig[block.type];

  const fieldLabels: Record<string, string> = {
    showLogo: 'Mostrar Logo',
    showSecureBadge: 'Badge de Segurança',
    showImage: 'Mostrar Imagem',
    showDescription: 'Mostrar Descrição',
    showOriginalPrice: 'Mostrar Preço Original',
    showInstallments: 'Mostrar Parcelas',
    highlightPix: 'Destacar PIX',
    message: 'Mensagem',
    title: 'Título',
    description: 'Descrição',
    days: 'Dias de Garantia',
    count: 'Quantidade',
    style: 'Estilo',
    enabled: 'Ativado',
    videoUrl: 'URL do Vídeo',
    backgroundColor: 'Cor de Fundo',
    textColor: 'Cor do Texto',
    hours: 'Horas',
    minutes: 'Minutos',
    seconds: 'Segundos',
    displayTime: 'Tempo de Exibição (seg)',
    intervalTime: 'Intervalo entre Notificações (seg)',
    position: 'Posição',
    showPaymentMethod: 'Mostrar Método de Pagamento',
    logoUrl: 'URL da Logo',
    imageUrl: 'URL da Imagem',
    altText: 'Texto Alternativo',
    linkUrl: 'Link ao Clicar',
    openInNewTab: 'Abrir em Nova Aba',
    maxHeight: 'Altura Máxima (px)',
  };

  // Fields to skip from automatic rendering (handled manually)
  const skipFields = ['logoUrl', 'imageUrl', 'linkUrl', 'altText', 'maxHeight', 'borderRadius'];

  const renderField = (key: string, value: any, label: string) => {
    if (typeof value === 'boolean') {
      return (
        <div key={key} className="flex items-center justify-between">
          <label className="text-sm font-medium text-tx-muted">{label}</label>
          <Switch
            checked={value}
            onChange={(checked) => onUpdateBlock({ ...block.settings, [key]: checked })}
            className={`${
              value ? 'bg-accent-primary' : 'bg-gray-600'
            } relative inline-flex h-5 w-9 items-center rounded-full transition-colors`}
          >
            <span className={`${value ? 'translate-x-5' : 'translate-x-1'} inline-block h-3 w-3 transform rounded-full bg-surface-card transition-transform`} />
          </Switch>
        </div>
      );
    }
    if (typeof value === 'string') {
      return (
        <div key={key}>
          <label className="block text-sm font-medium text-tx-muted mb-1">{label}</label>
          <input
            type="text"
            value={value}
            onChange={(e) => onUpdateBlock({ ...block.settings, [key]: e.target.value })}
            className="input"
          />
        </div>
      );
    }
    if (typeof value === 'number') {
      return (
        <div key={key}>
          <label className="block text-sm font-medium text-tx-muted mb-1">{label}</label>
          <input
            type="number"
            value={value}
            onChange={(e) => onUpdateBlock({ ...block.settings, [key]: Number(e.target.value) })}
            className="input"
          />
        </div>
      );
    }
    return null;
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2 pb-2 border-b border-gray-700">
        {(() => {
          const Icon = config.icon;
          return <Icon className="h-5 w-5 text-green-400" />;
        })()}
        <h3 className="font-semibold text-white">{config.name}</h3>
      </div>
      
      {Object.entries(block.settings).map(([key, value]) => {
        if (Array.isArray(value)) return null;
        if (skipFields.includes(key)) return null;
        return renderField(key, value, fieldLabels[key] || key);
      })}

      {/* Header Logo Configuration */}
      {block.type === 'header' && block.settings.showLogo && (
        <div className="pt-4 border-t border-gray-700">
          <label className="block text-sm font-medium text-tx-muted mb-2">Logo</label>
          
          {/* Logo Preview */}
          {block.settings.logoUrl && (
            <div className="mb-3 p-2 bg-gray-800 rounded-lg">
              <img 
                src={block.settings.logoUrl} 
                alt="Logo preview" 
                className="max-h-16 mx-auto object-contain"
                onError={(e) => {
                  (e.target as HTMLImageElement).style.display = 'none';
                }}
              />
            </div>
          )}
          
          {/* URL Input */}
          <div className="space-y-2">
            <input
              type="text"
              placeholder="Cole a URL da logo aqui"
              value={block.settings.logoUrl || ''}
              onChange={(e) => onUpdateBlock({ ...block.settings, logoUrl: e.target.value })}
              className="input text-sm"
            />
            
            <div className="flex items-center gap-2">
              <div className="flex-1 border-t border-gray-700"></div>
              <span className="text-xs text-tx-muted">ou</span>
              <div className="flex-1 border-t border-gray-700"></div>
            </div>
            
            {/* File Upload */}
            <label className="flex items-center justify-center gap-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg cursor-pointer transition-colors">
              <CloudArrowUpIcon className="h-5 w-5 text-green-400" />
              <span className="text-sm text-tx-muted">Fazer upload</span>
              <input
                type="file"
                accept="image/*"
                onChange={async (e) => {
                  const file = e.target.files?.[0];
                  if (!file) return;
                  
                  // Check file size (max 2MB)
                  if (file.size > 2 * 1024 * 1024) {
                    toast.error('Imagem muito grande. Máximo 2MB');
                    return;
                  }
                  
                  // Convert to base64 for preview (in production, upload to server)
                  const reader = new FileReader();
                  reader.onload = (event) => {
                    const base64 = event.target?.result as string;
                    onUpdateBlock({ ...block.settings, logoUrl: base64 });
                    toast.success('Logo carregada!');
                  };
                  reader.readAsDataURL(file);
                }}
                className="hidden"
              />
            </label>
            
            {block.settings.logoUrl && (
              <button
                type="button"
                onClick={() => onUpdateBlock({ ...block.settings, logoUrl: '' })}
                className="w-full text-xs text-red-400 hover:text-red-300 py-1"
              >
                Remover logo
              </button>
            )}
          </div>
          <p className="text-xs text-tx-muted mt-2">PNG ou JPG, máximo 2MB</p>
        </div>
      )}

      {/* Bonus Items Management */}
      {block.type === 'bonus' && block.settings.items && (
        <div className="pt-4 border-t border-gray-700">
          <div className="flex items-center justify-between mb-3">
            <label className="text-sm font-medium text-tx-muted">Itens do Bônus</label>
            <button
              onClick={() => {
                const newItem = { name: 'Novo Bônus', value: 'R$ 0,00' };
                onUpdateBlock({
                  ...block.settings,
                  items: [...block.settings.items, newItem]
                });
              }}
              className="btn-primary text-xs px-2 py-1"
            >
              <PlusIcon className="h-3 w-3 inline mr-1" />
              Adicionar
            </button>
          </div>
          <div className="space-y-3">
            {block.settings.items.map((item: any, index: number) => (
              <div key={index} className="rounded-lg bg-gray-800 p-3 space-y-2">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-medium text-tx-muted">Item {index + 1}</span>
                  <button
                    onClick={() => {
                      const newItems = block.settings.items.filter((_: any, i: number) => i !== index);
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="text-red-400 hover:text-red-300"
                  >
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </div>
                <input
                  type="text"
                  placeholder="Nome do bônus"
                  value={item.name}
                  onChange={(e) => {
                    const newItems = [...block.settings.items];
                    newItems[index] = { ...item, name: e.target.value };
                    onUpdateBlock({ ...block.settings, items: newItems });
                  }}
                  className="input text-sm"
                />
                <input
                  type="text"
                  placeholder="R$ 0,00"
                  value={item.value}
                  onChange={(e) => {
                    const newItems = [...block.settings.items];
                    newItems[index] = { ...item, value: e.target.value };
                    onUpdateBlock({ ...block.settings, items: newItems });
                  }}
                  className="input text-sm"
                />
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Benefits Items Management */}
      {block.type === 'benefits' && block.settings.items && (
        <div className="pt-4 border-t border-gray-700">
          <div className="flex items-center justify-between mb-3">
            <label className="text-sm font-medium text-tx-muted">Benefícios</label>
            <button
              onClick={() => {
                const newItem = { icon: 'check', text: 'Novo benefício' };
                onUpdateBlock({
                  ...block.settings,
                  items: [...block.settings.items, newItem]
                });
              }}
              className="btn-primary text-xs px-2 py-1"
            >
              <PlusIcon className="h-3 w-3 inline mr-1" />
              Adicionar
            </button>
          </div>
          <div className="space-y-3">
            {block.settings.items.map((item: any, index: number) => (
              <div key={index} className="rounded-lg bg-gray-800 p-3 space-y-2">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-medium text-tx-muted">Benefício {index + 1}</span>
                  <button
                    onClick={() => {
                      const newItems = block.settings.items.filter((_: any, i: number) => i !== index);
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="text-red-400 hover:text-red-300"
                  >
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </div>
                <input
                  type="text"
                  placeholder="Descrição do benefício"
                  value={item.text}
                  onChange={(e) => {
                    const newItems = [...block.settings.items];
                    newItems[index] = { ...item, text: e.target.value };
                    onUpdateBlock({ ...block.settings, items: newItems });
                  }}
                  className="input text-sm"
                />
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Testimonials Items Management */}
      {block.type === 'testimonials' && block.settings.items && (
        <div className="pt-4 border-t border-gray-700">
          <div className="flex items-center justify-between mb-3">
            <label className="text-sm font-medium text-tx-muted">Depoimentos</label>
            <button
              onClick={() => {
                const newItem = { name: 'Cliente', avatar: '', rating: 5, text: 'Depoimento...' };
                onUpdateBlock({
                  ...block.settings,
                  items: [...block.settings.items, newItem]
                });
              }}
              className="btn-primary text-xs px-2 py-1"
            >
              <PlusIcon className="h-3 w-3 inline mr-1" />
              Adicionar
            </button>
          </div>
          <div className="space-y-3">
            {block.settings.items.map((item: any, index: number) => (
              <div key={index} className="rounded-lg bg-gray-800 p-3 space-y-2">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-medium text-tx-muted">Depoimento {index + 1}</span>
                  <button
                    onClick={() => {
                      const newItems = block.settings.items.filter((_: any, i: number) => i !== index);
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="text-red-400 hover:text-red-300"
                  >
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </div>
                <div>
                  <label className="text-xs text-tx-muted mb-1 block">Nome</label>
                  <input
                    type="text"
                    placeholder="Nome do cliente"
                    value={item.name}
                    onChange={(e) => {
                      const newItems = [...block.settings.items];
                      newItems[index] = { ...item, name: e.target.value };
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="input text-sm"
                  />
                </div>
                <div>
                  <label className="text-xs text-tx-muted mb-1 block">Depoimento</label>
                  <textarea
                    placeholder="Texto do depoimento..."
                    value={item.text}
                    onChange={(e) => {
                      const newItems = [...block.settings.items];
                      newItems[index] = { ...item, text: e.target.value };
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="input text-sm min-h-[60px]"
                    rows={3}
                  />
                </div>
                <div>
                  <label className="text-xs text-tx-muted mb-1 block">Estrelas (1-5)</label>
                  <input
                    type="number"
                    min="1"
                    max="5"
                    placeholder="5"
                    value={item.rating}
                    onChange={(e) => {
                      const newItems = [...block.settings.items];
                      const rating = Math.min(5, Math.max(1, Number(e.target.value)));
                      newItems[index] = { ...item, rating };
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="input text-sm"
                  />
                </div>
                <div>
                  <label className="text-xs text-tx-muted mb-1 block">URL da Foto de Perfil</label>
                  <input
                    type="text"
                    placeholder="https://..."
                    value={item.avatar || ''}
                    onChange={(e) => {
                      const newItems = [...block.settings.items];
                      newItems[index] = { ...item, avatar: e.target.value };
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="input text-sm"
                  />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* FAQ Items Management */}
      {block.type === 'faq' && block.settings.items && (
        <div className="pt-4 border-t border-gray-700">
          <div className="flex items-center justify-between mb-3">
            <label className="text-sm font-medium text-tx-muted">Perguntas e Respostas</label>
            <button
              onClick={() => {
                const newItem = { question: 'Nova pergunta?', answer: 'Resposta aqui...' };
                onUpdateBlock({
                  ...block.settings,
                  items: [...block.settings.items, newItem]
                });
              }}
              className="btn-primary text-xs px-2 py-1"
            >
              <PlusIcon className="h-3 w-3 inline mr-1" />
              Adicionar
            </button>
          </div>
          <div className="space-y-3">
            {block.settings.items.map((item: any, index: number) => (
              <div key={index} className="rounded-lg bg-gray-800 p-3 space-y-2">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-medium text-tx-muted">Pergunta {index + 1}</span>
                  <button
                    onClick={() => {
                      const newItems = block.settings.items.filter((_: any, i: number) => i !== index);
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="text-red-400 hover:text-red-300"
                  >
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </div>
                <div>
                  <label className="text-xs text-tx-muted mb-1 block">Pergunta</label>
                  <input
                    type="text"
                    placeholder="Digite a pergunta..."
                    value={item.question}
                    onChange={(e) => {
                      const newItems = [...block.settings.items];
                      newItems[index] = { ...item, question: e.target.value };
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="input text-sm"
                  />
                </div>
                <div>
                  <label className="text-xs text-tx-muted mb-1 block">Resposta</label>
                  <textarea
                    placeholder="Digite a resposta..."
                    value={item.answer}
                    onChange={(e) => {
                      const newItems = [...block.settings.items];
                      newItems[index] = { ...item, answer: e.target.value };
                      onUpdateBlock({ ...block.settings, items: newItems });
                    }}
                    className="input text-sm min-h-[60px]"
                    rows={3}
                  />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Social Proof Configuration */}
      {block.type === 'social_proof' && (
        <div className="pt-4 border-t border-gray-700 space-y-4">
          <div>
            <label className="text-xs text-tx-muted mb-1 block">Posição da Notificação</label>
            <select
              value={block.settings.position || 'bottom-left'}
              onChange={(e) => onUpdateBlock({ ...block.settings, position: e.target.value })}
              className="input text-sm"
            >
              <option value="bottom-left">Inferior Esquerdo</option>
              <option value="bottom-right">Inferior Direito</option>
              <option value="top-left">Superior Esquerdo</option>
              <option value="top-right">Superior Direito</option>
            </select>
          </div>

          <div className="flex items-center justify-between mb-3">
            <label className="text-sm font-medium text-tx-muted">Nomes para Notificações</label>
            <button
              onClick={() => {
                const newNames = [...(block.settings.names || []), 'Novo Nome'];
                onUpdateBlock({ ...block.settings, names: newNames });
              }}
              className="btn-primary text-xs px-2 py-1"
            >
              <PlusIcon className="h-3 w-3 inline mr-1" />
              Adicionar
            </button>
          </div>
          <div className="space-y-2 max-h-40 overflow-y-auto">
            {(block.settings.names || []).map((name: string, index: number) => (
              <div key={index} className="flex items-center gap-2">
                <input
                  type="text"
                  value={name}
                  onChange={(e) => {
                    const newNames = [...block.settings.names];
                    newNames[index] = e.target.value;
                    onUpdateBlock({ ...block.settings, names: newNames });
                  }}
                  className="input text-sm flex-1"
                  placeholder="Nome da pessoa"
                />
                <button
                  onClick={() => {
                    const newNames = block.settings.names.filter((_: string, i: number) => i !== index);
                    onUpdateBlock({ ...block.settings, names: newNames });
                  }}
                  className="text-red-400 hover:text-red-300 p-1"
                >
                  <TrashIcon className="h-4 w-4" />
                </button>
              </div>
            ))}
          </div>

          <div className="pt-3 border-t border-gray-700">
            <div className="flex items-center justify-between mb-3">
              <label className="text-sm font-medium text-tx-muted">Métodos de Pagamento</label>
              <button
                onClick={() => {
                  const newMethods = [...(block.settings.paymentMethods || []), 'Novo Método'];
                  onUpdateBlock({ ...block.settings, paymentMethods: newMethods });
                }}
                className="btn-primary text-xs px-2 py-1"
              >
                <PlusIcon className="h-3 w-3 inline mr-1" />
                Adicionar
              </button>
            </div>
            <div className="space-y-2">
              {(block.settings.paymentMethods || []).map((method: string, index: number) => (
                <div key={index} className="flex items-center gap-2">
                  <input
                    type="text"
                    value={method}
                    onChange={(e) => {
                      const newMethods = [...block.settings.paymentMethods];
                      newMethods[index] = e.target.value;
                      onUpdateBlock({ ...block.settings, paymentMethods: newMethods });
                    }}
                    className="input text-sm flex-1"
                    placeholder="Ex: PIX, Cartão de Crédito"
                  />
                  <button
                    onClick={() => {
                      const newMethods = block.settings.paymentMethods.filter((_: string, i: number) => i !== index);
                      onUpdateBlock({ ...block.settings, paymentMethods: newMethods });
                    }}
                    className="text-red-400 hover:text-red-300 p-1"
                  >
                    <TrashIcon className="h-4 w-4" />
                  </button>
                </div>
              ))}
            </div>
          </div>

          <div className="pt-3 border-t border-gray-700 rounded-lg bg-gray-800 p-3">
            <p className="text-xs text-tx-muted mb-2">Preview da notificação:</p>
            <div className="bg-surface-card rounded-lg p-3">
              <p className="text-sm text-tx-primary">
                <span className="font-semibold">{block.settings.names?.[0] || 'João Silva'}</span>
                {' '}comprou{' '}
                <span className="text-accent-primary font-medium">este produto</span>
                {block.settings.showPaymentMethod && (
                  <span className="text-tx-secondary"> com {block.settings.paymentMethods?.[0] || 'PIX'}</span>
                )}
              </p>
              <p className="text-xs text-tx-muted mt-1">Agora mesmo</p>
            </div>
          </div>
        </div>
      )}

      {/* Shipping Configuration */}
      {block.type === 'shipping' && (
        <div className="pt-4 border-t border-gray-700 space-y-4">
          <div>
            <label className="block text-sm font-medium text-tx-muted mb-2">Tipo de Produto</label>
            <div className="space-y-2">
              <label className="flex items-center gap-2 text-sm text-tx-muted cursor-pointer">
                <input
                  type="radio"
                  name="productType"
                  value="digital"
                  checked={block.settings.type === 'digital'}
                  onChange={(e) => {
                    onUpdateBlock({
                      ...block.settings,
                      type: 'digital',
                      message: '📧 Entrega imediata por e-mail após confirmação'
                    });
                  }}
                  className="h-4 w-4 border-gray-600 bg-gray-700 text-accent-primary focus:ring-green-500"
                />
                📧 Produto Digital
              </label>
              <label className="flex items-center gap-2 text-sm text-tx-muted cursor-pointer">
                <input
                  type="radio"
                  name="productType"
                  value="physical"
                  checked={block.settings.type === 'physical'}
                  onChange={(e) => {
                    onUpdateBlock({
                      ...block.settings,
                      type: 'physical',
                      freeShipping: false,
                      shippingPrice: 0
                    });
                  }}
                  className="h-4 w-4 border-gray-600 bg-gray-700 text-accent-primary focus:ring-green-500"
                />
                📦 Produto Físico
              </label>
            </div>
          </div>

          {block.settings.type === 'physical' && (
            <div className="space-y-3 rounded-lg bg-gray-800 p-3">
              <h4 className="text-sm font-semibold text-white">Informações de Frete</h4>
              
              <div className="flex items-center justify-between">
                <label className="text-sm font-medium text-tx-muted">Frete Grátis</label>
                <Switch
                  checked={block.settings.freeShipping || false}
                  onChange={(checked) => onUpdateBlock({ ...block.settings, freeShipping: checked })}
                  className={`${
                    block.settings.freeShipping ? 'bg-accent-primary' : 'bg-gray-600'
                  } relative inline-flex h-5 w-9 items-center rounded-full transition-colors`}
                >
                  <span className={`${block.settings.freeShipping ? 'translate-x-5' : 'translate-x-1'} inline-block h-3 w-3 transform rounded-full bg-surface-card transition-transform`} />
                </Switch>
              </div>

              {!block.settings.freeShipping && (
                <div>
                  <label className="block text-sm font-medium text-tx-muted mb-1">Valor do Frete (R$)</label>
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={block.settings.shippingPrice || 0}
                    onChange={(e) => onUpdateBlock({ ...block.settings, shippingPrice: Number(e.target.value) })}
                    className="input"
                    placeholder="0.00"
                  />
                </div>
              )}

              <div className="rounded-lg bg-blue-900/20 border border-blue-800 p-3">
                <p className="text-xs text-blue-300">
                  ℹ️ Um formulário de endereço completo será exibido no checkout para produtos físicos.
                </p>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Banner Configuration */}
      {block.type === 'banner' && (
        <div className="pt-4 border-t border-gray-700 space-y-3">
          <div>
            <label className="block text-sm font-medium text-tx-muted mb-1">URL da Imagem</label>
            <input
              type="text"
              value={block.settings.imageUrl || ''}
              onChange={(e) => onUpdateBlock({ ...block.settings, imageUrl: e.target.value })}
              className="input"
              placeholder="https://exemplo.com/banner.jpg"
            />
          </div>

          {block.settings.imageUrl && (
            <div className="rounded-lg bg-gray-800 p-2">
              <img
                src={block.settings.imageUrl}
                alt={block.settings.altText || 'Banner'}
                className="w-full object-cover rounded-md"
                style={{ maxHeight: `${block.settings.maxHeight || 300}px` }}
                onError={(e) => { (e.target as HTMLImageElement).style.display = 'none'; }}
              />
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-tx-muted mb-1">Link ao Clicar (opcional)</label>
            <input
              type="text"
              value={block.settings.linkUrl || ''}
              onChange={(e) => onUpdateBlock({ ...block.settings, linkUrl: e.target.value })}
              className="input"
              placeholder="https://..."
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-tx-muted mb-1">Altura Máxima (px)</label>
            <input
              type="number"
              min="50"
              max="800"
              value={block.settings.maxHeight || 300}
              onChange={(e) => onUpdateBlock({ ...block.settings, maxHeight: Number(e.target.value) })}
              className="input w-24"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-tx-muted mb-1">Arredondamento</label>
            <select
              value={block.settings.borderRadius || 'lg'}
              onChange={(e) => onUpdateBlock({ ...block.settings, borderRadius: e.target.value })}
              className="input"
            >
              <option value="none">Nenhum</option>
              <option value="sm">Pequeno</option>
              <option value="md">Médio</option>
              <option value="lg">Grande</option>
              <option value="xl">Extra Grande</option>
              <option value="2xl">2x Grande</option>
            </select>
          </div>
        </div>
      )}
    </div>
  );
}

// Main Component
export default function CheckoutBuilderPage() {
  const { id: checkoutId } = useParams<{ id: string }>();
  const [settings, setSettings] = useState<CheckoutSettings>(defaultSettings);
  const [viewMode, setViewMode] = useState<'desktop' | 'mobile'>('desktop');
  const [activeTab, setActiveTab] = useState(0);
  const [selectedBlockId, setSelectedBlockId] = useState<string | null>(null);
  const [activeBlockId, setActiveBlockId] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(!!checkoutId);
  const [availableProducts, setAvailableProducts] = useState<any[]>([]);
  const [checkoutSlug, setCheckoutSlug] = useState<string | null>(null);
  const [iframeKey, setIframeKey] = useState(0);
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const settingsRef = useRef(settings);
  settingsRef.current = settings;

  // Enviar settings atualizados para o iframe em tempo real via postMessage
  const sendSettingsToIframe = useCallback(() => {
    if (!iframeRef.current?.contentWindow) return;
    iframeRef.current.contentWindow.postMessage({
      type: 'checkout-builder-settings',
      settings: settingsRef.current,
    }, window.location.origin);
  }, []);

  useEffect(() => {
    if (!checkoutSlug) return;
    sendSettingsToIframe();
  }, [settings, checkoutSlug, sendSettingsToIframe]);

  // Enviar settings quando o iframe terminar de carregar
  const handleIframeLoad = useCallback(() => {
    // Pequeno delay para garantir que o listener do CheckoutPage esteja pronto
    setTimeout(sendSettingsToIframe, 200);
  }, [sendSettingsToIframe]);

  // Carregar produtos disponíveis
  useEffect(() => {
    const loadProducts = async () => {
      let products: any[] = [];
      
      // Carregar do localStorage
      try {
        const savedProducts = JSON.parse(localStorage.getItem('dev_products') || '[]');
        products = [...savedProducts];
      } catch {
        // Ignorar
      }
      
      // Carregar da API
      try {
        const response = await api.get('/products');
        if (response.data.success && response.data.data) {
          const apiProducts = response.data.data;
          // Merge sem duplicatas
          const localIds = new Set(products.map(p => p.id));
          apiProducts.forEach((p: any) => {
            if (!localIds.has(p.id)) {
              products.push(p);
            }
          });
        }
      } catch {
        // Usar só localStorage
      }
      
      setAvailableProducts(products);
    };
    
    loadProducts();
  }, []);

  // Carregar dados do checkout quando há um ID
  useEffect(() => {
    if (!checkoutId) return;

    const loadCheckout = async () => {
      setIsLoading(true);
      
      // Buscar do localStorage primeiro
      try {
        const savedCheckouts = JSON.parse(localStorage.getItem('dev_checkouts') || '[]');
        const localCheckout = savedCheckouts.find((c: any) => c.id === checkoutId);
        
        if (localCheckout) {
          // Save slug for live preview
          if (localCheckout.slug) {
            setCheckoutSlug(localCheckout.slug);
          }

          // Buscar produto associado
          let product = localCheckout.product;
          if (!product && localCheckout.product_id) {
            const savedProducts = JSON.parse(localStorage.getItem('dev_products') || '[]');
            product = savedProducts.find((p: any) => p.id === localCheckout.product_id);
          }

          if (product) {
            setSettings(prev => {
              const checkoutSettings = localCheckout.settings || {};
              return {
                ...prev,
                name: localCheckout.name || prev.name,
                product: {
                  id: product.id,
                  name: product.name,
                  description: product.description || '',
                  price: product.price,
                  originalPrice: product.original_price,
                  image_url: product.image_url,
                  type: product.type,
                  shipping_required: product.shipping_required,
                },
                // Carregar configurações completas do builder se existirem
                ...(checkoutSettings.theme && { theme: { ...prev.theme, ...checkoutSettings.theme } }),
                ...(checkoutSettings.payment && { payment: { ...prev.payment, ...checkoutSettings.payment } }),
                ...(checkoutSettings.conversion && { conversion: { ...prev.conversion, ...checkoutSettings.conversion } }),
                ...(checkoutSettings.blocks && { blocks: checkoutSettings.blocks }),
              };
            });
          }
          setIsLoading(false);
          return;
        }
      } catch {
        // Ignorar erro de localStorage
      }

      // Tentar buscar da API
      try {
        const response = await api.get(`/checkouts/${checkoutId}`);
        if (response.data.success) {
          const checkout = response.data.data;
          const product = checkout.product;
          const checkoutSettings = checkout.settings || {};

          // Save slug for live preview iframe
          if (checkout.slug) {
            setCheckoutSlug(checkout.slug);
          }

          setSettings(prev => {
          // Merge theme from both checkout.theme and settings.theme, prioritizing settings.theme
          const mergedTheme = {
            ...prev.theme,
            ...(checkout.theme || {}),
            ...(checkoutSettings.theme || {}),
          };

          return {
            ...prev,
            name: checkout.name || prev.name,
            // Carregar tema completo com merge correto
            theme: mergedTheme,
            // Carregar configurações de pagamento
            ...(checkoutSettings.payment && { payment: { ...prev.payment, ...checkoutSettings.payment } }),
            // Carregar configurações de conversão
            ...(checkoutSettings.conversion && { conversion: { ...prev.conversion, ...checkoutSettings.conversion } }),
            // Carregar blocos salvos
            ...(checkoutSettings.blocks && { blocks: checkoutSettings.blocks }),
            // Carregar produto
            ...(product && {
              product: {
                id: product.id,
                name: product.name,
                description: product.description || '',
                price: product.price,
                originalPrice: product.original_price,
                image_url: product.image_url || product.images?.[0],
                type: product.type,
                shipping_required: product.shipping_required,
              },
            }),
            // Fallback para configurações legadas
            ...(!checkoutSettings.payment && {
              payment: {
                ...prev.payment,
                methods: checkoutSettings.payment_methods || prev.payment.methods,
                maxInstallments: checkoutSettings.max_installments || prev.payment.maxInstallments,
                pixDiscount: checkoutSettings.pix_discount || prev.payment.pixDiscount,
              },
            }),
            ...(!checkoutSettings.conversion && {
              conversion: {
                ...prev.conversion,
                timerEnabled: checkoutSettings.timer_enabled ?? prev.conversion.timerEnabled,
                timerMinutes: checkoutSettings.timer_minutes || prev.conversion.timerMinutes,
                testimonialsEnabled: checkoutSettings.testimonials_enabled ?? prev.conversion.testimonialsEnabled,
                exitPopupEnabled: checkoutSettings.exit_popup_enabled ?? prev.conversion.exitPopupEnabled,
                guaranteeDays: checkoutSettings.guarantee_days || prev.conversion.guaranteeDays,
              },
            }),
          };
          });
        }
      } catch (error) {
        // Error handled silently
      } finally {
        setIsLoading(false);
      }
    };

    loadCheckout();
  }, [checkoutId]);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  );

  const handleDragStart = (event: DragStartEvent) => {
    setActiveBlockId(event.active.id as string);
  };

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    setActiveBlockId(null);

    if (over && active.id !== over.id) {
      setSettings((prev) => {
        const oldIndex = prev.blocks.findIndex((b) => b.id === active.id);
        const newIndex = prev.blocks.findIndex((b) => b.id === over.id);
        return {
          ...prev,
          blocks: arrayMove(prev.blocks, oldIndex, newIndex),
        };
      });
    }
  };

  const toggleBlock = useCallback((blockId: string) => {
    setSettings((prev) => ({
      ...prev,
      blocks: prev.blocks.map((b) =>
        b.id === blockId ? { ...b, enabled: !b.enabled } : b
      ),
    }));
  }, []);

  const removeBlock = useCallback((blockId: string) => {
    setSettings((prev) => ({
      ...prev,
      blocks: prev.blocks.filter((b) => b.id !== blockId),
    }));
    if (selectedBlockId === blockId) {
      setSelectedBlockId(null);
    }
  }, [selectedBlockId]);

  const addBlock = useCallback((type: BlockType) => {
    const config = blockConfig[type];
    const newBlock: CheckoutBlock = {
      id: `${type}_${Date.now()}`,
      type,
      enabled: true,
      settings: { ...config.defaultSettings },
    };
    setSettings((prev) => ({
      ...prev,
      blocks: [...prev.blocks, newBlock],
    }));
    setSelectedBlockId(newBlock.id);
    toast.success(`Bloco "${config.name}" adicionado!`);
  }, []);

  const updateTheme = useCallback((key: string, value: any) => {
    setSettings((prev) => ({
      ...prev,
      theme: { ...prev.theme, [key]: value },
    }));
  }, []);

  const updatePayment = useCallback((key: string, value: any) => {
    setSettings((prev) => ({
      ...prev,
      payment: { ...prev.payment, [key]: value },
    }));
  }, []);

  const updateConversion = useCallback((key: string, value: any) => {
    setSettings((prev) => ({
      ...prev,
      conversion: { ...prev.conversion, [key]: value },
    }));
  }, []);

  const updateBlockSettings = useCallback((blockId: string, newSettings: Record<string, any>) => {
    setSettings((prev) => ({
      ...prev,
      blocks: prev.blocks.map((b) =>
        b.id === blockId ? { ...b, settings: newSettings } : b
      ),
    }));
  }, []);

  const handleSave = async () => {
    if (!checkoutId) {
      toast.error('Nenhum checkout selecionado');
      return;
    }

    const builderSettings = {
      theme: settings.theme,
      payment: settings.payment,
      conversion: settings.conversion,
      blocks: settings.blocks,
    };

    // Salvar no localStorage
    try {
      const savedCheckouts = JSON.parse(localStorage.getItem('dev_checkouts') || '[]');
      const index = savedCheckouts.findIndex((c: any) => c.id === checkoutId);
      
      if (index !== -1) {
        savedCheckouts[index] = {
          ...savedCheckouts[index],
          builder_settings: builderSettings,
          settings: {
            ...savedCheckouts[index].settings,
            // Configurações legadas para compatibilidade
            theme_color: settings.theme.primaryColor,
            timer_enabled: settings.conversion.timerEnabled,
            timer_minutes: settings.conversion.timerMinutes,
            testimonials_enabled: settings.conversion.testimonialsEnabled,
            exit_popup_enabled: settings.conversion.exitPopupEnabled,
            payment_methods: settings.payment.methods,
            max_installments: settings.payment.maxInstallments,
            pix_discount: settings.payment.pixDiscount,
            guarantee_days: settings.conversion.guaranteeDays,
            // Configurações completas do builder
            theme: settings.theme,
            payment: settings.payment,
            conversion: settings.conversion,
            blocks: settings.blocks,
          },
        };
        localStorage.setItem('dev_checkouts', JSON.stringify(savedCheckouts));
      }
    } catch {
      // Ignorar erro
    }

    // Tentar salvar na API - enviar TODAS as configurações do builder
    try {
      const payload = {
        settings: {
          // Configurações legadas para compatibilidade
          theme_color: settings.theme.primaryColor,
          timer_enabled: settings.conversion.timerEnabled,
          timer_minutes: settings.conversion.timerMinutes,
          testimonials_enabled: settings.conversion.testimonialsEnabled,
          exit_popup_enabled: settings.conversion.exitPopupEnabled,
          payment_methods: settings.payment.methods,
          max_installments: settings.payment.maxInstallments,
          pix_discount: settings.payment.pixDiscount,
          guarantee_days: settings.conversion.guaranteeDays,
          // Configurações completas do builder
          theme: settings.theme,
          payment: settings.payment,
          conversion: settings.conversion,
          blocks: settings.blocks,
        },
        theme: settings.theme,
      };

      await api.put(`/checkouts/${checkoutId}`, payload);
      
      // Refresh iframe to reflect saved changes
      setIframeKey(prev => prev + 1);
    } catch {
      // Já salvou no localStorage
    }

    toast.success('Checkout salvo!');
  };

  const handlePublish = async () => {
    await handleSave();
    
    // Atualizar status para ativo
    try {
      const savedCheckouts = JSON.parse(localStorage.getItem('dev_checkouts') || '[]');
      const index = savedCheckouts.findIndex((c: any) => c.id === checkoutId);
      if (index !== -1) {
        savedCheckouts[index].status = 'active';
        localStorage.setItem('dev_checkouts', JSON.stringify(savedCheckouts));
      }
    } catch {
      // Ignorar erro
    }

    try {
      await api.put(`/checkouts/${checkoutId}`, { status: 'active' });
    } catch {
      // Já salvou no localStorage
    }

    toast.success('Checkout publicado!');
  };

  const selectedBlock = settings.blocks.find((b) => b.id === selectedBlockId);

  const availableBlockTypes: BlockType[] = ['benefits', 'bonus', 'faq', 'video', 'urgency', 'trust_badges', 'shipping', 'coupon', 'social_proof'];

  // Loading state quando está editando um checkout existente
  if (isLoading && checkoutId) {
    return (
      <div className="flex h-screen items-center justify-center bg-[rgb(16,16,16)]">
        <div className="flex flex-col items-center gap-4">
          <div className="h-10 w-10 animate-spin rounded-full border-4 border-green-500 border-t-transparent"></div>
          <p className="text-tx-muted">Carregando checkout...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex h-screen flex-col bg-[rgb(16,16,16)] overflow-hidden">
      {/* Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-gray-800 bg-[rgb(22,22,22)] px-3 py-2 sm:px-6 sm:py-3">
        <div className="flex items-center gap-4">
          <Link to="/checkouts" className="text-tx-muted hover:text-white transition-colors">
            <ArrowLeftIcon className="h-5 w-5" />
          </Link>
          <div>
            <h1 className="text-lg font-semibold text-white">Checkout Builder</h1>
            <p className="text-xs text-tx-muted hidden sm:block">Personalize sua página de checkout</p>
          </div>
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto overflow-x-auto sm:overflow-visible">
          <div className="flex rounded-element border border-[rgb(38,38,38)] bg-[rgb(22,22,22)] p-1 flex-shrink-0">
            <button
              onClick={() => setViewMode('desktop')}
              className={`rounded-md p-2 transition-all ${
          viewMode === 'desktop'
            ? 'bg-accent-primary text-white'
            : 'text-tx-muted hover:text-white'
              }`}
            >
              <ComputerDesktopIcon className="h-4 w-4" />
            </button>
            <button
              onClick={() => setViewMode('mobile')}
              className={`rounded-md p-2 transition-all ${
          viewMode === 'mobile'
            ? 'bg-accent-primary text-white'
            : 'text-tx-muted hover:text-white'
              }`}
            >
              <DevicePhoneMobileIcon className="h-4 w-4" />
            </button>
          </div>

          <button 
            onClick={() => {
              // Gerar ID único para o preview
              const previewId = `preview_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
              
              // Salvar settings no localStorage com o ID
              localStorage.setItem(`checkout_preview_${previewId}`, JSON.stringify(settings));
              
              // Abrir preview em nova aba com o ID
              const previewUrl = `/checkout-preview?id=${previewId}`;
              window.open(previewUrl, '_blank');
            }}
            className="btn-secondary inline-flex items-center text-xs sm:text-sm whitespace-nowrap flex-shrink-0"
          >
            <SparklesIcon className="mr-1.5 sm:mr-2 h-3.5 w-3.5 sm:h-4 sm:w-4" />
            Preview
          </button>

          <button onClick={handleSave} className="btn-secondary text-xs sm:text-sm whitespace-nowrap flex-shrink-0">
            Salvar Rascunho
          </button>

          <button 
            onClick={handlePublish} 
            className="inline-flex items-center gap-1.5 sm:gap-2 rounded-lg bg-gradient-to-r from-green-600 to-pink-600 px-3 sm:px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-green-500/25 hover:shadow-green-500/40 transition-all whitespace-nowrap flex-shrink-0"
          >
            <CloudArrowUpIcon className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
            Publicar
          </button>
        </div>
      </div>

      {/* Main Content */}
      <div className="flex flex-col lg:flex-row flex-1 overflow-hidden">
        {/* Left Sidebar - Blocks */}
        <div className="w-full lg:w-80 overflow-y-auto border-b lg:border-b-0 lg:border-r border-gray-800 bg-[rgb(22,22,22)] max-h-[40vh] lg:max-h-none">
          <Tab.Group selectedIndex={activeTab} onChange={setActiveTab}>
            <Tab.List className="grid grid-cols-4 border-b border-gray-800">
              {[
                { icon: CubeIcon, label: 'Blocos' },
                { icon: SwatchIcon, label: 'Tema' },
                { icon: GiftIcon, label: 'Bumps' },
                { icon: BoltIcon, label: 'Upsells' },
              ].map((tab, i) => (
                <Tab
                  key={i}
                  className={({ selected }) =>
                    `flex flex-col items-center justify-center gap-1 py-2.5 text-[10px] font-medium transition-all focus:outline-none ${
                      selected
                        ? 'border-b-2 border-green-500 text-green-400'
                        : 'text-tx-muted hover:text-tx-muted'
                    }`
                  }
                >
                  <tab.icon className="h-4 w-4" />
                  {tab.label}
                </Tab>
              ))}
            </Tab.List>

            <Tab.Panels className="p-4">
              {/* Blocks Panel */}
              <Tab.Panel className="space-y-4">
                <div>
                  <h3 className="text-sm font-semibold text-white mb-1">Blocos Ativos</h3>
                  <p className="text-xs text-tx-muted mb-3">Arraste para reorganizar</p>

                  <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragStart={handleDragStart}
                    onDragEnd={handleDragEnd}
                  >
                    <SortableContext
                      items={settings.blocks.map((b) => b.id)}
                      strategy={verticalListSortingStrategy}
                    >
                      <div className="space-y-2">
                        {settings.blocks.map((block) => (
                          <SortableBlock
                            key={block.id}
                            block={block}
                            isSelected={selectedBlockId === block.id}
                            onSelect={() => setSelectedBlockId(block.id)}
                            onToggle={() => toggleBlock(block.id)}
                            onRemove={() => removeBlock(block.id)}
                          />
                        ))}
                      </div>
                    </SortableContext>
                    
                    <DragOverlay>
                      {activeBlockId ? (
                        <div className="rounded-card border-2 border-green-500 bg-accent-subtle0/20 p-3 opacity-80">
                          <div className="flex items-center gap-2">
                            <Bars3Icon className="h-5 w-5 text-accent-primary" />
                            <span className="font-medium text-accent-primary">
                              {blockConfig[settings.blocks.find(b => b.id === activeBlockId)?.type || 'header'].name}
                            </span>
                          </div>
                        </div>
                      ) : null}
                    </DragOverlay>
                  </DndContext>
                </div>

                {/* Add New Blocks */}
                <div className="pt-4 border-t border-gray-800">
                  <h3 className="text-sm font-semibold text-white mb-3">Adicionar Bloco</h3>
                  <div className="grid grid-cols-2 gap-2">
                    {availableBlockTypes.map((type) => (
                      <AvailableBlock
                        key={type}
                        type={type}
                        onAdd={() => addBlock(type)}
                      />
                    ))}
                  </div>
                </div>
              </Tab.Panel>

              {/* Design Panel */}
              <Tab.Panel className="space-y-4">
                {/* Template Selection */}
                <div>
                  <h3 className="text-sm font-semibold text-white">Template do Checkout</h3>
                  <p className="text-xs text-tx-muted mb-3">Escolha o layout do seu checkout</p>
                  
                  <div className="grid grid-cols-2 gap-3">
                    <button
                      onClick={() => setSettings(prev => ({ ...prev, theme: { ...prev.theme, template: 'classic' } }))}
                      className={`relative rounded-lg border-2 p-3 text-left transition-all ${
                        (settings.theme as any).template !== 'modern-split'
                          ? 'border-green-500 ring-1 ring-green-500/50'
                          : 'border-gray-700 hover:border-gray-500'
                      }`}
                    >
                      <div className="rounded-md overflow-hidden h-20 mb-2 bg-gray-800">
                        <div className="p-2 space-y-1">
                          <div className="h-2 w-full bg-gray-600 rounded" />
                          <div className="h-2 w-3/4 bg-gray-600 rounded" />
                          <div className="h-8 w-full bg-green-500 rounded mt-2" />
                        </div>
                      </div>
                      <p className="text-xs font-medium text-white">Clássico</p>
                      <p className="text-[10px] text-gray-400">Layout vertical tradicional</p>
                      {(settings.theme as any).template !== 'modern-split' && (
                        <div className="absolute top-2 right-2 h-4 w-4 rounded-full bg-green-500 border border-gray-900 flex items-center justify-center">
                          <svg className="h-3 w-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                          </svg>
                        </div>
                      )}
                    </button>

                    <button
                      onClick={() => setSettings(prev => ({ ...prev, theme: { ...prev.theme, template: 'modern-split' } }))}
                      className={`relative rounded-lg border-2 p-3 text-left transition-all ${
                        (settings.theme as any).template === 'modern-split'
                          ? 'border-green-500 ring-1 ring-green-500/50'
                          : 'border-gray-700 hover:border-gray-500'
                      }`}
                    >
                      <div className="rounded-md overflow-hidden h-20 mb-2 bg-gray-800">
                        <div className="flex gap-1 p-2 h-full">
                          <div className="flex-1 space-y-1">
                            <div className="h-1.5 w-full bg-gray-600 rounded" />
                            <div className="h-1.5 w-3/4 bg-gray-600 rounded" />
                            <div className="h-6 w-full bg-green-500 rounded mt-1" />
                          </div>
                          <div className="w-12 bg-gray-700 rounded" />
                        </div>
                      </div>
                      <p className="text-xs font-medium text-white">Moderno Split</p>
                      <p className="text-[10px] text-gray-400">Layout dividido (2 colunas)</p>
                      {(settings.theme as any).template === 'modern-split' && (
                        <div className="absolute top-2 right-2 h-4 w-4 rounded-full bg-green-500 border border-gray-900 flex items-center justify-center">
                          <svg className="h-3 w-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                          </svg>
                        </div>
                      )}
                    </button>
                  </div>
                </div>

                {/* Divider */}
                <div className="flex items-center gap-2 pt-2">
                  <div className="flex-1 border-t border-gray-700" />
                  <span className="text-[10px] text-gray-500 uppercase tracking-wider">Cores</span>
                  <div className="flex-1 border-t border-gray-700" />
                </div>

                <h3 className="text-sm font-semibold text-white">Temas Prontos</h3>
                <p className="text-xs text-tx-muted -mt-2">Selecione um tema para aplicar</p>

                <div className="grid grid-cols-2 gap-2">
                  {[
                    {
                      name: 'Roxo Moderno',
                      preview: { bg: '#f8fafc', card: '#ffffff', primary: '#16a34a', secondary: '#6366f1', text: '#1e293b', muted: '#64748b' },
                      theme: { primaryColor: '#16a34a', secondaryColor: '#6366f1', backgroundColor: '#f8fafc', cardBackground: '#ffffff', textColor: '#1e293b', mutedColor: '#64748b', borderRadius: 'xl', fontFamily: 'Inter', buttonStyle: 'gradient', shadow: 'lg' },
                    },
                    {
                      name: 'Verde Vendas',
                      preview: { bg: '#f0fdf4', card: '#ffffff', primary: '#16a34a', secondary: '#059669', text: '#14532d', muted: '#6b7280' },
                      theme: { primaryColor: '#16a34a', secondaryColor: '#059669', backgroundColor: '#f0fdf4', cardBackground: '#ffffff', textColor: '#14532d', mutedColor: '#6b7280', borderRadius: 'lg', fontFamily: 'Poppins', buttonStyle: 'solid', shadow: 'md' },
                    },
                    {
                      name: 'Azul Profissional',
                      preview: { bg: '#f0f9ff', card: '#ffffff', primary: '#2563eb', secondary: '#3b82f6', text: '#1e3a5f', muted: '#64748b' },
                      theme: { primaryColor: '#2563eb', secondaryColor: '#3b82f6', backgroundColor: '#f0f9ff', cardBackground: '#ffffff', textColor: '#1e3a5f', mutedColor: '#64748b', borderRadius: 'md', fontFamily: 'Roboto', buttonStyle: 'solid', shadow: 'lg' },
                    },
                    {
                      name: 'Laranja Energia',
                      preview: { bg: '#fff7ed', card: '#ffffff', primary: '#ea580c', secondary: '#f59e0b', text: '#431407', muted: '#78716c' },
                      theme: { primaryColor: '#ea580c', secondaryColor: '#f59e0b', backgroundColor: '#fff7ed', cardBackground: '#ffffff', textColor: '#431407', mutedColor: '#78716c', borderRadius: 'xl', fontFamily: 'Montserrat', buttonStyle: 'gradient', shadow: 'xl' },
                    },
                    {
                      name: 'Dark Premium',
                      preview: { bg: '#0f172a', card: '#1e293b', primary: '#f59e0b', secondary: '#eab308', text: '#f1f5f9', muted: '#94a3b8' },
                      theme: { primaryColor: '#f59e0b', secondaryColor: '#eab308', backgroundColor: '#0f172a', cardBackground: '#1e293b', textColor: '#f1f5f9', mutedColor: '#94a3b8', borderRadius: 'lg', fontFamily: 'Inter', buttonStyle: 'gradient', shadow: 'xl' },
                    },
                    {
                      name: 'Rosa Elegante',
                      preview: { bg: '#fdf2f8', card: '#ffffff', primary: '#db2777', secondary: '#e879a8', text: '#831843', muted: '#9ca3af' },
                      theme: { primaryColor: '#db2777', secondaryColor: '#e879a8', backgroundColor: '#fdf2f8', cardBackground: '#ffffff', textColor: '#831843', mutedColor: '#9ca3af', borderRadius: '2xl', fontFamily: 'Poppins', buttonStyle: 'solid', shadow: 'md' },
                    },
                    {
                      name: 'Minimalista',
                      preview: { bg: '#ffffff', card: '#ffffff', primary: '#18181b', secondary: '#3f3f46', text: '#18181b', muted: '#a1a1aa' },
                      theme: { primaryColor: '#18181b', secondaryColor: '#3f3f46', backgroundColor: '#ffffff', cardBackground: '#ffffff', textColor: '#18181b', mutedColor: '#a1a1aa', borderRadius: 'sm', fontFamily: 'Inter', buttonStyle: 'solid', shadow: 'sm' },
                    },
                    {
                      name: 'Vermelho Urgente',
                      preview: { bg: '#fef2f2', card: '#ffffff', primary: '#dc2626', secondary: '#ef4444', text: '#1c1917', muted: '#78716c' },
                      theme: { primaryColor: '#dc2626', secondaryColor: '#ef4444', backgroundColor: '#fef2f2', cardBackground: '#ffffff', textColor: '#1c1917', mutedColor: '#78716c', borderRadius: 'lg', fontFamily: 'Montserrat', buttonStyle: 'gradient', shadow: 'lg' },
                    },
                  ].map((preset) => {
                    const isActive =
                      settings.theme.primaryColor === preset.theme.primaryColor &&
                      settings.theme.backgroundColor === preset.theme.backgroundColor;
                    return (
                      <button
                        key={preset.name}
                        onClick={() =>
                          setSettings((prev) => ({ 
                            ...prev, 
                            theme: {
                              ...preset.theme,
                              template: (prev.theme as any).template || 'classic', // Preservar o template atual
                            }
                          }))
                        }
                        className={`relative rounded-lg border-2 p-2 text-left transition-all ${
                          isActive
                            ? 'border-green-500 ring-1 ring-green-500/50'
                            : 'border-gray-700 hover:border-gray-500'
                        }`}
                      >
                        {/* Mini preview */}
                        <div
                          className="rounded-md overflow-hidden h-16 mb-1.5"
                          style={{ backgroundColor: preset.preview.bg }}
                        >
                          <div
                            className="mx-2 mt-2 rounded-md p-1.5 h-12"
                            style={{ backgroundColor: preset.preview.card, boxShadow: '0 1px 3px rgba(0,0,0,0.08)' }}
                          >
                            <div
                              className="h-1.5 w-10 rounded-full mb-1"
                              style={{ backgroundColor: preset.preview.text, opacity: 0.7 }}
                            />
                            <div
                              className="h-1 w-14 rounded-full mb-2"
                              style={{ backgroundColor: preset.preview.muted, opacity: 0.4 }}
                            />
                            <div
                              className="h-3 w-full rounded"
                              style={{ background: `linear-gradient(135deg, ${preset.preview.primary}, ${preset.preview.secondary})` }}
                            />
                          </div>
                        </div>
                        <p className="text-[11px] font-medium text-white truncate">{preset.name}</p>
                        {isActive && (
                          <div className="absolute top-1 right-1 h-3 w-3 rounded-full bg-green-500 border border-gray-900">
                            <svg className="h-3 w-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                              <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                            </svg>
                          </div>
                        )}
                      </button>
                    );
                  })}
                </div>

                {/* Divider */}
                <div className="flex items-center gap-2 pt-2">
                  <div className="flex-1 border-t border-gray-700" />
                  <span className="text-[10px] text-gray-500 uppercase tracking-wider">Personalizar</span>
                  <div className="flex-1 border-t border-gray-700" />
                </div>

                <div className="space-y-4">
                  <div>
                    <label className="block text-xs font-medium text-tx-muted mb-1.5">Cor Principal</label>
                    <div className="flex gap-2">
                      <input
                        type="color"
                        value={settings.theme.primaryColor}
                        onChange={(e) => updateTheme('primaryColor', e.target.value)}
                        className="h-10 w-12 cursor-pointer rounded-element border border-gray-700 bg-transparent"
                      />
                      <input
                        type="text"
                        value={settings.theme.primaryColor}
                        onChange={(e) => updateTheme('primaryColor', e.target.value)}
                        className="input flex-1 text-sm"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-muted mb-1.5">Cor Secundária</label>
                    <div className="flex gap-2">
                      <input
                        type="color"
                        value={settings.theme.secondaryColor}
                        onChange={(e) => updateTheme('secondaryColor', e.target.value)}
                        className="h-10 w-12 cursor-pointer rounded-element border border-gray-700 bg-transparent"
                      />
                      <input
                        type="text"
                        value={settings.theme.secondaryColor}
                        onChange={(e) => updateTheme('secondaryColor', e.target.value)}
                        className="input flex-1 text-sm"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-muted mb-1.5">Fundo</label>
                    <div className="flex gap-2">
                      <input
                        type="color"
                        value={settings.theme.backgroundColor}
                        onChange={(e) => updateTheme('backgroundColor', e.target.value)}
                        className="h-10 w-12 cursor-pointer rounded-element border border-gray-700 bg-transparent"
                      />
                      <input
                        type="text"
                        value={settings.theme.backgroundColor}
                        onChange={(e) => updateTheme('backgroundColor', e.target.value)}
                        className="input flex-1 text-sm"
                      />
                    </div>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-muted mb-1.5">Estilo do Botão</label>
                    <select
                      value={settings.theme.buttonStyle}
                      onChange={(e) => updateTheme('buttonStyle', e.target.value)}
                      className="input text-sm"
                    >
                      <option value="solid">Sólido</option>
                      <option value="gradient">Gradiente</option>
                      <option value="outline">Contorno</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-muted mb-1.5">Bordas</label>
                    <select
                      value={settings.theme.borderRadius}
                      onChange={(e) => updateTheme('borderRadius', e.target.value)}
                      className="input text-sm"
                    >
                      <option value="none">Sem borda</option>
                      <option value="sm">Pequena</option>
                      <option value="md">Média</option>
                      <option value="lg">Grande</option>
                      <option value="xl">Extra Grande</option>
                      <option value="2xl">2x Grande</option>
                      <option value="full">Arredondada</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-muted mb-1.5">Sombra</label>
                    <select
                      value={settings.theme.shadow}
                      onChange={(e) => updateTheme('shadow', e.target.value)}
                      className="input text-sm"
                    >
                      <option value="none">Sem sombra</option>
                      <option value="sm">Pequena</option>
                      <option value="md">Média</option>
                      <option value="lg">Grande</option>
                      <option value="xl">Extra Grande</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-tx-muted mb-1.5">Fonte</label>
                    <select
                      value={settings.theme.fontFamily}
                      onChange={(e) => updateTheme('fontFamily', e.target.value)}
                      className="input text-sm"
                    >
                      <option value="Inter">Inter</option>
                      <option value="Roboto">Roboto</option>
                      <option value="Poppins">Poppins</option>
                      <option value="Montserrat">Montserrat</option>
                      <option value="Open Sans">Open Sans</option>
                    </select>
                  </div>
                </div>
              </Tab.Panel>

              {/* Order Bumps Panel */}
              <Tab.Panel>
                {checkoutId ? (
                  <OrderBumpsPanel checkoutId={checkoutId} availableProducts={availableProducts} />
                ) : (
                  <div className="rounded-lg border border-dashed border-gray-700 p-6 text-center">
                    <p className="text-sm text-tx-muted">Salve o checkout primeiro para gerenciar Order Bumps</p>
                  </div>
                )}
              </Tab.Panel>

              {/* Upsells Panel */}
              <Tab.Panel>
                {checkoutId ? (
                  <UpsellsPanel checkoutId={checkoutId} availableProducts={availableProducts} />
                ) : (
                  <div className="rounded-lg border border-dashed border-gray-700 p-6 text-center">
                    <p className="text-sm text-tx-muted">Salve o checkout primeiro para gerenciar Upsells</p>
                  </div>
                )}
              </Tab.Panel>
            </Tab.Panels>
          </Tab.Group>
        </div>

        {/* Preview Area */}
        <div className="flex-1 overflow-hidden bg-gradient-to-br from-gray-900 via-black to-green-900/20">
          {checkoutSlug ? (
            <div className="h-full flex flex-col">
              <div className="flex items-center justify-between px-4 py-2 bg-black/40 border-b border-gray-800">
                <span className="text-xs text-gray-400">Preview ao vivo</span>
                <button
                  onClick={() => setIframeKey(prev => prev + 1)}
                  className="text-xs text-green-400 hover:text-green-300 flex items-center gap-1"
                >
                  <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
                  </svg>
                  Recarregar
                </button>
              </div>
              <div className="flex-1 flex items-start justify-center overflow-auto p-4">
                <div
                  className={`bg-white transition-all ${
                    viewMode === 'mobile' ? 'w-[375px]' : 'w-full max-w-[1200px]'
                  }`}
                  style={{
                    height: viewMode === 'mobile' ? '667px' : '100%',
                    borderRadius: viewMode === 'mobile' ? '20px' : '8px',
                    overflow: 'hidden',
                    boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.5)',
                    border: viewMode === 'mobile' ? '3px solid #333' : '1px solid #333',
                  }}
                >
                  <iframe
                    ref={iframeRef}
                    key={iframeKey}
                    src={`/checkout/${checkoutSlug}?preview=true`}
                    className="w-full h-full border-0"
                    title="Checkout Preview"
                    onLoad={handleIframeLoad}
                    style={{ minHeight: viewMode === 'mobile' ? '667px' : '600px' }}
                  />
                </div>
              </div>
            </div>
          ) : (
            <div className="h-full overflow-y-auto p-4 sm:p-8">
              <div className="flex items-center justify-center min-h-full">
                <CheckoutPreview settings={settings} viewMode={viewMode} />
              </div>
            </div>
          )}
        </div>

        {/* Right Sidebar - Block Settings */}
        {selectedBlock && (
          <div className="w-full lg:w-72 overflow-y-auto border-t lg:border-t-0 lg:border-l border-gray-800 bg-[rgb(22,22,22)] p-4">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-sm font-semibold text-white">Configurar Bloco</h3>
              <button
                onClick={() => setSelectedBlockId(null)}
                className="text-tx-muted hover:text-white"
              >
                <ArrowLeftIcon className="h-4 w-4" />
              </button>
            </div>
            <BlockSettingsPanel
              block={selectedBlock}
              onUpdateBlock={(newSettings) => updateBlockSettings(selectedBlock.id, newSettings)}
            />
          </div>
        )}
      </div>
    </div>
  );
}


