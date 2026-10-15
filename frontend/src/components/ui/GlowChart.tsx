import { useRef, useEffect, useMemo, useCallback } from 'react';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  LogarithmicScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Filler,
  Tooltip,
  Legend,
  Title,
  type ChartOptions,
  type Plugin,
  type ChartData,
} from 'chart.js';
import { Line, Doughnut } from 'react-chartjs-2';

ChartJS.register(
  CategoryScale,
  LinearScale,
  LogarithmicScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Filler,
  Tooltip,
  Legend,
  Title
);

// ─── Theme detection helper ────────────────────────────────────────
function useIsDark() {
  const ref = useRef(false);
  ref.current = typeof document !== 'undefined'
    ? document.documentElement.classList.contains('dark')
    : false;
  return ref.current;
}

// ─── Glow Line Plugin ──────────────────────────────────────────────
const glowLinePlugin: Plugin<'line'> = {
  id: 'glowLine',
  beforeDatasetsDraw(chart) {
    const { ctx } = chart;
    ctx.save();

    chart.data.datasets.forEach((dataset, i) => {
      const meta = chart.getDatasetMeta(i);
      if (meta.type !== 'line' || meta.hidden) return;

      const points = meta.data;
      if (points.length < 2) return;

      // Draw glow layers (multiple passes for neon effect)
      const glowLayers = [
        { blur: 20, alpha: 0.08, width: 16 },
        { blur: 14, alpha: 0.12, width: 10 },
        { blur: 8, alpha: 0.18, width: 6 },
        { blur: 4, alpha: 0.25, width: 3 },
      ];

      const borderColor = (dataset.borderColor as string) || 'rgb(16, 185, 129)';

      for (const layer of glowLayers) {
        ctx.save();
        ctx.filter = `blur(${layer.blur}px)`;
        ctx.globalAlpha = layer.alpha;
        ctx.strokeStyle = borderColor;
        ctx.lineWidth = layer.width;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';

        ctx.beginPath();
        const tension = (dataset.tension as number) ?? 0.4;

        if (tension > 0) {
          // Draw bezier curve matching Chart.js tension
          for (let p = 0; p < points.length; p++) {
            const pt = points[p] as any;
            if (p === 0) {
              ctx.moveTo(pt.x, pt.y);
            } else {
              const prev = points[p - 1] as any;
              const cpx1 = prev.x + (pt.x - prev.x) / 3;
              const cpx2 = pt.x - (pt.x - prev.x) / 3;
              ctx.bezierCurveTo(
                prev.cp2?.x ?? cpx1, prev.cp2?.y ?? prev.y,
                pt.cp1?.x ?? cpx2, pt.cp1?.y ?? pt.y,
                pt.x, pt.y
              );
            }
          }
        } else {
          points.forEach((pt: any, idx: number) => {
            if (idx === 0) ctx.moveTo(pt.x, pt.y);
            else ctx.lineTo(pt.x, pt.y);
          });
        }

        ctx.stroke();
        ctx.restore();
      }

      ctx.restore();
    });
  },
};

// ─── Animated grid pulse plugin ────────────────────────────────────
const gridPulsePlugin: Plugin<'line'> = {
  id: 'gridPulse',
  beforeDraw(chart) {
    const { ctx, chartArea, scales } = chart;
    if (!chartArea) return;

    const isDark = document.documentElement.classList.contains('dark');
    const yScale = scales.y;
    if (!yScale) return;

    // Draw subtle horizontal scan lines for a futuristic look
    ctx.save();
    const ticks = yScale.ticks;
    
    for (let i = 0; i < ticks.length; i++) {
      const y = yScale.getPixelForTick(i);
      if (y < chartArea.top || y > chartArea.bottom) continue;

      const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
      if (isDark) {
        gradient.addColorStop(0, 'rgba(16, 185, 129, 0)');
        gradient.addColorStop(0.2, 'rgba(16, 185, 129, 0.06)');
        gradient.addColorStop(0.5, 'rgba(16, 185, 129, 0.08)');
        gradient.addColorStop(0.8, 'rgba(16, 185, 129, 0.06)');
        gradient.addColorStop(1, 'rgba(16, 185, 129, 0)');
      } else {
        gradient.addColorStop(0, 'rgba(16, 185, 129, 0)');
        gradient.addColorStop(0.2, 'rgba(16, 185, 129, 0.04)');
        gradient.addColorStop(0.5, 'rgba(16, 185, 129, 0.06)');
        gradient.addColorStop(0.8, 'rgba(16, 185, 129, 0.04)');
        gradient.addColorStop(1, 'rgba(16, 185, 129, 0)');
      }

      ctx.strokeStyle = gradient;
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 4]);
      ctx.beginPath();
      ctx.moveTo(chartArea.left, y);
      ctx.lineTo(chartArea.right, y);
      ctx.stroke();
      ctx.setLineDash([]);
    }
    ctx.restore();
  },
};

