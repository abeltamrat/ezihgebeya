import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { boostApi } from '../api/boost';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

function money(n: number): string {
  return `${n.toLocaleString('en-US', { maximumFractionDigits: 2 })} ETB`;
}

function PaymentFields({
  paymentMethods,
  paymentMethod,
  setPaymentMethod,
  reference,
  setReference,
  onFile,
}: {
  paymentMethods: Record<string, string>;
  paymentMethod: string;
  setPaymentMethod: (v: string) => void;
  reference: string;
  setReference: (v: string) => void;
  onFile: (file: File | null) => void;
}) {
  return (
    <>
      <label>
        Payment method
        <select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
          {Object.entries(paymentMethods).map(([k, label]) => (
            <option key={k} value={k}>
              {label}
            </option>
          ))}
        </select>
      </label>
      <label>
        Transaction reference
        <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Payment ref number" />
      </label>
      <label className="span2">
        Payment proof screenshot
        <input type="file" accept="image/*" onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
      </label>
    </>
  );
}

export function VendorBoost() {
  const queryClient = useQueryClient();
  const boost = useQuery({ queryKey: ['vendor', 'boost'], queryFn: boostApi.get });
  const data = boost.data;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['vendor', 'boost'] });

  // TOP Pin purchase form state
  const [pinPackage, setPinPackage] = useState('');
  const [pinListing, setPinListing] = useState('');
  const [pinMethod, setPinMethod] = useState('');
  const [pinRef, setPinRef] = useState('');
  const [pinFile, setPinFile] = useState<File | null>(null);

  const buyPinMutation = useMutation({
    mutationFn: () => {
      const [listingType, listingId] = pinListing.split(':');
      const form = new FormData();
      form.set('package', pinPackage);
      form.set('listing_type', listingType ?? '');
      form.set('listing_id', listingId ?? '');
      form.set('payment_method', pinMethod);
      form.set('reference_number', pinRef);
      if (pinFile) form.set('proof_image', pinFile);
      return boostApi.buyTopPin(form);
    },
    onSuccess: () => {
      setPinRef('');
      setPinFile(null);
      invalidate();
    },
  });

  // Boost subscription form state
  const [tier, setTier] = useState('');
  const [months, setMonths] = useState(1);
  const [boostMethod, setBoostMethod] = useState('');
  const [boostRef, setBoostRef] = useState('');
  const [boostFile, setBoostFile] = useState<File | null>(null);

  const subscribeMutation = useMutation({
    mutationFn: () => {
      const form = new FormData();
      form.set('tier', tier);
      form.set('months', String(months));
      form.set('payment_method', boostMethod);
      form.set('reference_number', boostRef);
      if (boostFile) form.set('proof_image', boostFile);
      return boostApi.subscribe(form);
    },
    onSuccess: () => {
      setBoostRef('');
      setBoostFile(null);
      invalidate();
    },
  });

  const cancelMutation = useMutation({
    mutationFn: ({ kind, id }: { kind: 'top_pin' | 'boost'; id: number }) => boostApi.cancel(kind, id),
    onSuccess: invalidate,
  });

  return (
    <DashLayout>
      <h1>🚀 Boost &amp; TOP Pin</h1>
      <p className="muted small">Pay via Telebirr/CBE/bank; admin activates after confirming your payment proof.</p>
      {boost.isLoading && <p className="muted">Loading…</p>}
      {boost.error && (
        <div className="alert alert-error">{boost.error instanceof ApiError ? boost.error.message : 'Could not load boost options.'}</div>
      )}

      {data && !data.verified && (
        <div className="alert alert-warning mb-3">
          Only verified businesses can buy promotions. Submit your TIN/license from Verification first.
        </div>
      )}

      {data && (
        <>
          <h2 className="section-gap">TOP Pin — pin one listing</h2>
          <div className="stat-grid promo-card-grid">
            {Object.entries(data.top_pin_packages).map(([k, pkg]) => (
              <div className={`stat-card promo-choice-card ${pinPackage === k ? 'is-selected' : ''}`} key={k}>
                <h3>{pkg.label}</h3>
                <div className="stat-num promo-price">{money(pkg.price)}</div>
                <p className="muted small">{pkg.duration_days} days pinned in category/browse results</p>
                <button className="btn btn-outline btn-sm" onClick={() => setPinPackage(k)} disabled={!data.verified}>
                  Select
                </button>
              </div>
            ))}
          </div>

          {pinPackage && (
            <form
              className="panel form-2col"
              onSubmit={(e: FormEvent) => {
                e.preventDefault();
                buyPinMutation.mutate();
              }}
            >
              <h3 className="span2">Buy {data.top_pin_packages[pinPackage]?.label}</h3>
              <label className="span2">
                Listing to pin
                <select value={pinListing} onChange={(e) => setPinListing(e.target.value)} required>
                  <option value="">Select…</option>
                  {data.listings.map((l) => (
                    <option key={`${l.type}:${l.id}`} value={`${l.type}:${l.id}`}>
                      {l.title} ({l.type})
                    </option>
                  ))}
                </select>
              </label>
              <PaymentFields
                paymentMethods={data.payment_methods}
                paymentMethod={pinMethod || Object.keys(data.payment_methods)[0] || ''}
                setPaymentMethod={setPinMethod}
                reference={pinRef}
                setReference={setPinRef}
                onFile={setPinFile}
              />
              {buyPinMutation.error && (
                <div className="span2 alert alert-error">
                  {buyPinMutation.error instanceof ApiError ? buyPinMutation.error.message : 'Could not submit TOP Pin request.'}
                </div>
              )}
              <div className="span2">
                <button className="btn btn-primary" disabled={buyPinMutation.isPending || !pinListing}>
                  {buyPinMutation.isPending ? 'Submitting…' : `Request TOP Pin — ${money(data.top_pin_packages[pinPackage]?.price ?? 0)}`}
                </button>
              </div>
            </form>
          )}

          {data.top_pins.length > 0 && (
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Listing</th>
                    <th>Cost</th>
                    <th>Status</th>
                    <th>Runs</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {data.top_pins.map((p) => (
                    <tr key={p.id}>
                      <td>
                        {p.listing_type} #{p.listing_id}
                      </td>
                      <td>{money(p.budget)}</td>
                      <td>
                        <span className={`badge badge-status-${p.status}`}>{p.status}</span>
                      </td>
                      <td className="small">
                        {p.starts_at && p.ends_at ? `${new Date(p.starts_at).toLocaleDateString()} – ${new Date(p.ends_at).toLocaleDateString()}` : '—'}
                      </td>
                      <td>
                        {['pending', 'scheduled', 'active', 'paused'].includes(p.status) && (
                          <button
                            className="btn btn-ghost btn-sm"
                            disabled={cancelMutation.isPending}
                            onClick={() => {
                              if (window.confirm('Cancel this TOP Pin?')) cancelMutation.mutate({ kind: 'top_pin', id: p.id });
                            }}
                          >
                            Cancel
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <h2 className="section-gap">Boost subscription — vendor-wide ranking lift</h2>
          <div className="stat-grid boost-card-grid">
            {Object.entries(data.boost_tiers).map(([k, t]) => (
              <div
                className={`stat-card promo-choice-card ${data.current_boost === k ? 'is-selected' : ''}`}
                key={k}
              >
                <h3>
                  {t.label} {data.current_boost === k ? '✔' : ''}
                </h3>
                <div className="stat-num promo-price">{money(t.price)}/mo</div>
                <ul className="small promo-benefits">
                  {t.benefits.map((b) => (
                    <li key={b}>{b}</li>
                  ))}
                </ul>
                {data.current_boost !== k && (
                  <button className="btn btn-outline btn-sm" onClick={() => setTier(k)} disabled={!data.verified}>
                    Select
                  </button>
                )}
              </div>
            ))}
          </div>

          {tier && (
            <form
              className="panel form-2col"
              onSubmit={(e: FormEvent) => {
                e.preventDefault();
                subscribeMutation.mutate();
              }}
            >
              <h3 className="span2">Subscribe to {data.boost_tiers[tier]?.label}</h3>
              <label>
                Months
                <input type="number" min={1} max={12} value={months} onChange={(e) => setMonths(Math.max(1, Math.min(12, parseInt(e.target.value) || 1)))} />
              </label>
              <div />
              <PaymentFields
                paymentMethods={data.payment_methods}
                paymentMethod={boostMethod || Object.keys(data.payment_methods)[0] || ''}
                setPaymentMethod={setBoostMethod}
                reference={boostRef}
                setReference={setBoostRef}
                onFile={setBoostFile}
              />
              {subscribeMutation.error && (
                <div className="span2 alert alert-error">
                  {subscribeMutation.error instanceof ApiError ? subscribeMutation.error.message : 'Could not submit Boost request.'}
                </div>
              )}
              <div className="span2">
                <button className="btn btn-primary" disabled={subscribeMutation.isPending}>
                  {subscribeMutation.isPending ? 'Submitting…' : `Request Boost — ${money((data.boost_tiers[tier]?.price ?? 0) * months)}`}
                </button>
              </div>
            </form>
          )}

          {data.boost_subscriptions.length > 0 && (
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Tier</th>
                    <th>Months</th>
                    <th>Status</th>
                    <th>Period</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {data.boost_subscriptions.map((s) => (
                    <tr key={s.id}>
                      <td>{data.boost_tiers[s.plan]?.label ?? s.plan}</td>
                      <td>{s.months}</td>
                      <td>
                        <span className={`badge badge-status-${s.status}`}>{s.status}</span>
                      </td>
                      <td className="small">
                        {s.starts_at && s.ends_at ? `${new Date(s.starts_at).toLocaleDateString()} – ${new Date(s.ends_at).toLocaleDateString()}` : '—'}
                      </td>
                      <td>
                        {['pending', 'active'].includes(s.status) && (
                          <button
                            className="btn btn-ghost btn-sm"
                            disabled={cancelMutation.isPending}
                            onClick={() => {
                              if (window.confirm('Cancel this Boost subscription?')) cancelMutation.mutate({ kind: 'boost', id: s.id });
                            }}
                          >
                            Cancel
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </DashLayout>
  );
}
