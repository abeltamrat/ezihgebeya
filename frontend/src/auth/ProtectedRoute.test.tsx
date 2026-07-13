import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ProtectedRoute } from './ProtectedRoute';
import { loginUrlForPath } from './redirect';
import { useSession } from './session';

vi.mock('./session', () => ({
  useSession: vi.fn(),
}));

const mockUseSession = vi.mocked(useSession);

function setSession(overrides: Partial<ReturnType<typeof useSession>> = {}) {
  mockUseSession.mockReturnValue({
    loading: false,
    authenticated: true,
    user: {
      id: 1,
      name: 'Vendor User',
      phone: '0911000000',
      email: null,
      account_type: 'seller',
      phone_verified: true,
    },
    shell: null,
    refresh: vi.fn(),
    ...overrides,
  });
}

describe('ProtectedRoute', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    window.history.pushState({}, '', '/app/vendor');
  });

  it('shows a loading state while the shared PHP session is being checked', () => {
    setSession({ loading: true, authenticated: false, user: null });

    render(
      <ProtectedRoute>
        <div>Private screen</div>
      </ProtectedRoute>,
    );

    expect(screen.getByText('Loading…')).toBeTruthy();
    expect(screen.queryByText('Private screen')).toBeNull();
  });

  it('renders children for an authenticated user with an allowed role', () => {
    setSession();

    render(
      <ProtectedRoute roles={['seller']}>
        <div>Private screen</div>
      </ProtectedRoute>,
    );

    expect(screen.getByText('Private screen')).toBeTruthy();
  });

  it('renders no protected content for a logged-out user while sending them to PHP login', () => {
    setSession({ authenticated: false, user: null });

    render(
      <ProtectedRoute roles={['seller']}>
        <div>Private screen</div>
      </ProtectedRoute>,
    );

    expect(screen.queryByText('Private screen')).toBeNull();
    expect(loginUrlForPath('/app/vendor')).toBe('/login?return=%2Fapp%2Fvendor');
  });

  it('blocks an authenticated user with the wrong role before the API would reject them', () => {
    setSession({ user: { id: 2, name: 'Customer', phone: '0922000000', email: null, account_type: 'customer', phone_verified: true } });

    render(
      <ProtectedRoute roles={['seller']}>
        <div>Vendor-only screen</div>
      </ProtectedRoute>,
    );

    expect(screen.getByText('Not authorized for this account type.')).toBeTruthy();
    expect(screen.queryByText('Vendor-only screen')).toBeNull();
  });
});
