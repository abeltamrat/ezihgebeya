import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function VendorReviews() {
  const queryClient = useQueryClient();
  const [drafts, setDrafts] = useState<Record<number, string>>({});

  const reviews = useQuery({ queryKey: ['vendor', 'reviews'], queryFn: vendorApi.reviews });

  const replyMutation = useMutation({
    mutationFn: ({ id, reply }: { id: number; reply: string }) => vendorApi.replyToReview(id, reply),
    onSuccess: (_, variables) => {
      setDrafts((current) => ({ ...current, [variables.id]: '' }));
      queryClient.invalidateQueries({ queryKey: ['vendor', 'reviews'] });
    },
  });

  return (
    <DashLayout>
      <h1>Reviews</h1>
      {reviews.data ? (
        <p className="muted">
          Rating: {reviews.data.rating_average.toFixed(1)} / 5 from {reviews.data.rating_count} reviews. You can reply once to each approved review.
        </p>
      ) : null}

      {reviews.isLoading && <p className="muted">Loading…</p>}
      {reviews.error && <div className="alert alert-error">{reviews.error instanceof ApiError ? reviews.error.message : 'Could not load reviews.'}</div>}
      {reviews.data && reviews.data.data.length === 0 && <div className="panel muted">No reviews yet.</div>}

      {reviews.data?.data.map((review) => (
        <div className="panel" key={review.id}>
          <div className="review-head">
            <strong>{review.reviewer_name}</strong>
            <span className="stars">{'★'.repeat(review.rating)}</span>
            {review.is_verified_purchase ? <span className="badge badge-verified">Verified purchase</span> : null}
            <span className={`badge badge-status-${review.status}`}>{review.status}</span>
            <span className="muted">{new Date(review.created_at).toLocaleString()}</span>
          </div>
          {review.title ? <h3>{review.title}</h3> : null}
          <p>{review.comment}</p>
          {review.vendor_reply ? (
            <div className="panel" style={{ background: 'var(--brand-soft)' }}>
              <div className="review-head">
                <strong>Your reply</strong>
                <span className="muted">{review.vendor_replied_at ? new Date(review.vendor_replied_at).toLocaleString() : ''}</span>
              </div>
              <p>{review.vendor_reply}</p>
            </div>
          ) : review.status === 'approved' ? (
            <form
              className="btn-row"
              onSubmit={(event) => {
                event.preventDefault();
                const reply = (drafts[review.id] ?? '').trim();
                if (reply) replyMutation.mutate({ id: review.id, reply });
              }}
            >
              <input
                value={drafts[review.id] ?? ''}
                onChange={(e) => setDrafts((current) => ({ ...current, [review.id]: e.target.value }))}
                placeholder="Write your one public reply…"
                required
              />
              <button className="btn btn-outline btn-sm" disabled={replyMutation.isPending}>
                Reply
              </button>
            </form>
          ) : null}
        </div>
      ))}
    </DashLayout>
  );
}
