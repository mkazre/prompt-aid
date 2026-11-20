import { api } from '../api/client';

/**
 * Who can sign up from the app (backend: App\Services\Onboarding\ProviderSignup,
 * switched on/off in Staff → Site content → Sign up & sign in). The provider
 * types, their fields and labels come from the server, so they change without
 * an app update.
 */
export type ProviderField = 'business_name' | 'registration_no' | 'hpcsa_sapc_no' | 'specialization' | 'address' | 'city' | 'category';

export interface ProviderType {
  key: string;
  label: string;
  description: string;
  icon: string;
  fields: ProviderField[];
  required: ProviderField[];
  business_label: string;
  business_required: boolean;
  number_label: string;
}

export interface SignupOptions {
  patient_open: boolean;
  driver_open: boolean;
  types: ProviderType[];
  categories: { key: string; label: string }[];
  intro: string;
  review_note: string;
  documents_hint: string;
}

/** Used when the server can't be reached (older backend / offline): patients and drivers only. */
export const FALLBACK_OPTIONS: SignupOptions = {
  patient_open: true,
  driver_open: true,
  types: [],
  categories: [],
  intro: '',
  review_note: '',
  documents_hint: '',
};

let cache: SignupOptions | null = null;

export async function getSignupOptions(): Promise<SignupOptions> {
  try {
    const { data } = await api.get('/auth/signup-options', { timeout: 8000 });
    if (data?.data && Array.isArray(data.data.types)) {
      cache = data.data as SignupOptions;
      return cache;
    }
  } catch {
    /* fall through */
  }
  return cache ?? FALLBACK_OPTIONS;
}

export function cachedSignupOptions(): SignupOptions | null {
  return cache;
}
