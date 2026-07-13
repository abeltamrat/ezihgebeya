import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { accountApi } from '../api/account';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function AccountFavorites() {
  const queryClient = useQueryClient();
  const favorites = useQuery({ queryKey: ['account', 'favorites'], queryFn: accountApi.favorites });
  const removeMutation = useMutation({
    mutationFn: (productId: number) => accountApi.removeFavorite(productId),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['account', 'favorites'] }),
  });

  return (
    <DashLayout>
      <h1>Saved products ({favorites.data?.data.length ?? 0})</h1>
      {favorites.isLoading && <p className="muted">Loading…</p>}
      {favorites.error && <div className="alert alert-error">{favorites.error instanceof ApiError ? favorites.error.message : 'Could not load saved products.'}</div>}
      {favorites.data && favorites.data.data.length === 0 && (
        <div className="panel muted">
          Nothing saved yet. Browse <a href="/products">furniture</a> and save products you like.
        </div>
      )}

      {favorites.data && favorites.data.data.length > 0 && (
        <div className="favorite-grid">
          {favorites.data.data.map((item) => (
            <div className="favorite-card panel" key={item.id}>
              <a href={item.url} className="favorite-img">
                {item.image_url ? <img src={item.image_url} alt={item.title} /> : <span>{item.category_name}</span>}
              </a>
              <div>
                <p className="muted small">{item.category_name} · {item.subcity ? `${item.subcity}, ` : ''}{item.city ?? 'Ethiopia'}</p>
                <h2><a href={item.url}>{item.title}</a></h2>
                <p><strong>{item.price || 'Negotiable'}</strong> {item.old_price ? <span className="muted" style={{ textDecoration: 'line-through' }}>{item.old_price}</span> : null}</p>
                <p className="muted small">{item.business_name} · saved {new Date(item.saved_at).toLocaleDateString()}</p>
                <button
                  className="btn btn-outline btn-sm"
                  disabled={removeMutation.isPending}
                  onClick={() => removeMutation.mutate(item.id)}
                >
                  Remove
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </DashLayout>
  );
}
