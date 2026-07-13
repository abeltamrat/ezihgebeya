import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { cartApi } from '../api/cart';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

function money(n: number): string {
  return `${n.toLocaleString('en-US', { maximumFractionDigits: 2 })} ETB`;
}

export function Cart() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const cart = useQuery({ queryKey: ['cart'], queryFn: cartApi.get });

  const updateMutation = useMutation({
    mutationFn: ({ type, id, qty }: { type: 'product' | 'supply'; id: number; qty: number }) =>
      cartApi.update(type, id, qty),
    onSuccess: (data) => queryClient.setQueryData(['cart'], data),
  });
  const removeMutation = useMutation({
    mutationFn: ({ type, id }: { type: 'product' | 'supply'; id: number }) => cartApi.remove(type, id),
    onSuccess: (data) => queryClient.setQueryData(['cart'], data),
  });

  const data = cart.data;

  return (
    <DashLayout>
      <h1>🛒 My Cart</h1>
      {cart.isLoading && <p className="muted">Loading…</p>}
      {cart.error && (
        <div className="alert alert-error">{cart.error instanceof ApiError ? cart.error.message : 'Could not load cart.'}</div>
      )}

      {data && data.groups.length === 0 && (
        <div className="panel muted">
          Your cart is empty. Browse <a href="/products">furniture</a> or <a href="/supplies">supplies</a>.
        </div>
      )}

      {data && data.groups.length > 0 && (
        <>
          <p className="muted small">
            {data.groups.length} shop{data.groups.length === 1 ? '' : 's'} in this cart
          </p>
          {data.groups.map((g) => (
            <div className="panel" key={g.business_id}>
              <h3>🏪 {g.business_name}</h3>
              <div className="table-wrap">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Unit price</th>
                      <th>Qty</th>
                      <th>Total</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {g.items.map((it) => (
                      <tr key={`${it.type}:${it.id}`}>
                        <td>{it.title}</td>
                        <td>
                          {money(it.price)} / {it.unit}
                        </td>
                        <td>
                          <input
                            type="number"
                            min={0}
                            step="any"
                            defaultValue={it.qty}
                            style={{ width: 80 }}
                            onBlur={(e) => {
                              const qty = parseFloat(e.target.value);
                              if (!Number.isNaN(qty) && qty !== it.qty) {
                                updateMutation.mutate({ type: it.type, id: it.id, qty });
                              }
                            }}
                          />
                        </td>
                        <td>
                          <strong>{money(it.line)}</strong>
                        </td>
                        <td>
                          <button
                            className="btn btn-ghost btn-sm"
                            title="Remove"
                            disabled={removeMutation.isPending}
                            onClick={() => removeMutation.mutate({ type: it.type, id: it.id })}
                          >
                            🗑
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p style={{ textAlign: 'right' }}>
                <strong>Subtotal: {money(g.subtotal)}</strong>
              </p>
            </div>
          ))}

          <div className="cta-banner">
            <div>
              <h2>Grand total: {money(data.grand_total)}</h2>
              <p>Delivery cost is arranged directly with each seller. One order is created per shop.</p>
            </div>
            <button className="btn btn-primary btn-lg" onClick={() => navigate('/checkout')}>
              Proceed to checkout →
            </button>
          </div>
        </>
      )}
    </DashLayout>
  );
}
