import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { accountApi } from '../api/account';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function AccountInquiryThread() {
  const { id } = useParams<{ id: string }>();
  const inquiryId = Number(id);
  const queryClient = useQueryClient();
  const [body, setBody] = useState('');

  const inquiry = useQuery({
    queryKey: ['account', 'inquiry', inquiryId],
    queryFn: () => accountApi.inquiry(inquiryId),
    enabled: Number.isFinite(inquiryId) && inquiryId > 0,
  });

  const replyMutation = useMutation({
    mutationFn: () => accountApi.replyToInquiry(inquiryId, body),
    onSuccess: () => {
      setBody('');
      queryClient.invalidateQueries({ queryKey: ['account', 'inquiry', inquiryId] });
      queryClient.invalidateQueries({ queryKey: ['account', 'inquiries'] });
    },
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (body.trim()) replyMutation.mutate();
  };

  const data = inquiry.data;

  return (
    <DashLayout>
      <p><Link to="/account/inquiries">← Back to inquiries</Link></p>
      {inquiry.isLoading && <p className="muted">Loading…</p>}
      {inquiry.error && <div className="alert alert-error">{inquiry.error instanceof ApiError ? inquiry.error.message : 'Could not load this inquiry.'}</div>}
      {data && (
        <>
          <h1>{data.data.listing_title || `${data.data.listing_type} inquiry`}</h1>
          <p className="muted">{data.data.business_name} · <span className={`badge badge-status-${data.data.status}`}>{data.data.status}</span></p>
          <div className="panel">
            <div className="review-head"><strong>Your first message</strong><span className="muted">{new Date(data.data.created_at).toLocaleString()}</span></div>
            <p>{data.data.message}</p>
            {data.data.phone ? <p className="muted small">Phone used: {data.data.phone}</p> : null}
          </div>
          <div className="thread-list">
            {data.messages.map((m) => (
              <div className={`panel thread-message ${m.mine ? 'mine' : ''}`} key={m.id}>
                <div className="review-head"><strong>{m.mine ? 'You' : m.sender_name}</strong><span className="muted">{new Date(m.created_at).toLocaleString()}</span></div>
                <p>{m.body}</p>
              </div>
            ))}
          </div>
          <form className="panel" onSubmit={onSubmit}>
            <label>Reply <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={3} required /></label>
            {replyMutation.error ? <div className="alert alert-error">{replyMutation.error instanceof ApiError ? replyMutation.error.message : 'Could not send reply.'}</div> : null}
            <button className="btn btn-primary" disabled={replyMutation.isPending || !body.trim()}>
              {replyMutation.isPending ? 'Sending…' : 'Send reply'}
            </button>
          </form>
        </>
      )}
    </DashLayout>
  );
}
