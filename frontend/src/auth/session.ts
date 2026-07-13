import { createContext, useContext } from 'react';

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
  fcm_web_config?: FcmWebConfig | null;
  // System UI Optimizer design tokens (CSS custom property → value), applied to :root
  // by applyServerTheme() so admin re-theming reaches the SPA. See system_ui_css_vars().
  theme?: Record<string, string> | null;
}

export interface FcmWebConfig {
  apiKey: string;
  authDomain: string;
  projectId: string;
  storageBucket?: string;
  messagingSenderId: string;
  appId: string;
  vapidKey: string;
}

export interface SessionState {
  loading: boolean;
  authenticated: boolean;
  user: SessionUser | null;
  shell: ShellState | null;
  refresh: () => Promise<void>;
}

export const SessionContext = createContext<SessionState | null>(null);

export function useSession(): SessionState {
  const context = useContext(SessionContext);
  if (!context) throw new Error('useSession must be used within a SessionProvider');
  return context;
}
