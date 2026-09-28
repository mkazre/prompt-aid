import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { api, apiErrorMessage, clearToken, getToken, saveToken } from '../api/client';
import { Role, User } from '../api/types';

/**
 * Account fields are the only ones RegisterScreen's driver path fills in;
 * the patient wizard (RegisterMedicalScreen -> RegisterSchemeScreen ->
 * RegisterDocumentScreen) accumulates the rest via navigation params and
 * only calls `register` once, on its final screen — mirrors the web
 * wizard's session-then-finalize shape without needing session storage
 * here, since React Navigation params already hold the state between
 * screens.
 */
export interface RegisterPayload {
  name: string;
  email: string;
  phone?: string;
  password: string;
  role: Role;
  dob?: string;
  gender?: string;
  blood_group?: string;
  address?: string;
  allergies?: string;
  chronic_conditions?: string;
  emergency_contact_name?: string;
  emergency_contact_phone?: string;
  medical_scheme_id?: number;
  member_number?: string;
  dependant_code?: string;
  main_member_name?: string;
  document?: { uri: string; name: string; mimeType?: string | null };
  document_type?: 'id' | 'medical_aid_card';
}

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (data: RegisterPayload) => Promise<void>;
  logout: () => Promise<void>;
  refreshMe: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  async function refreshMe() {
    const { data } = await api.get<{ data: User }>('/auth/me');
    setUser(data.data);
  }

  useEffect(() => {
    (async () => {
      const token = await getToken();
      if (token) {
        try {
          await refreshMe();
        } catch {
          await clearToken();
        }
      }
      setLoading(false);
    })();
  }, []);

  async function login(email: string, password: string) {
    const { data } = await api.post('/auth/login', { email, password });
    await saveToken(data.token);
    setUser(data.user);
  }

  async function register(payload: RegisterPayload) {
    const { document, ...fields } = payload;
    let body: unknown = fields;
    let headers: Record<string, string> | undefined;

    if (document) {
      const form = new FormData();
      Object.entries(fields).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') form.append(key, String(value));
      });
      form.append('document', { uri: document.uri, name: document.name, type: document.mimeType ?? 'application/octet-stream' } as any);
      body = form;
      headers = { 'Content-Type': 'multipart/form-data' };
    }

    const { data } = await api.post('/auth/register', body, { headers });
    await saveToken(data.token);
    setUser(data.user);
  }

  async function logout() {
    try {
      await api.post('/auth/logout');
    } catch {
      // ignore — clear local state regardless
    }
    await clearToken();
    setUser(null);
  }

  const value = useMemo(() => ({ user, loading, login, register, logout, refreshMe }), [user, loading]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}

export { apiErrorMessage };
