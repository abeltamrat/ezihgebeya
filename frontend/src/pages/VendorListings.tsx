import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { vendorApi, type ListingType } from '../api/vendor';
import { DashLayout } from '../components/DashLayout';
import { ApiError } from '../api/client';
import { ListingBadge } from '../components/ListingBadge';

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
      <div className="page-title">
        <div>
          <p className="eyebrow">Inventory manager</p>
          <h1>{TYPE_LABELS[ltype]} <span className="count-pill">{data?.data.length ?? 0}</span></h1>
        </div>
        <Link className="btn btn-primary" to={`/vendor/listings/${ltype}/new`}>
          Add {ltype}
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
              {data.data.map((l) => {
                const regularPrice = Number(l.price ?? 0);
                const salePrice = Number(l.discount_price ?? 0);
                const discount = regularPrice > 0 && salePrice > 0 && salePrice < regularPrice ? Math.round(100 - salePrice / regularPrice * 100) : 0;
                return <tr key={l.id}>
                  <td>
                    <strong>{l.title}</strong>
                    <div className="listing-badges">
                      {l.is_featured ? <ListingBadge variant="featured">Featured</ListingBadge> : null}
                      {l.condition_type ? <ListingBadge variant="condition">{l.condition_type === 'new' ? 'Brand new' : l.condition_type}</ListingBadge> : null}
                      {discount ? <ListingBadge variant="discount">Save {discount}%</ListingBadge> : null}
                      {l.delivery_available ? <ListingBadge variant="delivery">Delivery</ListingBadge> : null}
                      {l.is_negotiable ? <ListingBadge variant="negotiable">Negotiable</ListingBadge> : null}
                    </div>
                    <div className="muted small">{l.city}{l.subcity ? ` · ${l.subcity}` : ''}</div>
                  </td>
                  <td>{l.category_name}</td>
                  <td>{l.price ? `${l.price} ETB` : '—'}</td>
                  <td>
                    <span className={`badge badge-status-${l.status}`}>{l.status.replace('_', ' ')}</span>
                  </td>
                  <td>{l.views}</td>
                  <td>{l.inquiries}</td>
                  <td>
                    <div className="table-actions">
                      <a className="btn btn-ghost btn-sm" href={l.public_url}>View</a>
                      <Link className="btn btn-outline btn-sm" to={`/vendor/listings/${ltype}/${l.id}/edit`}>Edit</Link>
                    <button
                      className="btn btn-ghost btn-sm danger-link"
                      disabled={deleteMutation.isPending}
                      onClick={() => {
                        if (confirm('Delete this listing?')) deleteMutation.mutate(l.id);
                      }}
                    >
                      Delete
                    </button>
                    </div>
                  </td>
                </tr>;
              })}
            </tbody>
          </table>
        </div>
      )}
    </DashLayout>
  );
}