// ─── Glow Doughnut Plugin ──────────────────────────────────────────
const glowDoughnutPlugin: Plugin<'doughnut'> = {
  id: 'glowDoughnut',
  beforeDraw(chart) {
    const { ctx } = chart;
    ctx.save();

    const meta = chart.getDatasetMeta(0);
    if (!meta || meta.data.length === 0) return;

    // Add a subtle glow behind the doughnut
    const arc = meta.data[0] as any;
    const centerX = arc.x;
    const centerY = arc.y;
    const outerRadius = arc.outerRadius || 100;

    const gradient = ctx.createRadialGradient(
      centerX, centerY, outerRadius * 0.5,
      centerX, centerY, outerRadius * 1.4
    );

    const isDark = document.documentElement.classList.contains('dark');
    if (isDark) {
      gradient.addColorStop(0, 'rgba(16, 185, 129, 0.08)');
      gradient.addColorStop(0.5, 'rgba(16, 185, 129, 0.04)');
      gradient.addColorStop(1, 'rgba(16, 185, 129, 0)');
    } else {
      gradient.addColorStop(0, 'rgba(16, 185, 129, 0.05)');
      gradient.addColorStop(0.5, 'rgba(16, 185, 129, 0.02)');
      gradient.addColorStop(1, 'rgba(16, 185, 129, 0)');
    }

    ctx.fillStyle = gradient;
    ctx.beginPath();
    ctx.arc(centerX, centerY, outerRadius * 1.4, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
  },
};

// ─── Color Palette ─────────────────────────────────────────────────
const PALETTE = {
  purple: {
    line: 'rgb(16, 185, 129)',
    lineHover: 'rgb(168, 85, 247)',
    gradientTop: { dark: 'rgba(16, 185, 129, 0.30)', light: 'rgba(16, 185, 129, 0.18)' },
    gradientMid: { dark: 'rgba(16, 185, 129, 0.08)', light: 'rgba(16, 185, 129, 0.06)' },
    gradientBot: { dark: 'rgba(16, 185, 129, 0)', light: 'rgba(16, 185, 129, 0)' },
    point: 'rgb(16, 185, 129)',
    pointBorder: { dark: 'rgb(22, 22, 22)', light: '#ffffff' },
    tooltip: { bg: '#1e1b4b', border: 'rgba(16, 185, 129, 0.4)' },
    grid: { dark: 'rgba(16, 185, 129, 0.08)', light: 'rgba(16, 185, 129, 0.06)' },
    tick: { dark: 'rgba(168, 162, 186, 0.6)', light: 'rgba(100, 100, 130, 0.6)' },
  },
  violet: {
    line: 'rgb(5, 150, 105)',
    lineHover: 'rgb(139, 92, 246)',
    gradientTop: { dark: 'rgba(5, 150, 105, 0.95)', light: 'rgba(5, 150, 105, 0.85)' },
    gradientMid: { dark: 'rgba(5, 150, 105, 0.80)', light: 'rgba(5, 150, 105, 0.65)' },
    gradientBot: { dark: 'rgba(5, 150, 105, 0.50)', light: 'rgba(5, 150, 105, 0.35)' },
    point: 'rgb(5, 150, 105)',
    pointBorder: { dark: 'rgb(22, 22, 22)', light: '#ffffff' },
    tooltip: { bg: '#1e1b4b', border: 'rgba(5, 150, 105, 0.4)' },
    grid: { dark: 'rgba(5, 150, 105, 0.08)', light: 'rgba(5, 150, 105, 0.06)' },
    tick: { dark: 'rgba(168, 162, 186, 0.6)', light: 'rgba(100, 100, 130, 0.6)' },
  },
  fuchsia: {
    line: 'rgb(52, 211, 153)',
    lineHover: 'rgb(232, 121, 249)',
    gradientTop: { dark: 'rgba(52, 211, 153, 0.25)', light: 'rgba(52, 211, 153, 0.14)' },
    gradientMid: { dark: 'rgba(52, 211, 153, 0.06)', light: 'rgba(52, 211, 153, 0.04)' },
    gradientBot: { dark: 'rgba(52, 211, 153, 0)', light: 'rgba(52, 211, 153, 0)' },
    point: 'rgb(52, 211, 153)',
    pointBorder: { dark: 'rgb(22, 22, 22)', light: '#ffffff' },
    tooltip: { bg: '#1e1b4b', border: 'rgba(52, 211, 153, 0.4)' },
    grid: { dark: 'rgba(52, 211, 153, 0.08)', light: 'rgba(52, 211, 153, 0.06)' },
    tick: { dark: 'rgba(168, 162, 186, 0.6)', light: 'rgba(100, 100, 130, 0.6)' },
  },
  rose: {
    line: 'rgb(225, 29, 72)',
    lineHover: 'rgb(244, 63, 94)',
    gradientTop: { dark: 'rgba(225, 29, 72, 0.30)', light: 'rgba(225, 29, 72, 0.18)' },
    gradientMid: { dark: 'rgba(225, 29, 72, 0.08)', light: 'rgba(225, 29, 72, 0.06)' },
    gradientBot: { dark: 'rgba(225, 29, 72, 0)', light: 'rgba(225, 29, 72, 0)' },
    point: 'rgb(225, 29, 72)',
    pointBorder: { dark: 'rgb(22, 22, 22)', light: '#ffffff' },
    tooltip: { bg: '#1c1917', border: 'rgba(225, 29, 72, 0.4)' },
    grid: { dark: 'rgba(225, 29, 72, 0.08)', light: 'rgba(225, 29, 72, 0.06)' },
    tick: { dark: 'rgba(168, 162, 186, 0.6)', light: 'rgba(100, 100, 130, 0.6)' },
  },
  red: {
    line: 'rgb(239, 68, 68)',
    lineHover: 'rgb(248, 113, 113)',
    gradientTop: { dark: 'rgba(239, 68, 68, 0.55)', light: 'rgba(239, 68, 68, 0.28)' },
    gradientMid: { dark: 'rgba(239, 68, 68, 0.18)', light: 'rgba(239, 68, 68, 0.10)' },
    gradientBot: { dark: 'rgba(239, 68, 68, 0)', light: 'rgba(239, 68, 68, 0)' },
    point: 'rgb(248, 113, 113)',
    pointBorder: { dark: 'rgb(13, 13, 13)', light: '#ffffff' },
    tooltip: { bg: '#1a0a0a', border: 'rgba(239, 68, 68, 0.4)' },
    grid: { dark: 'rgba(239, 68, 68, 0.08)', light: 'rgba(239, 68, 68, 0.06)' },
    tick: { dark: 'rgba(180, 180, 190, 0.6)', light: 'rgba(100, 100, 130, 0.6)' },
  },
};

type PaletteKey = keyof typeof PALETTE;

// ─── GlowLineChart Component ──────────────────────────────────────
interface GlowLineChartProps {
  labels: string[];
  data: number[];
  label?: string;
  color?: PaletteKey;
  height?: string;
  showPoints?: boolean;
  formatTooltip?: (value: number) => string;
  formatYTick?: (value: number) => string;
  suggestedMax?: number;
  tension?: number;
  className?: string;
  /** Clean, modern look: no neon glow / scan lines, subtle grid, filled area. */
  clean?: boolean;
}

export function GlowLineChart({
  labels,
  data,
  label = 'Valor',
  color = 'purple',
  height = 'h-80',
  showPoints = true,
  formatTooltip,
  formatYTick,
  suggestedMax,
  tension = 0.45,
  className = '',
  clean = false,
}: GlowLineChartProps) {
  const chartRef = useRef<ChartJS<'line'> | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const isDark = useIsDark();
  const palette = PALETTE[color];

  // Observe theme changes  
  useEffect(() => {
    const obs = new MutationObserver(() => {
      chartRef.current?.update('none');
    });
    obs.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => obs.disconnect();
  }, []);

  // ALWAYS call hooks - move createGradient before the early return
  const createGradient = useCallback(
    (ctx: CanvasRenderingContext2D, chartArea: { top: number; bottom: number }) => {
      const dark = document.documentElement.classList.contains('dark');
      const grad = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
      grad.addColorStop(0, dark ? palette.gradientTop.dark : palette.gradientTop.light);
      grad.addColorStop(0.5, dark ? palette.gradientMid.dark : palette.gradientMid.light);
      grad.addColorStop(1, dark ? palette.gradientBot.dark : palette.gradientBot.light);
      return grad;
    },
    [palette]
  );

  const chartData: ChartData<'line'> = useMemo(
    () => ({
      labels,
      datasets: [
        {
          label,
          data,
          fill: true,
          borderColor: palette.line,
          backgroundColor: (context: any) => {
            const chart = context.chart;
            const { ctx, chartArea } = chart;
            if (!chartArea) return 'rgba(147,51,234,0)';
            return createGradient(ctx, chartArea);
          },
          tension,
          pointRadius: clean
            ? (context: any) => (context.dataIndex === data.length - 1 ? 4.5 : 0)
            : 0,
          pointHoverRadius: 6,
          pointBackgroundColor: palette.point,
          pointBorderColor: isDark ? palette.pointBorder.dark : palette.pointBorder.light,
          pointBorderWidth: 3,
          pointHoverBackgroundColor: palette.lineHover,
          pointHoverBorderColor: isDark ? palette.pointBorder.dark : palette.pointBorder.light,
          pointHoverBorderWidth: 3,
          borderWidth: clean ? 3 : 2.5,
          borderCapStyle: 'round' as const,
          borderJoinStyle: 'round' as const,
          spanGaps: true,
        },
      ],
    }),
    [labels, data, label, palette, tension, showPoints, isDark, createGradient, clean]
  );

  const computedMax = useMemo(() => {
    if (suggestedMax !== undefined) return suggestedMax;
    const max = Math.max(0, ...data);
    return max === 0 ? 100 : max * 1.2;
  }, [data, suggestedMax]);

  const chartOptions: ChartOptions<'line'> = useMemo(() => {
    const dark = document.documentElement.classList.contains('dark');
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: {
        duration: 1200,
        easing: 'easeOutQuart',
        delay(ctx) {
          // Stagger animation per point for wave-in effect
          if (ctx.type === 'data' && ctx.mode === 'default') {
            return ctx.dataIndex * 40;
          }
          return 0;
        },
      },
      transitions: {
        active: { animation: { duration: 250 } },
      },
      elements: {
        line: { capBezierPoints: true },
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: palette.tooltip.bg,
          titleColor: '#e2e8f0',
          bodyColor: '#ffffff',
          borderColor: palette.tooltip.border,
          borderWidth: 1,
          padding: 16,
          cornerRadius: 12,
          displayColors: false,
          titleFont: { size: 12, weight: 'normal' as const },
          bodyFont: { size: 14, weight: 'bold' as const },
          callbacks: {
            label: (ctx: any) => {
              const val = ctx.raw as number;
              if (formatTooltip) return formatTooltip(val);
              return String(val);
            },
          },
        },
      },
      scales: {
        x: {
          grid: { display: false },
          border: { display: false },
          ticks: {
            color: dark ? palette.tick.dark : palette.tick.light,
            font: { size: 11, weight: 500 },
            maxRotation: 0,
            autoSkip: true,
            maxTicksLimit: clean ? 7 : 10,
            padding: 8,
          },
        },
        y: {
          type: 'linear' as const,
          beginAtZero: true,
          min: 0,
          suggestedMax: computedMax,
          border: { display: false },
          grid: {
            color: clean ? (dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)') : (dark ? palette.grid.dark : palette.grid.light),
            lineWidth: 1,
          },
          ticks: {
            color: dark ? palette.tick.dark : palette.tick.light,
            font: { size: 11, weight: 500 },
            padding: 12,
            maxTicksLimit: clean ? 5 : undefined,
            callback: (value: any) => {
              if (formatYTick) return formatYTick(value as number);
              return value;
            },
          },
        },
      },
      interaction: {
        intersect: false,
        mode: 'index' as const,
      },
      hover: {
        mode: 'index' as const,
        intersect: false,
      },
    };
  }, [palette, computedMax, formatTooltip, formatYTick, clean]);

  // Check if there's no data - AFTER all hooks are called
  const hasNoData = !data || data.length === 0 || data.every(val => val === 0);

  // If no data, show empty state AFTER all hooks
  if (hasNoData) {
    return (
      <div className={`${height} ${className} flex items-center justify-center`}>
        <div className="text-center">
          <svg
            className="mx-auto h-12 w-12 text-gray-400 dark:text-gray-600 mb-3"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            aria-hidden="true"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={1.5}
              d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
            />
          </svg>
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">
            Não há dados a serem exibidos
          </p>
          <p className="text-xs text-gray-500 dark:text-gray-500 mt-1">
            Os dados aparecerão aqui quando houver transações
          </p>
        </div>
      </div>
    );
  }

  return (
    <div
      ref={containerRef}
      className={`relative ${height} ${className} glow-chart-container`}
    >
      <Line
        ref={chartRef}
        data={chartData}
        options={chartOptions}
        plugins={clean ? [glowLinePlugin] : [glowLinePlugin, gridPulsePlugin]}
      />
    </div>
  );
}

