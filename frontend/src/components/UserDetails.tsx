import { useFeedbackState } from './feedbackContext';
import { UIState } from './ui';
import { Button } from './ui';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import { formatInstallationDateTime } from '../utils/installationTime';
import SlidingDrawer from './SlidingDrawer';

type UserDetailsData = { id: number; name: string; email: string; phone?: string; role: string; status: string;
  created_at?: string; last_login_at?: string; email_verified_at?: string;
  customer?: { id: number; company_name: string }; assets?: { id: number; name: string; asset_tag: string }[] };
const roles: Record<string, string> = { admin: 'Contractor Admin', technician: 'Technician', customer_admin: 'Customer Admin', customer_user: 'Customer User' };
export default function UserDetails({ id, onClose, hideRole = false, hideCompany = false, hideAssets = false }: { id: number; onClose: () => void; hideRole?: boolean; hideCompany?: boolean; hideAssets?: boolean }) {
  const [user, setUser] = useState<UserDetailsData | null>(null), [error, setError] = useFeedbackState('error'), [revision, setRevision] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    client.get<UserDetailsData>(`/users/${id}`, { signal: controller.signal }).then(({ data }) => { setUser(data); setError(''); })
      .catch(e => { if (!controller.signal.aborted) setError(getApiErrorMessage(e, 'Could not load user details.')); });
    return () => controller.abort();
  }, [id, revision]);
  const fields: [string, string | undefined][] = user ? [
    ['Name', user.name], ['Email', user.email], ['Role', hideRole ? undefined : roles[user.role] || user.role], ['Client', hideCompany ? undefined : user.customer?.company_name],
    ['Status', user.status], ['Phone', user.phone],
    ['Joined', user.created_at && formatInstallationDateTime(user.created_at)],
    ['Last sign-in', user.last_login_at && formatInstallationDateTime(user.last_login_at)],
    ['Email verified', user.email_verified_at && formatInstallationDateTime(user.email_verified_at)],
  ] : [];
  return <SlidingDrawer title="User details" onClose={onClose}><div className="drawer-body record-details">
    {error ? <div role="alert"><p className="error">{error}</p><Button className="btn btn-secondary" type="button" onClick={() => setRevision(r => r + 1)}>Retry</Button></div>
      : !user ? <UIState tone="loading">Loading user details…</UIState> : <>
        <h2>{user.name}</h2>
        <dl className="record-detail-list">{fields.filter(([, value]) => value?.trim()).map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>
        {!hideAssets && !!user.assets?.length && <section><h3>Assigned assets</h3><ul>{user.assets.map(asset => <li key={asset.id}>{asset.name} · {asset.asset_tag}</li>)}</ul></section>}
      </>}
  </div></SlidingDrawer>;
}
