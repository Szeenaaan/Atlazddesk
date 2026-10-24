import axios from 'axios';

// Demo fixtures are never enabled in a production build, even if misconfigured.
export const DEMO = import.meta.env.DEV && import.meta.env.VITE_DEMO_MODE === 'true';
// Use one browser origin for cookies and API requests. Vite proxies development
// requests to Laravel; production routes them through the installation web server.
const backendOrigin = window.location.origin;
export const API = `${backendOrigin}/api/v2`;

// Cookie-based Sanctum SPA auth. Never persist API tokens in localStorage.
export const client = axios.create({
  baseURL: API,
  timeout: 15000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
});

// Ignore expired-session responses from a previous login after the user signs in again.
let authEpoch = 0;
const requestEpochs = new WeakMap<object, number>();
export function advanceAuthEpoch(): void { authEpoch++; }
client.interceptors.request.use(config => {
  requestEpochs.set(config, authEpoch);
  return config;
});

export async function getCsrfCookie(): Promise<void> {
  await client.get(`${backendOrigin}/sanctum/csrf-cookie`, {
    withCredentials: true,
    withXSRFToken: true,
  });
}

client.interceptors.response.use(response => response, error => {
  if (error.response?.status === 401 && error.config
      && requestEpochs.get(error.config) === authEpoch && !String(error.config.url).endsWith('/auth/login')) {
    window.dispatchEvent(new Event('amc:session-expired'));
  }
  if (error.response?.status === 503 && error.response?.data?.code === 'APPLICATION_MAINTENANCE') {
    window.dispatchEvent(new Event('application-maintenance'));
  }
  return Promise.reject(error);
});
