import { api } from './client';

/** What is being paid for. Amounts are always computed by the server. */
export type PayableType = 'invoice' | 'order' | 'ride_charge' | 'ride' | 'consult';
export type PaymentService = 'pharmacy' | 'invoice' | 'ride' | 'subscription';

export type PaymentMethodOption = {
  key: 'payfast' | 'paystack' | 'ozow' | 'mock';
  label: string;
  hint: string;
  color: string;
  mark: string;
  test_mode: boolean;
};

export type PaymentOptions = {
  service: PaymentService;
  mock: boolean;
  online: boolean;
  methods: PaymentMethodOption[];
  save_card_supported: boolean;
};

export type PaymentIntentStatus = 'pending' | 'processing' | 'paid' | 'authorized' | 'failed' | 'cancelled' | 'refunded' | 'released';

export type PaymentIntent = {
  id: string;
  status: PaymentIntentStatus;
  amount: number;
  currency: string;
  gateway: string;
  reference: string;
  description: string | null;
  payable_type: string | null;
  payable_id: number | null;
  paid_at: string | null;
  capture_mode: 'immediate' | 'hold';
  secured: boolean;
  message: string;
};

export type CheckoutStart = { intent: PaymentIntent; embed_url: string; return_prefix: string };

export const serviceFor = (t: PayableType): PaymentService =>
  t === 'order' ? 'pharmacy' : t === 'ride' || t === 'ride_charge' ? 'ride' : 'invoice';

export async function getPaymentOptions(service: PaymentService) {
  const { data } = await api.get<PaymentOptions>('/payments/options', { params: { service } });
  return data;
}

export async function createPaymentIntent(body: {
  payable_type: PayableType;
  payable_id: number;
  gateway: string;
  idempotency_key: string;
  save_card?: boolean;
}) {
  const { data } = await api.post<CheckoutStart>('/payments/intents', body);
  return data;
}

export async function subscribeToPlan(body: { plan_id: number; gateway: string; idempotency_key: string; subject_type?: string; subject_id?: number }) {
  const { data } = await api.post<CheckoutStart & { subscription: unknown }>('/subscriptions', body);
  return data;
}

export async function getPaymentIntent(id: string) {
  const { data } = await api.get<{ intent: PaymentIntent }>(`/payments/intents/${id}`);
  return data.intent;
}

export async function cancelPaymentIntent(id: string) {
  try {
    await api.post(`/payments/intents/${id}/cancel`);
  } catch {
    // best effort
  }
}

export type SubscriptionPlan = {
  id: number;
  name: string;
  description: string | null;
  scope: string;
  amount: number;
  frequency: string;
  frequency_label: string;
  cycles: number;
  discount_percent: number | null;
  benefits: string[];
};

export async function getSubscriptionPlans(scope?: string) {
  const { data } = await api.get<{ data: SubscriptionPlan[] }>('/subscription-plans', { params: scope ? { scope } : {} });
  return data.data;
}

export const isSecured = (s: PaymentIntentStatus) => s === 'paid' || s === 'authorized';
