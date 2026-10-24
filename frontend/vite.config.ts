import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { appVersion } from './build-version.mjs';
export default defineConfig(({ mode }) => {
  // Server-side only: this setting is not included in the browser bundle.
  const env = loadEnv(mode, '.', 'AMC_DEV_');
  const target = env.AMC_DEV_BACKEND_ORIGIN || 'http://127.0.0.1:8000';
  const origin = new URL(target);
  if (!['http:', 'https:'].includes(origin.protocol) || origin.username || origin.password || origin.pathname !== '/' || origin.search || origin.hash) {
    throw new Error('AMC_DEV_BACKEND_ORIGIN must be an HTTP(S) origin without credentials, a path, or a query.');
  }
  return { define: { __APP_VERSION__: JSON.stringify(appVersion()) }, plugins: [react()], server: { port: 5173, strictPort: true, proxy: {
    '/api': { target: origin.origin, changeOrigin: true },
    '/sanctum': { target: origin.origin, changeOrigin: true },
  } } };
});
