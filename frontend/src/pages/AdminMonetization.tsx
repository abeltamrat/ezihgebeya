import { useQuery } from '@tanstack/react-query';
import { adminApi } from '../api/admin';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

function money(n: number): string {
  return `${n.toLocaleString('en-US', { maximumFractionDigits: 2 })} ETB`;
}

export function AdminMonetization() {
  const monetization = useQuery({ queryKey: ['admin', 'monetization'], queryFn: adminApi.monetization });
  const data = monetization.data;

  return (
    <DashLayout>
      <div className="section-head">
        <div>
          <h1>Monetization</h1>
          <p className="muted">Package catalog and manual-payment operations for TOP Pins and Boost subscriptions.</p>
        </div>
        {data ? <a className="btn btn-outline" href={data.php_admin_url}>Review payments in PHP admin</a> : null}
      </div>

      {monetization.isLoading ? <p className="muted">Loading…</p> : null}
      {monetization.error ? (
        <div className="alert alert-error">
          {monetization.error instanceof ApiError ? monetization.error.message : 'Could not load monetization data.'}
        </div>
      ) : null}

      {data ? (
        <>
          <div className="stat-grid">
            <div className="stat-card"><div className="stat-num">{data.top_pin_stats.active}</div><div className="stat-label">Active TOP Pins</div></div>
            <div className="stat-card"><div className="stat-num">{data.top_pin_stats.pending}</div><div className="stat-label">Pending TOP Pins</div></div>
            <div className="stat-card"><div className="stat-num">{data.boost_stats.active}</div><div className="stat-label">Active Boost subscriptions</div></div>
            <div className="stat-card"><div className="stat-num">{data.boost_stats.pending}</div><div className="stat-label">Pending Boost subscriptions</div></div>
            <div className="stat-card"><div className="stat-num">{data.revenue_30d.top_pin_formatted}</div><div className="stat-label">TOP Pin revenue, 30d</div></div>
            <div className="stat-card"><div className="stat-num">{data.revenue_30d.boost_formatted}</div><div className="stat-label">Boost revenue, 30d</div></div>
          </div>

          <section className="grid-2">
            <div className="panel">
              <h2>TOP Pin packages</h2>
              <p className="muted small">TOP Pins set listing-level promoted/featured flags after payment confirmation.</p>
              {Object.entries(data.top_pin_packages).map(([key, pkg]) => (
                <article className="list-card" key={key}>
                  <div>
                    <h3>{pkg.label}</h3>
                    <p className="muted small">{pkg.duration_days} days · single listing visibility boost</p>
                  </div>
                  <strong>{money(pkg.price)}</strong>
                </article>
              ))}
            </div>

            <div className="panel">
              <h2>Boost tiers</h2>
              <p className="muted small">Boost rank weight is applied in browse, search, and similar-listing placement.</p>
              {Object.entries(data.boost_tiers).map(([key, tier]) => (
                <article className="list-card" key={key}>
                  <div>
                    <h3>{tier.label}</h3>
                    <p className="muted small">Rank weight {tier.rank_weight}</p>
                    <ul className="small">
                      {tier.benefits.map((benefit) => <li key={benefit}>{benefit}</li>)}
                    </ul>
                  </div>
                  <strong>{money(tier.price)}/mo</strong>
                </article>
              ))}
            </div>
          </section>

          <section className="panel">
            <h2>Pending commercial payments</h2>
            {data.pending_payments.length === 0 ? <p className="muted">No pending TOP Pin, Boost, subscription, promotion, or ad payments.</p> : null}
            {data.pending_payments.length > 0 ? (
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Business</th>
                      <th>Type</th>
                      <th>Package</th>
                      <th>Amount</th>
                      <th>Reference</th>
                      <th>Submitted</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.pending_payments.map((payment) => (
                      <tr key={payment.id}>
                        <td>{payment.business_name ?? '—'}</td>
                        <td>{payment.payment_type.replaceAll('_', ' ')}</td>
                        <td>{payment.promotion_type ?? payment.subscription_plan ?? '—'}</td>
                        <td>{payment.amount_formatted}</td>
                        <td>{payment.reference_number ?? payment.payment_method}</td>
                        <td>{new Date(payment.created_at).toLocaleDateString()}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : null}
          </section>
        </>
      ) : null}
    </DashLayout>
  );
}
