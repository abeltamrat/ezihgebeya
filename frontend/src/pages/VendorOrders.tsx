import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function VendorOrders() {
  const queryClient = useQueryClient();
  const { data, isLoading, error } = useQuery({
    queryKey: ['vendor', 'orders'],
    queryFn: vendorApi.orders,
  });

  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => vendorApi.updateOrderStatus(id, status),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor', 'orders'] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'dashboard'] });
    },
  });

  const paymentMutation = useMutation({
    mutationFn: ({ orderId, paymentId }: { orderId: number; paymentId: number }) =>
      vendorApi.confirmOrderPayment(orderId, paymentId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor', 'orders'] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'dashboard'] });
    },
  });

  return (
    <DashLayout>
      <div className="page-title">
        <div>
          <p className="eyebrow">Sales operations</p>
          <h1>Orders <span className="count-pill">{data?.data.length ?? 0}</span></h1>
        </div>
      </div>

      {isLoading && <p className="muted">Loading…</p>}
      {error && <div className="alert alert-error">{error instanceof ApiError ? error.message : 'Could not load orders.'}</div>}
      {data && data.data.length === 0 && <div className="panel muted">No orders yet.</div>}

      {data?.data.map((order) => (
        <div className="panel order-card" key={order.id}>
          <div className="order-head">
            <div>
              <p className="eyebrow">{order.order_number}</p>
              <h2>{order.customer}</h2>
              <p className="muted small">
                {new Date(order.created_at).toLocaleString()} · {order.payment_method.replaceAll('_', ' ')} · {order.delivery_option}
              </p>
            </div>
            <div className="order-head-actions">
              <span className={`badge badge-status-${order.status}`}>{order.status.replaceAll('_', ' ')}</span>
              {order.phone ? <a className="btn btn-outline btn-sm" href={`tel:${order.phone}`}>Call customer</a> : null}
            </div>
          </div>

          {order.delivery_option === 'delivery' ? (
            <div className="order-note">
              Delivery: {[order.delivery_address, order.subcity, order.city].filter(Boolean).join(', ')}
            </div>
          ) : null}
          {order.note ? <p className="order-note">Note: {order.note}</p> : null}

          <div className="table-wrap">
            <table className="data-table">
              <tbody>
                {order.items.map((item) => (
                  <tr key={item.id}>
                    <td>{item.title}</td>
                    <td>
                      {item.unit_price} × {item.quantity}
                    </td>
                    <td>
                      <strong>{item.line_total_formatted}</strong>
                    </td>
                  </tr>
                ))}
                <tr>
                  <td colSpan={2}>
                    <strong>Total</strong>
                  </td>
                  <td>
                    <strong>{order.total_formatted}</strong>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          {order.payments.map((payment) => (
            <div className="payment-row" key={payment.id}>
              <span>
                {payment.amount_formatted} via {payment.payment_method.replaceAll('_', ' ')}
                {payment.reference_number ? ` · ref ${payment.reference_number}` : ''}
              </span>
              {payment.proof_url ? (
                <a href={payment.proof_url} target="_blank" rel="noreferrer">
                  View proof
                </a>
              ) : null}
              <span className={`badge badge-status-${payment.status === 'confirmed' ? 'active' : payment.status}`}>
                {payment.status}
              </span>
              {payment.status === 'pending' ? (
                <button
                  className="btn btn-outline btn-sm"
                  disabled={paymentMutation.isPending}
                  onClick={() => paymentMutation.mutate({ orderId: order.id, paymentId: payment.id })}
                >
                  Confirm received
                </button>
              ) : null}
            </div>
          ))}

          <div className="order-footer">
            <span className="muted small">Update fulfillment status</span>
            <select
              value=""
              disabled={statusMutation.isPending || order.allowed_next_statuses.length === 0}
              onChange={(e) => {
                if (e.target.value) statusMutation.mutate({ id: order.id, status: e.target.value });
              }}
            >
              <option value="">{order.allowed_next_statuses.length ? 'Choose next status' : 'Final status'}</option>
              {order.allowed_next_statuses.map((status) => (
                <option key={status} value={status}>
                  {status.replaceAll('_', ' ')}
                </option>
              ))}
            </select>
          </div>
        </div>
      ))}
    </DashLayout>
  );
}
