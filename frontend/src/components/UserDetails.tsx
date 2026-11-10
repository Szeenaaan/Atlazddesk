import { useFeedbackState } from './feedbackContext';
import { RefreshButton, UIState } from './ui';
import { useEffect, useState, type ReactNode } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import { formatInstallationDateTime } from '../utils/installationTime';
import SlidingDrawer from './SlidingDrawer';
import { ROLE_LABELS } from '../auth/rolePermissions';
import type { Role } from '../data';

type UserDetailsData = { id: number; name: string; email: string; phone?: string; role: Role; status: string;
  designation?: { name: string; level: number }; workforce_type?: string;
  created_at?: string; last_login_at?: string; email_verified_at?: string;
  customer?: { id: number; company_name: string }; assets?: { id: number; name: string; asset_tag: string }[] };
export default function UserDetails({ id, onClose, hideRole = false, hideCompany = false, hideAssets = false, actions }: { id: number; onClose: () => void; hideRole?: boolean; hideCompany?: boolean; hideAssets?: boolean; actions?: ReactNode }) {
  const [user, setUser] = useState<UserDetailsData | null>(null), [error, setError] = useFeedbackState('error'), [revision, setRevision] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    client.get<UserDetailsData>(`/users/${id}`, { signal: controller.signal }).then(({ data }) => { if (!controller.signal.aborted) { setUser(data); setError(''); } })
      .catch(e => { if (!controller.signal.aborted) setError(getApiErrorMessage(e, 'Could not load user details.')); });
    return () => controller.abort();
  }, [id, revision]);
  const fields: [string, string | undefined][] = user ? [
    ['Name', user.name], ['Email', user.email], ['User', hideRole ? undefined : ROLE_LABELS[user.role]], ['Client', hideCompany ? undefined : user.customer?.company_name],
    ['Designation', user.designation?.name], ['Workforce classification', user.workforce_type],
    ['Status', user.status], ['Phone', user.phone],
    ['Joined', user.created_at && formatInstallationDateTime(user.created_at)],
    ['Last sign-in', user.last_login_at && formatInstallationDateTime(user.last_login_at)],
    ['Email verified', user.email_verified_at && formatInstallationDateTime(user.email_verified_at)],
  ] : [];
  return <SlidingDrawer title="User details" onClose={onClose}><div className="drawer-body record-details user-record-details">
    {error ? <div role="alert"><p className="error">{error}</p><RefreshButton label="Retry loading user details" onClick={() => setRevision(r => r + 1)}/></div>
      : !user ? <UIState tone="loading">Loading user details…</UIState> : <>
        <h2>{user.name}</h2>
        <dl className="record-detail-list">{fields.filter(([, value]) => value?.trim()).map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>
        {!hideAssets && !!user.assets?.length && <section><h3>Assigned assets</h3><ul>{user.assets.map(asset => <li key={asset.id}>{asset.name} · {asset.asset_tag}</li>)}</ul></section>}
        {actions && <div className="ui-actions">{actions}</div>}
      </>}
  </div></SlidingDrawer>;
}
