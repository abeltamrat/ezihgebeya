import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';

export function VendorVerification() {
  const queryClient = useQueryClient();
  const [level, setLevel] = useState('document_verified');
  const [message, setMessage] = useState('');
  const [files, setFiles] = useState<Record<string, File | null>>({});

  const verification = useQuery({ queryKey: ['vendor', 'verification'], queryFn: vendorApi.verification });

  const submitMutation = useMutation({
    mutationFn: () => {
      const form = new FormData();
      form.append('requested_level', level);
      form.append('message', message);
      if (verification.data?.open?.status === 'changes_requested') form.append('do', 'update');
      Object.entries(files).forEach(([key, file]) => {
        if (file) form.append(`doc_${key}`, file);
      });
      return vendorApi.submitVerification(form);
    },
    onSuccess: () => {
      setMessage('');
      setFiles({});
      queryClient.invalidateQueries({ queryKey: ['vendor', 'verification'] });
    },
  });

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    submitMutation.mutate();
  };

  const open = verification.data?.open;
  const canSubmit = !open || open.status === 'changes_requested';

  return (
    <DashLayout>
      <h1>Verification</h1>
      {verification.isLoading && <p className="muted">Loading…</p>}
      {verification.error && (
        <div className="alert alert-error">{verification.error instanceof ApiError ? verification.error.message : 'Could not load verification.'}</div>
      )}

      {verification.data && (
        <>
          <p className="muted">Current level: {verification.data.current_level.replaceAll('_', ' ')}</p>

          {open ? (
            <div className="panel">
              <h2>
                Request in review <span className={`badge badge-status-${open.status}`}>{open.status.replaceAll('_', ' ')}</span>
              </h2>
              <p className="muted small">
                Requested level: {open.requested_level.replaceAll('_', ' ')} · sent {new Date(open.created_at).toLocaleString()}
              </p>
              {open.admin_note ? <div className="alert alert-warning">Admin note: {open.admin_note}</div> : null}
            </div>
          ) : null}

          {canSubmit ? (
            <form className="panel form-2col" onSubmit={onSubmit}>
              <h2 className="span2">{open ? 'Resubmit documents' : 'Submit verification documents'}</h2>
              <label className="span2">
                Verification level
                <select value={level} onChange={(e) => setLevel(e.target.value)}>
                  {verification.data.levels.map((item) => (
                    <option key={item.key} value={item.key}>
                      {item.label}
                    </option>
                  ))}
                </select>
              </label>
              {verification.data.doc_types.map((doc) => (
                <label key={doc.key}>
                  {doc.label}
                  <input
                    type="file"
                    accept="image/*"
                    onChange={(e) => setFiles((current) => ({ ...current, [doc.key]: e.target.files?.[0] ?? null }))}
                  />
                </label>
              ))}
              <label className="span2">
                Message for reviewer
                <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={2} />
              </label>
              {submitMutation.error ? (
                <div className="span2 alert alert-error">
                  {submitMutation.error instanceof ApiError ? submitMutation.error.message : 'Could not submit verification.'}
                </div>
              ) : null}
              <div className="span2">
                <button className="btn btn-primary" disabled={submitMutation.isPending}>
                  {submitMutation.isPending ? 'Submitting…' : open ? 'Resubmit for review' : 'Submit for verification'}
                </button>
              </div>
            </form>
          ) : null}

          <h2>Request history</h2>
          {verification.data.history.length === 0 && <div className="panel muted">No verification requests yet.</div>}
          {verification.data.history.map((request) => (
            <div className="panel" key={request.id}>
              <div className="review-head">
                <strong>{request.requested_level.replaceAll('_', ' ')}</strong>
                <span className={`badge badge-status-${request.status}`}>{request.status.replaceAll('_', ' ')}</span>
                <span className="muted">{new Date(request.created_at).toLocaleString()}</span>
              </div>
              {request.admin_note ? <p className="muted small">Admin note: {request.admin_note}</p> : null}
              {request.documents.length > 0 ? (
                <div className="btn-row">
                  {request.documents.map((doc) => (
                    <a className="btn btn-outline btn-sm" href={doc.url ?? '#'} target="_blank" rel="noreferrer" key={doc.id}>
                      {doc.label}
                    </a>
                  ))}
                </div>
              ) : null}
            </div>
          ))}
        </>
      )}
    </DashLayout>
  );
}
