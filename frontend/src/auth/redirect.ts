export function loginUrlForPath(pathname: string): string {
  return `/login?return=${encodeURIComponent(pathname)}`;
}
