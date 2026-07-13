import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { checkoutApi, type CheckoutSubmission } from '../api/cart';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

function money(n: number): string {
  return `${n.toLocaleString('en-US', { maximumFractionDigits: 2 })} ETB`;
}

export function Checkout() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const checkout = useQuery({ queryKey: ['checkout'], queryFn: checkoutApi.info });

  const [deliveryOption, setDeliveryOption] = useState<'pickup' | 'delivery'>('pickup');
  const [phone, setPhone] = useState('');
  const [city, setCity] = useState('');
  const [subcity, setSubcity] = useState('');
  const [address, setAddress] = useState('');
  const [note, setNote] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('');

  useEffect(() => {
    if (checkout.data?.phone) setPhone((current) => current || checkout.data.phone || '');
    const first = checkout.data ? Object.keys(checkout.data.payment_methods)[0] : undefined;
    if (first) setPaymentMethod((current) => current || first);
  }, [checkout.data]);

  const submitMutation = useMutation({
    mutationFn: (body: CheckoutSubmission) => checkoutApi.submit(body),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['cart'] });
      navigate('/account/orders', { state: { placed: result.order_numbers } });
    },
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    submitMutation.mutate({
      delivery_option: deliveryOption,
      delivery_address: address,
      city,
      subcity,
      phone,
      note,
      payment_method: paymentMethod,
    });
  };

  const data = checkout.data;

  return (
    <DashLayout>
      <ul className="steps steps-horizontal w-full mb-6 text-sm">
        <li className="step step-primary">Cart</li>
        <li className="step step-primary">Delivery &amp; Payment</li>
        <li className="step">Confirmation</li>
      </ul>
      <h1>Checkout</h1>
      {checkout.isLoading && <p className="muted">Loading…</p>}
      {checkout.error && (
        <div className="alert alert-error">
          {checkout.error instanceof ApiError ? checkout.error.message : 'Could not load checkout.'}
        </div>
      )}

      {data && (
        <div className="detail-layout checkout-page">
          <div className="detail-main">
            {submitMutation.error && (
              <div role="alert" className="alert alert-error mb-3">
                <span>{submitMutation.error instanceof ApiError ? submitMutation.error.message : 'Could not place order.'}</span>
              </div>
            )}
            <form className="panel" onSubmit={onSubmit}>
              <h3>Delivery</h3>
              <label className="check">
                <input
                  type="radio"
                  name="delivery_option"
                  checked={deliveryOption === 'pickup'}
                  onChange={() => setDeliveryOption('pickup')}
                />{' '}
                Pickup from seller
              </label>
              <label className="check">
                <input
                  type="radio"
                  name="delivery_option"
                  checked={deliveryOption === 'delivery'}
                  onChange={() => setDeliveryOption('delivery')}
                />{' '}
                Deliver to me (fee arranged with seller)
              </label>
              <label>
                Phone *
                <input value={phone} required onChange={(e) => setPhone(e.target.value)} />
              </label>
              <label>
                City
                <select value={city} onChange={(e) => { setCity(e.target.value); setSubcity(''); }}>
                  <option value="">Select…</option>
                  {data.cities.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                Sub-city
                <select value={subcity} onChange={(e) => setSubcity(e.target.value)}>
                  <option value="">Select…</option>
                  {(data.subcities[city] ?? []).map((s) => (
                    <option key={s} value={s}>
                      {s}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                Delivery address
                <textarea rows={2} placeholder="Area, street, building…" value={address} onChange={(e) => setAddress(e.target.value)} />
              </label>
              <label>
                Note to seller
                <textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
              </label>

              <h3>Payment</h3>
              <div className="alert alert-warning safety-tips">
                <div>
                  <strong>Pay safely</strong>
                  <ul>
                    <li>Confirm item availability and delivery details with the seller.</li>
                    <li>Keep Telebirr/CBE/bank reference numbers and proof screenshots.</li>
                    <li>For high-value orders, inspect before final payment when possible.</li>
                  </ul>
                </div>
              </div>
              {Object.entries(data.payment_methods).map(([k, label]) => (
                <label className="check" key={k}>
                  <input type="radio" name="payment_method" checked={paymentMethod === k} onChange={() => setPaymentMethod(k)} />{' '}
                  {label}
                  {k !== 'cash_on_delivery' ? ' (manual confirmation — upload proof after ordering)' : ''}
                </label>
              ))}
              {data.payment_instructions && <p className="muted small">{data.payment_instructions}</p>}

              <button className="btn btn-primary btn-lg btn-block" disabled={submitMutation.isPending}>
                {submitMutation.isPending
                  ? 'Placing order…'
                  : `Place order${data.groups.length > 1 ? 's' : ''} — ${money(data.grand_total)}`}
              </button>
            </form>
          </div>
          <aside className="detail-side">
            <div className="panel checkout-summary-panel">
              <h3>Order summary</h3>
              {data.groups.map((g) => (
                <div key={g.business_id}>
                  <strong>🏪 {g.business_name}</strong>
                  {g.items.map((it) => (
                    <div className="bar-row" key={`${it.type}:${it.id}`}>
                      <span>
                        {it.title} × {it.qty}
                      </span>
                      <b>{money(it.line)}</b>
                    </div>
                  ))}
                </div>
              ))}
              <div className="bar-row" style={{ borderTop: '2px solid var(--line)' }}>
                <span>
                  <strong>Grand total</strong>
                </span>
                <b>{money(data.grand_total)}</b>
              </div>
              <p className="muted small">Payments stay between you and the seller in this version — the platform records and tracks them.</p>
            </div>
          </aside>
        </div>
      )}
    </DashLayout>
  );
}
