import type { ReactNode } from 'react';

export type ListingBadgeVariant = 'featured' | 'promoted' | 'discount' | 'condition' | 'delivery' | 'negotiable' | 'verified';
type IconName = 'star' | 'trend' | 'tag' | 'box' | 'truck' | 'offer' | 'check';

const ICONS: Record<IconName, string> = {
  star: 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z',
  trend: 'M5 17 11 11l4 4 5-7 M15 8h5v5',
  tag: 'M3 5v6l10 10 8-8L11 3H5a2 2 0 0 0-2 2z M8 8h.01',
  box: 'm4 7 8-4 8 4-8 4z M4 7v10l8 4 8-4V7 M12 11v10',
  truck: 'M3 6h11v11H3z M14 10h4l3 3v4h-7z M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4 M18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
  offer: 'M12 3v18 M16 7.5C16 5.6 14.2 5 12 5s-4 .8-4 3 4 3 4 3 4 .8 4 3-1.8 3-4 3-4-.6-4-2.5',
  check: 'M5 12.5 9.5 17 19 7',
};

const DEFAULT_ICONS: Record<ListingBadgeVariant, IconName> = {
  featured: 'star', promoted: 'trend', discount: 'tag', condition: 'box',
  delivery: 'truck', negotiable: 'offer', verified: 'check',
};

export function ListingBadge({ variant, children, icon }: { variant: ListingBadgeVariant; children: ReactNode; icon?: IconName }) {
  return (
    <span className={`badge badge-${variant}`}>
      <svg className="badge-icon" viewBox="0 0 24 24" aria-hidden="true"><path d={ICONS[icon ?? DEFAULT_ICONS[variant]]} /></svg>
      <span>{children}</span>
    </span>
  );
}
