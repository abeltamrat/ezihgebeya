import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { accountApi, type AccountNotification } from '../api/account';

function notificationIcon(type: string) {
  if (type.includes('order') || type.includes('payment')) return '▣';
  if (type.includes('inquiry') || type.includes('message')) return '✉';
  if (type.includes('review')) return '★';
  if (type.includes('verification') || type.includes('approved')) return '✓';
  if (type.includes('listing') || type.includes('product')) return '□';
  return '●';
}

function relativeTime(value: string) {
  const seconds = Math.max(1, Math.floor((Date.now() - new Date(value).getTime()) / 1000));
  if (seconds < 60) return 'Just now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
  return new Date(value).toLocaleDateString([], { month: 'short', day: 'numeric' });
}

export function NotificationPopover({ initialCount, allUrl }: { initialCount: number; allUrl: string }) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const queryClient = useQueryClient();
  const notifications = useQuery({
    queryKey: ['account', 'notifications'],
    queryFn: accountApi.notifications,
    enabled: open,
    staleTime: 20_000,
  });
  const markOne = useMutation({
    mutationFn: (id: number) => accountApi.markNotificationRead(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['account', 'notifications'] }),
  });
  const markAll = useMutation({
    mutationFn: accountApi.markAllNotificationsRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['account', 'notifications'] }),
  });

  useEffect(() => {
    if (!open) return;
    const closeOutside = (event: MouseEvent) => {
      if (!root.current?.contains(event.target as Node)) setOpen(false);
    };
    const closeEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', closeOutside);
    document.addEventListener('keydown', closeEscape);
    return () => {
      document.removeEventListener('mousedown', closeOutside);
      document.removeEventListener('keydown', closeEscape);
    };
  }, [open]);

  const count = notifications.data?.unread_count ?? initialCount;
  const recent = notifications.data?.data.slice(0, 6) ?? [];
  const openNotification = (item: AccountNotification) => {
    if (item.unread) markOne.mutate(item.id);
    if (item.url) window.location.assign(item.url);
  };

  return (
    <div className="dash-notification-center" ref={root}>
      <button
        type="button"
        className={`dash-notification-trigger ${open ? 'is-open' : ''}`}
        aria-label={`Notifications${count ? `, ${count} unread` : ''}`}
        aria-expanded={open}
        aria-controls="dashboard-notification-popover"
        onClick={() => setOpen((value) => !value)}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
        {count ? <span>{count > 99 ? '99+' : count}</span> : null}
      </button>

      {open ? (
        <section className="dash-notification-popover" id="dashboard-notification-popover" aria-label="Recent notifications">
          <header>
            <div><p>Activity center</p><h2>Notifications {count ? <small>{count} new</small> : null}</h2></div>
            {count ? <button type="button" disabled={markAll.isPending} onClick={() => markAll.mutate()}>Mark all read</button> : null}
          </header>
          <div className="dash-notification-list">
            {notifications.isLoading ? <div className="dash-notification-loading"><i /><i /><i /></div> : null}
            {notifications.error ? <div className="dash-notification-empty"><strong>Could not load notifications</strong><p>Close this panel and try again.</p></div> : null}
            {notifications.data && recent.length === 0 ? (
              <div className="dash-notification-empty"><b>✓</b><strong>You’re all caught up</strong><p>Replies, approvals, orders, and account updates will appear here.</p></div>
            ) : null}
            {recent.map((item) => (
              <button
                type="button"
                className={`dash-notification-item ${item.unread ? 'is-unread' : ''}`}
                onClick={() => openNotification(item)}
                key={item.id}
              >
                <span className={`dash-notification-icon type-${item.type}`}>{notificationIcon(item.type)}</span>
                <span className="dash-notification-copy">
                  <span><strong>{item.title}</strong>{item.unread ? <i aria-label="Unread" /> : null}</span>
                  {item.body ? <em>{item.body}</em> : null}
                  <time dateTime={item.created_at}>{relativeTime(item.created_at)}</time>
                </span>
              </button>
            ))}
          </div>
          <footer><a href={allUrl}>Show all notifications <span aria-hidden="true">→</span></a></footer>
        </section>
      ) : null}
    </div>
  );
}
