import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';
import { inquiryInitials, inquiryTime, VendorConversationList } from '../components/VendorConversationList';

const FALLBACK_STATUSES = ['new', 'seen', 'responded', 'negotiating', 'converted', 'closed', 'spam'];

export function VendorInquiryThread() {
  const { id } = useParams<{ id: string }>();
  const inquiryId = Number(id);
  const queryClient = useQueryClient();
  const [body, setBody] = useState('');
  const [search, setSearch] = useState('');
  const messageEnd = useRef<HTMLDivElement>(null);

  const listQuery = useQuery({
    queryKey: ['vendor', 'inquiries', ''],
    queryFn: () => vendorApi.inquiries(''),
  });
  const threadQuery = useQuery({
    queryKey: ['vendor', 'inquiry', inquiryId],
    queryFn: () => vendorApi.inquiry(inquiryId),
    enabled: Number.isFinite(inquiryId) && inquiryId > 0,
  });

  useEffect(() => {
    messageEnd.current?.scrollIntoView({ block: 'end' });
  }, [threadQuery.data?.messages.length]);

  const replyMutation = useMutation({
    mutationFn: () => vendorApi.replyToInquiry(inquiryId, body),
    onSuccess: () => {
      setBody('');
      queryClient.invalidateQueries({ queryKey: ['vendor', 'inquiry', inquiryId] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'inquiries'] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'dashboard'] });
    },
  });
  const statusMutation = useMutation({
    mutationFn: (status: string) => vendorApi.updateInquiryStatus(inquiryId, status),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor', 'inquiry', inquiryId] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'inquiries'] });
    },
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (body.trim()) replyMutation.mutate();
  };

  const inquiry = threadQuery.data?.data;
  const statuses = listQuery.data?.statuses ?? FALLBACK_STATUSES;

  return (
    <DashLayout>
      {(listQuery.error || threadQuery.error) ? (
        <div className="alert alert-error">
          {(listQuery.error instanceof ApiError && listQuery.error.message) ||
            (threadQuery.error instanceof ApiError && threadQuery.error.message) ||
            'Could not load messages.'}
        </div>
      ) : null}

      <div className="tg-inbox tg-thread-open">
        {listQuery.data ? (
          <VendorConversationList inquiries={listQuery.data.data} activeId={inquiryId} search={search} onSearch={setSearch} />
        ) : <aside className="tg-conversations"><p className="muted">Loading…</p></aside>}

        {inquiry ? (
          <section className="tg-chat" aria-label={`Conversation with ${inquiry.name || 'Customer'}`}>
            <header className="tg-chat-head">
              <Link className="tg-mobile-back" to="/vendor/inquiries" aria-label="Back to conversations">‹</Link>
              <span className="tg-avatar tg-avatar-small">{inquiryInitials(inquiry.name)}</span>
              <div className="tg-person">
                <strong>{inquiry.name || 'Customer'}</strong>
                <span>{inquiry.phone || inquiry.preferred_contact_method}</span>
              </div>
              <select
                className="tg-status"
                aria-label="Inquiry status"
                value={inquiry.status}
                disabled={statusMutation.isPending}
                onChange={(event) => statusMutation.mutate(event.target.value)}
              >
                {statuses.map((status) => <option value={status} key={status}>{status.replaceAll('_', ' ')}</option>)}
              </select>
              {inquiry.phone ? <a className="tg-call" href={`tel:${inquiry.phone}`} aria-label={`Call ${inquiry.name || 'customer'}`}>☎</a> : null}
            </header>

            <div className="tg-context">
              <span>Inquiry about</span>
              <strong>{inquiry.listing_title || `${inquiry.listing_type} listing`}</strong>
              <small>{inquiry.inquiry_type.replaceAll('_', ' ')}</small>
            </div>

            <div className="tg-messages">
              <div className="tg-day"><span>{new Date(inquiry.created_at).toLocaleDateString([], { month: 'long', day: 'numeric', year: 'numeric' })}</span></div>
              <div className="tg-bubble-row">
                <span className="tg-avatar tg-avatar-message">{inquiryInitials(inquiry.name)}</span>
                <div className="tg-bubble">
                  <p>{inquiry.message}</p>
                  <time>{inquiryTime(inquiry.created_at)}</time>
                </div>
              </div>
              {(threadQuery.data?.messages ?? []).map((message) => (
                <div className={`tg-bubble-row ${message.mine ? 'mine' : ''}`} key={message.id}>
                  {!message.mine ? <span className="tg-avatar tg-avatar-message">{inquiryInitials(message.sender_name)}</span> : null}
                  <div className="tg-bubble">
                    <p>{message.body}</p>
                    <time>{inquiryTime(message.created_at)}{message.mine ? <span aria-label={message.read_at ? 'Read' : 'Sent'}> ✓{message.read_at ? '✓' : ''}</span> : null}</time>
                  </div>
                </div>
              ))}
              {!inquiry.customer_id ? <div className="tg-system-note">Guest inquiry — call the customer to ensure they receive your response.</div> : null}
              <div ref={messageEnd} />
            </div>

            <form className="tg-composer" onSubmit={onSubmit}>
              <textarea
                value={body}
                onChange={(event) => setBody(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    if (body.trim() && !replyMutation.isPending) replyMutation.mutate();
                  }
                }}
                rows={1}
                required
                aria-label="Message"
                placeholder="Write a message…"
              />
              <button aria-label="Send message" disabled={replyMutation.isPending || !body.trim()}>
                {replyMutation.isPending ? '…' : '➤'}
              </button>
            </form>
            {replyMutation.error ? <div className="tg-send-error">{replyMutation.error instanceof ApiError ? replyMutation.error.message : 'Could not send reply.'}</div> : null}
          </section>
        ) : (
          <section className="tg-empty-chat"><p className="muted">Loading conversation…</p></section>
        )}
      </div>
    </DashLayout>
  );
}
