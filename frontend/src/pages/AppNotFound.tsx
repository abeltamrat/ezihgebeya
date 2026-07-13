import { useSession } from '../auth/session';
import { dashboardPath } from '../auth/dashboardPath';

export function AppNotFound() {
  const { loading, authenticated, user, shell } = useSession();

  if (loading) return <div className="container section"><p className="muted">Loading…</p></div>;

  const dashboard = authenticated ? dashboardPath(user?.account_type) : (shell?.login_url ?? '/login');
  const dashboardLabel = authenticated ? 'Go to dashboard' : 'Log in';

  return (
    <main className="container section">
      <section className="panel empty-state" aria-labelledby="app-not-found-title">
        <span className="eyebrow">Error 404</span>
        <h1 id="app-not-found-title">This app page does not exist</h1>
        <p className="muted">The link may be outdated, or the workflow may have moved.</p>
        <div className="action-row">
          <a className="btn btn-primary" href={dashboard}>{dashboardLabel}</a>
          <a className="btn btn-outline" href={shell?.home_url ?? '/'}>Marketplace home</a>
        </div>
      </section>
    </main>
  );
}
