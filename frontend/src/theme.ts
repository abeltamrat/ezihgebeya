// Applies the System UI Optimizer's runtime design tokens (delivered via /api/v1/me
// shell.theme, computed server-side by system_ui_css_vars()) onto :root, overriding the
// static fallback values in index.css. This keeps the SPA visually in sync with the PHP
// pages whenever an admin re-themes the site — same brand color, radii, shadows, font.
export function applyServerTheme(theme: Record<string, string> | null | undefined): void {
  if (!theme) return;
  const root = document.documentElement;
  for (const [name, value] of Object.entries(theme)) {
    if (name.startsWith('--') && typeof value === 'string') {
      root.style.setProperty(name, value);
    }
  }
  const brand = theme['--brand'];
  if (brand && /^#[0-9a-fA-F]{6}$/.test(brand)) {
    let meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.name = 'theme-color';
      document.head.appendChild(meta);
    }
    meta.content = brand;
  }
}
