import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { api, setCsrfToken } from '../api/client';

export interface SessionUser {
  id: number;
  name: string;
  phone: string;
  email: string | null;
  account_type: string;
  phone_verified: boolean;
}

export interface ShellState {
  authenticated: boolean;
  home_url: string;
  browse_url: string;
  cart_url: string;
  cart_count: number;
  cart_enabled: boolean;
  sell_url: string;
  sell_label: string;
  login_url?: string;
  register_url?: string;
  account_url?: string;
  account_label?: string;
  notifications_url?: string;
  notification_count?: number;
  logout_url?: string;
  business_profile_url?: string | null;
  public_business_url?: string | null;
}

interface MeResponse {
  ok: true;
  authenticated: boolean;
  csrf_token: string;
  user?: SessionUser;
  shell: ShellState;
}

interface SessionState {
  loading: boolean;
  authenticated: boolean;
  user: SessionUser | null;
  shell: ShellState | null;
  refresh: () => Promise<void>;
}

const SessionContext = createContext<SessionState | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [loading, setLoading] = useState(true);
  const [authenticated, setAuthenticated] = useState(false);
  const [user, setUser] = useState<SessionUser | null>(null);
  const [shell, setShell] = useState<ShellState | null>(null);

  const refresh = async () => {
    setLoading(true);
    try {
      const data = await api.get<MeResponse>('/me');
      setCsrfToken(data.csrf_token);
      setAuthenticated(data.authenticated);
      setUser(data.user ?? null);
      setShell(data.shell);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    refresh();
  }, []);

  return (
    <SessionContext.Provider value={{ loading, authenticated, user, shell, refresh }}>
      {children}
    </SessionContext.Provider>
  );
}

export function useSession(): SessionState {
  const ctx = useContext(SessionContext);
  if (!ctx) throw new Error('useSession must be used within a SessionProvider');
  return ctx;
}
