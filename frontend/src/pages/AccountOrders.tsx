import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { useLocation } from 'react-router-dom';
import { ordersApi, type Order } from '../api/cart';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

function money(n: number): string {
  return `${n.toLocaleString('en-US', { maximumFractionDigits: 2 })} ETB`;
}

function PayForm({ order, onDone }: { order: Order; onDone: (order: Order) => void }) {
  const [open, setOpen] = useState(false);
  const [reference, setReference] = useState('');
  const [file, setFile] = useState<File | null>(null);

  const payMutation = useMutation({
    mutationFn: () => {
      const form = new FormData();
      form.set('payment_method', order.payment_method);
      form.set('reference_number', reference);
      if (file) form.set('proof_image', file);
      return ordersApi.pay(order.id, form);
    },
    onSuccess: (result) => onDone(result.order),
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    payMutation.mutate();
  };

  if (!open) {
    return (
      <button className="btn btn-outline btn-sm" onClick={() => setOpen(true)}>
        Submit payment proof
      </button>
    );
  }

  return (
    <form className="panel" onSubmit={onSubmit}>
      {payMutation.error && (
        <div className="alert alert-error">
          {payMutation.error instanceof ApiError ? payMutation.error.message : 'Could not submit payment.'}
        </div>
      )}
      <p className="muted small">Paying via {order.payment_method.replaceAll('_', ' ')}.</p>
      <label>
        Transaction reference
        <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="e.g. FT26XXXX / Telebirr ref" />
      </label>
      <label>
        Proof screenshot
        <input type="file" accept="image/*" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
      </label>
      <button className="btn btn-primary btn-sm" disabled={payMutation.isPending}>
        {payMutation.isPending ? 'Submitting…' : 'Submit'}
      </button>
    </form>
  );
}

export function AccountOrders() {
  const location = useLocation();
  const placed = (location.state as { placed?: string[] } | null)?.placed;
  const queryClient = useQueryClient();
  const orders = useQuery({ queryKey: ['account', 'orders'], queryFn: ordersApi.list });

  const cancelMutation = useMutation({
    mutationFn: (orderId: number) => ordersApi.cancel(orderId),
    onSuccess: (result) => {
      queryClient.setQueryData<{ ok: true; data: Order[] } | undefined>(['account', 'orders'], (current) =>
        current ? { ok: true, data: current.data.map((o) => (o.id === result.order.id ? result.order : o)) } : current,
      );
    },
  });

  const onPaid = (updated: Order) => {
    queryClient.setQueryData<{ ok: true; data: Order[] } | undefined>(['account', 'orders'], (current) =>
      current ? { ok: true, data: current.data.map((o) => (o.id === updated.id ? updated : o)) } : current,
    );
  };

  const list = orders.data?.data ?? [];

  return (
    <DashLayout>
      <div className="page-title">
        <div>
          <p className="eyebrow">Purchases</p>
          <h1>My orders <span className="count-pill">{list.length}</span></h1>
        </div>
      </div>
      {placed && placed.length > 0 && (
        <div className="alert alert-success mb-3">
          Order{placed.length > 1 ? 's' : ''} placed: {placed.join(', ')}. The seller will confirm availability.
        </div>
      )}
      {orders.isLoading && <p className="muted">Loading…</p>}
      {orders.error && (
        <div className="alert alert-error">{orders.error instanceof ApiError ? orders.error.message : 'Could not load orders.'}</div>
      )}
      {orders.data && list.length === 0 && (
        <div className="panel muted">
          No orders yet. Add products to your <a href="/cart">cart</a> to get started.
        </div>
      )}

      {list.map((o) => (
        <div className="panel order-card" key={o.id}>
          <div className="order-head">
            <div>
              <p className="eyebrow">{o.order_number}</p>
              <h2>{o.business_name}</h2>
              <p className="muted small">
                {new Date(o.created_at).toLocaleString()} · {o.payment_method.replaceAll('_', ' ')} · {o.delivery_option}
              </p>
            </div>
            <span className={`badge badge-status-${o.status}`}>{o.status.replaceAll('_', ' ')}</span>
          </div>
          <div className="table-wrap">
            <table className="data-table">
              <tbody>
                {o.items.map((it, idx) => (
                  <tr key={idx}>
                    <td>{it.title}</td>
                    <td>
                      {money(it.unit_price)} × {it.quantity}
                    </td>
                    <td>
                      <strong>{money(it.line_total)}</strong>
                    </td>
                  </tr>
                ))}
                <tr>
                  <td colSpan={2}>
                    <strong>Total</strong>
                  </td>
                  <td>
                    <strong>{money(o.total)}</strong>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          {o.payments.map((p) => (
            <div className="payment-row" key={p.id}>
              <span>
                Payment {money(p.amount)} via {p.payment_method.replaceAll('_', ' ')}
                {p.reference_number ? ` · ref ${p.reference_number}` : ''}
              </span>
              <span
                className={`badge badge-status-${p.status === 'confirmed' ? 'active' : p.status === 'rejected' ? 'rejected' : 'pending'}`}
              >
                {p.status}
              </span>
            </div>
          ))}

          <div className="btn-row order-actions">
            {o.can_submit_payment && <PayForm order={o} onDone={onPaid} />}
            {o.can_cancel && (
              <button
                className="btn btn-ghost btn-sm"
                disabled={cancelMutation.isPending}
                onClick={() => {
                  if (window.confirm('Cancel this order?')) cancelMutation.mutate(o.id);
                }}
              >
                Cancel order
              </button>
            )}
            <a className="btn btn-ghost btn-sm" href={`tel:${o.business_phone}`}>
              Call seller
            </a>
          </div>
        </div>
      ))}
    </DashLayout>
  );
}
