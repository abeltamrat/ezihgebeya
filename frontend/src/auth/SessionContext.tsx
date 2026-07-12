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

interface MeResponse {
  ok: true;
  authenticated: boolean;
  csrf_token: string;
  user?: SessionUser;
}

interface SessionState {
  loading: boolean;
  authenticated: boolean;
  user: SessionUser | null;
  refresh: () => Promise<void>;
}

const SessionContext = createContext<SessionState | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [loading, setLoading] = useState(true);
  const [authenticated, setAuthenticated] = useState(false);
  const [user, setUser] = useState<SessionUser | null>(null);

  const refresh = async () => {
    setLoading(true);
    try {
      const data = await api.get<MeResponse>('/me');
      setCsrfToken(data.csrf_token);
      setAuthenticated(data.authenticated);
      setUser(data.user ?? null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    refresh();
  }, []);

  return (
    <SessionContext.Provider value={{ loading, authenticated, user, refresh }}>
      {children}
    </SessionContext.Provider>
  );
}

export function useSession(): SessionState {
  const ctx = useContext(SessionContext);
  if (!ctx) throw new Error('useSession must be used within a SessionProvider');
  return ctx;
}
