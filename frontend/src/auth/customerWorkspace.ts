import { advanceAuthEpoch } from '../api/client';

/** Server session context changes apply to every tab sharing the authentication cookie. */
export function reloadCustomerWorkspace(): void {
  advanceAuthEpoch();
  try { localStorage.setItem('amc:workspace-change', crypto.randomUUID()); } catch { /* Server scopes remain authoritative. */ }
  window.location.reload();
}
