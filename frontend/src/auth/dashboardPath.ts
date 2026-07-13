const VENDOR_ROLES = ['seller', 'manufacturer', 'importer', 'service_provider', 'supplier'];

export function dashboardPath(accountType?: string): string {
  if (accountType === 'admin' || accountType === 'super_admin') return '/app/admin/health';
  if (accountType && VENDOR_ROLES.includes(accountType)) return '/app/vendor';
  return '/app/account';
}
