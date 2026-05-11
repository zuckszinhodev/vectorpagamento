import { useState, useRef, useEffect, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
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
  BoltIcon,
  ArrowTopRightOnSquareIcon,
  ExclamationTriangleIcon,
  ShieldExclamationIcon,
} from '@heroicons/react/24/outline';
import PixIcon from '@/components/icons/PixIcon';
import { GlowLineChart, ChartCard } from '@/components/ui/GlowChart';
import { Skeleton } from '@/components/ui/Skeleton';
import { useAuthStore } from '@/stores/authStore';

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

type DashboardPeriod = '7d' | '30d' | '90d' | '1y' | 'custom';

const periodOptions: { key: DashboardPeriod; label: string }[] = [
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
  onEndDateChange 
}: {
  startDate: string;
  endDate: string;
  onStartDateChange: (date: string) => void;
  onEndDateChange: (date: string) => void;
}) {
  const [isOpen, setIsOpen] = useState(false);
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
    const dateStr = date.toISOString().split('T')[0];
    const start = new Date(startDate + 'T00:00:00');
    const end = new Date(endDate + 'T00:00:00');
    return date >= start && date <= end;
  };

  const isStartOrEndDate = (day: number) => {
    const year = currentMonth.getFullYear();
    const month = currentMonth.getMonth();
    const date = new Date(year, month, day);
    const dateStr = date.toISOString().split('T')[0];
    return dateStr === startDate || dateStr === endDate;
  };

  const handleDayClick = (day: number) => {
    const year = currentMonth.getFullYear();
    const month = currentMonth.getMonth();
    const date = new Date(year, month, day);
    const dateStr = date.toISOString().split('T')[0];
    const today = new Date().toISOString().split('T')[0];
    
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
              const dateStr = date.toISOString().split('T')[0];
              const isToday = dateStr === today.toISOString().split('T')[0];
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
  const navigate = useNavigate();
  const { user, isImpersonating, stopImpersonation } = useAuthStore();
  const [goalMilestoneState, setGoalMilestoneState] = useState<GoalMilestoneState>(() =>
    readGoalMilestoneState(user?.id ? getGoalMilestoneStorageKey(user.id) : null)
  );
  
  const getDatesByPeriod = (period: DashboardPeriod) => {
    const today = new Date();
    const end = today;
    const start = new Date(today);

    switch (period) {
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
      start: start.toISOString().split('T')[0],
      end: end.toISOString().split('T')[0],
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

  const showDashboardSkeleton = isDashboardLoading || (isDashboardFetching && !dashboardResponse);
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
      ? `Olá! Sou ${user.name} e bati minha meta de faturamento na Vector Pagamentos. Gostaria de falar com o suporte.`
      : 'Olá! Bati minha meta de faturamento na Vector Pagamentos e gostaria de falar com o suporte.';

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
  return (
    <div className="space-y-2 sm:space-y-3 pb-4">
      {/* Impersonation Banner */}
      {isImpersonating && (
        <div className="flex items-center gap-2 sm:gap-3 bg-green-50 dark:bg-green-950/40 border border-green-300 dark:border-green-700 rounded-lg px-2.5 py-1.5 sm:px-4 sm:py-3">
          <ShieldExclamationIcon className="h-4 w-4 sm:h-6 sm:w-6 text-green-500 flex-shrink-0" />
          <div className="flex-1 min-w-0">
            <p className="text-xs sm:text-sm font-medium text-green-800 dark:text-green-200">
              Você está logado como <strong>{user?.name}</strong> (modo impersonação)
            </p>
            <p className="text-[10px] sm:text-xs text-green-600 dark:text-green-400 mt-0.5 hidden sm:block">
              Todas as ações serão registradas no audit log.
            </p>
          </div>
          <button
            onClick={() => stopImpersonation()}
            className="flex-shrink-0 text-[10px] sm:text-xs font-semibold bg-green-500 hover:bg-green-600 text-white px-2 py-1 sm:px-3 sm:py-1.5 rounded-md transition-colors"
          >
            Voltar ao Admin
          </button>
        </div>
      )}

      {/* 2FA Alert - Only show if MFA is disabled AND KYC is approved AND not impersonating */}
      {user && !user.mfa_enabled && user.kyc_status === 'approved' && !isImpersonating && (
        <div className="flex items-center gap-2 sm:gap-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-700 rounded-lg px-2.5 py-1.5 sm:px-4 sm:py-3">
          <ShieldExclamationIcon className="h-4 w-4 sm:h-6 sm:w-6 text-amber-500 flex-shrink-0" />
          <div className="flex-1 min-w-0">
            <p className="text-xs sm:text-sm font-medium text-amber-800 dark:text-amber-200">
              Sua conta ainda não tem autenticação de dois fatores ativada.
            </p>
            <p className="text-[10px] sm:text-xs text-amber-600 dark:text-amber-400 mt-0.5 hidden sm:block">
              Ative o 2FA para proteger sua conta contra acessos não autorizados.
            </p>
          </div>
          <button
            onClick={() => navigate('/settings?tab=security')}
            className="flex-shrink-0 text-[10px] sm:text-xs font-semibold bg-amber-500 hover:bg-amber-600 text-white px-2 py-1 sm:px-3 sm:py-1.5 rounded-md transition-colors"
          >
            Ativar agora
          </button>
        </div>
      )}

      {/* Top Stats Row - 3 cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
        {showDashboardSkeleton ? (
          <>
            <DashboardMetricCardSkeleton />
            <DashboardMetricCardSkeleton />
            <DashboardMetricCardSkeleton />
          </>
        ) : (
          <>
            {/* Vendas hoje */}
            <div className="group relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#0e121a] via-[#0a0f16] to-[#070b12] p-3 sm:p-4 transition-all duration-300 hover:-translate-y-0.5 hover:border-green-400/40 hover:shadow-[0_10px_30px_rgba(34,197,94,0.12)]">
              <div className="pointer-events-none absolute -right-12 -top-12 h-24 w-24 rounded-full bg-green-500/10 blur-2xl" />
              <div className="relative flex items-center gap-2 mb-2">
                <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-green-500/10 ring-1 ring-green-500/20">
                  <ShoppingCartIcon className="h-5 w-5 text-green-400" />
                </div>
                <span className="text-xs uppercase tracking-[0.08em] text-tx-muted">Faturamento Total</span>
              </div>
              <p className="relative text-2xl sm:text-3xl font-bold text-white">{formatCurrency(summary.total_revenue || 0)}</p>
            </div>

            {/* Ticket médio */}
            <div className="group relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#11121a] via-[#0d0f16] to-[#0a0b12] p-3 sm:p-4 transition-all duration-300 hover:-translate-y-0.5 hover:border-amber-400/40 hover:shadow-[0_10px_30px_rgba(245,158,11,0.12)]">
              <div className="pointer-events-none absolute -right-12 -top-12 h-24 w-24 rounded-full bg-amber-500/10 blur-2xl" />
              <div className="relative flex items-center gap-2 mb-2">
                <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/10 ring-1 ring-amber-500/20">
                  <TicketIcon className="h-5 w-5 text-amber-400" />
                </div>
                <span className="text-xs uppercase tracking-[0.08em] text-tx-muted">Ticket médio</span>
              </div>
              <p className="relative text-2xl sm:text-3xl font-bold text-white">{formatCurrency(summary.average_ticket || 0)}</p>
            </div>

            {/* Alertas de chargeback */}
            <div 
              className="group relative cursor-pointer overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#101512] via-[#0c100d] to-[#0a0c0a] p-3 sm:p-4 transition-all duration-300 hover:-translate-y-0.5 hover:border-green-400/35 hover:shadow-[0_10px_30px_rgba(34,197,94,0.12)]"
              onClick={() => navigate('/chargebacks')}
            >
              <div className="pointer-events-none absolute -right-12 -top-12 h-24 w-24 rounded-full bg-green-500/10 blur-2xl" />
              <div className="relative flex items-center justify-between mb-2">
                <div className="flex items-center gap-2">
                  <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-green-500/10 ring-1 ring-green-500/20">
                    <ExclamationTriangleIcon className="h-5 w-5 text-green-400" />
                  </div>
                  <span className="text-xs uppercase tracking-[0.08em] text-tx-muted">Alertas de chargeback</span>
                </div>
                <ArrowTopRightOnSquareIcon className="h-3 w-3 sm:h-4 sm:w-4 text-tx-muted opacity-0 group-hover:opacity-100 transition-opacity" />
              </div>
              <p className="relative text-2xl sm:text-3xl font-bold text-white">{dashboardResponse?.chargeback_alerts || 0}</p>
            </div>
          </>
        )}
      </div>

      {/* Sales by Payment Method Row - 3 cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
        {showDashboardSkeleton ? (
          <>
            <DashboardMetricCardSkeleton />
            <DashboardMetricCardSkeleton />
            <DashboardMetricCardSkeleton />
          </>
        ) : (
          <>
            {/* Vendas pix */}
            <div className="group relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#0d1414] via-[#0a1111] to-[#070d0d] p-3 sm:p-4 transition-all duration-300 hover:-translate-y-0.5 hover:border-emerald-400/40 hover:shadow-[0_10px_30px_rgba(16,185,129,0.12)]">
              <div className="pointer-events-none absolute -right-12 -top-12 h-24 w-24 rounded-full bg-emerald-500/10 blur-2xl" />
              <div className="relative flex items-center gap-2 mb-2">
                <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/10 ring-1 ring-emerald-500/20">
                  <PixIcon className="h-5 w-5 text-emerald-400" />
                </div>
                <span className="text-xs uppercase tracking-[0.08em] text-tx-muted">PIX nas últimas 24h</span>
              </div>
              <p className="relative text-2xl sm:text-3xl font-bold text-emerald-400">{formatCurrency(pixRevenue24h)}</p>
            </div>

            {/* Vendas cartão */}
            <div className="group relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#11141b] via-[#0d1116] to-[#0a0d12] p-3 sm:p-4 transition-all duration-300 hover:-translate-y-0.5 hover:border-sky-400/40 hover:shadow-[0_10px_30px_rgba(56,189,248,0.12)]">
              <div className="pointer-events-none absolute -right-12 -top-12 h-24 w-24 rounded-full bg-sky-500/10 blur-2xl" />
              <div className="relative flex items-center gap-2 mb-2">
                <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-500/10 ring-1 ring-sky-500/20">
                  <CreditCardIcon className="h-5 w-5 text-sky-400" />
                </div>
                <span className="text-xs uppercase tracking-[0.08em] text-tx-muted">Cartão nas últimas 24h</span>
              </div>
              <p className="relative text-2xl sm:text-3xl font-bold text-sky-400">{formatCurrency(cardRevenue24h)}</p>
            </div>

            {/* Vendas boleto */}
            <div className="group relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#171312] via-[#130f0f] to-[#0f0b0a] p-3 sm:p-4 transition-all duration-300 hover:-translate-y-0.5 hover:border-orange-400/40 hover:shadow-[0_10px_30px_rgba(251,146,60,0.12)]">
              <div className="pointer-events-none absolute -right-12 -top-12 h-24 w-24 rounded-full bg-orange-500/10 blur-2xl" />
              <div className="relative flex items-center gap-2 mb-2">
                <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-orange-500/10 ring-1 ring-orange-500/20">
                  <DocumentTextIcon className="h-5 w-5 text-orange-400" />
                </div>
                <span className="text-xs uppercase tracking-[0.08em] text-tx-muted">Boleto nas últimas 24h</span>
              </div>
              <p className="relative text-2xl sm:text-3xl font-bold text-orange-400">{formatCurrency(boletoRevenue24h)}</p>
            </div>
          </>
        )}
      </div>

      {/* Main Content: Chart */}
      <div className="space-y-3">
        {/* Histórico de faturamento - Chart */}
        <div className="bg-surface-card border border-border-DEFAULT rounded-xl p-3 sm:p-5 relative z-10">
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
              
              <div className="h-[240px] sm:h-[360px] overflow-hidden">
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
      </div>

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


