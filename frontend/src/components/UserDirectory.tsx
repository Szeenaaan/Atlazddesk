import { useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Pagination } from './ui';
import { Button, Card, Input } from './ui';
import SearchableDropdown from './SearchableDropdown';
import SortableTable from './SortableTable';
import UserDetails from './UserDetails';
import UserAssetPicker from './UserAssetPicker';
import UserAssetEditor from './UserAssetEditor';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import FeedbackToast, { type Feedback } from './FeedbackToast';
import type { Role, RecordItem } from '../data';
import { HardDrive, LayoutDashboard, ShieldCheck, UserCheck, UserX, Pencil } from 'lucide-react';
import { reloadCustomerWorkspace } from '../auth/customerWorkspace';
import RowActions from './RowActions';
import SlidingDrawer from './SlidingDrawer';

type Account = { id: number; name: string; email: string; role: Role; customer_id: number | null; status: string };
type Props = { role: Role; customerId: number | null; customers: RecordItem[] };

const labels: Record<string, string> = {
  admin: 'Contractor Admin', technician: 'Technician', customer_admin: 'Client Admin', customer_user: 'Client User',
};
function errorText(error: any): string {
  const errors = error.response?.data?.errors;
  return errors ? Object.values(errors).flat().join(' ') : error.response?.data?.message || 'Request failed.';
}

