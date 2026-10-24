import { useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Button, Card, Switch, Input } from './ui';
import SearchableDropdown from './SearchableDropdown';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import type { RecordItem } from '../data';

type Policy = { totp_enabled: boolean; email_enabled: boolean; require_mfa: boolean };
type UserAccount = { id: number; name: string; email: string; customer_id: number | null; role: string };
const empty: Policy = { totp_enabled: false, email_enabled: false, require_mfa: false };
function messageOf(error: unknown): string {
  return getApiErrorMessage(error, 'The sign-in security settings could not be updated. Please try again.');
}

/** Admin manages client/user enrollment policy; technical mail service configuration stays with the owner. */
export default function ClientMfaPolicies({ customers }: { customers: RecordItem[] }) {
  const [customer, setCustomer] = useState('');
  const [userId, setUserId] = useState('');
  const [users, setUsers] = useState<UserAccount[]>([]);
  const [scope, setScope] = useState<'customer' | 'user'>('customer');
  const [policy, setPolicy] = useState<Policy>(empty);
  const [override, setOverride] = useState(false);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [, setMessage] = useFeedbackState('success');
  const [error, setError] = useFeedbackState('error');

  useEffect(() => {
    if (!customer) { setUsers([]); return; }
    client.get<{ data: UserAccount[] }>('/users', { params: { customer_id: customer, per_page: 100 } })
      .then(({ data }) => setUsers(data.data.filter(u => u.customer_id && u.role.startsWith('customer_'))))
      .catch(e => setError(messageOf(e)));
  }, [customer]);
  useEffect(() => {
    setMessage(''); setError(''); setOverride(false);
    const url = scope === 'customer'
      ? customer && `/admin/client-mfa/customers/${customer}`
      : userId && `/admin/client-mfa/users/${userId}`;
    if (!url) { setPolicy(empty); return; }
    client.get(url).then(({ data }) => {
      if (scope === 'customer') setPolicy(data.data);
      else { setPolicy(data.data.effective); setOverride(Boolean(data.data.override)); }
    }).catch(e => setError(messageOf(e)));
  }, [scope, customer, userId]);

  const save = async () => {
    if (!password) return;
    setBusy(true); setMessage(''); setError('');
    try {
      const url = scope === 'customer' ? `/admin/client-mfa/customers/${customer}` : `/admin/client-mfa/users/${userId}`;
      await client.put(url, { ...policy, require_mfa: false, current_password: password });
      setOverride(scope === 'user'); setPassword('');
      setMessage('Sign-in security settings saved. Email verification is required when enabled. Users can set up Google Authenticator in My Security.');
    } catch (e: any) { setError(messageOf(e)); }
    finally { setBusy(false); }
  };
  const restore = async () => {
    setBusy(true); setError('');
    try {
      await client.delete(`/admin/client-mfa/users/${userId}`, { data: { current_password: password } });
      const { data } = await client.get(`/admin/client-mfa/users/${userId}`);
      setPolicy(data.data.effective); setOverride(false); setPassword(''); setMessage('This account now uses the company sign-in security settings.');
    } catch (e: any) { setError(messageOf(e)); }
    finally { setBusy(false); }
  };
  const filteredUsers = users.filter(u => String(u.customer_id) === customer);
  return <Card className="panel mfa-config-panel client-mfa-settings" aria-label="Client authentication policies">
    <h3>Client MFA management</h3>
    <p>Choose which sign-in factors each client must complete. Manage client email providers in Email Delivery; technical diagnostics remain with Super Admin.</p>
    <p>Companies without saved settings use the organization-wide authentication settings. Save a company policy here to control that client’s sign-in requirements. Individual user settings can override the company policy.</p>
    <div className="policy-selectors">
      <Field>Client company
        <SearchableDropdown value={customer} onChange={e => { setCustomer(e.target.value); setUserId(''); setScope('customer'); }}>
          <option value="">Choose a client</option>{customers.map(c => <option key={c.id} value={c.id}>{String(c.company_name)}</option>)}
        </SearchableDropdown>
      </Field>
      <Field>Policy level
        <SearchableDropdown value={scope} onChange={e => setScope(e.target.value as 'customer' | 'user')}>
          <option value="customer">Whole client company</option><option value="user">Individual customer user</option>
        </SearchableDropdown>
      </Field>
      {scope === 'user' && <Field>Customer account
        <SearchableDropdown value={userId} onChange={e => setUserId(e.target.value)}>
          <option value="">Choose user</option>{filteredUsers.map(u => <option key={u.id} value={u.id}>{u.name} — {u.email}</option>)}
        </SearchableDropdown>
      </Field>}
    </div>
    {(scope === 'customer' ? !!customer : !!userId) && <div className="client-policy-form">
      {scope === 'user' && <p>{override ? 'Custom policy for this account' : 'This user currently inherits the company policy.'}</p>}
      <Field className="mfa-switch"><span><b>Google Authenticator</b><small>Permit time-based authentication on the client account.</small></span>
        <Switch type="checkbox" checked={policy.totp_enabled} onChange={e => setPolicy(p => ({ ...p, totp_enabled: e.target.checked }))}/>
      </Field>
      <Field className="mfa-switch"><span><b>Email OTP</b><small>Available only if the installation has working SMTP delivery.</small></span>
        <Switch type="checkbox" checked={policy.email_enabled} onChange={e => setPolicy(p => ({ ...p, email_enabled: e.target.checked }))}/>
      </Field>
      <p className="hint">Email OTP is mandatory when enabled. Google Authenticator is available for voluntary enrollment and becomes mandatory for each enrolled user. Both apply after Google enrollment. Configure and test email delivery first.</p>
      <Field className="mfa-field">Confirm with your Admin password
        <Input type="password" autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)}/>
      </Field>
      <div className="policy-actions">
        <Button type="button" className="btn btn-primary" disabled={!password || busy} onClick={save}>Save policy</Button>
        {scope === 'user' && override && <Button type="button" className="btn btn-secondary" disabled={!password || busy} onClick={restore}>Restore company policy</Button>}
      </div>
    </div>}

    {error && <p className="error" role="alert">{error}</p>}
  </Card>;
}
