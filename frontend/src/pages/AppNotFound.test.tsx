import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AppNotFound } from './AppNotFound';
import { dashboardPath } from '../auth/dashboardPath';
import { useSession } from '../auth/session';

vi.mock('../auth/session', () => ({ useSession: vi.fn() }));
const mockUseSession = vi.mocked(useSession);

describe('authenticated app 404', () => {
  it('chooses a dashboard that matches the account role', () => {
    expect(dashboardPath('customer')).toBe('/app/account');
    expect(dashboardPath('seller')).toBe('/app/vendor');
    expect(dashboardPath('super_admin')).toBe('/admin');
  });

  it('does not silently redirect an invalid route to the vendor dashboard', () => {
    mockUseSession.mockReturnValue({
      loading: false,
      authenticated: true,
      user: { id: 7, name: 'Customer', phone: '0900000000', email: null, account_type: 'customer', phone_verified: true },
      shell: { authenticated: true, home_url: '/', browse_url: '/products', cart_url: '/cart', cart_count: 0, cart_enabled: true, sell_url: '/register', sell_label: 'Sell' },
      refresh: vi.fn(),
    });

    render(<AppNotFound />);
    expect(screen.getByRole('heading', { name: 'This app page does not exist' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Go to dashboard' }).getAttribute('href')).toBe('/app/account');
  });
});
