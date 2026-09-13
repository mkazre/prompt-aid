import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { api, apiErrorMessage, clearToken, getToken, saveToken } from '../api/client';
import { Role, User } from '../api/types';

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (data: { name: string; email: string; phone?: string; password: string; role: Role }) => Promise<void>;
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

  async function register(payload: { name: string; email: string; phone?: string; password: string; role: Role }) {
    const { data } = await api.post('/auth/register', payload);
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
