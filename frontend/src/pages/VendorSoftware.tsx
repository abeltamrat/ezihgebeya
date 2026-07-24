import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { DashLayout } from '../components/DashLayout';
import { vendorApi, type VendorSoftwareItem } from '../api/vendor';

function formatBytes(bytes: number | null): string | null {
  if (!bytes) return null;
  if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
  if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function SoftwareArtwork({ item }: { item: VendorSoftwareItem }) {
  const screenshot = item.screenshots[0];
  return (
    <div className={`software-artwork software-artwork-${item.item_type}`}>
      {screenshot
        ? <img src={screenshot.url} alt={`Screenshot of ${item.title}`} />
        : <div className="software-artwork-fallback"><span>{item.item_type === 'plugin' ? 'PLG' : 'APP'}</span><small>{item.category ?? 'Vendor tool'}</small></div>}
      <div className="software-card-badges">
        <span>{item.item_type}</span>
        {item.is_featured ? <span className="is-featured">Featured</span> : null}
      </div>
    </div>
  );
}

export function VendorSoftware() {
  const library = useQuery({ queryKey: ['vendor', 'software'], queryFn: vendorApi.softwareLibrary });
  const [search, setSearch] = useState('');
  const [type, setType] = useState('all');
  const [category, setCategory] = useState('all');
  const [platform, setPlatform] = useState('all');
  const [selected, setSelected] = useState<VendorSoftwareItem | null>(null);

  useEffect(() => {
    if (!selected) return;
    const previousOverflow = document.body.style.overflow;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setSelected(null);
    };
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', closeOnEscape);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', closeOnEscape);
    };
  }, [selected]);

  const items = useMemo(() => {
    const query = search.trim().toLocaleLowerCase();
    return (library.data?.data ?? []).filter((item) => {
      const haystack = [item.title, item.short_description, item.description, item.developer, item.category, ...item.platforms]
        .filter(Boolean).join(' ').toLocaleLowerCase();
      return (!query || haystack.includes(query))
        && (type === 'all' || item.item_type === type)
        && (category === 'all' || item.category === category)
        && (platform === 'all' || item.platforms.includes(platform));
    });
  }, [library.data, search, type, category, platform]);

  return (
    <DashLayout>
      <section className="software-library-hero">
        <div>
          <p className="eyebrow">Vendor resource center</p>
          <h1>Software and plugins</h1>
          <p>Tools selected by EzihGebeya to help you design, manage and grow your business.</p>
        </div>
        <div className="software-hero-orbit" aria-hidden="true"><span>&lt;/&gt;</span></div>
      </section>

      <section className="software-library-controls" aria-label="Filter software and plugins">
        <label className="software-search">
          <span aria-hidden="true">⌕</span>
          <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search tools, developers or platforms…" />
        </label>
        <select aria-label="Resource type" value={type} onChange={(event) => setType(event.target.value)}>
          <option value="all">All types</option><option value="software">Software</option><option value="plugin">Plugins</option>
        </select>
        <select aria-label="Category" value={category} onChange={(event) => setCategory(event.target.value)}>
          <option value="all">All categories</option>
          {(library.data?.categories ?? []).map((value) => <option value={value} key={value}>{value}</option>)}
        </select>
        <select aria-label="Platform" value={platform} onChange={(event) => setPlatform(event.target.value)}>
          <option value="all">All platforms</option>
          {(library.data?.platforms ?? []).map((value) => <option value={value} key={value}>{value}</option>)}
        </select>
      </section>

      {library.isLoading ? <div className="software-loading"><span /><span /><span /></div> : null}
      {library.isError ? <div className="alert alert-error">The software library could not be loaded. Refresh and try again.</div> : null}
      {!library.isLoading && !library.isError && items.length === 0 ? (
        <div className="software-library-empty"><span>⌕</span><h2>No matching resources</h2><p>Try a broader search or clear one of the filters.</p></div>
      ) : null}

      <div className="software-library-grid">
        {items.map((item) => (
          <article className="software-library-card" key={item.id}>
            <button className="software-card-open" type="button" onClick={() => setSelected(item)} aria-label={`View ${item.title}`}>
              <SoftwareArtwork item={item} />
              <div className="software-card-copy">
                <div className="software-card-meta">
                  {item.version ? <span>v{item.version}</span> : null}
                  {item.license_type ? <span>{item.license_type}</span> : null}
                  {item.delivery_type === 'external' ? <span>External</span> : null}
                </div>
                <h2>{item.title}</h2>
                <p>{item.short_description}</p>
                <div className="software-platforms">{item.platforms.slice(0, 3).map((value) => <span key={value}>{value}</span>)}</div>
                <div className="software-card-foot"><span>{item.developer ?? 'EzihGebeya resource'}</span><b>View details →</b></div>
              </div>
            </button>
          </article>
        ))}
      </div>

      {selected ? (
        <div className="software-detail-backdrop" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) setSelected(null);
        }}>
          <section className="software-detail-modal" role="dialog" aria-modal="true" aria-labelledby="software-detail-title">
            <button className="software-detail-close" type="button" onClick={() => setSelected(null)} aria-label="Close details">×</button>
            <div className="software-detail-scroll">
              <SoftwareArtwork item={selected} />
              <div className="software-detail-body">
                <div className="software-detail-kicker"><span>{selected.item_type}</span>{selected.category ? <span>{selected.category}</span> : null}</div>
                <h2 id="software-detail-title">{selected.title}</h2>
                <p className="software-detail-lead">{selected.short_description}</p>
                <div className="software-detail-facts">
                  {selected.version ? <div><small>Version</small><strong>{selected.version}</strong></div> : null}
                  {selected.developer ? <div><small>Developer</small><strong>{selected.developer}</strong></div> : null}
                  {selected.license_type ? <div><small>Licence</small><strong>{selected.license_type}</strong></div> : null}
                  {formatBytes(selected.file_size) ? <div><small>Download size</small><strong>{formatBytes(selected.file_size)}</strong></div> : null}
                </div>
                <div className="software-detail-description">
                  {selected.description.split(/\r?\n/).map((paragraph, index) => paragraph.trim() ? <p key={index}>{paragraph}</p> : null)}
                </div>
                {selected.screenshots.length > 1 ? (
                  <div className="software-detail-gallery">
                    {selected.screenshots.map((shot) => <img key={shot.id} src={shot.url} alt={shot.caption ?? `${selected.title} screenshot`} />)}
                  </div>
                ) : null}
                {selected.youtube_embed_url ? (
                  <div className="software-video">
                    <iframe
                      src={selected.youtube_embed_url}
                      title={`${selected.title} video`}
                      loading="lazy"
                      allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                      referrerPolicy="strict-origin-when-cross-origin"
                      allowFullScreen
                    />
                  </div>
                ) : null}
              </div>
            </div>
            <footer className="software-detail-footer">
              <div><strong>Ready to use this resource?</strong><small>{selected.delivery_type === 'external' ? 'You will continue to the publisher’s download page.' : selected.file_name ?? 'Private EzihGebeya download'}</small></div>
              <a className="btn btn-primary" href={selected.download_url}>{selected.delivery_type === 'external' ? 'Open download page ↗' : 'Download now ↓'}</a>
            </footer>
          </section>
        </div>
      ) : null}
    </DashLayout>
  );
}
