import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { accountApi } from '../api/account';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function AccountNotifications() {
  const queryClient = useQueryClient();
  const notifications = useQuery({ queryKey: ['account', 'notifications'], queryFn: accountApi.notifications });

  const markOne = useMutation({
    mutationFn: (id: number) => accountApi.markNotificationRead(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['account', 'notifications'] }),
  });
  const markAll = useMutation({
    mutationFn: accountApi.markAllNotificationsRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['account', 'notifications'] }),
  });

  return (
    <DashLayout>
      <div className="section-head">
        <h1>Notifications{notifications.data?.unread_count ? ` (${notifications.data.unread_count} new)` : ''}</h1>
        {notifications.data?.unread_count ? (
          <button className="btn btn-outline btn-sm" disabled={markAll.isPending} onClick={() => markAll.mutate()}>
            Mark all read
          </button>
        ) : null}
      </div>
      {notifications.isLoading && <p className="muted">Loading…</p>}
      {notifications.error && <div className="alert alert-error">{notifications.error instanceof ApiError ? notifications.error.message : 'Could not load notifications.'}</div>}
      {notifications.data && notifications.data.data.length === 0 && (
        <div className="panel muted">Nothing yet — you’ll see replies, approvals, and order updates here.</div>
      )}
      {notifications.data?.data.map((item) => {
        const content = (
          <>
            <div className="review-head">
              <strong>{item.title}</strong>
              {item.unread ? <span className="mini-pill">new</span> : null}
              <span className="muted">{new Date(item.created_at).toLocaleString()}</span>
            </div>
            {item.body ? <p className="muted small">{item.body}</p> : null}
          </>
        );
        return (
          <div className="panel notification-card" data-unread={item.unread ? 'true' : 'false'} key={item.id}>
            {item.url ? <a href={item.url} onClick={() => markOne.mutate(item.id)}>{content}</a> : content}
            {item.unread ? (
              <button className="btn btn-outline btn-sm" disabled={markOne.isPending} onClick={() => markOne.mutate(item.id)}>
                Mark read
              </button>
            ) : null}
          </div>
        );
      })}
    </DashLayout>
  );
}
