/// <reference types="vitest/config" />
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Production layout (Phase 5): public_html/app/index.html + app/assets/, served under /app/*
// (BASE_URL is '' for the root-domain deploy per config.php, so the SPA itself lives at /app/).
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, '..', 'APP_')
  const publicBase = (env.APP_BASE_URL ?? '').replace(/\/+$/, '')
  return {
  base: `${publicBase}/app/`,
  plugins: [react(), tailwindcss()],
  server: {
    proxy: {
      // During local dev, the Vite dev server proxies API calls to the PHP built-in server
      // so the browser sees one origin and the session cookie / CSRF flow works unmodified.
      '/api': 'http://localhost:8899',
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
  },
  }
})
