import React from 'react';
import PublicCheckoutPage from './CheckoutPage';
import { ModernSplitCheckout } from '@/components/checkout/ModernSplitCheckout';

// Este wrapper detecta o template e renderiza o componente correto
export default function CheckoutPageWrapper() {
  // Por enquanto, sempre renderiza o checkout clássico
  // O CheckoutPage já tem toda a lógica, então vamos apenas retornar ele
  return <PublicCheckoutPage />;
}

