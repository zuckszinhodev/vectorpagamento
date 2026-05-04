import { useState } from 'react';
import { Bell, X, CheckCircle, AlertCircle, DollarSign } from 'lucide-react';
import { useAccountNotifications, useLiveSalesCount, requestNotificationPermission } from '../hooks/useWebSocket';

interface NotificationCenterProps {
  accountId: string;
}

export function NotificationCenter({ accountId }: NotificationCenterProps) {
  const [isOpen, setIsOpen] = useState(false);
  const { notifications, isConnected, clearNotifications, removeNotification } =
    useAccountNotifications(accountId);

  const unreadCount = notifications.length;

  const handleEnableNotifications = () => {
    requestNotificationPermission();
  };

  const getNotificationIcon = (type: string, severity?: string) => {
    if (severity === 'high') {
      return <AlertCircle className="w-5 h-5 text-red-500" />;
    }
    switch (type) {
      case 'payment':
        return <CheckCircle className="w-5 h-5 text-green-500" />;
      case 'chargeback':
        return <AlertCircle className="w-5 h-5 text-red-500" />;
      default:
        return <DollarSign className="w-5 h-5 text-blue-500" />;
    }
  };

  const formatTimestamp = (timestamp: Date) => {
    const now = new Date();
    const diff = now.getTime() - new Date(timestamp).getTime();
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);

    if (minutes < 1) return 'agora mesmo';
    if (minutes < 60) return `${minutes}m atrás`;
    if (hours < 24) return `${hours}h atrás`;
    return `${days}d atrás`;
  };

  return (
    <div className="relative">
      {/* Bell Icon Button */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="relative p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors"
      >
        <Bell className="w-6 h-6" />
        {unreadCount > 0 && (
          <span className="absolute top-0 right-0 inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
        {/* Connection indicator */}
        <span
          className={`absolute bottom-1 right-1 w-2 h-2 rounded-full ${
            isConnected ? 'bg-green-500' : 'bg-gray-400'
          }`}
          title={isConnected ? 'Conectado' : 'Desconectado'}
        />
      </button>

      {/* Notification Panel */}
      {isOpen && (
        <>
          {/* Backdrop */}
          <div
            className="fixed inset-0 z-40"
            onClick={() => setIsOpen(false)}
          />

          {/* Panel */}
          <div className="absolute right-0 mt-2 w-96 bg-white rounded-lg shadow-xl border border-gray-200 z-50 max-h-[600px] flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between p-4 border-b border-gray-200">
              <div>
                <h3 className="text-lg font-semibold text-gray-900">
                  Notificações
                </h3>
                <p className="text-sm text-gray-500">
                  {unreadCount} {unreadCount === 1 ? 'nova' : 'novas'}
                </p>
              </div>
              {unreadCount > 0 && (
                <button
                  onClick={clearNotifications}
                  className="text-sm text-blue-600 hover:text-blue-700"
                >
                  Limpar todas
                </button>
              )}
            </div>

            {/* Enable Browser Notifications */}
            {'Notification' in window && Notification.permission === 'default' && (
              <div className="p-4 bg-blue-50 border-b border-blue-100">
                <p className="text-sm text-blue-900 mb-2">
                  Ative as notificações do navegador para receber alertas em tempo real
                </p>
                <button
                  onClick={handleEnableNotifications}
                  className="w-full px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
                >
                  Ativar Notificações
                </button>
              </div>
            )}

            {/* Notifications List */}
            <div className="flex-1 overflow-y-auto">
              {notifications.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 text-gray-500">
                  <Bell className="w-12 h-12 mb-2 opacity-50" />
                  <p className="text-sm">Nenhuma notificação</p>
                </div>
              ) : (
                <div className="divide-y divide-gray-100">
                  {notifications.map((notification) => (
                    <div
                      key={notification.id}
                      className={`p-4 hover:bg-gray-50 transition-colors ${
                        notification.severity === 'high' ? 'bg-red-50' : ''
                      }`}
                    >
                      <div className="flex items-start gap-3">
                        {getNotificationIcon(notification.type, notification.severity)}
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium text-gray-900">
                            {notification.title}
                          </p>
                          <p className="text-sm text-gray-600 mt-1">
                            {notification.message}
                          </p>
                          <p className="text-xs text-gray-500 mt-1">
                            {formatTimestamp(notification.timestamp)}
                          </p>
                        </div>
                        <button
                          onClick={() => removeNotification(notification.id)}
                          className="text-gray-400 hover:text-gray-600"
                        >
                          <X className="w-4 h-4" />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Footer */}
            <div className="p-3 border-t border-gray-200 bg-gray-50">
              <p className="text-xs text-center text-gray-500">
                Status:{' '}
                <span className={isConnected ? 'text-green-600' : 'text-red-600'}>
                  {isConnected ? '● Conectado' : '● Desconectado'}
                </span>
              </p>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

// Live Sales Counter Component
export function LiveSalesCounter({ accountId }: { accountId: string }) {
  const { stats, isConnected } = useLiveSalesCount(accountId);

  return (
    <div className="bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg p-6 text-white shadow-lg">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-lg font-semibold">Vendas ao Vivo</h3>
        <span
          className={`inline-flex items-center gap-1 text-xs ${
            isConnected ? 'text-green-200' : 'text-red-200'
          }`}
        >
          <span className="w-2 h-2 rounded-full bg-current animate-pulse" />
          {isConnected ? 'Ao vivo' : 'Offline'}
        </span>
      </div>

      <div className="space-y-4">
        <div>
          <p className="text-sm text-blue-100">Total de Vendas</p>
          <p className="text-3xl font-bold">{stats.totalSales.toLocaleString()}</p>
        </div>

        <div>
          <p className="text-sm text-blue-100">Receita Total</p>
          <p className="text-2xl font-bold">
            R$ {stats.totalRevenue.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
          </p>
        </div>
      </div>
    </div>
  );
}

