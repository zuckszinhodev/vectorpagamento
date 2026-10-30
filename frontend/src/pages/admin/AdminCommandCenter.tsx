import { lazy, Suspense } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ChartBarIcon } from '@heroicons/react/24/outline';
import clsx from 'clsx';
import { AdminPageHeader, AdminPageShell } from '@/components/admin/AdminPageShell';
import { hasAdminPermission } from '@/lib/adminPermissions';
import { useAuthStore } from '@/stores/authStore';

const AdminDashboard = lazy(() => import('@/pages/admin/AdminDashboard'));
const AdminControlTower = lazy(() => import('@/pages/admin/AdminControlTower'));
const AdminMonitoring = lazy(() => import('@/pages/admin/AdminMonitoring'));

const TABS = [
  { id: 'visao-geral', label: 'Visão geral', Page: AdminDashboard, permission: 'admin.dashboard.read' },
  { id: 'operacao', label: 'Operação', Page: AdminControlTower, permission: 'admin.dashboard.read' },
  { id: 'seguranca', label: 'Segurança', Page: AdminMonitoring, permission: 'admin.security.monitor' },
];

export default function AdminCommandCenter() {
  const { user } = useAuthStore();
  const [searchParams, setSearchParams] = useSearchParams();
  const tabs = TABS.filter((tab) => hasAdminPermission(user, tab.permission));
  const active = tabs.find((tab) => tab.id === searchParams.get('tab')) ?? tabs[0];

  return (
    <AdminPageShell>
      <AdminPageHeader
        title="Dashboard"
        subtitle="Negócio, operação e segurança em um só lugar"
        icon={ChartBarIcon}
      />

      <div role="tablist" className="flex gap-1 border-b border-white/[0.06]">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={tab.id === active?.id}
            onClick={() => setSearchParams(tab.id === tabs[0].id ? {} : { tab: tab.id })}
            className={clsx(
              '-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
              tab.id === active?.id ? 'border-red-500 text-white' : 'border-transparent text-gray-400 hover:text-white'
            )}
          >
            {tab.label}
          </button>
        ))}
      </div>

      <Suspense
        fallback={
          <div className="flex h-[40vh] items-center justify-center">
            <div className="h-8 w-8 animate-spin rounded-full border-2 border-red-500 border-t-transparent" />
          </div>
        }
      >
        {active && <active.Page embedded />}
      </Suspense>
    </AdminPageShell>
  );
}
