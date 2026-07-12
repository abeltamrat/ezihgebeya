import type { ReactNode } from 'react';
import { NavLink } from 'react-router-dom';

const NAV = [
  { to: '/vendor', label: 'Dashboard', end: true },
  { to: '/vendor/listings/product', label: 'Products' },
  { to: '/vendor/listings/service', label: 'Services' },
  { to: '/vendor/listings/supply', label: 'Supplies' },
];

export function DashLayout({ children }: { children: ReactNode }) {
  return (
    <div className="container section dash-layout">
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
        <a href="/vendor/business">Business profile ↗</a>
      </aside>
      <div className="dash-main">{children}</div>
    </div>
  );
}
