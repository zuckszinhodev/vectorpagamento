import { useState, useRef, useEffect, useMemo } from 'react';

const MOTIVATIONAL_MESSAGES = [
  'Que dia maravilhoso para escalar! 🚀',
  'Cada venda é uma semente do seu império 🌱',
  'Hoje é o dia perfeito para bater recordes 💪',
  'Seu próximo cliente já está te procurando 🎯',
  'Grandes resultados começam com pequenas ações diárias ⚡',
  'O sucesso ama quem é consistente 🏆',
  'Sua dedicação de hoje é o faturamento de amanhã 💰',
  'Você não chegou até aqui para parar agora 🔥',
  'Foco no processo, os resultados vêm 📈',
  'Mais um dia para transformar vidas com seu produto ',
  'Disciplina hoje, liberdade amanhã 🦅',
  'Seu negócio cresce na velocidade da sua mentalidade 🧠',
  'O mercado recompensa quem não desiste 💎',
  'Bora fazer acontecer! O topo te espera 🏔️',
  'Cada clique pode ser a próxima grande venda 🛒',
  'Acredite: o melhor mês da sua vida está chegando ',
  'Você é mais forte do que qualquer objeção 💪',
  'Hora de converter visitantes em fãs do seu produto 🎉',
  'Seu futuro eu vai agradecer o esforço de hoje ',
  'Nenhum concorrente supera quem trabalha com paixão ❤️‍🔥',
  'Dia novo, oportunidades novas. Aproveite cada uma! ',
  'A persistência é o combustível dos grandes empreendedores ',
  'Quem domina o digital, domina o futuro 🌐',
  'Transforme conhecimento em receita. Você consegue! 💰',
  'Seus clientes merecem o melhor — e você entrega! ',
  'Escalar é uma decisão. Você já tomou a sua ',
  'O algoritmo favorece quem não para de criar ',
  'Mais um dia para provar que você fez a escolha certa 👊',
  'Enquanto muitos duvidam, você executa ',
  'A melhor versão do seu negócio está sendo construída agora ',
  'Vender é servir. Sirva com excelência hoje! 🤝',
];

function getDailyMessage(): string {
  const now = new Date();
  const dayOfYear = Math.floor(
    (now.getTime() - new Date(now.getFullYear(), 0, 0).getTime()) / 86400000
  );
  return MOTIVATIONAL_MESSAGES[dayOfYear % MOTIVATIONAL_MESSAGES.length];
}

function getGreeting(): string {
  const hour = new Date().getHours();
  if (hour >= 5 && hour < 12) return 'Bom dia';
  if (hour >= 12 && hour < 18) return 'Boa tarde';
  return 'Boa noite';
}
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, formatCurrency } from '@/lib/api';
import {
  ArrowUpIcon,
  ArrowDownIcon,
} from '@heroicons/react/24/solid';
import {
  CurrencyDollarIcon,
  ShoppingCartIcon,
  TicketIcon,
  CreditCardIcon,
  TrophyIcon,
  UserPlusIcon,
  DocumentTextIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  CalendarIcon,
  EyeIcon,
  EyeSlashIcon,
  BoltIcon,
  WalletIcon,
  ChartBarIcon,
  ArrowTopRightOnSquareIcon,
  ExclamationTriangleIcon,
  ShieldExclamationIcon,
} from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';
import { GlowLineChart, ChartCard } from '@/components/ui/GlowChart';
import { Skeleton } from '@/components/ui/Skeleton';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';

const GOAL_TARGET_SEQUENCE_CENTS = [1000000, 5000000] as const;
const GOAL_ADVANCE_DELAY_MS = 2 * 24 * 60 * 60 * 1000;
const SUPPORT_WHATSAPP_NUMBER = '5511940968290';

type GoalMilestoneState = {
  currentIndex: number;
  achievedAt: string | null;
};

function getDefaultGoalMilestoneState(): GoalMilestoneState {
  return {
    currentIndex: 0,
    achievedAt: null,
  };
}

function getGoalMilestoneStorageKey(userId: string): string {
  return `vectorpagamentos.goal-milestone.${userId}`;
}

function normalizeGoalMilestoneState(value: Partial<GoalMilestoneState> | null | undefined): GoalMilestoneState {
  const lastGoalIndex = GOAL_TARGET_SEQUENCE_CENTS.length - 1;
  const parsedIndex = typeof value?.currentIndex === 'number'
    ? Math.min(Math.max(Math.floor(value.currentIndex), 0), lastGoalIndex)
    : 0;

  const achievedAt = typeof value?.achievedAt === 'string' && !Number.isNaN(Date.parse(value.achievedAt))
    ? value.achievedAt
    : null;

  return {
    currentIndex: parsedIndex,
    achievedAt,
  };
}

function readGoalMilestoneState(storageKey: string | null): GoalMilestoneState {
  if (!storageKey || typeof window === 'undefined') {
    return getDefaultGoalMilestoneState();
  }

  try {
    const rawValue = window.localStorage.getItem(storageKey);
    if (!rawValue) {
      return getDefaultGoalMilestoneState();
    }

    return normalizeGoalMilestoneState(JSON.parse(rawValue) as Partial<GoalMilestoneState>);
  } catch {
    return getDefaultGoalMilestoneState();
  }
}

function writeGoalMilestoneState(storageKey: string | null, value: GoalMilestoneState) {
  if (!storageKey || typeof window === 'undefined') {
    return;
  }

  try {
    window.localStorage.setItem(storageKey, JSON.stringify(value));
  } catch {
    // noop
  }
}

// YYYY-MM-DD no fuso local (toISOString usa UTC e vira o dia seguinte após 21h no Brasil)
const toLocalDateStr = (date: Date) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

type DashboardPeriod = 'today' | '7d' | '30d' | '90d' | '1y' | 'custom';

const periodOptions: { key: DashboardPeriod; label: string }[] = [
  { key: 'today', label: 'Hoje' },
  { key: '7d', label: '7 dias' },
  { key: '30d', label: '30 dias' },
  { key: '90d', label: '90 dias' },
  { key: '1y', label: '1 ano' },
  { key: 'custom', label: 'Personalizado' },
];

function DashboardMetricCardSkeleton() {
  return (
    <div className="relative overflow-hidden rounded-lg border border-border-DEFAULT bg-surface-card p-2 sm:p-3">
      <div className="mb-3 flex items-center gap-2">
        <Skeleton width="1.75rem" height="1.75rem" className="rounded-lg" />
        <Skeleton width="45%" height="0.75rem" />
      </div>
      <Skeleton width="38%" height="1.75rem" className="sm:h-8" />
    </div>
  );
}