// ─── GlowDoughnutChart Component ──────────────────────────────────
interface GlowDoughnutChartProps {
  labels: string[];
  data: number[];
  colors?: string[];
  height?: number;
  cutout?: string;
  className?: string;
}

export function GlowDoughnutChart({
  labels,
  data,
  colors,
  height = 250,
  cutout = '70%',
  className = '',
}: GlowDoughnutChartProps) {
  const isDark = useIsDark();

  const defaultColors = isDark
    ? ['rgba(16, 185, 129, 0.9)', 'rgba(52, 211, 153, 0.7)', 'rgba(5, 150, 105, 0.6)', 'rgba(168, 85, 247, 0.5)']
    : ['rgb(16, 185, 129)', 'rgb(52, 211, 153)', 'rgb(5, 150, 105)', 'rgb(168, 85, 247)'];

  const bgColors = colors || defaultColors;

  const chartData: ChartData<'doughnut'> = useMemo(
    () => ({
      labels,
      datasets: [
        {
          data,
          backgroundColor: bgColors,
          borderColor: isDark ? 'rgb(22, 22, 22)' : '#ffffff',
          borderWidth: 3,
          hoverBorderColor: isDark ? 'rgb(30, 30, 30)' : '#f3f4f6',
          hoverOffset: 8,
        },
      ],
    }),
    [labels, data, bgColors, isDark]
  );

  const chartOptions: ChartOptions<'doughnut'> = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: true,
      cutout,
      animation: {
        animateRotate: true,
        animateScale: true,
        duration: 1400,
        easing: 'easeOutQuart' as const,
      },
      plugins: {
        legend: {
          position: 'bottom' as const,
          labels: {
            color: isDark ? 'rgba(200, 200, 220, 0.8)' : 'rgba(60, 60, 80, 0.8)',
            padding: 20,
            usePointStyle: true,
            pointStyle: 'circle',
            font: { size: 12, weight: 500 },
          },
        },
        tooltip: {
          backgroundColor: '#1e1b4b',
          titleColor: '#e2e8f0',
          bodyColor: '#ffffff',
          borderColor: 'rgba(16, 185, 129, 0.4)',
          borderWidth: 1,
          padding: 14,
          cornerRadius: 12,
          displayColors: true,
          bodyFont: { size: 13, weight: 'bold' as const },
        },
      },
    }),
    [isDark, cutout]
  );

  return (
    <div
      className={`relative flex items-center justify-center glow-doughnut-container ${className}`}
      style={{ height }}
    >
      <Doughnut
        data={chartData}
        options={chartOptions}
        plugins={[glowDoughnutPlugin]}
      />
    </div>
  );
}

// ─── Chart Card Wrapper ────────────────────────────────────────────
interface ChartCardProps {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  actions?: React.ReactNode;
  className?: string;
}

export function ChartCard({ title, subtitle, children, actions, className = '' }: ChartCardProps) {
  return (
    <div
      className={`
        group relative rounded-2xl p-6 overflow-hidden
        bg-surface-card
        border border-border-DEFAULT
        transition-all duration-300 ease-out
        hover:border-accent-primary/20
        ${className}
      `}
    >
      {/* Header */}
      <div className="relative flex items-center justify-between mb-6">
        <div>
          <h3 className="text-lg font-semibold text-tx-primary">
            {title}
          </h3>
          {subtitle && (
            <p className="text-sm text-tx-muted mt-0.5">
              {subtitle}
            </p>
          )}
        </div>
        {actions && <div className="flex items-center gap-2">{actions}</div>}
      </div>

      {/* Chart Content */}
      <div className="relative">{children}</div>
    </div>
  );
}

export default GlowLineChart;
