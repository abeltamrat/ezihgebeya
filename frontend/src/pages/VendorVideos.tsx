import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function VendorVideos() {
  const queryClient = useQueryClient();
  const [fields, setFields] = useState({
    platform: 'tiktok',
    original_url: '',
    linked_type: 'business',
    linked_id: '',
    cta_label: 'Check Now',
    title: '',
  });

  const meta = useQuery({ queryKey: ['vendor', 'videos', 'meta'], queryFn: vendorApi.videoMeta });
  const videos = useQuery({ queryKey: ['vendor', 'videos'], queryFn: vendorApi.videos });

  const createMutation = useMutation({
    mutationFn: () =>
      vendorApi.createVideo({
        ...fields,
        linked_id: fields.linked_id ? Number(fields.linked_id) : null,
      }),
    onSuccess: () => {
      setFields((f) => ({ ...f, original_url: '', title: '', linked_type: 'business', linked_id: '' }));
      queryClient.invalidateQueries({ queryKey: ['vendor', 'videos'] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'dashboard'] });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => vendorApi.deleteVideo(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vendor', 'videos'] });
      queryClient.invalidateQueries({ queryKey: ['vendor', 'dashboard'] });
    },
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    createMutation.mutate();
  };

  const linkedOptions = fields.linked_type === 'business' ? [] : meta.data?.owned_listings[fields.linked_type as 'product' | 'service' | 'supply'] ?? [];

  return (
    <DashLayout>
      <h1>My Videos</h1>
      <p className="muted">Paste TikTok or YouTube links. Link each video to a listing or your shop page so viewers can act.</p>

      {meta.error && <div className="alert alert-error">{meta.error instanceof ApiError ? meta.error.message : 'Could not load video options.'}</div>}
      {createMutation.error && (
        <div className="alert alert-error">{createMutation.error instanceof ApiError ? createMutation.error.message : 'Could not submit video.'}</div>
      )}

      <form className="panel form-2col" onSubmit={onSubmit}>
        <label>
          Platform
          <select value={fields.platform} onChange={(e) => setFields((f) => ({ ...f, platform: e.target.value }))}>
            <option value="tiktok">TikTok</option>
            <option value="youtube">YouTube / Shorts</option>
          </select>
        </label>
        <label>
          Video URL *
          <input required value={fields.original_url} onChange={(e) => setFields((f) => ({ ...f, original_url: e.target.value }))} />
        </label>
        <label>
          Link to
          <select value={fields.linked_type} onChange={(e) => setFields((f) => ({ ...f, linked_type: e.target.value, linked_id: '' }))}>
            <option value="business">My shop page</option>
            <option value="product">A product</option>
            <option value="service">A service</option>
            <option value="supply">A supply item</option>
          </select>
        </label>
        <label>
          Linked listing
          <select
            value={fields.linked_id}
            disabled={fields.linked_type === 'business'}
            required={fields.linked_type !== 'business'}
            onChange={(e) => setFields((f) => ({ ...f, linked_id: e.target.value }))}
          >
            <option value="">—</option>
            {linkedOptions.map((item) => (
              <option key={item.id} value={item.id}>
                {item.title}
              </option>
            ))}
          </select>
        </label>
        <label>
          CTA button
          <select value={fields.cta_label} onChange={(e) => setFields((f) => ({ ...f, cta_label: e.target.value }))}>
            {(meta.data?.cta_labels ?? ['Check Now']).map((label) => (
              <option key={label} value={label}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <label>
          Caption
          <input maxLength={255} value={fields.title} onChange={(e) => setFields((f) => ({ ...f, title: e.target.value }))} />
        </label>
        <div className="span2">
          <button className="btn btn-primary" disabled={createMutation.isPending || meta.data?.can_add_video === false}>
            {createMutation.isPending ? 'Submitting…' : 'Submit video for review'}
          </button>
          {meta.data?.can_add_video === false ? (
            <p className="muted small">Video limit reached for your {meta.data.plan} plan.</p>
          ) : null}
        </div>
      </form>

      <h2>Submitted videos ({videos.data?.data.length ?? 0})</h2>
      {videos.isLoading && <p className="muted">Loading…</p>}
      {videos.data && videos.data.data.length === 0 && <div className="panel muted">No videos yet.</div>}
      {videos.error && <div className="alert alert-error">{videos.error instanceof ApiError ? videos.error.message : 'Could not load videos.'}</div>}

      {videos.data && videos.data.data.length > 0 && (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Platform</th>
                <th>Caption</th>
                <th>Linked</th>
                <th>Status</th>
                <th>Views</th>
                <th>CTA clicks</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {videos.data.data.map((video) => (
                <tr key={video.id}>
                  <td>{video.platform === 'tiktok' ? 'TikTok' : 'YouTube'}</td>
                  <td>
                    <a href={video.original_url} target="_blank" rel="noreferrer">
                      {video.title || video.original_url}
                    </a>
                  </td>
                  <td>{video.linked_type}</td>
                  <td>
                    <span className={`badge badge-status-${video.status}`}>{video.status}</span>
                  </td>
                  <td>{video.views_count}</td>
                  <td>{video.cta_clicks_count}</td>
                  <td>
                    <button
                      className="btn btn-outline btn-sm"
                      disabled={deleteMutation.isPending}
                      onClick={() => {
                        if (confirm('Remove this video?')) deleteMutation.mutate(video.id);
                      }}
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </DashLayout>
  );
}
