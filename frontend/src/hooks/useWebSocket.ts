import { useEffect, useRef, useState } from 'react';
import Pusher from 'pusher-js';

// Configuração do Pusher/Soketi
let pusher: Pusher;

function getPusherInstance(): Pusher {
  if (!pusher) {
    pusher = new Pusher(import.meta.env.VITE_PUSHER_APP_KEY || 'local', {
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
      wsHost: import.meta.env.VITE_PUSHER_HOST || window.location.hostname,
      wsPort: parseInt(import.meta.env.VITE_PUSHER_PORT || '6001'),
      forceTLS: import.meta.env.VITE_PUSHER_SCHEME === 'https',
      disableStats: true,
      enabledTransports: ['ws', 'wss'],
      authEndpoint: `${import.meta.env.VITE_API_URL}/broadcasting/auth`,
      auth: {
        headers: {
          get Authorization() {
            return `Bearer ${localStorage.getItem('auth_token')}`;
          },
        },
      },
    });
  }
  return pusher;
}

export interface WebSocketHookConfig {
  channel: string;
  event: string;
  onMessage: (data: any) => void;
  enabled?: boolean;
}

export function useWebSocket({
  channel,
  event,
  onMessage,
  enabled = true,
}: WebSocketHookConfig) {
  const [isConnected, setIsConnected] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const channelRef = useRef<any>(null);

  useEffect(() => {
    if (!enabled) return;

    try {
      // Subscribe to channel
      const subscribedChannel = getPusherInstance().subscribe(channel);
      channelRef.current = subscribedChannel;

      // Bind to event
      subscribedChannel.bind(event, onMessage);

      // Connection status
      subscribedChannel.bind('pusher:subscription_succeeded', () => {
        setIsConnected(true);
        setError(null);
      });

      subscribedChannel.bind('pusher:subscription_error', (err: any) => {
        setError(new Error(err.error || 'Subscription failed'));
        setIsConnected(false);
      });

      return () => {
        subscribedChannel.unbind(event, onMessage);
        getPusherInstance().unsubscribe(channel);
        setIsConnected(false);
      };
    } catch (err) {
      setError(err as Error);
      setIsConnected(false);
    }
  }, [channel, event, enabled]);

  return { isConnected, error, pusher: getPusherInstance() };
}

// Hook para múltiplos eventos no mesmo canal
export function useChannelEvents(
  channel: string,
  events: Array<{ event: string; callback: (data: any) => void }>,
  enabled = true
) {
  const [isConnected, setIsConnected] = useState(false);
  const channelRef = useRef<any>(null);

  useEffect(() => {
    if (!enabled) return;

    const subscribedChannel = getPusherInstance().subscribe(channel);
    channelRef.current = subscribedChannel;

    // Bind all events
    events.forEach(({ event, callback }) => {
      subscribedChannel.bind(event, callback);
    });

    subscribedChannel.bind('pusher:subscription_succeeded', () => {
      setIsConnected(true);
    });

    return () => {
      events.forEach(({ event, callback }) => {
        subscribedChannel.unbind(event, callback);
      });
      getPusherInstance().unsubscribe(channel);
      setIsConnected(false);
    };
  }, [channel, enabled]);

  return { isConnected, channel: channelRef.current };
}

// Hook para notificações de conta
export function useAccountNotifications(accountId: string, enabled = true) {
  const [notifications, setNotifications] = useState<any[]>([]);

  const handlePaymentStatusChanged = (data: any) => {
    setNotifications((prev) => [
      {
        id: crypto.randomUUID(),
        type: 'payment',
        title: `Pagamento ${data.status}`,
        message: `R$ ${(data.amount / 100).toFixed(2)} - ${data.customer_email || 'Cliente'}`,
        data,
        timestamp: new Date(),
      },
      ...prev.slice(0, 49), // Keep last 50
    ]);

    // Browser notification
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification(`Pagamento ${data.status}`, {
        body: `R$ ${(data.amount / 100).toFixed(2)}`,
        icon: '/img/logo.png',
      });
    }
  };

  const handleChargebackReceived = (data: any) => {
    setNotifications((prev) => [
      {
        id: crypto.randomUUID(),
        type: 'chargeback',
        title: 'Chargeback Recebido',
        message: `R$ ${(data.amount / 100).toFixed(2)} - ${data.reason}`,
        data,
        timestamp: new Date(),
        severity: 'high',
      },
      ...prev.slice(0, 49),
    ]);

    // Browser notification
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification('⚠️ Chargeback Recebido', {
        body: `R$ ${(data.amount / 100).toFixed(2)} - ${data.reason}`,
        icon: '/img/logo.png',
        requireInteraction: true,
      });
    }
  };

  const { isConnected } = useChannelEvents(
    `private-account.${accountId}`,
    [
      { event: 'payment.status.changed', callback: handlePaymentStatusChanged },
      { event: 'chargeback.received', callback: handleChargebackReceived },
    ],
    enabled
  );

  const clearNotifications = () => setNotifications([]);
  const removeNotification = (id: string) => {
    setNotifications((prev) => prev.filter((n) => n.id !== id));
  };

  return {
    notifications,
    isConnected,
    clearNotifications,
    removeNotification,
  };
}

// Hook para contador de vendas ao vivo
export function useLiveSalesCount(accountId: string, enabled = true) {
  const [stats, setStats] = useState({
    totalSales: 0,
    totalRevenue: 0,
    today: {},
  });

  const handleSalesUpdate = (data: any) => {
    setStats({
      totalSales: data.total_sales,
      totalRevenue: data.total_revenue,
      today: data.today || {},
    });
  };

  const { isConnected } = useWebSocket({
    channel: `private-account.${accountId}.stats`,
    event: 'sales.count.updated',
    onMessage: handleSalesUpdate,
    enabled,
  });

  return { stats, isConnected };
}

// Request browser notification permission
export function requestNotificationPermission() {
  if ('Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission();
  }
}