function DashboardChartSkeleton() {
  const barHeights = [28, 44, 36, 56, 42, 64, 52, 74, 58, 46, 62, 40];

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap items-center gap-1">
          {periodOptions.map((option) => (
            <Skeleton
              key={option.key}
              width="4rem"
              height="1.75rem"
              className="rounded-full"
            />
          ))}
        </div>
        <Skeleton width="14rem" height="2rem" className="rounded-element" />
      </div>

      <div className="h-[140px] rounded-xl border border-border-DEFAULT/60 bg-surface-page/40 p-3 sm:h-[220px] sm:p-4">
        <div className="flex h-full items-end gap-2">
          {barHeights.map((height, index) => (
            <div key={index} className="flex h-full flex-1 items-end">
              <Skeleton width="100%" height={`${height}%`} className="rounded-full" />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function DashboardWalletSkeleton() {
  return (
    <div className="bg-surface-card border border-border-DEFAULT rounded-xl p-2 sm:p-4 relative z-0">
      <div className="mb-2 lg:mb-4">
        <Skeleton width="7rem" height="1rem" />
      </div>

      <div className="grid grid-cols-3 gap-2 lg:grid-cols-1">
        {Array.from({ length: 3 }).map((_, index) => (
          <div key={index} className="rounded-lg border border-border-DEFAULT p-2 lg:p-3">
            <div className="mb-2 flex items-center gap-2">
              <Skeleton width="1.25rem" height="1.25rem" className="rounded-md" />
              <Skeleton width="45%" height="0.75rem" />
            </div>
            <Skeleton width="60%" height="1rem" className="lg:h-7" />
          </div>
        ))}
      </div>

      <Skeleton width="100%" height="2.5rem" className="mt-2 rounded-lg lg:mt-4" />
    </div>
  );
}

function DashboardCircleMetricSkeleton() {
  return (
    <div className="flex flex-col items-center gap-1 sm:flex-row sm:gap-3">
      <Skeleton width="3rem" height="3rem" className="rounded-full hidden sm:block" />
      <Skeleton width="2rem" height="2rem" className="rounded-full sm:hidden" />
      <div className="w-full min-w-0 text-center sm:text-left">
        <Skeleton width="60%" height="0.625rem" className="mx-auto sm:mx-0 mb-2" />
        <Skeleton width="70%" height="0.875rem" className="mx-auto sm:mx-0 mb-1" />
        <Skeleton width="45%" height="0.5rem" className="mx-auto sm:mx-0 hidden sm:block" />
      </div>
    </div>
  );
}

function DashboardRevenueHeroSkeleton() {
  return (
    <div className="relative p-6 text-center sm:p-10">
      <Skeleton width="9rem" height="1rem" className="mx-auto mb-4" />
      <Skeleton width="22rem" height="4rem" className="mx-auto mb-8 max-w-full sm:h-24" />

      <div className="mx-auto mb-8 max-w-lg">
        <div className="mb-4 flex items-center justify-between gap-3">
          <Skeleton width="3rem" height="0.75rem" />
          <Skeleton width="7rem" height="0.75rem" />
        </div>
        <Skeleton width="100%" height="1rem" className="rounded-full" />
        <div className="mt-4 flex items-center justify-between gap-3">
          <Skeleton width="2rem" height="0.75rem" />
          <Skeleton width="9rem" height="0.75rem" />
        </div>
      </div>

      <div className="flex items-center justify-center gap-4">
        <Skeleton width="180px" height="180px" className="rounded-2xl sm:w-[280px] sm:h-[220px]" />
        <Skeleton width="140px" height="180px" className="rounded-2xl sm:w-[200px] sm:h-[220px]" />
      </div>
    </div>
  );
}

// Componente de Calendário
function DateRangePicker({ 
  startDate, 
  endDate, 
  onStartDateChange,
  onEndDateChange,
  defaultOpen = false,
}: {
  startDate: string;
  endDate: string;
  onStartDateChange: (date: string) => void;
  onEndDateChange: (date: string) => void;
  defaultOpen?: boolean;
}) {
  const [isOpen, setIsOpen] = useState(defaultOpen);
  const [currentMonth, setCurrentMonth] = useState(new Date());
  const [selectingStart, setSelectingStart] = useState(true);
  const calendarRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (calendarRef.current && !calendarRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const getDaysInMonth = (date: Date) => {
    const year = date.getFullYear();
    const month = date.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const daysInMonth = lastDay.getDate();
    const startingDayOfWeek = firstDay.getDay();
    
    return { daysInMonth, startingDayOfWeek, year, month };
  };

  const formatDate = (dateStr: string) => {
    const date = new Date(dateStr + 'T00:00:00');
    return `${date.getDate()} de ${date.toLocaleDateString('pt-BR', { month: 'short' })}, ${date.getFullYear()}`;
  };

  const formatDateShort = (dateStr: string) => {
    const date = new Date(dateStr + 'T00:00:00');
    return `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}`;
  };

  const isDateInRange = (day: number) => {
    const year = currentMonth.getFullYear();
    const month = currentMonth.getMonth();
    const date = new Date(year, month, day);
    const dateStr = toLocalDateStr(date);
    const start = new Date(startDate + 'T00:00:00');
    const end = new Date(endDate + 'T00:00:00');
    return date >= start && date <= end;
  };

  const isStartOrEndDate = (day: number) => {
    const year = currentMonth.getFullYear();
    const month = currentMonth.getMonth();
    const date = new Date(year, month, day);
    const dateStr = toLocalDateStr(date);
    return dateStr === startDate || dateStr === endDate;
  };

  const handleDayClick = (day: number) => {
    const year = currentMonth.getFullYear();
    const month = currentMonth.getMonth();
    const date = new Date(year, month, day);
    const dateStr = toLocalDateStr(date);
    const today = toLocalDateStr(new Date());
    
    if (dateStr > today) return;

    if (selectingStart) {
      onStartDateChange(dateStr);
      if (dateStr > endDate) {
        onEndDateChange(dateStr);
      }
      setSelectingStart(false);
    } else {
      if (dateStr < startDate) {
        onStartDateChange(dateStr);
      } else {
        onEndDateChange(dateStr);
      }
      setSelectingStart(true);
    }
  };

  const { daysInMonth, startingDayOfWeek, year, month } = getDaysInMonth(currentMonth);
  const monthName = currentMonth.toLocaleDateString('pt-BR', { month: 'long' });
  const today = new Date();

  return (
    <div className="relative" ref={calendarRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="flex items-center gap-1 sm:gap-2 px-1.5 sm:px-4 py-1 sm:py-2 text-[10px] sm:text-sm rounded-element border border-border-DEFAULT bg-surface-card text-tx-primary hover:border-accent-primary focus:ring-2 focus:ring-accent-primary/30 focus:border-accent-primary transition-colors"
      >
        <CalendarIcon className="h-3.5 w-3.5 sm:h-5 sm:w-5 text-tx-muted flex-shrink-0" />
        <span className="sm:hidden">{formatDateShort(startDate)} - {formatDateShort(endDate)}</span>
        <span className="hidden sm:inline">{formatDate(startDate)} até {formatDate(endDate)}</span>
      </button>

      {isOpen && (
        <div className="absolute left-0 sm:left-auto sm:right-0 top-full mt-2 z-50 w-[calc(100vw-2rem)] sm:w-80 max-w-[320px] rounded-card bg-surface-elevated shadow-float border border-border-DEFAULT p-3 sm:p-4">
          {/* Header */}
          <div className="flex items-center justify-between mb-3 sm:mb-4">
            <button
              onClick={() => setCurrentMonth(new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1))}
              className="p-1.5 sm:p-2 hover:bg-surface-hover rounded-element transition-colors"
            >
              <ChevronLeftIcon className="h-4 w-4 sm:h-5 sm:w-5 text-tx-secondary" />
            </button>
            <div className="text-center">
              <h3 className="text-xs sm:text-sm font-semibold text-tx-primary capitalize">
                {monthName}
              </h3>
              <p className="text-[10px] sm:text-xs text-tx-muted">{year}</p>
            </div>
            <button
              onClick={() => setCurrentMonth(new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1))}
              className="p-1.5 sm:p-2 hover:bg-surface-hover rounded-element transition-colors"
            >
              <ChevronRightIcon className="h-4 w-4 sm:h-5 sm:w-5 text-tx-secondary" />
            </button>
          </div>

          {/* Dias da semana */}
          <div className="grid grid-cols-7 gap-0.5 sm:gap-1 mb-2">
            {['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'].map((day) => (
              <div key={day} className="text-center text-[10px] sm:text-xs font-medium text-tx-muted py-1.5 sm:py-2">
                {day}
              </div>
            ))}
          </div>

          {/* Dias do mês */}
          <div className="grid grid-cols-7 gap-0.5 sm:gap-1">
            {/* Espaços vazios antes do primeiro dia */}
            {Array.from({ length: startingDayOfWeek }).map((_, i) => (
              <div key={`empty-${i}`} />
            ))}
            
            {/* Dias do mês */}
            {Array.from({ length: daysInMonth }).map((_, i) => {
              const day = i + 1;
              const date = new Date(year, month, day);
              const dateStr = toLocalDateStr(date);
              const isToday = dateStr === toLocalDateStr(today);
              const isFuture = date > today;
              const inRange = isDateInRange(day);
              const isStartEnd = isStartOrEndDate(day);

              return (
                <button
                  key={day}
                  onClick={() => handleDayClick(day)}
                  disabled={isFuture}
                  className={`
                    relative h-8 sm:h-10 text-xs sm:text-sm font-medium rounded-element transition-all
                    ${isFuture ? 'text-tx-muted/50 cursor-not-allowed' : ''}
                    ${!isFuture && !inRange ? 'text-tx-secondary hover:bg-surface-hover' : ''}
                    ${inRange && !isStartEnd ? 'bg-accent-subtle text-accent-primary' : ''}
                    ${isStartEnd ? 'bg-accent-primary text-white hover:bg-accent-hover' : ''}
                    ${isToday && !isStartEnd ? 'ring-2 ring-accent-primary ring-inset' : ''}
                  `}
                >
                  {day}
                </button>
              );
            })}
          </div>

          {/* Footer info */}
          <div className="mt-3 sm:mt-4 pt-3 sm:pt-4 border-t border-border-DEFAULT">
            <p className="text-[10px] sm:text-xs text-center text-tx-muted">
              {selectingStart ? 'Selecione a data inicial' : 'Selecione a data final'}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

export default function DashboardPage() {
  const platformName = useThemeStore((s) => s.platformName);
  const navigate = useNavigate();
  const { user, isImpersonating, stopImpersonation } = useAuthStore();
  const [showBalance, setShowBalance] = useState(true);
  const [goalMilestoneState, setGoalMilestoneState] = useState<GoalMilestoneState>(() =>
    readGoalMilestoneState(user?.id ? getGoalMilestoneStorageKey(user.id) : null)
  );
  
  const getDatesByPeriod = (period: DashboardPeriod) => {
    const today = new Date();
    const end = today;
    const start = new Date(today);

    switch (period) {
      case 'today':
        break;
      case '7d':
        start.setDate(today.getDate() - 6);
        break;
      case '30d':
        start.setDate(today.getDate() - 29);
        break;
      case '90d':
        start.setDate(today.getDate() - 89);
        break;
      case '1y':
        start.setFullYear(today.getFullYear() - 1);
        start.setDate(start.getDate() + 1); // inclusive 1 year
        break;
      case 'custom':
      default:
        start.setDate(today.getDate() - 29);
        break;
    }

    return {
      start: toLocalDateStr(start),
      end: toLocalDateStr(end),
    };
  };

  const [selectedPeriod, setSelectedPeriod] = useState<DashboardPeriod>('30d');
  const initialRange = getDatesByPeriod('30d');
  const [startDate, setStartDate] = useState(initialRange.start);
  const [endDate, setEndDate] = useState(initialRange.end);

  const applyPeriod = (period: DashboardPeriod) => {
    if (period === 'custom') {
      setSelectedPeriod(period);
      return;
    }

    const { start, end } = getDatesByPeriod(period);
    setSelectedPeriod(period);
    setStartDate(start);
    setEndDate(end);
  };

  // Gera dados vazios (sem pagamentos) - apenas 1 ponto central
  const getDailyData = (start: string, end: string) => {
    const startDateObj = new Date(start);
    const endDateObj = new Date(end);
    const midDate = new Date((startDateObj.getTime() + endDateObj.getTime()) / 2);
    
    return {
      labels: [`${String(midDate.getDate()).padStart(2, '0')}/${String(midDate.getMonth() + 1).padStart(2, '0')}`],
      data: [0],
    };
  };

  // Buscar dados do dashboard da API
  const {
    data: dashboardResponse,
    isLoading: isDashboardLoading,
    isFetching: isDashboardFetching,
  } = useQuery({
    queryKey: ['dashboard', selectedPeriod, startDate, endDate],
    queryFn: async () => {
      const params: any = {
        period: selectedPeriod,
        date_from: startDate,
        date_to: endDate,
      };

      const response = await api.get('/reports/dashboard', { params });
      return response.data?.data || {};
    },
    refetchInterval: 30000,
    staleTime: 15000,
    gcTime: 0, // Não manter cache antigo
  });

  const dashboardData = dashboardResponse?.summary || {};
  const dailyRevenue = dashboardResponse?.daily_revenue || [];
  const hourlyRevenue = dashboardResponse?.hourly_revenue || [];
  const pixRevenue24h = dashboardResponse?.pix_revenue || 0;
  const cardRevenue24h = dashboardResponse?.card_revenue || 0;
  const boletoRevenue24h = dashboardResponse?.boleto_revenue || 0;
  const checkoutRevenue = dashboardResponse?.checkout_revenue || 0;
  const checkoutCount = dashboardResponse?.checkout_count || 0;
  const memberAreaRevenue = dashboardResponse?.member_area_revenue || 0;
  const memberAreaCount = dashboardResponse?.member_area_count || 0;
  const orderBumpRevenue = dashboardResponse?.order_bump_revenue || 0;
  const orderBumpCount = dashboardResponse?.order_bump_count || 0;
  const upsellRevenue = dashboardResponse?.upsell_revenue || 0;
  const upsellCount = dashboardResponse?.upsell_count || 0;

  // Buscar saldo de retirada
  const {
    data: walletSummary,
    isLoading: isWalletLoading,
    isFetching: isWalletFetching,
  } = useQuery({
    queryKey: ['withdrawals-summary', user?.account_id],
    queryFn: async () => {
      const response = await api.get('/withdrawals/summary');
      const summary = response.data?.data;
      if (!summary) {
        throw new Error('Resumo da carteira indisponível');
      }
      return summary;
    },
    enabled: Boolean(user?.account_id),
    refetchOnMount: 'always',
    refetchInterval: 30000,
    staleTime: 15000,
  });

  const showDashboardSkeleton = isDashboardLoading || (isDashboardFetching && !dashboardResponse);
  const showWalletSkeleton = isWalletLoading || (isWalletFetching && !walletSummary);
  const showRevenueHeroSkeleton = showDashboardSkeleton;

  // Dados do resumo
  const summary = dashboardData || {
    total_revenue: 0,
    revenue_change: 0,
    total_sales: 0,
    sales_change: 0,
    average_ticket: 0,
    pending_amount: 0,
    approval_rate: 0,
    approval_rate_change: 0,
    conversion_rate: 0,
    conversion_rate_change: 0,
  };

  const goalMilestoneStorageKey = useMemo(
    () => (user?.id ? getGoalMilestoneStorageKey(user.id) : null),
    [user?.id]
  );

  useEffect(() => {
    setGoalMilestoneState(readGoalMilestoneState(goalMilestoneStorageKey));
  }, [goalMilestoneStorageKey]);

  const totalRevenueCents = summary.total_revenue || 0;
  const storedGoalIndex = Math.min(
    goalMilestoneState.currentIndex,
    GOAL_TARGET_SEQUENCE_CENTS.length - 1
  );
  const storedGoalReachedAtMs = goalMilestoneState.achievedAt
    ? Date.parse(goalMilestoneState.achievedAt)
    : NaN;
  const storedGoalHasNext = storedGoalIndex < GOAL_TARGET_SEQUENCE_CENTS.length - 1;
  const shouldAdvanceGoal = Boolean(goalMilestoneState.achievedAt)
    && storedGoalHasNext
    && !Number.isNaN(storedGoalReachedAtMs)
    && Date.now() - storedGoalReachedAtMs >= GOAL_ADVANCE_DELAY_MS;
  const currentGoalIndex = shouldAdvanceGoal ? storedGoalIndex + 1 : storedGoalIndex;
  const currentGoalAchievedAt = shouldAdvanceGoal ? null : goalMilestoneState.achievedAt;
  const activeGoalTargetCents = GOAL_TARGET_SEQUENCE_CENTS[currentGoalIndex]
    ?? GOAL_TARGET_SEQUENCE_CENTS[GOAL_TARGET_SEQUENCE_CENTS.length - 1];
  const firstGoalTargetCents = GOAL_TARGET_SEQUENCE_CENTS[0];
  const hasReachedFirstGoal = totalRevenueCents >= firstGoalTargetCents;
  const hasReachedGoal = totalRevenueCents >= activeGoalTargetCents;
  const shouldHideGoalProgress = hasReachedFirstGoal;
  const goalProgress = Math.min((totalRevenueCents / firstGoalTargetCents) * 100, 100);
  const remainingToGoalCents = Math.max(0, firstGoalTargetCents - totalRevenueCents);

  useEffect(() => {
    if (!goalMilestoneStorageKey || !shouldAdvanceGoal) {
      return;
    }

    const nextState: GoalMilestoneState = {
      currentIndex: currentGoalIndex,
      achievedAt: null,
    };

    setGoalMilestoneState(nextState);
    writeGoalMilestoneState(goalMilestoneStorageKey, nextState);
  }, [currentGoalIndex, goalMilestoneStorageKey, shouldAdvanceGoal]);

  useEffect(() => {
    if (!goalMilestoneStorageKey || currentGoalAchievedAt || !hasReachedGoal) {
      return;
    }

    const nextState: GoalMilestoneState = {
      currentIndex: currentGoalIndex,
      achievedAt: new Date().toISOString(),
    };

    setGoalMilestoneState(nextState);
    writeGoalMilestoneState(goalMilestoneStorageKey, nextState);
  }, [currentGoalAchievedAt, currentGoalIndex, goalMilestoneStorageKey, hasReachedGoal]);

  const supportWhatsAppUrl = useMemo(() => {
    const message = user?.name
      ? `Olá! Sou ${user.name} e bati minha meta de faturamento na ${platformName}. Gostaria de falar com o suporte.`
      : `Olá! Bati minha meta de faturamento na ${platformName} e gostaria de falar com o suporte.`;

    return `https://wa.me/${SUPPORT_WHATSAPP_NUMBER}?text=${encodeURIComponent(message)}`;
  }, [user?.name]);

  // Memoriza os dados do gráfico - preenche todas as horas/dias com 0 para mostrar variação
  const chartData = useMemo(() => {
    const startDateObj = new Date(startDate + 'T00:00:00');
    const endDateObj = new Date(endDate + 'T23:59:59');
    const diffDays = Math.ceil((endDateObj.getTime() - startDateObj.getTime()) / (1000 * 60 * 60 * 24));

    // Se período <= 3 dias, usa visão por hora (mostra todas as horas)
    if (diffDays <= 3) {
      const labels: string[] = [];
      const data: number[] = [];

      // Criar mapa de receita por hora a partir dos dados da API
      const revenueMap = new Map<string, number>();
      if (hourlyRevenue && hourlyRevenue.length > 0) {
        hourlyRevenue.forEach((item: { datetime: string; revenue: number }) => {
          // Normaliza a chave: "YYYY-MM-DD HH:00"
          const key = item.datetime.trim().substring(0, 13) + ':00';
          revenueMap.set(key, (revenueMap.get(key) || 0) + item.revenue);
        });
      }

      // Preenche TODAS as horas do período (as sem venda ficam com 0)
      const cursor = new Date(startDateObj);
      const finalHour = new Date(endDateObj);
      // Limita a hora final ao momento atual se for hoje
      const now = new Date();
      if (finalHour > now) {
        finalHour.setTime(now.getTime());
      }

      while (cursor <= finalHour) {
        const day = String(cursor.getDate()).padStart(2, '0');
        const month = String(cursor.getMonth() + 1).padStart(2, '0');
        const hour = String(cursor.getHours()).padStart(2, '0');
        const year = cursor.getFullYear();
        const monthIdx = String(cursor.getMonth() + 1).padStart(2, '0');
        const key = `${year}-${monthIdx}-${day} ${hour}:00`;
        
        labels.push(`${day}/${month} ${hour}:00`);
        data.push((revenueMap.get(key) || 0) / 100);

        cursor.setHours(cursor.getHours() + 1);
      }

      if (labels.length > 0) return { labels, data };
    }

    // Para períodos maiores, usa visão diária (mostra todos os dias)
    {
      const labels: string[] = [];
      const data: number[] = [];

      // Criar mapa de receita por dia
      const revenueMap = new Map<string, number>();
      if (dailyRevenue && dailyRevenue.length > 0) {
        dailyRevenue.forEach((item: { date: string; revenue: number }) => {
          revenueMap.set(item.date.trim(), item.revenue);
        });
      }

      // Preenche TODOS os dias do período (os sem venda ficam com 0)
      const cursor = new Date(startDateObj);
      const finalDay = new Date(endDateObj);

      while (cursor <= finalDay) {
        const day = String(cursor.getDate()).padStart(2, '0');
        const monthNum = String(cursor.getMonth() + 1).padStart(2, '0');
        const year = cursor.getFullYear();
        const key = `${year}-${monthNum}-${day}`;
        
        labels.push(`${day}/${monthNum}`);
        data.push((revenueMap.get(key) || 0) / 100);

        cursor.setDate(cursor.getDate() + 1);
      }

      if (labels.length > 0) return { labels, data };
    }

    // Fallback para datas vazias
    return getDailyData(startDate, endDate);
  }, [startDate, endDate, dailyRevenue, hourlyRevenue]);
  const themeVariant = useThemeStore((s) => s.themeVariant);

  // ═══ MODERN THEME LAYOUT ═══
  if (themeVariant === 'modern') {
    const approvalRate = summary.approval_rate || 0;
    const pendingRate = Math.max(0, 100 - approvalRate - (summary.chargeback_rate || 2.2));
    const rejectedRate = Math.max(0, 100 - approvalRate - pendingRate);
    const totalSales = summary.total_sales || 0;

    const donutSegments = [
      { label: 'Aprovadas', pct: approvalRate, color: '#ef4444' },
      { label: 'Pendentes', pct: pendingRate > 0 ? pendingRate : 4.1, color: '#b91c1c' },
      { label: 'Recusadas', pct: rejectedRate > 0 ? rejectedRate : 2.2, color: '#7f1d1d' },
    ];

    const donutRadius = 90;
    const donutStroke = 28;
    const donutCircumference = 2 * Math.PI * donutRadius;
    let donutOffset = 0;

    return (
      <div className="space-y-4 pb-6">
        {/* Banner */}
        <div className="rounded-xl overflow-hidden border border-white/[0.06] bg-[#0b0b0d]">
          <img
            src="/img/dashboard-banner-slim.webp"
            alt="Bem-vindo ao GorillaPay"
            className="block w-full h-auto"
            width={2159}
            height={314}
          />
        </div>

        {/* Top Stats */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          {showDashboardSkeleton ? (
            Array.from({ length: 4 }).map((_, i) => <DashboardMetricCardSkeleton key={i} />)
          ) : (
            <>
              <div className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-4 flex items-center gap-4">
                <div className="h-12 w-12 rounded-xl bg-red-600/15 flex items-center justify-center flex-shrink-0">
                  <CurrencyDollarIcon className="h-6 w-6 text-red-500" />
                </div>
                <div>
                  <p className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Volume Processado</p>
                  <p className="text-xl font-bold text-white mt-0.5">{formatCurrency(summary.total_revenue || 0)}</p>
                </div>
              </div>
              <div className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-4 flex items-center gap-4">
                <div className="h-12 w-12 rounded-xl bg-red-500/15 flex items-center justify-center flex-shrink-0">
                  <ShoppingCartIcon className="h-6 w-6 text-red-500" />
                </div>
                <div>
                  <p className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Transações</p>
                  <p className="text-xl font-bold text-white mt-0.5">{(totalSales).toLocaleString('pt-BR')}</p>
                </div>
              </div>
              <div className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-4 flex items-center gap-4">
                <div className="h-12 w-12 rounded-xl bg-red-500/15 flex items-center justify-center flex-shrink-0">
                  <svg className="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </div>
                <div>
                  <p className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Aprovação</p>
                  <p className="text-xl font-bold text-white mt-0.5">{approvalRate.toFixed(1)}%</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => navigate('/chargebacks')}
                className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-4 flex items-center gap-4 text-left transition-colors hover:border-red-500/40"
              >
                <div className="h-12 w-12 rounded-xl bg-red-500/15 flex items-center justify-center flex-shrink-0">
                  <ExclamationTriangleIcon className="h-6 w-6 text-red-500" />
                </div>
                <div>
                  <p className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">MED</p>
                  <p className="text-xl font-bold text-white mt-0.5">{dashboardResponse?.chargeback_alerts || 0}</p>
                </div>
              </button>
            </>
          )}
        </div>

        {/* Chart + Donut */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-3">
          {/* Line Chart */}
          <div className="lg:col-span-2 rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-5">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between mb-4">
              <h3 className="text-sm font-semibold text-white uppercase tracking-wider">Movimentação</h3>
              <div className="flex flex-wrap items-center justify-end gap-1">
                {periodOptions.map((option) => (
                  <button
                    key={option.key}
                    onClick={() => applyPeriod(option.key)}
                    className={`px-2.5 py-1 text-[10px] rounded-md border transition-all ${
                      selectedPeriod === option.key
                        ? 'bg-red-500/20 text-red-400 border-red-500/40'
                        : 'bg-transparent text-gray-500 border-gray-700 hover:border-gray-600'
                    }`}
                  >
                    {option.label}
                  </button>
                ))}
                {selectedPeriod === 'custom' && (
                  <DateRangePicker
                    defaultOpen
                    startDate={startDate}
                    endDate={endDate}
                    onStartDateChange={setStartDate}
                    onEndDateChange={setEndDate}
                  />
                )}
              </div>
            </div>
            {showDashboardSkeleton ? (
              <DashboardChartSkeleton />
            ) : (
              <div className="h-[260px]">
                <GlowLineChart
                  labels={chartData.labels}
                  data={chartData.data}
                  color="red"
                  height="h-full"
                  clean
                  tension={0.4}
                  formatYTick={(v) => (v >= 1000 ? `${(v / 1000).toFixed(0)}k` : String(v))}
                />
              </div>
            )}
          </div>

          {/* Donut Chart */}
          <div className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-5">
            <h3 className="text-sm font-semibold text-white uppercase tracking-wider mb-4">Status das Transações</h3>
            {showDashboardSkeleton ? (
              <div className="flex items-center justify-center h-[260px]">
                <div className="h-8 w-8 animate-spin rounded-full border-2 border-gray-500 border-t-transparent" />
              </div>
            ) : (
              <div className="flex flex-col items-center">
                <div className="relative w-[210px] h-[210px]">
                  <svg viewBox="0 0 220 220" className="w-full h-full -rotate-90">
                    <defs>
                      {donutSegments.map((seg, i) => (
                        <linearGradient key={i} id={`donutGrad${i}`} x1="0%" y1="0%" x2="100%" y2="100%">
                          <stop offset="0%" stopColor={seg.color} stopOpacity="0.65" />
                          <stop offset="100%" stopColor={seg.color} stopOpacity="1" />
                        </linearGradient>
                      ))}
                    </defs>
                    {/* Track */}
                    <circle cx="110" cy="110" r={donutRadius} fill="none" stroke="rgba(255,255,255,0.05)" strokeWidth={donutStroke} />
                    {donutSegments.map((seg, i) => {
                      const full = (seg.pct / 100) * donutCircumference;
                      const gapPx = seg.pct > 3 ? 7 : 0; // gap between visible segments
                      const dash = Math.max(0, full - gapPx);
                      const gap = donutCircumference - dash;
                      const offset = donutOffset;
                      donutOffset += full;
                      return (
                        <circle
                          key={i}
                          cx="110" cy="110" r={donutRadius}
                          fill="none"
                          stroke={`url(#donutGrad${i})`}
                          strokeWidth={donutStroke}
                          strokeDasharray={`${dash} ${gap}`}
                          strokeDashoffset={-offset}
                          strokeLinecap="round"
                        />
                      );
                    })}
                  </svg>
                  <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-[11px] uppercase tracking-wider text-gray-500">Total</span>
                    <span className="text-3xl font-bold text-white">{totalSales.toLocaleString('pt-BR')}</span>
                    <span className="text-[10px] text-gray-500 mt-0.5">transações</span>
                  </div>
                </div>
                <div className="mt-4 w-full space-y-2">
                  {donutSegments.map((seg) => (
                    <div key={seg.label} className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: seg.color }} />
                        <span className="text-xs text-gray-400">{seg.label}</span>
                      </div>
                      <span className="text-xs font-semibold text-white">{seg.pct.toFixed(1)}%</span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Wallet */}
        {showWalletSkeleton ? (
          <div className="grid grid-cols-3 gap-3">
            {[
              { label: 'Disponível', Icon: WalletIcon },
              { label: 'Pendente', Icon: ChartBarIcon },
              { label: 'Sacado', Icon: CurrencyDollarIcon },
            ].map(({ label, Icon }) => (
              <div key={label} className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-4">
                <div className="flex items-center gap-2 mb-2">
                  <Icon className="h-4 w-4 text-red-500/60" />
                  <span className="text-[11px] text-gray-500 uppercase tracking-wider">{label}</span>
                </div>
                <div className="h-6 w-24 rounded-md bg-white/[0.06] animate-pulse" />
              </div>
            ))}
          </div>
        ) : (
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-4">
              <div className="flex items-center gap-2 mb-1">
                <WalletIcon className="h-4 w-4 text-red-500" />
                <span className="text-[11px] text-gray-400 uppercase tracking-wider">Disponível</span>
              </div>
              <p className="text-lg font-bold text-red-400">{showBalance ? formatCurrency(walletSummary?.available_balance || 0) : '••••••'}</p>
            </div>
            <div className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-4">
              <div className="flex items-center gap-2 mb-1">
                <ChartBarIcon className="h-4 w-4 text-red-500" />
                <span className="text-[11px] text-gray-400 uppercase tracking-wider">Pendente</span>
              </div>
              <p className="text-lg font-bold text-red-400">{showBalance ? formatCurrency(walletSummary?.pending_balance || 0) : '••••••'}</p>
            </div>
            <div className="rounded-xl bg-[#0b0b0d] border border-white/[0.06] p-4">
              <div className="flex items-center gap-2 mb-1">
                <CurrencyDollarIcon className="h-4 w-4 text-red-500" />
                <span className="text-[11px] text-gray-400 uppercase tracking-wider">Sacado</span>
              </div>
              <p className="text-lg font-bold text-red-400">{showBalance ? formatCurrency(walletSummary?.total_withdrawn || 0) : '••••••'}</p>
            </div>
          </div>
        )}

        {/* Footer */}
        <footer className="border-t border-gray-800 mt-4 pt-4">
          <div className="flex flex-col gap-3 text-xs text-gray-500 md:flex-row md:items-center md:justify-between">
            <p>{platformName} Intermediadora de Pagamentos</p>
            <nav aria-label="Links rápidos" className="flex flex-wrap items-center gap-x-4 gap-y-2">
              {[
                { to: '/integrations', label: 'Integrações' },
                { to: '/settings?tab=api', label: 'Chave API' },
                { to: '/settings?tab=webhooks', label: 'Webhooks' },
                { to: '/docs', label: 'Documentação' },
                { to: '/support', label: 'Suporte' },
              ].map((link) => (
                <Link key={link.to} to={link.to} className="transition-colors hover:text-red-400">
                  {link.label}
                </Link>
              ))}
              <span className="text-gray-600">{platformName} © 2026</span>
            </nav>
          </div>
        </footer>
      </div>
    );
  }

  // ═══ CLASSIC THEME LAYOUT ═══
  return (
    <div className="space-y-2 sm:space-y-3 pb-4">
      {/* Impersonation Banner */}
      {isImpersonating && (
        <div className="flex items-center gap-2 sm:gap-3 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-700 rounded-lg px-2.5 py-1.5 sm:px-4 sm:py-3">
          <ShieldExclamationIcon className="h-4 w-4 sm:h-6 sm:w-6 text-emerald-500 flex-shrink-0" />
          <div className="flex-1 min-w-0">
            <p className="text-xs sm:text-sm font-medium text-emerald-800 dark:text-emerald-200">
              Você está logado como <strong>{user?.name}</strong> (modo impersonação)
            </p>
            <p className="text-[10px] sm:text-xs text-emerald-600 dark:text-emerald-400 mt-0.5 hidden sm:block">
              Todas as ações serão registradas no audit log.
            </p>
          </div>
          <button
            onClick={() => stopImpersonation()}
            className="flex-shrink-0 text-[10px] sm:text-xs font-semibold bg-emerald-500 hover:bg-emerald-600 text-white px-2 py-1 sm:px-3 sm:py-1.5 rounded-md transition-colors"
          >
            Voltar ao Admin
          </button>
        </div>
      )}

      {/* Greeting Section */}
      <div className="mb-0.5 sm:mb-1">
        <h1 className="text-lg sm:text-2xl font-bold text-tx-primary">
          {getGreeting()}, {user?.name?.split(' ')[0] || 'Usuário'}
        </h1>
        <p className="text-xs sm:text-sm text-tx-muted line-clamp-1">{getDailyMessage()}</p>
      </div>

      {/* Top Stats Row - 3 cards */}
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-1.5 sm:gap-3">
        {showDashboardSkeleton ? (
          <>
            <DashboardMetricCardSkeleton />
            <DashboardMetricCardSkeleton />
            <div className="col-span-2 sm:col-span-1">
              <DashboardMetricCardSkeleton />
            </div>
          </>
        ) : (
          <>
            {/* Vendas hoje */}
            <div className="relative bg-surface-card border border-border-DEFAULT rounded-lg p-2 sm:p-3 overflow-hidden">
              <div className="flex items-center gap-1.5 sm:gap-2 mb-0.5 sm:mb-1">
                <ShoppingCartIcon className="h-4 w-4 sm:h-6 sm:w-6 text-emerald-500" />
                <span className="text-[10px] sm:text-xs text-tx-muted">Faturamento Total</span>
              </div>
              <p className="text-lg sm:text-2xl font-bold text-tx-primary">{formatCurrency(summary.total_revenue || 0)}</p>
            </div>

            {/* Ticket médio */}
            <div className="relative bg-surface-card border border-border-DEFAULT rounded-lg p-2 sm:p-3 overflow-hidden">
              <div className="flex items-center gap-1.5 sm:gap-2 mb-0.5 sm:mb-1">
                <TicketIcon className="h-4 w-4 sm:h-6 sm:w-6 text-emerald-500" />
                <span className="text-[10px] sm:text-xs text-tx-muted">Ticket médio</span>
              </div>
              <p className="text-lg sm:text-2xl font-bold text-tx-primary">{formatCurrency(summary.average_ticket || 0)}</p>
            </div>

            {/* Alertas de chargeback */}
            <div 
              className="relative bg-surface-card border border-border-DEFAULT rounded-lg p-2 sm:p-3 overflow-hidden col-span-2 sm:col-span-1 cursor-pointer hover:border-emerald-500 transition-colors group"
              onClick={() => navigate('/chargebacks')}
            >
              <div className="flex items-center justify-between mb-0.5 sm:mb-1">
                <div className="flex items-center gap-1.5 sm:gap-2">
                  <ExclamationTriangleIcon className="h-4 w-4 sm:h-6 sm:w-6 text-emerald-500" />
                  <span className="text-[10px] sm:text-xs text-tx-muted">Alertas de chargeback</span>
                </div>
                <ArrowTopRightOnSquareIcon className="h-3 w-3 sm:h-4 sm:w-4 text-tx-muted opacity-0 group-hover:opacity-100 transition-opacity" />
              </div>
              <p className="text-lg sm:text-2xl font-bold text-tx-primary">{dashboardResponse?.chargeback_alerts || 0}</p>
            </div>
          </>
        )}
      </div>

      {/* Sales by Payment Method Row - 3 cards */}
      <div className="grid grid-cols-2 sm:grid-cols-3 gap-1.5 sm:gap-3">
        {showDashboardSkeleton ? (
          <>
            <DashboardMetricCardSkeleton />
            <DashboardMetricCardSkeleton />
            <div className="col-span-2 sm:col-span-1">
              <DashboardMetricCardSkeleton />
            </div>
          </>
        ) : (
          <>
            {/* Vendas pix */}
            <div className="relative bg-surface-card border border-border-DEFAULT rounded-lg p-2 sm:p-3 overflow-hidden">
              <div className="flex items-center gap-1.5 sm:gap-2 mb-0.5 sm:mb-1">
                <div className="flex items-center justify-center h-6 w-6 sm:h-8 sm:w-8 rounded-lg bg-emerald-500/10">
                  <PixIcon className="h-3 w-3 sm:h-4 sm:w-4 text-emerald-500" />
                </div>
                <span className="text-[10px] sm:text-xs text-tx-muted">PIX nas últimas 24h</span>
              </div>
              <p className="text-base sm:text-xl font-bold text-emerald-500">{formatCurrency(pixRevenue24h)}</p>
            </div>

            {/* Vendas cartão */}
            <div className="relative bg-surface-card border border-border-DEFAULT rounded-lg p-2 sm:p-3 overflow-hidden">
              <div className="flex items-center gap-1.5 sm:gap-2 mb-0.5 sm:mb-1">
                <div className="flex items-center justify-center h-6 w-6 sm:h-8 sm:w-8 rounded-lg bg-emerald-500/10">
                  <CreditCardIcon className="h-3 w-3 sm:h-4 sm:w-4 text-emerald-500" />
                </div>
                <span className="text-[10px] sm:text-xs text-tx-muted">Cartão nas últimas 24h</span>
              </div>
              <p className="text-base sm:text-xl font-bold text-emerald-500">{formatCurrency(cardRevenue24h)}</p>
            </div>

            {/* Vendas boleto */}
            <div className="relative bg-surface-card border border-border-DEFAULT rounded-lg p-2 sm:p-3 overflow-hidden col-span-2 sm:col-span-1">
              <div className="flex items-center gap-1.5 sm:gap-2 mb-0.5 sm:mb-1">
                <div className="flex items-center justify-center h-6 w-6 sm:h-8 sm:w-8 rounded-lg bg-emerald-500/10">
                  <DocumentTextIcon className="h-3 w-3 sm:h-4 sm:w-4 text-emerald-500" />
                </div>
                <span className="text-[10px] sm:text-xs text-tx-muted">Boleto nas últimas 24h</span>
              </div>
              <p className="text-base sm:text-xl font-bold text-emerald-500">{formatCurrency(boletoRevenue24h)}</p>
            </div>
          </>
        )}
      </div>

      {/* Main Content: Chart + Wallet Sidebar */}
      <div className="flex flex-col lg:grid lg:grid-cols-3 gap-2 sm:gap-3">
        {/* Histórico de faturamento - Chart */}
        <div className="lg:col-span-2 bg-surface-card border border-border-DEFAULT rounded-lg p-2 sm:p-4 relative z-10">
          {showDashboardSkeleton ? (
            <DashboardChartSkeleton />
          ) : (
            <>
              <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2 sm:mb-3">
                <div className="flex flex-wrap items-center gap-1">
                  {periodOptions.map((option) => (
                    <button
                      key={option.key}
                      onClick={() => applyPeriod(option.key)}
                      className={`px-2.5 py-1 text-[10px] sm:text-xs rounded-full border transition-all ${selectedPeriod === option.key
                        ? 'bg-accent-primary text-white border-accent-primary'
                        : 'bg-surface-card text-tx-primary border-border-DEFAULT hover:bg-surface-hover'
                      }`}
                      aria-label={`Período ${option.label}`}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>

                <DateRangePicker
                  startDate={startDate}
                  endDate={endDate}
                  onStartDateChange={(date) => {
                    setStartDate(date);
                    setSelectedPeriod('custom');
                  }}
                  onEndDateChange={(date) => {
                    setEndDate(date);
                    setSelectedPeriod('custom');
                  }}
                />
              </div>
              
              <div className="h-[140px] sm:h-[220px] overflow-hidden">
                <GlowLineChart
                  labels={chartData.labels}
                  data={chartData.data}
                  color="violet"
                  height="h-full"
                />
              </div>
            </>
          )}
        </div>

        {/* Minha carteira - Right Sidebar */}
        {showWalletSkeleton ? (
          <DashboardWalletSkeleton />
        ) : (
          <div className="bg-surface-card border border-border-DEFAULT rounded-xl p-2 sm:p-4 relative z-0">
            <div className="flex items-center justify-between mb-2 lg:mb-4">
              <h3 className="text-xs sm:text-base font-semibold text-tx-primary">Minha carteira</h3>
            </div>
            
            <div className="grid grid-cols-3 lg:grid-cols-1 gap-2">
              {/* Saldo disponível */}
              <div className="bg-surface-card border border-emerald-200/50 dark:border-emerald-500/20 rounded-lg p-2 lg:p-3 group hover:border-emerald-300 dark:hover:border-emerald-500/40 transition-colors">
                <div className="flex items-center justify-between gap-1 lg:gap-2 mb-0.5 lg:mb-1">
                  <div className="flex items-center gap-1 lg:gap-2">
                    <WalletIcon className="h-3.5 w-3.5 lg:h-5 lg:w-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0" />
                    <span className="text-[10px] lg:text-xs text-tx-muted truncate">Disponível</span>
                  </div>
                  <button
                    onClick={() => setShowBalance(!showBalance)}
                    className="p-0.5 lg:p-1 hover:bg-emerald-100 dark:hover:bg-emerald-900/30 rounded transition-colors"
                    title={showBalance ? 'Ocultar saldo' : 'Mostrar saldo'}
                  >
                    {showBalance ? (
                      <EyeIcon className="h-3 w-3 lg:h-4 lg:w-4 text-emerald-600 dark:text-emerald-400" />
                    ) : (
                      <EyeSlashIcon className="h-3 w-3 lg:h-4 lg:w-4 text-emerald-600 dark:text-emerald-400" />
                    )}
                  </button>
                </div>
                <p className="text-xs lg:text-lg font-bold text-emerald-600 dark:text-emerald-400 truncate">
                  {showBalance ? formatCurrency(walletSummary?.available_balance || 0) : '••••••'}
                </p>
              </div>

              {/* Total pendente */}
              <div className="bg-surface-card border border-amber-200/50 dark:border-amber-500/20 rounded-lg p-2 lg:p-3 group hover:border-amber-300 dark:hover:border-amber-500/40 transition-colors">
                <div className="flex items-center gap-1 lg:gap-2 mb-0.5 lg:mb-1">
                  <ChartBarIcon className="h-3.5 w-3.5 lg:h-5 lg:w-5 text-amber-600 dark:text-amber-400 flex-shrink-0" />
                  <span className="text-[10px] lg:text-xs text-tx-muted truncate">Pendente</span>
                </div>
                <p className="text-xs lg:text-lg font-bold text-amber-600 dark:text-amber-400 truncate">
                  {showBalance ? formatCurrency(walletSummary?.pending_balance || 0) : '••••••'}
                </p>
              </div>

              {/* Total sacado */}
              <div className="bg-surface-card border border-emerald-200/50 dark:border-emerald-500/20 rounded-lg p-2 lg:p-3 group hover:border-emerald-300 dark:hover:border-emerald-500/40 transition-colors">
                <div className="flex items-center gap-1 lg:gap-2 mb-0.5 lg:mb-1">
                  <CurrencyDollarIcon className="h-3.5 w-3.5 lg:h-5 lg:w-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0" />
                  <span className="text-[10px] lg:text-xs text-tx-muted truncate">Sacado</span>
                </div>
                <p className="text-xs lg:text-lg font-bold text-emerald-600 dark:text-emerald-400 truncate">
                  {showBalance ? formatCurrency(walletSummary?.total_withdrawn || 0) : '••••••'}
                </p>
              </div>
            </div>

            {/* Solicitar saque button */}
            <button
              onClick={() => navigate('/saques')}
              disabled={!walletSummary?.available_balance || walletSummary.available_balance <= 0}
              className="w-full mt-2 lg:mt-4 px-3 py-2 lg:py-2.5 bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-400 text-white dark:text-emerald-950 font-semibold rounded-lg transition-all duration-300 flex items-center justify-center gap-2 text-xs lg:text-sm shadow-sm shadow-emerald-900/10 hover:shadow-md hover:shadow-emerald-900/15 disabled:bg-emerald-600/45 dark:disabled:bg-emerald-500/35 disabled:text-white/70 dark:disabled:text-white/60 disabled:cursor-not-allowed disabled:shadow-none"
            >
              Solicitar saque
            </button>
          </div>
        )}
      </div>

      {/* Mini Gráficos Circulares em Linha */}
      <div className="bg-surface-card border border-border-DEFAULT rounded-xl p-2 sm:p-4">
        <div className="grid grid-cols-6 gap-1 sm:gap-4">
          {showDashboardSkeleton ? (
            Array.from({ length: 6 }).map((_, index) => (
              <DashboardCircleMetricSkeleton key={index} />
            ))
          ) : (
            <>
          {/* Vendas com Checkout */}
          <div className="flex flex-col sm:flex-row items-center gap-1 sm:gap-3">
            <div className="relative w-8 h-8 sm:w-12 sm:h-12 flex-shrink-0">
              <svg className="w-full h-full transform -rotate-90">
                <circle cx="50%" cy="50%" r="45%" fill="none" stroke="currentColor" strokeWidth="3" className="text-emerald-200 dark:text-gray-700" />
                <circle 
                  cx="50%" cy="50%" r="45%" fill="none" stroke="#10b981" strokeWidth="3" 
                  strokeDasharray={`${Math.min((checkoutRevenue / 100) / 10000 * 100, 100) * 2.83} 283`}
                  strokeLinecap="round"
                />
              </svg>
            </div>
            <div className="min-w-0 text-center sm:text-left">
              <p className="text-[8px] sm:text-xs font-semibold text-emerald-400 uppercase tracking-wide truncate">Checkout</p>
              <p className="text-[10px] sm:text-lg font-bold text-tx-primary">{formatCurrency(checkoutRevenue)}</p>
              <p className="text-[8px] text-tx-muted hidden sm:block">{checkoutCount || 0} vendas</p>
            </div>
          </div>

          {/* Assinaturas */}
          <div className="flex flex-col sm:flex-row items-center gap-1 sm:gap-3">
            <div className="relative w-8 h-8 sm:w-12 sm:h-12 flex-shrink-0">
              <svg className="w-full h-full transform -rotate-90">
                <circle cx="50%" cy="50%" r="45%" fill="none" stroke="currentColor" strokeWidth="3" className="text-emerald-200 dark:text-gray-700" />
                <circle 
                  cx="50%" cy="50%" r="45%" fill="none" stroke="#f59e0b" strokeWidth="3" 
                  strokeDasharray="0 283"
                  strokeLinecap="round"
                />
              </svg>
            </div>
            <div className="min-w-0 text-center sm:text-left">
              <p className="text-[8px] sm:text-xs font-semibold text-amber-400 uppercase tracking-wide truncate">Assinaturas</p>
              <p className="text-[10px] sm:text-lg font-bold text-tx-primary">R$ 0</p>
              <p className="text-[8px] text-tx-muted hidden sm:block">0 assinaturas</p>
            </div>
          </div>

          {/* Order Bump */}
          <div className="flex flex-col sm:flex-row items-center gap-1 sm:gap-3">
            <div className="relative w-8 h-8 sm:w-12 sm:h-12 flex-shrink-0">
              <svg className="w-full h-full transform -rotate-90">
                <circle cx="50%" cy="50%" r="45%" fill="none" stroke="currentColor" strokeWidth="3" className="text-emerald-200 dark:text-gray-700" />
                <circle
                  cx="50%" cy="50%" r="45%" fill="none" stroke="#ec4899" strokeWidth="3"
                  strokeDasharray={`${Math.min((orderBumpRevenue / 100) / 10000 * 100, 100) * 2.83} 283`}
                  strokeLinecap="round"
                />
              </svg>
            </div>
            <div className="min-w-0 text-center sm:text-left">
              <p className="text-[8px] sm:text-xs font-semibold text-pink-400 uppercase tracking-wide truncate">Bump</p>
              <p className="text-[10px] sm:text-lg font-bold text-tx-primary">{formatCurrency(orderBumpRevenue)}</p>
              <p className="text-[8px] text-tx-muted hidden sm:block">{orderBumpCount || 0} vendas</p>
            </div>
          </div>

          {/* Upsell */}
          <div className="flex flex-col sm:flex-row items-center gap-1 sm:gap-3">
            <div className="relative w-8 h-8 sm:w-12 sm:h-12 flex-shrink-0">
              <svg className="w-full h-full transform -rotate-90">
                <circle cx="50%" cy="50%" r="45%" fill="none" stroke="currentColor" strokeWidth="3" className="text-emerald-200 dark:text-gray-700" />
                <circle
                  cx="50%" cy="50%" r="45%" fill="none" stroke="#06b6d4" strokeWidth="3"
                  strokeDasharray={`${Math.min((upsellRevenue / 100) / 10000 * 100, 100) * 2.83} 283`}
                  strokeLinecap="round"
                />
              </svg>
            </div>
            <div className="min-w-0 text-center sm:text-left">
              <p className="text-[8px] sm:text-xs font-semibold text-cyan-400 uppercase tracking-wide truncate">Upsell</p>
              <p className="text-[10px] sm:text-lg font-bold text-tx-primary">{formatCurrency(upsellRevenue)}</p>
              <p className="text-[8px] text-tx-muted hidden sm:block">{upsellCount || 0} vendas</p>
            </div>
          </div>

          {/* Área de Membros */}
          <div className="flex flex-col sm:flex-row items-center gap-1 sm:gap-3">
            <div className="relative w-8 h-8 sm:w-12 sm:h-12 flex-shrink-0">
              <svg className="w-full h-full transform -rotate-90">
                <circle cx="50%" cy="50%" r="45%" fill="none" stroke="currentColor" strokeWidth="3" className="text-emerald-200 dark:text-gray-700" />
                <circle 
                  cx="50%" cy="50%" r="45%" fill="none" stroke="#10b981" strokeWidth="3" 
                  strokeDasharray={`${Math.min((memberAreaRevenue / 100) / 10000 * 100, 100) * 2.83} 283`}
                  strokeLinecap="round"
                />
              </svg>
            </div>
            <div className="min-w-0 text-center sm:text-left">
              <p className="text-[8px] sm:text-xs font-semibold text-emerald-400 uppercase tracking-wide truncate">Membros</p>
              <p className="text-[10px] sm:text-lg font-bold text-tx-primary">{formatCurrency(memberAreaRevenue)}</p>
              <p className="text-[8px] text-tx-muted hidden sm:block">{memberAreaCount || 0} vendas</p>
            </div>
          </div>

          {/* Taxa de Conversão */}
          <div className="flex flex-col sm:flex-row items-center gap-1 sm:gap-3">
            <div className="relative w-8 h-8 sm:w-12 sm:h-12 flex-shrink-0">
              <svg className="w-full h-full transform -rotate-90">
                <circle cx="50%" cy="50%" r="45%" fill="none" stroke="currentColor" strokeWidth="3" className="text-emerald-200 dark:text-gray-700" />
                <circle 
                  cx="50%" cy="50%" r="45%" fill="none" stroke="#10b981" strokeWidth="3" 
                  strokeDasharray={`${Math.min(summary.conversion_rate || 0, 100) * 2.83} 283`}
                  strokeLinecap="round"
                />
              </svg>
            </div>
            <div className="min-w-0 text-center sm:text-left">
              <p className="text-[8px] sm:text-xs font-semibold text-emerald-400 uppercase tracking-wide truncate">Conversão</p>
              <p className="text-[10px] sm:text-lg font-bold text-tx-primary">{(summary.conversion_rate || 0).toFixed(1)}%</p>
              <p className="text-[8px] text-tx-muted hidden sm:block">aprovadas</p>
            </div>
          </div>
            </>
          )}
        </div>
      </div>

      {/* Footer */}
      <footer className="border-t border-border-DEFAULT/60 mt-8 pt-6">
        <div className="flex flex-col sm:flex-row items-center justify-between gap-4 text-center sm:text-left">
          <div className="text-xs text-tx-muted">
            <p>{platformName} Intermediadora de Pagamentos</p>
            <a 
              href="https://wa.me/5511940968290" 
              target="_blank" 
              rel="noopener noreferrer"
              className="hover:text-tx-primary transition-colors"
            >
              Gerente de Contas: (11) 94096-8290
            </a>
          </div>
          <div className="flex items-center gap-4 text-xs text-tx-muted">
            <Link to="/docs" className="hover:text-tx-primary transition-colors">
              Documentação
            </Link>
            <a href="/privacy" className="hover:text-tx-primary transition-colors">
              Política de Privacidade
            </a>
            <span>{platformName} © 2026</span>
          </div>
        </div>
      </footer>

    </div>
  );
}

function StatCard({
  title,
  value,
  change,
  hideChange,
  icon,
  gradient,
  iconBg,
  iconColor,
  glowColor,
  sparkData,
}: {
  title: string;
  value: string;
  change: number;
  hideChange?: boolean;
  icon: React.ReactNode;
  gradient: string;
  iconBg: string;
  iconColor: string;
  glowColor: string;
  sparkData?: number[];
}) {
  return (
    <div className="relative glass-card stat-glow rounded-2xl p-5 group overflow-hidden h-full min-h-[130px] flex flex-col">
      {/* Gradient overlay */}
      <div className={`absolute inset-0 bg-gradient-to-br ${gradient} rounded-2xl pointer-events-none`} />
      {/* Hover glow */}
      <div
        className="absolute -bottom-8 -right-8 w-32 h-32 rounded-full blur-3xl opacity-0 group-hover:opacity-100 transition-opacity duration-500 pointer-events-none"
        style={{ background: glowColor }}
      />

      <div className="relative z-10 flex-1 flex flex-col">
        <div className="flex items-center justify-between mb-4">
          <p className="text-sm font-medium text-tx-muted">{title}</p>
          <div className={`h-9 w-9 rounded-xl ${iconBg} flex items-center justify-center ${iconColor} border border-black/5 dark:border-white/5 transition-transform duration-300 group-hover:scale-110`}>
            {icon}
          </div>
        </div>
        <div className="flex items-end justify-between gap-3 mt-auto">
          <div>
            <p className="text-2xl font-bold font-mono text-tx-primary tracking-tight">{value}</p>
            {!hideChange && (
              <div className="mt-2 flex items-center gap-2">
                <span className={`inline-flex items-center gap-0.5 text-xs font-semibold ${
                  change >= 0 ? 'text-emerald-500 dark:text-emerald-400' : 'text-red-500 dark:text-red-400'
                }`}>
                  {change >= 0 ? <ArrowUpIcon className="h-3 w-3" /> : <ArrowDownIcon className="h-3 w-3" />}
                  {change >= 0 ? '+' : ''}{change}%
                </span>
                <span className="text-[11px] text-tx-muted">vs mês anterior</span>
              </div>
            )}
          </div>
          {sparkData && sparkData.length >= 2 && (
            <MiniSparkline data={sparkData} color={glowColor} />
          )}
        </div>
      </div>
    </div>
  );
}

// ═══ Mini Sparkline SVG ═══
function MiniSparkline({ data, color }: { data: number[]; color: string }) {
  const width = 64;
  const height = 28;
  const max = Math.max(...data, 1);
  const min = Math.min(...data, 0);
  const range = max - min || 1;
  const points = data.map((v, i) => {
    const x = (i / (data.length - 1)) * width;
    const y = height - ((v - min) / range) * (height - 4) - 2;
    return `${x},${y}`;
  });
  const pathD = `M${points.join(' L')}`;
  const areaD = `${pathD} L${width},${height} L0,${height} Z`;
  const gradId = `spark-${color.replace(/[^a-z0-9]/gi, '')}`;

  return (
    <svg width={width} height={height} className="flex-shrink-0 opacity-60 group-hover:opacity-100 transition-opacity duration-300">
      <defs>
        <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.4" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <path d={areaD} fill={`url(#${gradId})`} />
      <path d={pathD} fill="none" stroke={color} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={points[points.length - 1]?.split(',')[0]} cy={points[points.length - 1]?.split(',')[1]} r="2.5" fill={color}>
        <animate attributeName="opacity" values="1;0.4;1" dur="2s" repeatCount="indefinite" />
      </circle>
    </svg>
  );
}

function ActivityItem({
  icon,
  title,
  description,
  time,
}: {
  icon: 'credit-card' | 'bolt' | 'trophy' | 'user-plus';
  title: string;
  description: string;
  time: string;
}) {
  const getIcon = () => {
    switch (icon) {
      case 'credit-card':
        return <CreditCardIcon className="h-4 w-4 text-accent-primary" />;
      case 'bolt':
        return <BoltIcon className="h-4 w-4 text-yellow-500" />;
      case 'trophy':
        return <TrophyIcon className="h-4 w-4 text-status-warning" />;
      case 'user-plus':
        return <UserPlusIcon className="h-4 w-4 text-status-success" />;
    }
  };

  return (
    <div className="flex items-start gap-3">
      <div className="h-8 w-8 rounded-lg bg-accent-subtle/20 flex items-center justify-center">
        {getIcon()}
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium text-tx-primary">{title}</p>
        <p className="text-xs text-tx-muted truncate">{description}</p>
      </div>
      <span className="text-xs text-tx-muted">{time}</span>
    </div>
  );
}
