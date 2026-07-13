import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function VendorInquiryThread() {
  const { id } = useParams<{ id: string }>();
  const inquiryId = Number(id);
  const queryClient = useQueryClient();
  const [body, setBody] = useState('');

  const { data, isLoading, error } = useQuery({
    queryKey: ['vendor', 'inquiry', inquiryId],
    queryFn: () => vendorApi.inquiry(inquiryId),
    enabled: Number.isFinite(inquiryId) && inquiryId > 0,
  });

  const replyMutation = useMutation({
    mutationFn: () => vendorApi.replyToInquiry(inquiryId, body),
    onSuccess: () => {
      setBody('');
      queryClient.invalidateQueries({ queryKey: ['vendor', 'inquiry', inquiryId] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'inquiries'] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'dashboard'] });
    },
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (!body.trim()) return;
    replyMutation.mutate();
  };

  const inquiry = data?.data;

  return (
    <DashLayout>
      <p>
        <Link to="/vendor/inquiries">← Back to inquiries</Link>
      </p>

      {isLoading && <p className="muted">Loading…</p>}
      {error && <div className="alert alert-error">{error instanceof ApiError ? error.message : 'Could not load this inquiry.'}</div>}

      {inquiry && (
        <>
          <h1>{inquiry.listing_title || `${inquiry.listing_type} inquiry`}</h1>
          <p className="muted">
            {inquiry.inquiry_type.replaceAll('_', ' ')} · <span className={`badge badge-status-${inquiry.status}`}>{inquiry.status}</span> ·{' '}
            {new Date(inquiry.created_at).toLocaleString()}
          </p>

          <div className="panel">
            <div className="review-head">
              <strong>{inquiry.name || 'Customer'}</strong>
              <span className="muted">{inquiry.preferred_contact_method}</span>
            </div>
            <p>{inquiry.message}</p>
            {inquiry.phone ? <p className="muted small">Phone: {inquiry.phone}</p> : null}
            {!inquiry.customer_id ? (
              <p className="muted small">Guest inquiry: replies are stored here, but call the customer to make sure they see it.</p>
            ) : null}
          </div>

          <div className="thread-list">
            {(data.messages ?? []).map((m) => (
              <div className={`panel thread-message ${m.mine ? 'mine' : ''}`} key={m.id}>
                <div className="review-head">
                  <strong>{m.mine ? 'You' : m.sender_name}</strong>
                  <span className="muted">{new Date(m.created_at).toLocaleString()}</span>
                </div>
                <p>{m.body}</p>
              </div>
            ))}
          </div>

          <form className="panel" onSubmit={onSubmit}>
            <label>
              Reply
              <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={3} required placeholder="Write your message…" />
            </label>
            {replyMutation.error ? (
              <div className="alert alert-error">
                {replyMutation.error instanceof ApiError ? replyMutation.error.message : 'Could not send reply.'}
              </div>
            ) : null}
            <button className="btn btn-primary" disabled={replyMutation.isPending || !body.trim()}>
              {replyMutation.isPending ? 'Sending…' : 'Send reply'}
            </button>
          </form>
        </>
      )}
    </DashLayout>
  );
}