export default function UserDirectory({ role, customerId, customers }: Props) {
  const roles: Role[] = role === 'admin' ? ['technician', 'customer_admin', 'customer_user']
    : role === 'customer_admin' ? ['customer_user'] : [];
  const [assetIds, setAssetIds] = useState<number[]>([]);
  const [detailId, setDetailId] = useState<number | null>(null);
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
  const [updatingUser, setUpdatingUser] = useState<number | null>(null);
  const [error, setError] = useFeedbackState('error');
  const [, setMessage] = useFeedbackState('success');
  const [recoveryTarget, setRecoveryTarget] = useState<Account | null>(null);
  const [recoveryReason, setRecoveryReason] = useState('');
  const [recoveryPassword, setRecoveryPassword] = useState('');
  const [recoveryError, setRecoveryError] = useFeedbackState('error');
  const [recoveryFeedback, setRecoveryFeedback] = useState<Feedback | null>(null);
  const [editTarget, setEditTarget] = useState<Account | null>(null);
  const [edit, setEdit] = useState({ name: '', email: '', password: '', password_confirmation: '', current_password: '', otp_code: '' });
  const [otpSent, setOtpSent] = useState(false);
  const [editFeedback, setEditFeedback] = useFeedbackState('error');
  const [emailOtpRequired, setEmailOtpRequired] = useState<boolean | null>(null);
  const identityChanged = Boolean(editTarget && (edit.name !== editTarget.name || edit.email !== editTarget.email));
  const editChanged = identityChanged || Boolean(edit.password);
  useEffect(() => {
    if (!editTarget) return;
    let active = true;
    setEmailOtpRequired(null);
    void client.get('/auth/mfa/status').then(({ data }) => {
      if (active && typeof data.available?.email_enabled === 'boolean') setEmailOtpRequired(data.available.email_enabled);
    }).catch(failure => { if (active) setEditFeedback(errorText(failure)); });
    return () => { active = false; };
  }, [editTarget]);
  const [sort, setSort] = useState<{sort?: string; direction?: string}>({});
  async function load(targetPage = pageNumber) {
    const params: Record<string, string | number | boolean> = { per_page: 25, page: targetPage };
    if (sort.sort) { params.sort = sort.sort; params.direction = sort.direction!; }
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
  }, [role, search, clientFilter, roleFilter, statusFilter, pageNumber, sort]);
  async function create(e: React.FormEvent) {
    e.preventDefault();
    if (busy || (e.nativeEvent as SubmitEvent).submitter?.getAttribute('name') !== 'save-user') return;
    setBusy(true); setError(''); setMessage('');
    try {
      const requiresCustomer = form.role === 'customer_admin' || form.role === 'customer_user';
      const payload = {
        ...form,
        ...(role === 'customer_admin' && form.role === 'customer_user' ? { asset_ids: assetIds } : {}),
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
    if (updatingUser !== null) return;
    setUpdatingUser(user.id);
    setError(''); setMessage('');
    try { await client.patch(`/users/${user.id}/status`, { status: user.status === 'active' ? 'inactive' : 'active' }); await load(); setMessage(`${user.name} ${user.status === 'active' ? 'deactivated' : 'activated'}.`); }
    catch (e: any) { setError(errorText(e)); }
    finally { setUpdatingUser(null); }
  }
  async function goToDashboard(user: Account) {
    if (busy || updatingUser !== null) return;
    setUpdatingUser(user.id); setError('');
    try {
      await client.post(`/users/${user.id}/dashboard`);
      reloadCustomerWorkspace();
    } catch (failure) { setError(errorText(failure)); setUpdatingUser(null); }
  }
  async function submitRecovery(e: React.FormEvent, close: () => void) {
    e.preventDefault();
    if (!recoveryTarget) return;
    setBusy(true); setRecoveryError(''); setRecoveryFeedback(null);
    try {
      await client.post(`/users/${recoveryTarget.id}/mfa/authenticator/reset`, {
        current_password: recoveryPassword, reason: recoveryReason,
      });
      setRecoveryFeedback({ tone: 'success', message: `Authenticator reset for ${recoveryTarget.email}. The old authenticator is revoked. The user can enroll a replacement in My Security.` });
      close(); setRecoveryPassword(''); setRecoveryReason('');
    } catch (err) { setRecoveryError(errorText(err)); }
    finally { setBusy(false); }
  }
  async function sendEditOtp() {
    if (!editTarget || busy || emailOtpRequired !== true) return;
    setBusy(true); setEditFeedback('');
    try { const { data } = await client.post(`/users/${editTarget.id}/details/otp`); setOtpSent(true); setEdit(p => ({ ...p, otp_code: '' })); setMessage(data.message); }
    catch (e: any) { setEditFeedback(errorText(e)); }
    finally { setBusy(false); }
  }
  async function submitEdit(e: React.FormEvent, close: () => void) {
    e.preventDefault(); if (!editTarget || busy || emailOtpRequired === null || !editChanged) return;
    setBusy(true); setEditFeedback('');
    const payload = {
      ...(edit.name !== editTarget.name ? { name: edit.name } : {}),
      ...(edit.email !== editTarget.email ? { email: edit.email } : {}),
      ...(edit.password ? { password: edit.password, password_confirmation: edit.password_confirmation } : {}),
      ...(identityChanged ? { current_password: edit.current_password } : {}),
      ...(emailOtpRequired ? { otp_code: edit.otp_code } : {}),
    };
    try {
      await client.patch(`/users/${editTarget.id}/details`, payload);
      close(); setEditTarget(null);
      setEdit({ name: '', email: '', password: '', password_confirmation: '', current_password: '', otp_code: '' });
      setOtpSent(false); setMessage('User details updated.');
      void load().catch(failure => setError(errorText(failure)));
    }
    catch (e: any) { setEditFeedback(errorText(e)); }
    finally { setBusy(false); }
  }
  if (roles.length === 0) return <Card className="panel user-management"><p>Not authorized to manage client users.</p></Card>;
  return <Card className="panel user-management">
    <h3>{role === 'customer_admin' ? 'My client users' : 'User administration'}</h3>

    <form className="admin-form" onSubmit={create} onKeyDown={event => { if (event.key === 'Enter' && event.target instanceof HTMLInputElement) event.preventDefault(); }}>
      <Field>Full name<Input required maxLength={255} value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))}/></Field>
      <Field>Email address<Input required type="email" autoComplete="off" value={form.email} onChange={e => setForm(p => ({ ...p, email: e.target.value }))}/></Field>
      {role === 'admin' && <Field>User type<SearchableDropdown value={form.role} onChange={e => { setForm(p => ({ ...p, role: e.target.value as Role })); setAssetIds([]); }}>
        {roles.map(r => <option value={r} key={r}>{labels[r]}</option>)}
      </SearchableDropdown></Field>}
      {(form.role === 'customer_admin' || form.role === 'customer_user') && role !== 'customer_admin' &&
        <Field>Client company<SearchableDropdown required value={form.customer_id} onChange={e => { setForm(p => ({ ...p, customer_id: e.target.value })); setAssetIds([]); }}>
          <option value="">Select company</option>{customers.map(c => <option value={c.id} key={c.id}>{String(c.company_name)}</option>)}
        </SearchableDropdown></Field>}

      {role === 'customer_admin' && form.role === 'customer_user' && <UserAssetPicker customerId={role === 'customer_admin' ? customerId : Number(form.customer_id) || null} value={assetIds} onChange={setAssetIds} disabled={busy}/>}
      <Field>Temporary password<Input required type="password" minLength={8} autoComplete="new-password" value={form.password} onChange={e => setForm(p => ({ ...p, password: e.target.value }))}/></Field>
      <Field>Confirm password<Input required type="password" minLength={8} autoComplete="new-password" value={form.password_confirmation} onChange={e => setForm(p => ({ ...p, password_confirmation: e.target.value }))}/></Field>
      <Button className="btn btn-primary" disabled={busy} type="submit" name="save-user">{busy ? 'Saving…' : 'Save user'}</Button>
    </form>
    {error && <p role="alert" className="error">{error}</p>}
    {detailId !== null && <UserDetails hideAssets={role === 'admin'} hideRole={role === 'customer_admin'} hideCompany={role === 'customer_admin'} key={detailId} id={detailId} onClose={() => setDetailId(null)}/>}
    {assetTarget && <SlidingDrawer title={`Assign assets · ${assetTarget.name}`} onClose={() => setAssetTarget(null)}>{close => <div className="drawer-body user-action-panel"><UserAssetEditor key={assetTarget.id} user={assetTarget} onClose={close}/></div>}</SlidingDrawer>}
    {recoveryTarget && <SlidingDrawer title={`Reset authenticator · ${recoveryTarget.name}`} onClose={() => { setRecoveryTarget(null); setRecoveryPassword(''); setRecoveryReason(''); setRecoveryError(''); }}>{close => <div className="drawer-body user-action-panel"><form className="admin-form" onSubmit={event => void submitRecovery(event, close)}>
      <p className="amc-inline-warning">Only perform this after verifying the person's identity. Existing sessions and backup codes will be invalidated. The user may enroll a replacement in My Security. Required Email OTP remains enforced.</p>
      <Field>Recovery reason / approved support reference
        <Input required minLength={15} maxLength={500} value={recoveryReason} onChange={e => setRecoveryReason(e.target.value)}/>
      </Field>
      <Field>Your administrator password
        <Input required type="password" autoComplete="current-password" value={recoveryPassword} onChange={e => setRecoveryPassword(e.target.value)}/>
      </Field>
      {recoveryError && <p role="alert" className="amc-field-error">{recoveryError}</p>}
      <Button className="btn btn-primary" disabled={busy || recoveryReason.length < 15 || !recoveryPassword}>Confirm reset</Button>
      <Button type="button" className="btn btn-secondary" disabled={busy} onClick={close}>Cancel</Button>
    </form></div>}</SlidingDrawer>}
    {editTarget && <SlidingDrawer title={`Edit details · ${editTarget.name}`} onClose={() => { setEditTarget(null); setOtpSent(false); setEditFeedback(''); setEdit({ name: '', email: '', password: '', password_confirmation: '', current_password: '', otp_code: '' }); }}>{close => <div className="drawer-body user-action-panel"><form className="admin-form" onSubmit={event => void submitEdit(event, close)}>
      <Field>Full name<Input required maxLength={255} value={edit.name} onChange={e => setEdit(p => ({ ...p, name: e.target.value }))}/></Field>
      <Field>Email address<Input required type="email" maxLength={255} value={edit.email} onChange={e => setEdit(p => ({ ...p, email: e.target.value }))}/></Field>
      <Field>New password<Input type="password" minLength={8} autoComplete="new-password" value={edit.password} onChange={e => setEdit(p => ({ ...p, password: e.target.value }))}/></Field>
      <Field>Confirm new password<Input required={Boolean(edit.password)} type="password" minLength={8} autoComplete="new-password" value={edit.password_confirmation} onChange={e => setEdit(p => ({ ...p, password_confirmation: e.target.value }))}/></Field>
      {identityChanged && <Field>Your account password<Input required type="password" autoComplete="current-password" value={edit.current_password} onChange={e => setEdit(p => ({ ...p, current_password: e.target.value }))}/></Field>}
      {emailOtpRequired && <>
        <Field>Code from your account email<Input required inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={edit.otp_code} onChange={e => setEdit(p => ({ ...p, otp_code: e.target.value }))}/></Field>
        <Button type="button" className="btn btn-secondary" disabled={busy} onClick={() => void sendEditOtp()}>{otpSent ? 'Resend code to my email' : 'Send code to my email'}</Button>
      </>}
      {emailOtpRequired === null && <p role="status">{editFeedback ? 'Unable to load verification requirements. Close this panel and try again.' : 'Loading verification requirements…'}</p>}
      <Button type="submit" className="btn btn-primary" disabled={busy || emailOtpRequired === null || !editChanged}>{busy ? 'Saving…' : 'Save details'}</Button>
      <Button type="button" className="btn btn-secondary" disabled={busy} onClick={close}>Cancel</Button>
    </form></div>}</SlidingDrawer>}
    <FeedbackToast feedback={recoveryFeedback} onDismiss={() => setRecoveryFeedback(null)}/>
    <div className="amc-user-filters" role="search" aria-label="Filter user accounts">
      <Field>Search users<Input type="search" maxLength={100} value={search} onChange={e => { setSearch(e.target.value); setPageNumber(1); }}/></Field>
      {role === 'admin' && <Field>Client company<SearchableDropdown value={clientFilter} onChange={e => { setClientFilter(e.target.value); setPageNumber(1); }}>
        <option value="">All accounts</option><option value="workforce">Contractor workforce</option>
        {customers.map(c => <option key={c.id} value={c.id}>{String(c.company_name)}</option>)}
      </SearchableDropdown></Field>}
      {role === 'admin' && <Field>User type<SearchableDropdown value={roleFilter} onChange={e => { setRoleFilter(e.target.value); setPageNumber(1); }}>
        <option value="">All user types</option><option value="admin">Contractor Admin</option>{roles.map(r => <option key={r} value={r}>{labels[r] || r}</option>)}
      </SearchableDropdown></Field>}
      <Field>Status<SearchableDropdown value={statusFilter} onChange={e => { setStatusFilter(e.target.value); setPageNumber(1); }}>
        <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
      </SearchableDropdown></Field>
      <Button className="btn btn-secondary" type="button" onClick={() => { setSearch(''); setClientFilter(''); setRoleFilter(''); setStatusFilter(''); setPageNumber(1); }}>Clear filters</Button>
    </div>
    <div className="amc-list-summary">{total} users</div>
    <div className="table-scroll"><SortableTable onSort={({column, direction}) => { setSort({sort: (role === 'customer_admin' ? ['name','email','status'] : ['name','email','role','customer','status'])[column], direction}); setPageNumber(1); }}><thead><tr><th>User</th><th>Email</th>{role !== 'customer_admin' && <th>User type</th>}{role !== 'customer_admin' && <th>Client</th>}<th>Status</th><th>Action</th></tr></thead>
      <tbody>{accounts.map(u => <tr key={u.id} className="record-row" onClick={() => setDetailId(u.id)}><td><button type="button" className="record-link" onClick={event => { event.stopPropagation(); setDetailId(u.id); }}>{u.name}</button></td><td>{u.email}</td>{role !== 'customer_admin' && <td>{labels[u.role] || u.role}</td>}{role !== 'customer_admin' && <td>{u.customer_id ? String(customers.find(c => Number(c.id) === Number(u.customer_id))?.company_name || 'Client #' + u.customer_id) : 'Contractor workforce'}</td>}<td><span className={`record-status ${u.status === 'active' ? 'is-active' : ''}`}>{updatingUser === u.id ? 'Updating…' : u.status}</span></td><td onClick={event => event.stopPropagation()}><RowActions name={u.name} actions={[
        ...(role === 'admin' && u.role === 'customer_admin' ? [{label: 'Go to dashboard', icon: <LayoutDashboard size={16}/>, disabled: busy || updatingUser !== null || u.status !== 'active', onClick: () => void goToDashboard(u)}] : []),
        ...(role === 'customer_admin' && u.role === 'customer_user' ? [{ label: 'Assign assets', icon: <HardDrive size={16}/>, onClick: () => setAssetTarget(u), disabled: busy }] : []),
        ...(u.role !== 'super_admin' ? [{ label: u.status === 'active' ? 'Deactivate account' : 'Activate account', icon: u.status === 'active' ? <UserX size={16}/> : <UserCheck size={16}/>, danger: u.status === 'active', disabled: updatingUser !== null || busy, onClick: () => { void toggle(u); } }] : []),
        ...(['technician', 'customer_admin', 'customer_user'].includes(u.role) ? [{ label: 'Reset authenticator', icon: <ShieldCheck size={16}/>, disabled: busy, onClick: () => { setRecoveryTarget(u); setRecoveryReason(''); setRecoveryPassword(''); setRecoveryError(''); } }] : []),
        ...(['technician', 'customer_admin', 'customer_user'].includes(u.role) ? [{ label: 'Edit details', icon: <Pencil size={16}/>, disabled: busy || updatingUser !== null, onClick: () => { setEditTarget(u); setEdit({ name: u.name, email: u.email, password: '', password_confirmation: '', current_password: '', otp_code: '' }); setOtpSent(false); setEditFeedback(''); } }] : []),
      ]}/></td></tr>)}</tbody></SortableTable>{accounts.length === 0 && <div className="amc-muted-empty">No matching accounts.</div>}</div>
    <Pagination page={pageNumber} last={lastPage} onPage={setPageNumber} label="User list pages"/>

  </Card>;
}
