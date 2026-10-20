import axios from 'axios';

// Demo fixtures are never enabled in a production build, even if misconfigured.
export const DEMO = import.meta.env.DEV && import.meta.env.VITE_DEMO_MODE === 'true';
// Development uses Laravel's separate server; production shares the HTTPS site origin.
const backendOrigin = import.meta.env.DEV
  ? `${window.location.protocol}//${window.location.hostname}:8000`
  : window.location.origin;
export const API = `${backendOrigin}/api/v2`;

// Cookie-based Sanctum SPA auth. Never persist API tokens in localStorage.
export const client = axios.create({
  baseURL: API,
  timeout: 15000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
});

export async function getCsrfCookie(): Promise<void> {
  await client.get(`${backendOrigin}/sanctum/csrf-cookie`, {
    withCredentials: true,
    withXSRFToken: true,
  });
}
