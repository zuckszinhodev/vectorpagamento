export function prepareProductDiscount(price: number, enabled: boolean, mode: 'direct' | 'coupon', type: 'percentage' | 'fixed', value?: number) {
  const basePrice = Math.round(Number(price) * 100);
  if (!enabled) return { price: basePrice, original_price: null };
  const amount = Number(value);
  if (!Number.isFinite(amount) || amount <= 0 || (type === 'percentage' && amount > 100)) {
    throw new Error('Informe um desconto válido.');
  }
  const finalPrice = basePrice - Math.round(type === 'percentage' ? basePrice * amount / 100 : amount * 100);
  if (finalPrice < 100) throw new Error('O preço com desconto deve ser de pelo menos R$ 1,00.');
  return mode === 'direct'
    ? { price: finalPrice, original_price: basePrice }
    : { price: basePrice, original_price: null };
}
