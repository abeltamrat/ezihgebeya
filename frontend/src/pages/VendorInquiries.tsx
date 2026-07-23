import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { vendorApi } from '../api/vendor';
import { ApiError } from '../api/client';
import { DashLayout } from '../components/DashLayout';
import { VendorConversationList } from '../components/VendorConversationList';

export function VendorInquiries() {
  const [search, setSearch] = useState('');
  const { data, isLoading, error } = useQuery({
    queryKey: ['vendor', 'inquiries', ''],
    queryFn: () => vendorApi.inquiries(''),
  });

  return (
    <DashLayout>
      {isLoading ? <p className="muted">Loading…</p> : null}
      {error ? <div className="alert alert-error">{error instanceof ApiError ? error.message : 'Could not load inquiries.'}</div> : null}
      {data ? (
        <div className="tg-inbox">
          <VendorConversationList inquiries={data.data} search={search} onSearch={setSearch} />
          <section className="tg-empty-chat">
            <div className="tg-empty-icon" aria-hidden="true">✉</div>
            <strong>Select a conversation</strong>
            <p>Choose an inquiry from the left to read and reply.</p>
          </section>
        </div>
      ) : null}
    </DashLayout>
  );
}
