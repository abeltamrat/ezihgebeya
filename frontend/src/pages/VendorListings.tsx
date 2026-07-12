import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { vendorApi, type ListingType } from '../api/vendor';
import { DashLayout } from '../components/DashLayout';
import { ApiError } from '../api/client';

const TYPE_LABELS: Record<ListingType, string> = { product: 'Products', service: 'Services', supply: 'Supplies' };

export function VendorListings() {
  const { type } = useParams<{ type: ListingType }>();
  const ltype = (type ?? 'product') as ListingType;
  const queryClient = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: ['vendor', 'listings', ltype],
    queryFn: () => vendorApi.list(ltype),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => vendorApi.remove(ltype, id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['vendor', 'listings', ltype] }),
  });

  return (
    <DashLayout>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <h1>My {TYPE_LABELS[ltype]} ({data?.data.length ?? 0})</h1>
        <Link className="btn btn-primary" to={`/vendor/listings/${ltype}/new`}>
          + Add {ltype}
        </Link>
      </div>

      {isLoading && <p className="muted">Loading…</p>}
      {error && <div className="alert alert-error">{error instanceof ApiError ? error.message : 'Failed to load listings.'}</div>}
      {data && data.data.length === 0 && <div className="panel muted">No {ltype}s yet.</div>}

      {data && data.data.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Category</th>
                <th>Price</th>
                <th>Status</th>
                <th>Views</th>
                <th>Inquiries</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((l) => (
                <tr key={l.id}>
                  <td>{l.title}</td>
                  <td>{l.category_name}</td>
                  <td>{l.price ? `${l.price} ETB` : '—'}</td>
                  <td>
                    <span className={`badge badge-status-${l.status}`}>{l.status.replace('_', ' ')}</span>
                  </td>
                  <td>{l.views}</td>
                  <td>{l.inquiries}</td>
                  <td style={{ display: 'flex', gap: 8 }}>
                    <Link to={`/vendor/listings/${ltype}/${l.id}/edit`}>Edit</Link>
                    <button
                      className="btn btn-outline"
                      style={{ padding: '2px 8px', minHeight: 'auto' }}
                      disabled={deleteMutation.isPending}
                      onClick={() => {
                        if (confirm('Delete this listing?')) deleteMutation.mutate(l.id);
                      }}
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </DashLayout>
  );
}
