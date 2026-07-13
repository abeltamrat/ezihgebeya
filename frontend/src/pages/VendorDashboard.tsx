import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { vendorApi } from '../api/vendor';
import { DashLayout } from '../components/DashLayout';
import { useSession } from '../auth/session';

const STAT_LABELS: Record<string, string> = {
  products: 'Products',
  services: 'Services',
  supplies: 'Supplies',
  videos: 'Videos',
  new_orders: 'New orders',
  new_inquiries: 'New inquiries',
  total_inquiries: 'Total inquiries',
  product_views: 'Product views',
};

const STAT_ICONS: Record<string, string> = {
  products: '▦',
  services: '◈',
  supplies: '◫',
  videos: '▶',
  new_orders: '✓',
  new_inquiries: '✉',
  total_inquiries: '☰',
  product_views: '↗',
};

export function VendorDashboard() {
  const { shell } = useSession();
  const { data, isLoading, error } = useQuery({
    queryKey: ['vendor', 'dashboard'],
    queryFn: vendorApi.dashboard,
  });

  return (
    <DashLayout>
      <div className="page-title">
        <div>
          <p className="eyebrow">Vendor workspace</p>
          <h1>Dashboard</h1>
        </div>
        <Link className="btn btn-primary" to="/vendor/listings/product/new">
          Add listing
        </Link>
      </div>
      {isLoading && <p className="muted">Loading…</p>}
      {error && <div className="alert alert-error">Could not load the dashboard. Try refreshing.</div>}

      {data && !data.business && (
        <div className="panel">
          <h2>Welcome! First step: register your business</h2>
          <p className="muted">Create your business profile so customers can find and trust you.</p>
          <Link className="btn btn-primary" to="/vendor/business">
            Create business profile
          </Link>
        </div>
      )}

      {data?.business && (
        <>
          {data.business.status === 'pending' && (
            <div className="alert alert-info mb-3">
              Your business "{data.business.name}" is pending admin approval.
            </div>
          )}

          <div className="panel dashboard-hero">
            <div>
              <p className="eyebrow">Shop profile</p>
              <h2>{data.business.name}</h2>
              <p className="muted">
                {data.business.city} · {data.business.plan} plan · status: <span className={`badge badge-status-${data.business.status}`}>{data.business.status}</span>
              </p>
            </div>
            <div className="btn-row">
              <Link className="btn btn-primary" to="/vendor/listings/product/new">
                Post new listing
              </Link>
              <Link className="btn btn-outline" to="/vendor/business">
                Edit profile
              </Link>
              {shell?.public_business_url ? <a className="btn btn-outline" href={shell.public_business_url}>Public shop ↗</a> : null}
            </div>
          </div>

          <div className="stat-grid">
            {Object.entries(data.stats ?? {}).map(([key, n]) => (
              <div className="stat-card metric-card" key={key}>
                <div className="metric-icon" aria-hidden="true">{STAT_ICONS[key] ?? '•'}</div>
                <div>
                  <div className="stat-num">{n}</div>
                  <div className="stat-label">{STAT_LABELS[key] ?? key}</div>
                </div>
              </div>
            ))}
          </div>

          <div className="panel">
            <div className="panel-title-row">
              <div>
                <p className="eyebrow">Next steps</p>
                <h3>Quick actions</h3>
              </div>
            </div>
            <div className="quick-action-grid">
              <Link className="btn btn-primary" to="/vendor/listings/product">
                Manage products
              </Link>
              <Link className="btn btn-outline" to="/vendor/listings/service">
                Manage services
              </Link>
              <Link className="btn btn-outline" to="/vendor/listings/supply">
                Manage supplies
              </Link>
              <Link className="btn btn-outline" to="/vendor/inquiries">
                View inquiries
              </Link>
              <Link className="btn btn-outline" to="/vendor/orders">
                View orders
              </Link>
              <Link className="btn btn-outline" to="/vendor/videos">
                Manage videos
              </Link>
              <Link className="btn btn-outline" to="/vendor/verification">
                Verification
              </Link>
              <Link className="btn btn-outline" to="/vendor/reviews">
                Reviews
              </Link>
              <Link className="btn btn-outline" to="/vendor/analytics">
                Analytics
              </Link>
            </div>
          </div>

          <div className="panel">
            <div className="panel-title-row">
              <div>
                <p className="eyebrow">Customer activity</p>
                <h3>Latest inquiries</h3>
              </div>
              <Link className="btn btn-outline btn-sm" to="/vendor/inquiries">View all</Link>
            </div>
            {(data.recent_inquiries ?? []).length === 0 && <p className="muted">No inquiries yet.</p>}
            {(data.recent_inquiries ?? []).map((i) => (
              <div className="activity-row" key={i.id}>
                <div className="activity-avatar">{(i.name ?? 'C').slice(0, 1).toUpperCase()}</div>
                <div>
                <strong>{i.name ?? 'Customer'}</strong> · {i.phone}{' '}
                <span className={`badge badge-status-${i.status}`}>{i.status}</span>
                <div className="muted small">
                  {i.listing_title ?? i.listing_type} · {new Date(i.created_at).toLocaleDateString()}
                </div>
                <p className="activity-message">{i.message}</p>
                </div>
              </div>
            ))}
          </div>
        </>
      )}
    </DashLayout>
  );
}
