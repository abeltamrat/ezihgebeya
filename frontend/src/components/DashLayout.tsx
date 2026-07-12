import type { ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { useSession } from '../auth/SessionContext';

const NAV = [
  { to: '/vendor', label: 'Dashboard', end: true },
  { to: '/vendor/listings/product', label: 'Products' },
  { to: '/vendor/listings/service', label: 'Services' },
  { to: '/vendor/listings/supply', label: 'Supplies' },
];

export function DashLayout({ children }: { children: ReactNode }) {
  const { user, shell } = useSession();
  const notificationCount = shell?.notification_count ?? 0;
  const cartCount = shell?.cart_count ?? 0;

  return (
    <div className="container section">
      <div className="app-shellbar" aria-label="Account status">
        <div>
          <a className="app-brand" href={shell?.home_url ?? '/'}>
            EzihGebeya
          </a>
          <span className="muted small">
            {user ? `${user.name} · ${shell?.account_label ?? 'Dashboard'}` : 'Dashboard'}
          </span>
        </div>
        <nav className="app-shelllinks" aria-label="Account shortcuts">
          <a href={shell?.browse_url ?? '/products'}>Browse</a>
          <a className="btn btn-primary btn-sm" href={shell?.sell_url ?? '/register'}>
            {shell?.sell_label ?? 'Sell / Join'}
          </a>
          {shell?.notifications_url ? (
            <a href={shell.notifications_url} aria-label={`Notifications${notificationCount ? ` (${notificationCount} unread)` : ''}`}>
              Notifications{notificationCount ? <span className="mini-pill">{notificationCount}</span> : null}
            </a>
          ) : null}
          {shell?.cart_enabled ? (
            <a href={shell.cart_url} aria-label={`Cart${cartCount ? ` (${cartCount} items)` : ''}`}>
              Cart{cartCount ? <span className="mini-pill">{cartCount}</span> : null}
            </a>
          ) : null}
          {shell?.account_url ? <a href={shell.account_url}>{shell.account_label ?? 'Account'}</a> : null}
          {shell?.logout_url ? (
            <a className="danger-link" href={shell.logout_url}>
              Log out
            </a>
          ) : null}
        </nav>
      </div>

      <div className="dash-layout">
        <aside className="dash-nav">
          {NAV.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) => (isActive ? 'current' : '')}
            >
              {item.label}
            </NavLink>
          ))}
          {shell?.business_profile_url ? <a href={shell.business_profile_url}>Business profile ↗</a> : null}
          {shell?.public_business_url ? <a href={shell.public_business_url}>Public shop ↗</a> : null}
        </aside>
        <div className="dash-main">{children}</div>
      </div>
    </div>
  );
}
