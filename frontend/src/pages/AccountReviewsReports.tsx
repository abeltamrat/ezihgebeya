import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { accountApi } from '../api/account';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

const listingTypes = ['business', 'product', 'service', 'supply'] as const;
const reportedTypes = ['product', 'service', 'supply', 'business', 'video', 'review', 'user'] as const;
const reportReasons = ['scam', 'wrong_category', 'fake_or_misleading', 'duplicate', 'offensive', 'prohibited_item', 'other'];

function messageFrom(error: unknown, fallback: string) {
  return error instanceof ApiError ? error.message : fallback;
}

export function AccountReviewsReports() {
  const queryClient = useQueryClient();
  const reviews = useQuery({ queryKey: ['account', 'reviews'], queryFn: accountApi.reviews });
  const reports = useQuery({ queryKey: ['account', 'reports'], queryFn: accountApi.reports });

  const [reviewForm, setReviewForm] = useState({
    business_id: '',
    listing_type: 'business',
    listing_id: '',
    rating: '5',
    title: '',
    comment: '',
  });
  const [reportForm, setReportForm] = useState({
    reported_type: 'product',
    reported_id: '',
    reason: 'scam',
    description: '',
  });

  const submitReview = useMutation({
    mutationFn: () =>
      accountApi.submitReview({
        business_id: Number(reviewForm.business_id),
        listing_type: reviewForm.listing_type,
        listing_id: reviewForm.listing_id ? Number(reviewForm.listing_id) : null,
        rating: Number(reviewForm.rating),
        title: reviewForm.title,
        comment: reviewForm.comment,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'reviews'] });
      setReviewForm((current) => ({ ...current, title: '', comment: '' }));
    },
  });

  const submitReport = useMutation({
    mutationFn: () =>
      accountApi.submitReport({
        reported_type: reportForm.reported_type,
        reported_id: Number(reportForm.reported_id),
        reason: reportForm.reason,
        description: reportForm.description,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'reports'] });
      setReportForm((current) => ({ ...current, description: '' }));
    },
  });

  const onReviewSubmit = (event: FormEvent) => {
    event.preventDefault();
    submitReview.mutate();
  };

  const onReportSubmit = (event: FormEvent) => {
    event.preventDefault();
    submitReport.mutate();
  };

  return (
    <DashLayout>
      <h1>Reviews & reports</h1>
      <p className="muted">Manage feedback you sent to vendors and safety reports you submitted to EzihGebeya.</p>

      <div className="grid-2">
        <form className="panel form-2col" onSubmit={onReviewSubmit}>
          <div className="span2">
            <h2>Write a review</h2>
            <p className="muted small">Use the business/listing IDs from the public page. Verified purchases are detected server-side.</p>
          </div>
          <label>
            Business ID
            <input
              type="number"
              min="1"
              required
              value={reviewForm.business_id}
              onChange={(e) => setReviewForm((current) => ({ ...current, business_id: e.target.value }))}
            />
          </label>
          <label>
            Listing type
            <select
              value={reviewForm.listing_type}
              onChange={(e) => setReviewForm((current) => ({ ...current, listing_type: e.target.value }))}
            >
              {listingTypes.map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </select>
          </label>
          <label>
            Listing ID
            <input
              type="number"
              min="1"
              placeholder="Optional"
              value={reviewForm.listing_id}
              onChange={(e) => setReviewForm((current) => ({ ...current, listing_id: e.target.value }))}
            />
          </label>
          <label>
            Rating
            <select
              value={reviewForm.rating}
              onChange={(e) => setReviewForm((current) => ({ ...current, rating: e.target.value }))}
            >
              {[5, 4, 3, 2, 1].map((rating) => (
                <option key={rating} value={rating}>
                  {rating} star{rating === 1 ? '' : 's'}
                </option>
              ))}
            </select>
          </label>
          <label className="span2">
            Title
            <input
              maxLength={120}
              value={reviewForm.title}
              onChange={(e) => setReviewForm((current) => ({ ...current, title: e.target.value }))}
            />
          </label>
          <label className="span2">
            Comment
            <textarea
              required
              rows={4}
              maxLength={2000}
              value={reviewForm.comment}
              onChange={(e) => setReviewForm((current) => ({ ...current, comment: e.target.value }))}
            />
          </label>
          {submitReview.error ? <div className="span2 alert alert-error">{messageFrom(submitReview.error, 'Could not submit review.')}</div> : null}
          {submitReview.isSuccess ? <div className="span2 alert alert-success">Review submitted.</div> : null}
          <div className="span2">
            <button className="btn btn-primary" disabled={submitReview.isPending}>
              {submitReview.isPending ? 'Submitting…' : 'Submit review'}
            </button>
          </div>
        </form>

        <form className="panel form-2col" onSubmit={onReportSubmit}>
          <div className="span2">
            <h2>Report a problem</h2>
            <p className="muted small">Reports go to moderation. The server decides ownership, status, and moderation handling.</p>
          </div>
          <label>
            Reported type
            <select
              value={reportForm.reported_type}
              onChange={(e) => setReportForm((current) => ({ ...current, reported_type: e.target.value }))}
            >
              {reportedTypes.map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </select>
          </label>
          <label>
            Reported ID
            <input
              type="number"
              min="1"
              required
              value={reportForm.reported_id}
              onChange={(e) => setReportForm((current) => ({ ...current, reported_id: e.target.value }))}
            />
          </label>
          <label className="span2">
            Reason
            <select
              value={reportForm.reason}
              onChange={(e) => setReportForm((current) => ({ ...current, reason: e.target.value }))}
            >
              {reportReasons.map((reason) => (
                <option key={reason} value={reason}>
                  {reason.replaceAll('_', ' ')}
                </option>
              ))}
            </select>
          </label>
          <label className="span2">
            Details
            <textarea
              rows={4}
              maxLength={2000}
              value={reportForm.description}
              onChange={(e) => setReportForm((current) => ({ ...current, description: e.target.value }))}
            />
          </label>
          {submitReport.error ? <div className="span2 alert alert-error">{messageFrom(submitReport.error, 'Could not submit report.')}</div> : null}
          {submitReport.isSuccess ? <div className="span2 alert alert-success">Report submitted.</div> : null}
          <div className="span2">
            <button className="btn btn-primary" disabled={submitReport.isPending}>
              {submitReport.isPending ? 'Submitting…' : 'Submit report'}
            </button>
          </div>
        </form>
      </div>

      <div className="panel">
        <h2>My reviews</h2>
        {reviews.isLoading ? <p className="muted">Loading…</p> : null}
        {reviews.error ? <div className="alert alert-error">{messageFrom(reviews.error, 'Could not load reviews.')}</div> : null}
        {reviews.data?.data.length ? (
          <div className="stack-list">
            {reviews.data.data.map((review) => (
              <article className="list-card" key={review.id}>
                <div>
                  <h3>{review.business_name}</h3>
                  <p className="muted small">
                    {'★'.repeat(review.rating)} · {review.listing_type}
                    {review.listing_id ? ` #${review.listing_id}` : ''} · {review.status}
                    {review.is_verified_purchase ? ' · verified purchase' : ''}
                  </p>
                  {review.title ? <strong>{review.title}</strong> : null}
                  {review.comment ? <p>{review.comment}</p> : null}
                  {review.vendor_reply ? <p className="muted small">Vendor reply: {review.vendor_reply}</p> : null}
                </div>
                <span className="badge">{new Date(review.created_at).toLocaleDateString()}</span>
              </article>
            ))}
          </div>
        ) : !reviews.isLoading ? (
          <p className="muted">No reviews yet.</p>
        ) : null}
      </div>

      <div className="panel">
        <h2>My reports</h2>
        {reports.isLoading ? <p className="muted">Loading…</p> : null}
        {reports.error ? <div className="alert alert-error">{messageFrom(reports.error, 'Could not load reports.')}</div> : null}
        {reports.data?.data.length ? (
          <div className="stack-list">
            {reports.data.data.map((report) => (
              <article className="list-card" key={report.id}>
                <div>
                  <h3>
                    {report.reported_type} #{report.reported_id}
                  </h3>
                  <p className="muted small">
                    {report.reason.replaceAll('_', ' ')} · {report.status}
                  </p>
                  {report.description ? <p>{report.description}</p> : null}
                  {report.admin_note ? <p className="muted small">Admin note: {report.admin_note}</p> : null}
                </div>
                <span className="badge">{new Date(report.created_at).toLocaleDateString()}</span>
              </article>
            ))}
          </div>
        ) : !reports.isLoading ? (
          <p className="muted">No reports yet.</p>
        ) : null}
      </div>
    </DashLayout>
  );
}
