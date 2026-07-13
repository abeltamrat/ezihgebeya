import { useQuery } from '@tanstack/react-query';
import { adminApi } from '../api/admin';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

function valueOrDash(value: number | null, suffix = '') {
  return value === null ? '—' : `${value}${suffix}`;
}

export function AdminHealth() {
  const health = useQuery({ queryKey: ['admin', 'health'], queryFn: adminApi.health });
  const data = health.data;

  return (
    <DashLayout>
      <div className="section-head">
        <div>
          <h1>Marketplace health</h1>
          <p className="muted">A React operations view for the new capability layer; the full legacy admin remains in PHP.</p>
        </div>
        <a className="btn btn-outline" href="/admin/analytics">
          PHP analytics
        </a>
      </div>

      {health.isLoading ? <p className="muted">Loading…</p> : null}
      {health.error ? (
        <div className="alert alert-error">
          {health.error instanceof ApiError ? health.error.message : 'Could not load marketplace health.'}
        </div>
      ) : null}

      {data ? (
        <>
          <section className="panel">
            <h2>Commerce</h2>
            <div className="stat-grid">
              <div className="stat-card"><div className="stat-num">{data.commerce.gmv_30d_formatted}</div><div className="stat-label">GMV, last 30 days</div></div>
              <div className="stat-card"><div className="stat-num">{data.commerce.orders_30d}</div><div className="stat-label">Orders, last 30 days</div></div>
              <div className="stat-card"><div className="stat-num">{data.commerce.aov_30d_formatted}</div><div className="stat-label">Average order value</div></div>
              <div className="stat-card"><div className="stat-num">{data.commerce.platform_revenue_mtd_formatted}</div><div className="stat-label">Platform revenue, MTD</div></div>
              <div className="stat-card"><div className="stat-num">{data.commerce.payment_backlog_formatted}</div><div className="stat-label">Payment backlog ({data.commerce.payment_backlog_count})</div></div>
            </div>
            <div className="table-wrap">
              <table className="data-table">
                <tbody>
                  <tr><td>Active / completed orders</td><td>{data.commerce.active_orders_30d} / {data.commerce.completed_orders_30d}</td></tr>
                  <tr><td>Promotion revenue</td><td>{data.commerce.promotion_revenue_mtd_formatted}</td></tr>
                  <tr><td>Subscription revenue</td><td>{data.commerce.subscription_revenue_mtd_formatted}</td></tr>
                </tbody>
              </table>
            </div>
          </section>

          <section className="grid-2">
            <div className="panel">
              <h2>Supply</h2>
              <div className="stack-list">
                <div className="bar-row"><span>Active vendors</span><b>{data.supply.active_vendors}</b></div>
                <div className="bar-row"><span>Active listings</span><b>{data.supply.active_listings}</b></div>
                <div className="bar-row"><span>Listings per active vendor</span><b>{data.supply.avg_listings_per_vendor}</b></div>
                <div className="bar-row"><span>New-vendor activation</span><b>{valueOrDash(data.supply.activation_rate_30d, '%')}</b></div>
                <div className="bar-row"><span>Activated / new vendors, 30d</span><b>{data.supply.activated_new_vendors_30d} / {data.supply.new_vendors_30d}</b></div>
              </div>
            </div>

            <div className="panel">
              <h2>Liquidity</h2>
              <div className="stack-list">
                <div className="bar-row"><span>Median time to first inquiry</span><b>{valueOrDash(data.liquidity.median_first_inquiry_hours, ' hrs')}</b></div>
                <div className="bar-row"><span>Zero-traction share</span><b>{valueOrDash(data.liquidity.zero_traction_share, '%')}</b></div>
                <div className="bar-row"><span>Zero / older active listings</span><b>{data.liquidity.zero_traction_older_listings} / {data.liquidity.older_active_listings}</b></div>
              </div>
            </div>
          </section>

          <section className="grid-2">
            <div className="panel">
              <h2>Demand</h2>
              {data.demand.top_searches.length === 0 ? <p className="muted">No search data yet.</p> : null}
              {data.demand.top_searches.map((search) => (
                <div className="bar-row" key={search.query}>
                  <span>{search.query}</span>
                  <b>{search.searches}{search.zeroes ? ` · ${search.zeroes} zero` : ''}</b>
                </div>
              ))}
              {data.demand.zero_searches.length ? <h3>Zero-result searches</h3> : null}
              {data.demand.zero_searches.map((search) => (
                <div className="bar-row" key={`${search.query}:${search.last_seen}`}>
                  <span>{search.query}</span>
                  <b>{search.zeroes}</b>
                </div>
              ))}
            </div>

            <div className="panel">
              <h2>Trust</h2>
              <div className="stack-list">
                <div className="bar-row"><span>Reports, 30d / 7d</span><b>{data.trust.reports_30d} / {data.trust.reports_7d}</b></div>
                <div className="bar-row"><span>Open reports</span><b>{data.trust.open_reports}</b></div>
                <div className="bar-row"><span>Closed reports, 30d</span><b>{data.trust.closed_reports_30d}</b></div>
                <div className="bar-row"><span>Suspicious flags</span><b>{data.trust.suspicious_flags}</b></div>
              </div>
              <div className="table-wrap">
                <table className="data-table">
                  <tbody>
                    {Object.entries(data.trust.suspicious_breakdown).map(([key, count]) => (
                      <tr key={key}>
                        <td>{key.replaceAll('_', ' ')}</td>
                        <td>{count}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </section>
        </>
      ) : null}
    </DashLayout>
  );
}
