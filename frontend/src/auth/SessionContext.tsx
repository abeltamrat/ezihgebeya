import { useEffect, useState, type ReactNode } from 'react';
import { api, setCsrfToken } from '../api/client';
import { registerPushIfConfigured } from '../push';
import { applyServerTheme } from '../theme';
import { SessionContext, type SessionUser, type ShellState } from './session';

// Firebase's client-side web config is designed to be public (it identifies the project,
// it is not a secret) — see app/notify.php's server-side fcm_service_account_json for the
// credential that must never reach the browser.
interface MeResponse {
  ok: true;
  authenticated: boolean;
  csrf_token: string;
  user?: SessionUser;
  shell: ShellState;
}

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
      applyServerTheme(data.shell.theme);
      if (data.authenticated) void registerPushIfConfigured(data.shell.fcm_web_config);
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
