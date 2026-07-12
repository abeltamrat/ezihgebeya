import type { ReactNode } from 'react';
import { useSession } from './SessionContext';

/**
 * Guards a route behind an authenticated session with an allowed account_type. This is a UX
 * convenience only — per the Decision, authorization is enforced server-side on every request
 * (v1_require_role/v1_require_owner in pages/api_v1.php); a client-side check can never replace
 * that, it just avoids flashing a screen the API would reject anyway.
 */
export function ProtectedRoute({ children, roles }: { children: ReactNode; roles?: string[] }) {
  const { loading, authenticated, user } = useSession();

  if (loading) {
    return (
      <div className="container section">
        <p className="muted">Loading…</p>
      </div>
    );
  }

  if (!authenticated) {
    const returnTo = encodeURIComponent(window.location.pathname);
    window.location.href = `/login?return=${returnTo}`;
    return null;
  }

  if (roles && user && !roles.includes(user.account_type)) {
    return (
      <div className="container section">
        <div className="alert alert-error">Not authorized for this account type.</div>
      </div>
    );
  }

  return <>{children}</>;
}
