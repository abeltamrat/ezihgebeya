import { useQuery } from '@tanstack/react-query';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

function formatStat(value: number | null, formatted?: string, suffix?: string) {
  if (formatted) return formatted;
  if (value === null) return '—';
  return `${Number(value).toLocaleString()}${suffix ?? ''}`;
}

function UpsellPanel({ title, minTier, upgradeUrl }: { title: string; minTier: string; upgradeUrl: string }) {
  return (
    <div className="panel">
      <h2>{title}</h2>
      <p className="muted">
        Unlock this with {minTier}. <a href={upgradeUrl}>See Boost tiers →</a>
      </p>
    </div>
  );
}

export function VendorAnalytics() {
  const analytics = useQuery({ queryKey: ['vendor', 'analytics'], queryFn: vendorApi.analytics });
  const data = analytics.data;

  return (
    <DashLayout>
      <h1>Analytics</h1>
      {analytics.isLoading && <p className="muted">Loading…</p>}
      {analytics.error && (
        <div className="alert alert-error">{analytics.error instanceof ApiError ? analytics.error.message : 'Could not load analytics.'}</div>
      )}

      {data && (
        <>
          <div className="stat-grid">
            {data.totals.map((item) => (
              <div className="stat-card" key={item.label}>
                <div className="stat-num">{formatStat(item.value, item.formatted, item.suffix)}</div>
                <div className="stat-label">{item.label}</div>
              </div>
            ))}
          </div>

          <div className="panel">
            <h2>30-day funnel</h2>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr><th>Step</th><th>Count</th><th>Drop-off</th></tr>
                </thead>
                <tbody>
                  {data.funnel.map((step) => (
                    <tr key={step.label}>
                      <td>{step.label}</td>
                      <td>{step.count.toLocaleString()}</td>
                      <td>{step.dropoff_percent === null ? '—' : `${step.dropoff_percent}% drop`}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {data.money ? (
            <div className="panel">
              <h2>Money metrics, last 30 days</h2>
              <div className="stat-grid">
                <div className="stat-card"><div className="stat-num">{data.money.order_revenue_30d_formatted}</div><div className="stat-label">Order value</div></div>
                <div className="stat-card"><div className="stat-num">{data.money.average_order_value_30d_formatted}</div><div className="stat-label">Average order value</div></div>
                <div className="stat-card"><div className="stat-num">{data.money.promotion_spend_30d_formatted}</div><div className="stat-label">Promotion spend</div></div>
                <div className="stat-card"><div className="stat-num">{data.money.promoted_inquiries_30d} / {data.money.promoted_orders_30d}</div><div className="stat-label">Attributed inquiries / orders</div></div>
              </div>
            </div>
          ) : (
            <UpsellPanel title="Money metrics, last 30 days" minTier="Boost Basic" upgradeUrl={data.upgrade_url} />
          )}

          {data.analytics_level === 'full' ? (
            <div className="panel">
              <h2>Per-listing drill-down</h2>
              {data.listings.length === 0 ? <p className="muted">No listings yet.</p> : (
                <div className="table-wrap">
                  <table className="data-table">
                    <thead>
                      <tr><th>Listing</th><th>Status</th><th>30d views</th><th>7d views</th><th>30d saves</th><th>30d inquiries</th><th>7d inquiries</th><th>Orders</th><th>Revenue</th></tr>
                    </thead>
                    <tbody>
                      {data.listings.map((listing) => (
                        <tr key={`${listing.listing_type}-${listing.id}`}>
                          <td><span className="badge">{listing.listing_type}</span> {listing.title}</td>
                          <td>{listing.status}</td>
                          <td>{listing.views30.toLocaleString()}</td>
                          <td>{listing.views7.toLocaleString()}</td>
                          <td>{listing.favorites30.toLocaleString()}</td>
                          <td>{listing.inquiries30.toLocaleString()}</td>
                          <td>{listing.inquiries7.toLocaleString()}</td>
                          <td>{listing.orders30.toLocaleString()}</td>
                          <td>{listing.revenue30_formatted}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          ) : (
            <UpsellPanel title="Per-listing drill-down" minTier="Boost Pro or Boost Max" upgradeUrl={data.upgrade_url} />
          )}

          {data.reviews ? (
            <div className="panel">
              <h2>Reviews and response metrics</h2>
              <div className="stat-grid">
                <div className="stat-card"><div className="stat-num">{data.reviews.average_rating === null ? '—' : `${data.reviews.average_rating.toFixed(1)}/5`}</div><div className="stat-label">Average rating</div></div>
                <div className="stat-card"><div className="stat-num">{data.reviews.reviews_30d.toLocaleString()}</div><div className="stat-label">Reviews, 30d</div></div>
                <div className="stat-card"><div className="stat-num">{data.reviews.average_rating_30d === null ? '—' : `${data.reviews.average_rating_30d.toFixed(1)}/5`}</div><div className="stat-label">Rating trend, 30d</div></div>
                <div className="stat-card"><div className="stat-num">{data.reviews.median_response_label ?? '—'}</div><div className="stat-label">Median inquiry response</div></div>
              </div>
            </div>
          ) : (
            <UpsellPanel title="Reviews and response metrics" minTier="Boost Pro or Boost Max" upgradeUrl={data.upgrade_url} />
          )}

          {data.analytics_level === 'full' ? (
            <>
              <div className="panel">
                <h2>Leads by source</h2>
                {data.lead_sources.length === 0 ? <p className="muted">No inquiries yet.</p> : data.lead_sources.map((row) => (
                  <div className="bar-row" key={row.source}><span>{row.source.replaceAll('_', ' ')}</span><b>{row.count}</b></div>
                ))}
              </div>

              <div className="panel">
                <h2>Leads by status</h2>
                {data.lead_statuses.length === 0 ? <p className="muted">No inquiries yet.</p> : data.lead_statuses.map((row) => (
                  <div className="bar-row" key={row.status}><span>{row.status}</span><b>{row.count}</b></div>
                ))}
              </div>

              <div className="panel">
                <h2>Revenue by listing</h2>
                {data.revenue_by_listing.length === 0 ? <p className="muted">No listing-level order revenue in the last 30 days yet.</p> : (
                  <div className="table-wrap">
                    <table className="data-table">
                      <thead><tr><th>Listing</th><th>Type</th><th>Orders</th><th>Revenue</th></tr></thead>
                      <tbody>{data.revenue_by_listing.map((row) => (
                        <tr key={`${row.listing_type}-${row.listing_id}`}><td>{row.title}</td><td>{row.listing_type}</td><td>{row.orders_count}</td><td>{row.revenue_formatted}</td></tr>
                      ))}</tbody>
                    </table>
                  </div>
                )}
              </div>
            </>
          ) : (
            <UpsellPanel title="Leads and revenue by listing" minTier="Boost Pro or Boost Max" upgradeUrl={data.upgrade_url} />
          )}

          {data.analytics_level !== 'basic' ? (
            <div className="panel">
              <h2>Video performance</h2>
              {data.top_videos.length === 0 ? <p className="muted">No approved videos yet.</p> : (
                <div className="table-wrap">
                  <table className="data-table">
                    <thead><tr><th>Video</th><th>Views</th><th>CTA clicks</th><th>CTR</th></tr></thead>
                    <tbody>{data.top_videos.map((video) => (
                      <tr key={video.id}><td>{video.title}</td><td>{video.views}</td><td>{video.cta_clicks}</td><td>{video.ctr_percent === null ? '—' : `${video.ctr_percent}%`}</td></tr>
                    ))}</tbody>
                  </table>
                </div>
              )}
            </div>
          ) : (
            <UpsellPanel title="Video performance" minTier="Boost Basic" upgradeUrl={data.upgrade_url} />
          )}
        </>
      )}
    </DashLayout>
  );
}
