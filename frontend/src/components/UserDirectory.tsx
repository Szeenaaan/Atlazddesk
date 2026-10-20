import UserAssetPicker from './UserAssetPicker';
import UserAssetEditor from './UserAssetEditor';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import FeedbackToast, { type Feedback } from './FeedbackToast';
import type { Role, RecordItem } from '../data';

type Account = { id: number; name: string; email: string; role: Role; customer_id: number | null; status: string };
type Props = { role: Role; customerId: number | null; customers: RecordItem[] };

const labels: Record<string, string> = {
  admin: 'Company Admin', technician: 'Technician', customer_admin: 'Customer Admin', customer_user: 'Customer User',
};
function errorText(error: any): string {
  const errors = error.response?.data?.errors;
  return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'Request failed.';
}

export default function UserDirectory({ role, customerId, customers }: Props) {
  const roles: Role[] = role === 'admin' ? ['technician', 'customer_admin', 'customer_user']
    : role === 'customer_admin' ? ['customer_user'] : [];
  const [assetIds, setAssetIds] = useState<number[]>([]);
  const [assetTarget, setAssetTarget] = useState<Account | null>(null);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [search, setSearch] = useState('');
  const [clientFilter, setClientFilter] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [pageNumber, setPageNumber] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', role: roles[0] ?? 'customer_user', customer_id: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [recoveryTarget, setRecoveryTarget] = useState<Account | null>(null);
  const [recoveryReason, setRecoveryReason] = useState('');
  const [recoveryPassword, setRecoveryPassword] = useState('');
  const [recoveryError, setRecoveryError] = useState('');
  const [recoveryFeedback, setRecoveryFeedback] = useState<Feedback | null>(null);
  async function load(targetPage = pageNumber) {
    const params: Record<string, string | number | boolean> = { per_page: 25, page: targetPage };
    if (search.trim()) params.search = search.trim();
    if (role === 'admin' && clientFilter === 'workforce') params.workforce_only = 1;
    else if (role === 'admin' && clientFilter) params.customer_id = Number(clientFilter);
    if (role === 'admin' && roleFilter) params.role = roleFilter;
    if (statusFilter) params.status = statusFilter;
    const { data } = await client.get<{ data: Account[]; total: number; last_page: number }>('/users', { params });
    setAccounts(data.data);
    setTotal(data.total);
    setLastPage(data.last_page);
  }
  useEffect(() => {
    const timeout = window.setTimeout(() => { void load().catch(e => setError(errorText(e))); }, 250);
    return () => window.clearTimeout(timeout);
    // load is intentionally refetched whenever the selected filters or page change.
  }, [role, search, clientFilter, roleFilter, statusFilter, pageNumber]);
  async function create(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setError(''); setMessage('');
    try {
      const requiresCustomer = form.role === 'customer_admin' || form.role === 'customer_user';
      const payload = {
        ...form,
        ...(form.role === 'customer_user' ? { asset_ids: assetIds } : {}),
        ...(role === 'customer_admin' ? { customer_id: undefined } : { customer_id: requiresCustomer ? Number(form.customer_id) : undefined }),
      };
      if (requiresCustomer && role !== 'customer_admin' && !payload.customer_id) throw new Error('Choose a client company.');
      await client.post('/users', payload);
      setAssetIds([]);
      setForm(p => ({ ...p, name: '', email: '', password: '', password_confirmation: '' }));
      await load(); setMessage('User created. Share initial credentials securely and require a password change.');
    } catch (e: any) { setError(e.message === 'Choose a client company.' ? e.message : errorText(e)); }
    finally { setBusy(false); }
  }
  async function toggle(user: Account) {
    setError(''); setMessage('');
    try { await client.patch(`/users/${user.id}/status`, { status: user.status === 'active' ? 'inactive' : 'active' }); await load(); }
    catch (e: any) { setError(errorText(e)); }
  }
  async function submitRecovery(e: React.FormEvent) {
    e.preventDefault();
    if (!recoveryTarget) return;
    setBusy(true); setRecoveryError(''); setRecoveryFeedback(null);
    try {
      await client.post(`/users/${recoveryTarget.id}/mfa/authenticator/reset`, {
        current_password: recoveryPassword, reason: recoveryReason,
      });
      setRecoveryFeedback({ tone: 'success', message: `Authenticator reset for ${recoveryTarget.email}. The old authenticator is revoked. The user can enroll a replacement in My Security.` });
      setRecoveryTarget(null); setRecoveryPassword(''); setRecoveryReason('');
    } catch (err) { setRecoveryError(errorText(err)); }
    finally { setBusy(false); }
  }
  if (roles.length === 0) return <section className="panel user-management"><p>Not authorized to manage client users.</p></section>;
  return <section className="panel user-management">
    <h3>{role === 'customer_admin' ? 'My customer users' : 'User administration'}</h3>
    
    <form className="admin-form" onSubmit={create}>
      <label>Full name<input required maxLength={255} value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))}/></label>
      <label>Email address<input required type="email" autoComplete="off" value={form.email} onChange={e => setForm(p => ({ ...p, email: e.target.value }))}/></label>
      <label>Role<select value={form.role} onChange={e => { setForm(p => ({ ...p, role: e.target.value as Role })); setAssetIds([]); }}>
        {roles.map(r => <option value={r} key={r}>{labels[r]}</option>)}
      </select></label>
      {(form.role === 'customer_admin' || form.role === 'customer_user') && role !== 'customer_admin' &&
        <label>Client company<select required value={form.customer_id} onChange={e => { setForm(p => ({ ...p, customer_id: e.target.value })); setAssetIds([]); }}>
          <option value="">Select company</option>{customers.map(c => <option value={c.id} key={c.id}>{String(c.company_name)}</option>)}
        </select></label>}
      
      {form.role === 'customer_user' && <UserAssetPicker customerId={role === 'customer_admin' ? customerId : Number(form.customer_id) || null} value={assetIds} onChange={setAssetIds} disabled={busy}/>}
      <label>Temporary password<input required type="password" minLength={12} autoComplete="new-password" value={form.password} onChange={e => setForm(p => ({ ...p, password: e.target.value }))}/></label>
      <label>Confirm password<input required type="password" minLength={12} autoComplete="new-password" value={form.password_confirmation} onChange={e => setForm(p => ({ ...p, password_confirmation: e.target.value }))}/></label>
      <button className="btn btn-primary" disabled={busy} type="submit">{busy ? 'Creating…' : 'Create account'}</button>
    </form>
    {error && <p role="alert" className="error">{error}</p>}{message && <p className="success-note">{message}</p>}
    {assetTarget && <UserAssetEditor key={assetTarget.id} user={assetTarget} onClose={() => setAssetTarget(null)}/>}
    {recoveryTarget && <form className="admin-form" onSubmit={submitRecovery}>
      <h4>Reset authenticator — {recoveryTarget.name}</h4>
      <p className="amc-inline-warning">Only perform this after verifying the person's identity. Existing sessions and backup codes will be invalidated. The user may enroll a replacement in My Security. Required Email OTP remains enforced.</p>
      <label>Recovery reason / approved support reference
        <input required minLength={15} maxLength={500} value={recoveryReason} onChange={e => setRecoveryReason(e.target.value)}/>
      </label>
      <label>Your administrator password
        <input required type="password" autoComplete="current-password" value={recoveryPassword} onChange={e => setRecoveryPassword(e.target.value)}/>
      </label>
      {recoveryError && <p role="alert" className="amc-field-error">{recoveryError}</p>}
      <button className="btn btn-primary" disabled={busy || recoveryReason.length < 15 || !recoveryPassword}>Confirm reset</button>
      <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => { setRecoveryTarget(null); setRecoveryPassword(''); setRecoveryReason(''); setRecoveryError(''); }}>Cancel</button>
    </form>}
    <FeedbackToast feedback={recoveryFeedback} onDismiss={() => setRecoveryFeedback(null)}/>
    <div className="amc-user-filters" role="search" aria-label="Filter user accounts">
      <label>Search users<input type="search" maxLength={100} value={search} onChange={e => { setSearch(e.target.value); setPageNumber(1); }}/></label>
      {role === 'admin' && <label>Client company<select value={clientFilter} onChange={e => { setClientFilter(e.target.value); setPageNumber(1); }}>
        <option value="">All accounts</option><option value="workforce">Contractor workforce</option>
        {customers.map(c => <option key={c.id} value={c.id}>{String(c.company_name)}</option>)}
      </select></label>}
      {role === 'admin' && <label>Role<select value={roleFilter} onChange={e => { setRoleFilter(e.target.value); setPageNumber(1); }}>
        <option value="">All roles</option><option value="admin">Contractor Admin</option>{roles.map(r => <option key={r} value={r}>{labels[r] || r}</option>)}
      </select></label>}
      <label>Status<select value={statusFilter} onChange={e => { setStatusFilter(e.target.value); setPageNumber(1); }}>
        <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
      </select></label>
      <button className="btn btn-secondary" type="button" onClick={() => { setSearch(''); setClientFilter(''); setRoleFilter(''); setStatusFilter(''); setPageNumber(1); }}>Clear filters</button>
    </div>
    <div className="amc-list-summary">{total} users</div>
    <div className="table-scroll"><table><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Client</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>{accounts.map(u => <tr key={u.id}><td>{u.name}</td><td>{u.email}</td><td>{labels[u.role] || u.role}</td><td>{u.customer_id ? String(customers.find(c => Number(c.id) === Number(u.customer_id))?.company_name || 'Client #' + u.customer_id) : 'Contractor workforce'}</td><td>{u.status}</td><td>{u.role === 'customer_user' && <button className="btn btn-secondary" type="button" onClick={() => setAssetTarget(u)}>Assign assets</button>}{u.role !== 'super_admin' && <button className="btn btn-secondary" type="button" onClick={() => toggle(u)}>{u.status === 'active' ? 'Deactivate' : 'Activate'}</button>}{['technician', 'customer_admin', 'customer_user'].includes(u.role) && <button className="btn btn-secondary" type="button" disabled={busy} onClick={() => { setRecoveryTarget(u); setRecoveryReason(''); setRecoveryPassword(''); setRecoveryError(''); }}>Reset authenticator</button>}</td></tr>)}</tbody></table>{accounts.length === 0 && <div className="amc-muted-empty">No matching accounts.</div>}</div>
    {lastPage > 1 && <nav className="amc-list-pagination" aria-label="User list pages">
      <button type="button" className="btn btn-secondary" disabled={pageNumber <= 1} onClick={() => setPageNumber(n => Math.max(1, n - 1))}>Previous</button>
      <span>Page {pageNumber} of {lastPage}</span>
      <button type="button" className="btn btn-secondary" disabled={pageNumber >= lastPage} onClick={() => setPageNumber(n => Math.min(lastPage, n + 1))}>Next</button>
    </nav>}
  </section>;
}
