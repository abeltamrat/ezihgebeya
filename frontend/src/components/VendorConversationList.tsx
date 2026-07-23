import { Link } from 'react-router-dom';
import type { VendorInquiry } from '../api/vendor';

export function inquiryInitials(name: string | null) {
  const parts = (name || 'Customer').trim().split(/\s+/);
  return parts.slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

export function inquiryTime(value: string) {
  const date = new Date(value);
  const today = new Date();
  return date.toDateString() === today.toDateString()
    ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : date.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

export function VendorConversationList({
  inquiries,
  activeId,
  search,
  onSearch,
}: {
  inquiries: VendorInquiry[];
  activeId?: number;
  search: string;
  onSearch: (value: string) => void;
}) {
  const needle = search.trim().toLowerCase();
  const filtered = needle
    ? inquiries.filter((item) => [item.name, item.listing_title, item.message, item.phone].some((value) => value?.toLowerCase().includes(needle)))
    : inquiries;

  return (
    <aside className="tg-conversations" aria-label="Inquiry conversations">
      <div className="tg-sidebar-head">
        <div>
          <p className="eyebrow">Messages</p>
          <h1>Inquiries</h1>
        </div>
        <span className="tg-total">{inquiries.length}</span>
      </div>
      <label className="tg-search">
        <span aria-hidden="true">⌕</span>
        <input value={search} onChange={(event) => onSearch(event.target.value)} placeholder="Search conversations" />
      </label>
      <div className="tg-chat-list">
        {filtered.map((item) => (
          <Link className={`tg-chat-row ${activeId === item.id ? 'is-active' : ''}`} to={`/vendor/inquiries/${item.id}`} key={item.id}>
            <span className="tg-avatar">{inquiryInitials(item.name)}</span>
            <span className="tg-chat-copy">
              <span className="tg-chat-line">
                <strong>{item.name || 'Customer'}</strong>
                <time>{inquiryTime(item.updated_at || item.created_at)}</time>
              </span>
              <span className="tg-chat-line tg-chat-preview">
                <span>{item.message || 'New inquiry'}</span>
                {item.status === 'new' ? <b className="tg-unread" aria-label="New inquiry">1</b> : null}
              </span>
              <small>{item.listing_title || item.inquiry_type.replaceAll('_', ' ')}</small>
            </span>
          </Link>
        ))}
        {filtered.length === 0 ? <div className="tg-list-empty">No conversations found</div> : null}
      </div>
    </aside>
  );
}
