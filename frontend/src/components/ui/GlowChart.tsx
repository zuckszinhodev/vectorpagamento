import { type ReactNode } from 'react';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  ArcElement,
  Filler,
  Tooltip,
  Legend,
} from 'chart.js';
import { Line, Doughnut } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, ArcElement, Filler, Tooltip, Legend);

interface ChartCardProps {
  title: string;
  subtitle?: string;
  children: ReactNode;
}

export function ChartCard({ title, subtitle, children }: ChartCardProps) {
  return (
    <div className="rounded-lg border border-gray-200 bg-white p-5">
      <h3 className="text-sm font-medium text-gray-900">{title}</h3>
      {subtitle && <p className="mt-0.5 text-xs text-gray-500">{subtitle}</p>}
      <div className="mt-4">{children}</div>
    </div>
  );
}

interface GlowLineChartProps {
  labels: string[];
  data?: number[];
  datasets?: Array<{
    label?: string;
    data: number[];
    borderColor?: string;
    backgroundColor?: string;
  }>;
  label?: string;
  color?: string;
  height?: number | string;
  tension?: number;
  formatTooltip?: (value: number) => string;
  formatYTick?: (value: number) => string;
}

const colorMap: Record<string, string> = {
  emerald: '#10b981',
  green: '#16a34a',
  blue: '#3b82f6',
  purple: '#8b5cf6',
  red: '#ef4444',
  amber: '#f59e0b',
};

export function GlowLineChart({ labels, data: rawData, datasets: rawDatasets, label, color, height = 250, tension = 0.3, formatTooltip, formatYTick }: GlowLineChartProps) {
  const borderColor = color ? (colorMap[color] || color) : '#111827';
  const datasets = rawDatasets || [{ data: rawData || [], label }];

  const chartData = {
    labels,
    datasets: datasets.map((ds) => ({
      ...ds,
      borderWidth: 2,
      tension,
      pointRadius: 0,
      pointHoverRadius: 4,
      fill: true,
      backgroundColor: ds.backgroundColor || `${borderColor}0a`,
      borderColor: ds.borderColor || borderColor,
    })),
  };

  const isClassHeight = typeof height === 'string' && height.startsWith('h-');
  const containerStyle = isClassHeight ? undefined : { height: typeof height === 'number' ? height : 250 };
  const containerClass = isClassHeight ? height : undefined;

  return (
    <div style={containerStyle} className={containerClass}>
      <Line
        data={chartData}
        options={{
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: datasets.length > 1 },
            tooltip: formatTooltip ? {
              callbacks: { label: (ctx) => formatTooltip(Number(ctx.parsed.y) || 0) },
            } : undefined,
          },
          scales: {
            x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } },
            y: {
              grid: { color: '#f3f4f6' },
              ticks: {
                color: '#9ca3af',
                font: { size: 11 },
                ...(formatYTick ? { callback: (value: any) => formatYTick(Number(value)) } : {}),
              },
            },
          },
        }}
      />
    </div>
  );
}

interface GlowDoughnutChartProps {
  labels: string[];
  data: number[];
  colors?: string[];
  height?: number;
}

export function GlowDoughnutChart({ labels, data, colors, height = 250 }: GlowDoughnutChartProps) {
  const defaultColors = ['#111827', '#6b7280', '#d1d5db', '#e5e7eb'];

  const chartData = {
    labels,
    datasets: [
      {
        data,
        backgroundColor: colors || defaultColors,
        borderWidth: 0,
      },
    ],
  };

  return (
    <div style={{ height }}>
      <Doughnut
        data={chartData}
        options={{
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: '#6b7280', font: { size: 11 } } } },
          cutout: '65%',
        }}
      />
    </div>
  );
}
