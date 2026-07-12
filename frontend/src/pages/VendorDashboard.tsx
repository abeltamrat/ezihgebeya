import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { vendorApi } from '../api/vendor';
import { DashLayout } from '../components/DashLayout';

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

export function VendorDashboard() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['vendor', 'dashboard'],
    queryFn: vendorApi.dashboard,
  });

  return (
    <DashLayout>
      <h1>Vendor Dashboard</h1>
      {isLoading && <p className="muted">Loading…</p>}
      {error && <div className="alert alert-error">Could not load the dashboard. Try refreshing.</div>}

      {data && !data.business && (
        <div className="panel">
          <h2>Welcome! First step: register your business</h2>
          <p className="muted">Create your business profile so customers can find and trust you.</p>
        </div>
      )}

      {data?.business && (
        <>
          {data.business.status === 'pending' && (
            <div className="alert" style={{ background: 'var(--blue-soft)', color: 'var(--blue)', marginBottom: 16 }}>
              Your business "{data.business.name}" is pending admin approval.
            </div>
          )}

          <div className="stat-grid">
            {Object.entries(data.stats ?? {}).map(([key, n]) => (
              <div className="stat-card" key={key}>
                <div className="stat-num">{n}</div>
                <div className="stat-label">{STAT_LABELS[key] ?? key}</div>
              </div>
            ))}
          </div>

          <div className="panel">
            <h3>Quick actions</h3>
            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
              <Link className="btn btn-primary" to="/vendor/listings/product">
                Manage products
              </Link>
              <Link className="btn btn-outline" to="/vendor/listings/service">
                Manage services
              </Link>
              <Link className="btn btn-outline" to="/vendor/listings/supply">
                Manage supplies
              </Link>
            </div>
          </div>

          <div className="panel">
            <h3>Latest inquiries</h3>
            {(data.recent_inquiries ?? []).length === 0 && <p className="muted">No inquiries yet.</p>}
            {(data.recent_inquiries ?? []).map((i) => (
              <div key={i.id} style={{ padding: '10px 0', borderBottom: '1px solid var(--line)' }}>
                <strong>{i.name ?? 'Customer'}</strong> · {i.phone}{' '}
                <span className={`badge badge-status-${i.status}`}>{i.status}</span>
                <div className="muted small">
                  {i.listing_title ?? i.listing_type} · {new Date(i.created_at).toLocaleDateString()}
                </div>
                <p style={{ margin: '4px 0 0' }}>{i.message}</p>
              </div>
            ))}
          </div>
        </>
      )}
    </DashLayout>
  );
}
