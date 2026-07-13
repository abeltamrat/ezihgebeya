import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent } from 'react';
import { accountApi, ACCOUNT_EXPORT_URL } from '../api/account';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function AccountSettings() {
  const queryClient = useQueryClient();
  const settings = useQuery({ queryKey: ['account', 'settings'], queryFn: accountApi.settings });
  const [marketing, setMarketing] = useState({ sms: true, email: true, push: true });
  const [categories, setCategories] = useState<Record<string, boolean>>({});
  const [deletePassword, setDeletePassword] = useState('');
  const [deleteConfirm, setDeleteConfirm] = useState('');

  useEffect(() => {
    if (!settings.data) return;
    setMarketing({
      sms: settings.data.marketing.sms,
      email: settings.data.marketing.email,
      push: settings.data.marketing.push,
    });
    setCategories(Object.fromEntries(settings.data.categories.map((item) => [item.key, item.enabled])));
  }, [settings.data]);

  const saveMutation = useMutation({
    mutationFn: () => accountApi.saveSettings({ marketing, categories }),
    onSuccess: (data) => {
      queryClient.setQueryData(['account', 'settings'], data);
    },
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    saveMutation.mutate();
  };

  const deleteMutation = useMutation({
    mutationFn: () => accountApi.deleteAccount(deletePassword, deleteConfirm),
    onSuccess: () => {
      // The session is gone server-side (session_unset + regenerate) — leave the SPA
      // entirely rather than navigate within it, since there's no valid session left
      // for any subsequent API call to use.
      window.location.href = '/';
    },
  });

  const onDeleteSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (!window.confirm('Delete your account? This cannot be undone.')) return;
    deleteMutation.mutate();
  };

  const data = settings.data;

  return (
    <DashLayout>
      <h1>Account settings</h1>
      {settings.isLoading && <p className="muted">Loading…</p>}
      {settings.error && <div className="alert alert-error">{settings.error instanceof ApiError ? settings.error.message : 'Could not load settings.'}</div>}

      {data && (
        <>
          <div className="panel">
            <h2>{data.profile.name}</h2>
            <p className="muted">
              {data.profile.account_type.replaceAll('_', ' ')} account · {data.profile.phone || 'No phone'}
              {data.profile.phone_verified ? ' · phone verified' : ''}
            </p>
            {!data.profile.phone_verified ? (
              <a className="btn btn-outline btn-sm" href={data.verify_phone_url}>
                Verify phone
              </a>
            ) : null}
            <div className="btn-row settings-shortcuts">
              <a className="btn btn-outline btn-sm" href="/app/account/favorites">
                Saved products
              </a>
              <a className="btn btn-outline btn-sm" href="/app/account/inquiries">
                My inquiries
              </a>
              <a className="btn btn-outline btn-sm" href="/app/account/notifications">
                Notifications
              </a>
              <a className="btn btn-outline btn-sm" href="/app/account/reviews">
                Reviews & reports
              </a>
            </div>
          </div>

          <form className="panel form-2col" onSubmit={onSubmit}>
            <div className="span2">
              <h2>Notification preferences</h2>
              <p className="muted small">
                Critical account, security, payment, verification, and moderation notices are always sent.
              </p>
            </div>
            <label className="check">
              <input
                type="checkbox"
                checked={marketing.sms}
                disabled={!data.capabilities.marketing_preferences}
                onChange={(e) => setMarketing((current) => ({ ...current, sms: e.target.checked }))}
              /> SMS offers and campaigns
            </label>
            <label className="check">
              <input
                type="checkbox"
                checked={marketing.email}
                disabled={!data.capabilities.marketing_preferences}
                onChange={(e) => setMarketing((current) => ({ ...current, email: e.target.checked }))}
              /> Email offers and newsletters
            </label>
            <label className="check span2">
              <input
                type="checkbox"
                checked={marketing.push}
                disabled={!data.capabilities.marketing_preferences}
                onChange={(e) => setMarketing((current) => ({ ...current, push: e.target.checked }))}
              /> Push / in-app promotional alerts
            </label>

            <div className="span2">
              <h3>Update categories</h3>
            </div>
            {data.categories.map((category) => (
              <label className="check" key={category.key}>
                <input
                  type="checkbox"
                  checked={!!categories[category.key]}
                  disabled={!data.capabilities.notification_preferences}
                  onChange={(e) => setCategories((current) => ({ ...current, [category.key]: e.target.checked }))}
                /> {category.label}
              </label>
            ))}

            {saveMutation.error ? (
              <div className="span2 alert alert-error">
                {saveMutation.error instanceof ApiError ? saveMutation.error.message : 'Could not save preferences.'}
              </div>
            ) : null}
            {saveMutation.isSuccess ? <div className="span2 alert alert-success">Preferences saved.</div> : null}
            <div className="span2">
              <button className="btn btn-primary" disabled={saveMutation.isPending}>
                {saveMutation.isPending ? 'Saving…' : 'Save preferences'}
              </button>
            </div>
          </form>

          <div className="panel">
            <h2>Export your data</h2>
            <p className="muted small">
              Download a copy of your profile, orders, inquiries, reviews, saved products, and notifications as a JSON file.
            </p>
            <a className="btn btn-outline" href={ACCOUNT_EXPORT_URL}>
              ⬇ Download my data
            </a>
          </div>

          <div className="panel">
            <h2>Delete your account</h2>
            <p className="muted small">
              This removes your name and contact details from your profile and past inquiries, and signs you out
              everywhere. Orders remain on record for business purposes, but are no longer linked to a usable
              account. This cannot be undone.
            </p>
            <form className="form-2col" onSubmit={onDeleteSubmit}>
              <label>
                Current password
                <input
                  type="password"
                  required
                  value={deletePassword}
                  onChange={(e) => setDeletePassword(e.target.value)}
                />
              </label>
              <label>
                Type DELETE to confirm
                <input
                  placeholder="DELETE"
                  required
                  value={deleteConfirm}
                  onChange={(e) => setDeleteConfirm(e.target.value)}
                />
              </label>
              {deleteMutation.error ? (
                <div className="span2 alert alert-error">
                  {deleteMutation.error instanceof ApiError ? deleteMutation.error.message : 'Could not delete account.'}
                </div>
              ) : null}
              <div className="span2">
                <button className="btn btn-error" type="submit" disabled={deleteMutation.isPending}>
                  {deleteMutation.isPending ? 'Deleting…' : 'Delete my account'}
                </button>
              </div>
            </form>
          </div>
        </>
      )}
    </DashLayout>
  );
}
