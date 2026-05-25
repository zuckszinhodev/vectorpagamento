import { useState, useEffect, Fragment } from 'react';
import { useQuery, useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { Dialog, Transition } from '@headlessui/react';
import {
  SparklesIcon,
  XMarkIcon,
  CheckIcon,
  DocumentTextIcon,
  ShoppingBagIcon,
  LinkIcon,
  SwatchIcon,
  BoltIcon,
  MoonIcon,
  SunIcon,
  VideoCameraIcon,
  Squares2X2Icon,
  BriefcaseIcon,
  ChatBubbleBottomCenterTextIcon,
  ClockIcon,
  SparklesIcon as StarIcon,
  GlobeAltIcon,
  ServerIcon,
} from '@heroicons/react/24/outline';
import toast from 'react-hot-toast';
import { landingPagesService, type LandingPage, type GenerateLandingPageData } from '@/services/api';
import { domainsService, type CustomDomain } from '@/services/api/domains.service';

const templates = [
  { id: 'modern', name: 'Moderno', description: 'Design limpo e moderno', icon: SwatchIcon },
  { id: 'bold', name: 'Impactante', description: 'Cores vibrantes e ousadas', icon: BoltIcon },
  { id: 'minimal', name: 'Minimalista', description: 'Simples e elegante', icon: Squares2X2Icon },
  { id: 'dark', name: 'Dark Mode', description: 'Design escuro profissional', icon: MoonIcon },
  { id: 'gradient', name: 'Gradiente', description: 'Gradientes modernos', icon: SunIcon },
  { id: 'video', name: 'VSL', description: 'Otimizado para vídeo', icon: VideoCameraIcon },
];

const tones = [
  { id: 'professional', name: 'Profissional', description: 'Tom corporativo e sério', icon: BriefcaseIcon },
  { id: 'casual', name: 'Casual', description: 'Descontraído e amigável', icon: ChatBubbleBottomCenterTextIcon },
  { id: 'urgent', name: 'Urgente', description: 'Escassez e urgência', icon: ClockIcon },
  { id: 'luxury', name: 'Luxo', description: 'Premium e exclusivo', icon: StarIcon },
];

interface GenerateLandingPageModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: (landingPage: LandingPage) => void;
  initialCheckoutId?: string;
  initialProductId?: string;
}

