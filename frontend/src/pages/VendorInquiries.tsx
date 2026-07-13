import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

const FALLBACK_STATUSES = ['new', 'seen', 'responded', 'negotiating', 'converted', 'closed', 'spam'];

export function VendorInquiries() {
  const [searchParams, setSearchParams] = useSearchParams();
  const status = searchParams.get('status') ?? '';
  const queryClient = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: ['vendor', 'inquiries', status],
    queryFn: () => vendorApi.inquiries(status),
  });

  const statusMutation = useMutation({
    mutationFn: ({ id, nextStatus }: { id: number; nextStatus: string }) => vendorApi.updateInquiryStatus(id, nextStatus),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor', 'inquiries'] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'dashboard'] });
    },
  });

  const statuses = data?.statuses ?? FALLBACK_STATUSES;

  return (
    <DashLayout>
      <div className="page-title">
        <div>
          <p className="eyebrow">Customer inbox</p>
          <h1>Inquiries <span className="count-pill">{data?.data.length ?? 0}</span></h1>
        </div>
        <select className="filter-select" value={status} onChange={(e) => setSearchParams(e.target.value ? { status: e.target.value } : {})}>
          <option value="">All statuses</option>
          {statuses.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
        </select>
      </div>

      {isLoading && <p className="muted">Loading…</p>}
      {error && <div className="alert alert-error">{error instanceof ApiError ? error.message : 'Could not load inquiries.'}</div>}
      {data && data.data.length === 0 && <div className="panel muted">No inquiries{status ? ` with status “${status}”` : ''} yet.</div>}

      {data?.data.map((i) => (
        <div className="panel inq-card" key={i.id}>
          <div className="inquiry-card-head">
            <div className="activity-avatar">{(i.name || 'C').slice(0, 1).toUpperCase()}</div>
            <div className="inquiry-title">
              <strong>{i.name || 'Customer'}</strong>
              <div className="muted small">
                {i.inquiry_type.replaceAll('_', ' ')}
                {i.listing_title ? ` · ${i.listing_title}` : ''}
              </div>
            </div>
            <div className="inquiry-meta">
              {i.phone ? <a href={`tel:${i.phone}`}>{i.phone}</a> : null}
              <span className="badge badge-muted">{i.preferred_contact_method}</span>
              <span className={`badge badge-status-${i.status}`}>{i.status}</span>
            </div>
          </div>
          <p className="inquiry-message">{i.message}</p>
          <div className="inquiry-actions">
            <span className="muted small">{new Date(i.created_at).toLocaleString()}</span>
            <Link className="btn btn-primary btn-sm" to={`/vendor/inquiries/${i.id}`}>
              Reply{i.message_count ? ` (${i.message_count})` : ''}
            </Link>
            <select
              value={i.status}
              disabled={statusMutation.isPending}
              onChange={(e) => statusMutation.mutate({ id: i.id, nextStatus: e.target.value })}
            >
              {statuses.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </select>
          </div>
        </div>
      ))}
    </DashLayout>
  );
}
