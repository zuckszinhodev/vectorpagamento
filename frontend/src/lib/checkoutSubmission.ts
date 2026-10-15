export type CheckoutPaymentMethod = 'pix' | 'credit_card' | 'boleto';

export function prepareCheckoutSubmission<T extends { payment_method?: CheckoutPaymentMethod; card?: unknown }>(
  data: T,
  fallbackMethod: CheckoutPaymentMethod,
) {
  const method = data.payment_method ?? fallbackMethod;
  return {
    ...data,
    payment_method: method,
    card: method === 'credit_card' ? data.card : undefined,
  };
}