export default function GenerateLandingPageModal({
  isOpen,
  onClose,
  onSuccess,
  initialCheckoutId,
  initialProductId,
}: GenerateLandingPageModalProps) {
  const [step, setStep] = useState(1);
  const [selectedProduct, setSelectedProduct] = useState<any>(null);
  const [selectedCheckout, setSelectedCheckout] = useState<any>(null);
  const [selectedTemplate, setSelectedTemplate] = useState('modern');
  const [selectedTone, setSelectedTone] = useState('professional');
  const [selectedDomain, setSelectedDomain] = useState<string>('default');
  const [prompt, setPrompt] = useState('');
  const [name, setName] = useState('');

  const totalSteps = 4;

  // Fetch products
  const { data: productsData } = useQuery({
    queryKey: ['products-for-lp'],
    queryFn: async () => {
      const response = await api.get('/products', { params: { per_page: 100 } });
      return response.data;
    },
    enabled: isOpen,
  });

  // Fetch checkouts
  const { data: checkoutsData } = useQuery({
    queryKey: ['checkouts-for-lp'],
    queryFn: async () => {
      const response = await api.get('/checkouts', { params: { per_page: 100 } });
      return response.data;
    },
    enabled: isOpen,
  });

  // Fetch custom domains
  const { data: domainsData } = useQuery({
    queryKey: ['domains-for-lp'],
    queryFn: () => domainsService.list(),
    enabled: isOpen,
  });

  const generateMutation = useMutation({
    mutationFn: async (data: GenerateLandingPageData) => {
      return landingPagesService.generate(data);
    },
    onSuccess: (response) => {
      toast.success('Landing page gerada com sucesso!');
      onSuccess(response.data);
      onClose();
      resetForm();
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Erro ao gerar landing page');
    },
  });

  const resetForm = () => {
    setStep(1);
    setSelectedProduct(null);
    setSelectedCheckout(null);
    setSelectedTemplate('modern');
    setSelectedTone('professional');
    setSelectedDomain('default');
    setPrompt('');
    setName('');
  };

  const handleGenerate = () => {
    if (!prompt.trim()) {
      toast.error('Digite uma descrição para sua página');
      return;
    }

    const data: GenerateLandingPageData = {
      prompt,
      product_id: selectedProduct?.id,
      checkout_id: selectedCheckout?.id,
      name: name || undefined,
      template: selectedTemplate,
      options: {
        tone: selectedTone as any,
      },
    };

    // Add custom domain if selected
    if (selectedDomain && selectedDomain !== 'default') {
      (data as any).custom_domain = selectedDomain;
    }

    generateMutation.mutate(data);
  };

  const products = productsData?.data || [];
  const checkouts = checkoutsData?.data || [];
  const verifiedDomains = (domainsData?.data || []).filter((d: CustomDomain) => d.status === 'verified');

  // Auto-select checkout when initialCheckoutId is provided
  useEffect(() => {
    if (initialCheckoutId && checkouts.length > 0 && !selectedCheckout) {
      const checkout = checkouts.find((c: any) => c.id === initialCheckoutId);
      if (checkout) {
        setSelectedCheckout(checkout);
        // Also select the product if available
        const product = products.find((p: any) => p.id === checkout.product_id);
        if (product) {
          setSelectedProduct(product);
        }
        // Skip to step 2 if checkout is auto-selected
        setStep(2);
      }
    }
  }, [initialCheckoutId, checkouts, products, selectedCheckout]);

  // Auto-select product when initialProductId is provided
  useEffect(() => {
    if (initialProductId && products.length > 0 && !selectedProduct) {
      const product = products.find((p: any) => p.id === initialProductId);
      if (product) {
        setSelectedProduct(product);
      }
    }
  }, [initialProductId, products, selectedProduct]);

  // Auto-select checkout when product is selected
  useEffect(() => {
    if (selectedProduct) {
      const productCheckout = checkouts.find((c: any) => c.product_id === selectedProduct.id);
      if (productCheckout) {
        setSelectedCheckout(productCheckout);
      }
    }
  }, [selectedProduct, checkouts]);

  return (
    <Transition appear show={isOpen} as={Fragment}>
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
          <div className="fixed inset-0 bg-black/50" />
        </Transition.Child>

        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 scale-95"
              enterTo="opacity-100 scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 scale-100"
              leaveTo="opacity-0 scale-95"
            >
              <Dialog.Panel className="w-full max-w-2xl transform overflow-hidden rounded-xl bg-white dark:bg-[rgb(24,24,24)] shadow-xl transition-all border border-gray-200 dark:border-[rgb(50,50,50)]">
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-[rgb(50,50,50)]">
                  <div className="flex items-center gap-3">
                    <div className="p-2 rounded-lg bg-emerald-100 dark:bg-emerald-900/30">
                      <SparklesIcon className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                      <Dialog.Title className="text-lg font-semibold text-gray-900 dark:text-white">
                        Gerar Página de Vendas com IA
                      </Dialog.Title>
                      <p className="text-sm text-gray-500 dark:text-gray-400">
                        Passo {step} de {totalSteps}
                      </p>
                    </div>
                  </div>
                  <button
                    onClick={onClose}
                    className="p-2 text-gray-400 hover:text-gray-500 dark:hover:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-[rgb(40,40,40)]"
                  >
                    <XMarkIcon className="h-5 w-5" />
                  </button>
                </div>

                {/* Progress Steps */}
                <div className="px-6 py-3 bg-gray-50 dark:bg-[rgb(30,30,30)] border-b border-gray-200 dark:border-[rgb(50,50,50)]">
                  <div className="flex items-center justify-between max-w-lg mx-auto">
                    {[
                      { num: 1, label: 'Produto' },
                      { num: 2, label: 'Template' },
                      { num: 3, label: 'Domínio' },
                      { num: 4, label: 'Descrição' },
                    ].map((s, index) => (
                      <div key={s.num} className="flex items-center">
                        <div className="flex flex-col items-center">
                          <div
                            className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium transition-colors ${
                              s.num === step
                                ? 'bg-emerald-600 text-white'
                                : s.num < step
                                ? 'bg-green-500 text-white'
                                : 'bg-gray-200 dark:bg-[rgb(50,50,50)] text-gray-500 dark:text-gray-400'
                            }`}
                          >
                            {s.num < step ? <CheckIcon className="h-4 w-4" /> : s.num}
                          </div>
                          <span className="text-xs text-gray-500 dark:text-gray-400 mt-1">{s.label}</span>
                        </div>
                        {index < 3 && (
                          <div className={`w-12 h-0.5 mx-1.5 ${s.num < step ? 'bg-green-500' : 'bg-gray-200 dark:bg-[rgb(50,50,50)]'}`} />
                        )}
                      </div>
                    ))}
                  </div>
                </div>

                {/* Content */}
                <div className="p-6 max-h-[60vh] overflow-y-auto">
                  {step === 1 && (
                    <div className="space-y-6">
                      <div>
                        <h3 className="text-sm font-medium text-gray-900 dark:text-white mb-3">
                          Selecione um Produto (opcional)
                        </h3>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 max-h-48 overflow-y-auto">
                          <button
                            onClick={() => setSelectedProduct(null)}
                            className={`p-3 rounded-lg border text-left transition-all ${
                              !selectedProduct
                                ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
                                : 'border-gray-200 dark:border-[rgb(50,50,50)] hover:border-gray-300 dark:hover:border-[rgb(70,70,70)]'
                            }`}
                          >
                            <DocumentTextIcon className="h-6 w-6 text-gray-400 mb-2" />
                            <p className="font-medium text-gray-900 dark:text-white text-sm">
                              Sem produto
                            </p>
                            <p className="text-xs text-gray-500">Página genérica</p>
                          </button>
                          {products.map((product: any) => (
                            <button
                              key={product.id}
                              onClick={() => setSelectedProduct(product)}
                              className={`p-3 rounded-lg border text-left transition-all ${
                                selectedProduct?.id === product.id
                                  ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
                                  : 'border-gray-200 dark:border-[rgb(50,50,50)] hover:border-gray-300 dark:hover:border-[rgb(70,70,70)]'
                              }`}
                            >
                              {product.image_url ? (
                                <img
                                  src={product.image_url}
                                  alt={product.name}
                                  className="w-8 h-8 rounded object-cover mb-2"
                                />
                              ) : (
                                <ShoppingBagIcon className="h-6 w-6 text-gray-400 mb-2" />
                              )}
                              <p className="font-medium text-gray-900 dark:text-white text-sm truncate">
                                {product.name}
                              </p>
                              <p className="text-xs text-gray-500">
                                R$ {(product.price / 100).toFixed(2)}
                              </p>
                            </button>
                          ))}
                        </div>
                      </div>

                      {selectedProduct && (
                        <div>
                          <h3 className="text-sm font-medium text-gray-900 dark:text-white mb-3">
                            Checkout Vinculado
                          </h3>
                          <div className="grid grid-cols-2 gap-3">
                            {checkouts
                              .filter((c: any) => c.product_id === selectedProduct.id || !c.product_id)
                              .slice(0, 6)
                              .map((checkout: any) => (
                                <button
                                  key={checkout.id}
                                  onClick={() => setSelectedCheckout(checkout)}
                                  className={`p-3 rounded-lg border text-left transition-all ${
                                    selectedCheckout?.id === checkout.id
                                      ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
                                      : 'border-gray-200 dark:border-[rgb(50,50,50)] hover:border-gray-300 dark:hover:border-[rgb(70,70,70)]'
                                  }`}
                                >
                                  <div className="flex items-center gap-2">
                                    <LinkIcon className="h-4 w-4 text-gray-400" />
                                    <p className="font-medium text-gray-900 dark:text-white text-sm">
                                      {checkout.name}
                                    </p>
                                  </div>
                                  <p className="text-xs text-gray-500 mt-1">/{checkout.slug}</p>
                                </button>
                              ))}
                          </div>
                        </div>
                      )}
                    </div>
                  )}

                  {step === 2 && (
                    <div className="space-y-6">
                      <div>
                        <h3 className="text-sm font-medium text-gray-900 dark:text-white mb-3">
                          Escolha o Template
                        </h3>
                        <div className="grid grid-cols-3 gap-3">
                          {templates.map((template) => (
                            <button
                              key={template.id}
                              onClick={() => setSelectedTemplate(template.id)}
                              className={`p-4 rounded-lg border text-center transition-all ${
                                selectedTemplate === template.id
                                  ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
                                  : 'border-gray-200 dark:border-[rgb(50,50,50)] hover:border-gray-300 dark:hover:border-[rgb(70,70,70)]'
                              }`}
                            >
                              <template.icon className={`h-8 w-8 mx-auto mb-2 ${
                                selectedTemplate === template.id
                                  ? 'text-emerald-600 dark:text-emerald-400'
                                  : 'text-gray-400'
                              }`} />
                              <p className="font-medium text-gray-900 dark:text-white text-sm">
                                {template.name}
                              </p>
                              <p className="text-xs text-gray-500 mt-1">{template.description}</p>
                            </button>
                          ))}
                        </div>
                      </div>

                      <div>
                        <h3 className="text-sm font-medium text-gray-900 dark:text-white mb-3">
                          Tom da Comunicação
                        </h3>
                        <div className="grid grid-cols-2 gap-3">
                          {tones.map((tone) => (
                            <button
                              key={tone.id}
                              onClick={() => setSelectedTone(tone.id)}
                              className={`p-3 rounded-lg border text-left transition-all flex items-start gap-3 ${
                                selectedTone === tone.id
                                  ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
                                  : 'border-gray-200 dark:border-[rgb(50,50,50)] hover:border-gray-300 dark:hover:border-[rgb(70,70,70)]'
                              }`}
                            >
                              <tone.icon className={`h-5 w-5 mt-0.5 ${
                                selectedTone === tone.id
                                  ? 'text-emerald-600 dark:text-emerald-400'
                                  : 'text-gray-400'
                              }`} />
                              <div>
                                <p className="font-medium text-gray-900 dark:text-white text-sm">
                                  {tone.name}
                                </p>
                                <p className="text-xs text-gray-500">{tone.description}</p>
                              </div>
                            </button>
                          ))}
                        </div>
                      </div>
                    </div>
                  )}

                  {step === 3 && (
                    <div className="space-y-4">
                      <h3 className="text-sm font-medium text-gray-900 dark:text-white mb-3">
                        Escolha o Domínio
                      </h3>

                      {/* Default domain */}
                      <button
                        onClick={() => setSelectedDomain('default')}
                        className={`w-full p-4 rounded-lg border text-left transition-all flex items-start gap-3 ${
                          selectedDomain === 'default'
                            ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
                            : 'border-gray-200 dark:border-[rgb(50,50,50)] hover:border-gray-300 dark:hover:border-[rgb(70,70,70)]'
                        }`}
                      >
                        <ServerIcon className={`h-6 w-6 mt-0.5 flex-shrink-0 ${
                          selectedDomain === 'default'
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : 'text-gray-400'
                        }`} />
                        <div>
                          <p className="font-medium text-gray-900 dark:text-white text-sm">Domínio Padrão</p>
                          <p className="text-xs text-gray-500 mt-0.5">
                            Sua página será acessível pelo subdomínio vectorpagamentos.com
                          </p>
                        </div>
                      </button>

                      {/* Verified custom domains */}
                      {verifiedDomains.length > 0 && (
                        <div>
                          <p className="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">
                            Domínios Personalizados
                          </p>
                          <div className="space-y-2">
                            {verifiedDomains.map((domain: CustomDomain) => (
                              <button
                                key={domain.id}
                                onClick={() => setSelectedDomain(domain.domain)}
                                className={`w-full p-4 rounded-lg border text-left transition-all flex items-start gap-3 ${
                                  selectedDomain === domain.domain
                                    ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20'
                                    : 'border-gray-200 dark:border-[rgb(50,50,50)] hover:border-gray-300 dark:hover:border-[rgb(70,70,70)]'
                                }`}
                              >
                                <GlobeAltIcon className={`h-6 w-6 mt-0.5 flex-shrink-0 ${
                                  selectedDomain === domain.domain
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-gray-400'
                                }`} />
                                <div>
                                  <p className="font-medium text-gray-900 dark:text-white text-sm">{domain.domain}</p>
                                  <p className="text-xs text-green-600 dark:text-green-400 mt-0.5 flex items-center gap-1">
                                    <CheckIcon className="h-3 w-3" /> Verificado
                                  </p>
                                </div>
                              </button>
                            ))}
                          </div>
                        </div>
                      )}

                      {verifiedDomains.length === 0 && (
                        <div className="bg-gray-50 dark:bg-[rgb(30,30,30)] rounded-lg p-4 border border-dashed border-gray-300 dark:border-[rgb(50,50,50)]">
                          <p className="text-sm text-gray-500 dark:text-gray-400">
                            Você ainda não possui domínios personalizados verificados.
                            Configure seus domínios na aba <span className="font-medium text-emerald-600 dark:text-emerald-400">Domínios</span>.
                          </p>
                        </div>
                      )}
                    </div>
                  )}

                  {step === 4 && (
                    <div className="space-y-5">
                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                          Nome da Landing Page
                        </label>
                        <input
                          type="text"
                          value={name}
                          onChange={(e) => setName(e.target.value)}
                          placeholder="Ex: Página de Vendas - Curso Marketing"
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-[rgb(50,50,50)] bg-white dark:bg-[rgb(30,30,30)] text-gray-900 dark:text-white focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                        />
                      </div>

                      <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                          Descreva o que você quer na página *
                        </label>
                        <textarea
                          value={prompt}
                          onChange={(e) => setPrompt(e.target.value)}
                          rows={5}
                          placeholder="Descreva sua página de vendas. Exemplo: Quero uma página para meu curso de marketing digital, focada em empreendedores iniciantes. Destaque aulas práticas, suporte por 1 ano e comunidade exclusiva. Oferta de 70% de desconto por 24 horas."
                          className="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-[rgb(50,50,50)] bg-white dark:bg-[rgb(30,30,30)] text-gray-900 dark:text-white focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                        />
                        <p className="mt-1.5 text-xs text-gray-500">
                          Quanto mais detalhes você fornecer, melhor será o resultado
                        </p>
                      </div>

                      {/* Summary */}
                      <div className="bg-gray-50 dark:bg-[rgb(30,30,30)] rounded-lg p-4 border border-gray-200 dark:border-[rgb(50,50,50)]">
                        <h4 className="text-sm font-medium text-gray-900 dark:text-white mb-3">
                          Resumo
                        </h4>
                        <div className="grid grid-cols-2 gap-3 text-sm">
                          <div>
                            <span className="text-gray-500 dark:text-gray-400">Produto:</span>
                            <p className="text-gray-900 dark:text-white font-medium">
                              {selectedProduct?.name || 'Nenhum'}
                            </p>
                          </div>
                          <div>
                            <span className="text-gray-500 dark:text-gray-400">Checkout:</span>
                            <p className="text-gray-900 dark:text-white font-medium">
                              {selectedCheckout?.name || 'Nenhum'}
                            </p>
                          </div>
                          <div>
                            <span className="text-gray-500 dark:text-gray-400">Template:</span>
                            <p className="text-gray-900 dark:text-white font-medium">
                              {templates.find((t) => t.id === selectedTemplate)?.name}
                            </p>
                          </div>
                          <div>
                            <span className="text-gray-500 dark:text-gray-400">Tom:</span>
                            <p className="text-gray-900 dark:text-white font-medium">
                              {tones.find((t) => t.id === selectedTone)?.name}
                            </p>
                          </div>
                          <div>
                            <span className="text-gray-500 dark:text-gray-400">Domínio:</span>
                            <p className="text-gray-900 dark:text-white font-medium">
                              {selectedDomain === 'default' ? 'Padrão (vectorpagamentos.com)' : selectedDomain}
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  )}
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between border-t border-gray-200 dark:border-[rgb(50,50,50)] px-6 py-4">
                  <button
                    onClick={() => (step === 1 ? onClose() : setStep(step - 1))}
                    className="px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white"
                  >
                    {step === 1 ? 'Cancelar' : 'Voltar'}
                  </button>
                  
                  {step < totalSteps ? (
                    <button
                      onClick={() => setStep(step + 1)}
                      className="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700"
                    >
                      Próximo
                    </button>
                  ) : (
                    <button
                      onClick={handleGenerate}
                      disabled={!prompt.trim() || generateMutation.isPending}
                      className="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 disabled:opacity-50 inline-flex items-center gap-2"
                    >
                      {generateMutation.isPending ? (
                        <>
                          <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                          </svg>
                          Gerando com IA...
                        </>
                      ) : (
                        <>
                          <SparklesIcon className="h-4 w-4" />
                          Gerar Página de Vendas
                        </>
                      )}
                    </button>
                  )}
                </div>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition>
  );
}
