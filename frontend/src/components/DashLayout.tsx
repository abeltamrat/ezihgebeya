import { useState, type ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { useSession } from '../auth/session';
import { publicBase } from '../base';
import { NotificationPopover } from './NotificationPopover';

const VENDOR_ROLES = ['seller', 'manufacturer', 'importer', 'service_provider', 'supplier'];
const ADMIN_ROLES = ['admin', 'super_admin'];

interface NavItem { to: string; label: string; icon: string; group: string; end?: boolean; external?: boolean }

const VENDOR_NAV: NavItem[] = [
  { to: '/vendor', label: 'Dashboard', icon: 'home', group: 'Overview', end: true },
  { to: '/vendor/business', label: 'Business profile', icon: 'shop', group: 'Manage' },
  { to: '/vendor/listings/product', label: 'Products', icon: 'box', group: 'Manage' },
  { to: '/vendor/listings/service', label: 'Services', icon: 'tool', group: 'Manage' },
  { to: '/vendor/listings/supply', label: 'Supplies', icon: 'layers', group: 'Manage' },
  { to: '/vendor/inquiries', label: 'Inquiries', icon: 'message', group: 'Sell' },
  { to: '/vendor/orders', label: 'Orders', icon: 'order', group: 'Sell' },
  { to: '/vendor/boost', label: 'Boost & TOP Pin', icon: 'spark', group: 'Grow' },
  { to: '/vendor/videos', label: 'Videos', icon: 'video', group: 'Grow' },
  { to: '/vendor/analytics', label: 'Analytics', icon: 'chart', group: 'Grow' },
  { to: '/vendor/verification', label: 'Verification', icon: 'shield', group: 'Trust' },
  { to: '/vendor/reviews', label: 'Reviews', icon: 'star', group: 'Trust' },
];

const CUSTOMER_NAV: NavItem[] = [
  { to: '/account', label: 'Account', icon: 'home', group: 'Account', end: true },
  { to: '/cart', label: 'Cart', icon: 'cart', group: 'Shopping' },
  { to: '/account/orders', label: 'My orders', icon: 'order', group: 'Shopping' },
  { to: '/account/favorites', label: 'Saved products', icon: 'heart', group: 'Shopping' },
  { to: '/account/inquiries', label: 'My inquiries', icon: 'message', group: 'Activity' },
  { to: '/account/reviews', label: 'Reviews & reports', icon: 'star', group: 'Activity' },
  { to: '/account/notifications', label: 'Notifications', icon: 'bell', group: 'Activity' },
];

const ADMIN_NAV: NavItem[] = [
  { to: '/admin', label: 'Dashboard', icon: 'home', group: 'Overview', external: true },
  { to: '/admin/businesses', label: 'Businesses', icon: 'shop', group: 'Marketplace', external: true },
  { to: '/admin/verification', label: 'Verification', icon: 'shield', group: 'Marketplace', external: true },
  { to: '/admin/listings', label: 'Listings', icon: 'box', group: 'Marketplace', external: true },
  { to: '/admin/videos', label: 'Videos', icon: 'video', group: 'Marketplace', external: true },
  { to: '/admin/reviews', label: 'Reviews', icon: 'star', group: 'Moderation', external: true },
  { to: '/admin/reports', label: 'Reports', icon: 'message', group: 'Moderation', external: true },
  { to: '/admin/users', label: 'Users', icon: 'home', group: 'Administration', external: true },
  { to: '/admin/orders', label: 'Orders', icon: 'order', group: 'Commerce', external: true },
  { to: '/admin/payments', label: 'Payments', icon: 'cart', group: 'Commerce', external: true },
  { to: '/admin/analytics', label: 'Analytics', icon: 'chart', group: 'Operations', external: true },
  { to: '/admin/ads', label: 'Ad Manager', icon: 'spark', group: 'Operations', external: true },
  { to: '/admin/settings', label: 'System Settings', icon: 'tool', group: 'System', external: true },
  { to: '/admin/backups', label: 'Backups', icon: 'layers', group: 'System', external: true },
];

const ICON_PATHS: Record<string, string> = {
  home: 'M3 10.5 12 3l9 7.5V21h-6v-6H9v6H3z', shop: 'M4 10v10h16V10M3 10l2-6h14l2 6M8 20v-6h8v6',
  box: 'm4 7 8-4 8 4-8 4z M4 7v10l8 4 8-4V7 M12 11v10', tool: 'M14 6a4 4 0 0 0-5 5L3 17l4 4 6-6a4 4 0 0 0 5-5l-3 1-2-2z',
  layers: 'm12 3 9 5-9 5-9-5z M3 12l9 5 9-5 M3 16l9 5 9-5', message: 'M4 5h16v12H8l-4 4z',
  order: 'M6 3h12v18H6z M9 8h6 M9 12h6 M9 16h4', spark: 'm12 2 2.2 6.8L21 11l-6.8 2.2L12 20l-2.2-6.8L3 11l6.8-2.2z',
  video: 'M3 6h13v12H3z M16 10l5-3v10l-5-3z', chart: 'M4 20V10 M10 20V4 M16 20v-7 M22 20H2',
  shield: 'M12 3 20 6v6c0 5-3.4 8-8 10-4.6-2-8-5-8-10V6z M8 12l3 3 5-6', star: 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z',
  cart: 'M3 4h2l2 11h10l3-8H6 M9 20h.01 M17 20h.01', heart: 'M12 21S3 16 3 9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 7-9 12-9 12z',
  bell: 'M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
};

function NavIcon({ name }: { name: string }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true"><path d={ICON_PATHS[name]} /></svg>;
}

export function DashLayout({ children }: { children: ReactNode }) {
  const [navOpen, setNavOpen] = useState(false);
  const { user, shell } = useSession();
  const notificationCount = shell?.notification_count ?? 0;
  const cartCount = shell?.cart_count ?? 0;
  const nav = user && ADMIN_ROLES.includes(user.account_type) ? ADMIN_NAV : user && VENDOR_ROLES.includes(user.account_type) ? VENDOR_NAV : CUSTOMER_NAV;

  return (
    <div className="container section">
      <a className="skip-link" href="#dashboard-content">Skip to content</a>
      <header className="app-shellbar" aria-label="Account status">
        <div className="app-identity">
          <a className="app-brand" href={shell?.home_url ?? '/'}><span className="app-brand-mark">EG</span><span>EzihGebeya</span></a>
          <span className="muted small">{user ? `${user.name} · ${shell?.account_label ?? 'Dashboard'}` : 'Dashboard'}</span>
        </div>
        <nav className="app-shelllinks" aria-label="Account shortcuts">
          <a href={shell?.browse_url ?? '/products'}>Browse</a>
          <a className="btn btn-primary btn-sm" href={shell?.sell_url ?? '/register'}>{shell?.sell_label ?? 'Sell / Join'}</a>
          {shell?.notifications_url ? <NotificationPopover initialCount={notificationCount} allUrl={shell.notifications_url} /> : null}
          {shell?.cart_enabled ? <a href={shell.cart_url}>Cart{cartCount ? <span className="mini-pill">{cartCount}</span> : null}</a> : null}
          {shell?.logout_url ? <a className="danger-link" href={shell.logout_url}>Log out</a> : null}
        </nav>
      </header>

      <div className="dash-layout">
        <button className="dash-nav-toggle" type="button" aria-expanded={navOpen} aria-controls="dashboard-navigation" onClick={() => setNavOpen((open) => !open)}>
          <span>Dashboard menu</span><span aria-hidden="true">{navOpen ? 'Close' : 'Open'}</span>
        </button>
        <aside id="dashboard-navigation" className={`dash-nav${navOpen ? ' is-open' : ''}`} aria-label="Dashboard navigation">
          {nav.map((item, index) => (
            <div className="dash-nav-item" key={item.to}>
              {index === 0 || nav[index - 1].group !== item.group ? <p className="dash-nav-group">{item.group}</p> : null}
              {item.external ? (
                <a href={`${publicBase}${item.to}`} onClick={() => setNavOpen(false)}>
                  <span className="dash-nav-icon"><NavIcon name={item.icon} /></span><span>{item.label}</span>
                </a>
              ) : (
                <NavLink to={item.to} end={item.end} onClick={() => setNavOpen(false)} className={({ isActive }) => isActive ? 'current' : ''}>
                  <span className="dash-nav-icon"><NavIcon name={item.icon} /></span><span>{item.label}</span>
                </NavLink>
              )}
            </div>
          ))}
          {shell?.public_business_url ? <a href={shell.public_business_url}>Public shop ↗</a> : null}
        </aside>
        <main id="dashboard-content" className="dash-main" tabIndex={-1}>{children}</main>
      </div>
    </div>
  );
}
