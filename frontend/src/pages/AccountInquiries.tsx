import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { accountApi } from '../api/account';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function AccountInquiries() {
  const inquiries = useQuery({ queryKey: ['account', 'inquiries'], queryFn: accountApi.inquiries });

  return (
    <DashLayout>
      <h1>My inquiries ({inquiries.data?.data.length ?? 0})</h1>
      {inquiries.isLoading && <p className="muted">Loading…</p>}
      {inquiries.error && <div className="alert alert-error">{inquiries.error instanceof ApiError ? inquiries.error.message : 'Could not load inquiries.'}</div>}
      {inquiries.data && inquiries.data.data.length === 0 && <div className="panel muted">No inquiries sent yet.</div>}
      {inquiries.data && inquiries.data.data.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead><tr><th>Date</th><th>To</th><th>Listing</th><th>Message</th><th>Status</th><th></th></tr></thead>
            <tbody>
              {inquiries.data.data.map((i) => (
                <tr key={i.id}>
                  <td>{new Date(i.created_at).toLocaleDateString()}</td>
                  <td>{i.business_name}</td>
                  <td>{i.listing_title || i.listing_type}</td>
                  <td>{(i.message ?? '').slice(0, 70)}</td>
                  <td><span className={`badge badge-status-${i.status}`}>{i.status}</span></td>
                  <td><Link className="btn btn-outline btn-sm" to={`/account/inquiries/${i.id}`}>Chat{i.message_count ? ` (${i.message_count})` : ''}</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </DashLayout>
  );
}
