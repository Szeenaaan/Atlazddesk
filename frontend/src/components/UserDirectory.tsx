import TechnicianFields from './TechnicianFields';
import TechnicianWorkforceEditor from './TechnicianWorkforceEditor';
import TechnicianAssignmentLimitSettings from './TechnicianAssignmentLimitSettings';
import type { Designation } from '../api/escalations';
import { getWorkforceErrorMessage } from '../api/escalations';
import { useFeedbackState } from './feedbackContext';
import { Button, Card, Field, Input, InlineNotice, Pagination, RefreshButton } from './ui';
import SearchableDropdown from './SearchableDropdown';
import SortableTable from './SortableTable';
import UserDetails from './UserDetails';
import UserAssetPicker from './UserAssetPicker';
import UserAssetEditor from './UserAssetEditor';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import type { Role, RecordItem } from '../data';
import { HardDrive, LayoutDashboard, ShieldCheck, UserCheck, UserX, Pencil, Trash2 } from 'lucide-react';
import { ConfirmationDialog } from './ui/Dialog';
import { reloadCustomerWorkspace } from '../auth/customerWorkspace';
import { ROLE_LABELS as labels } from '../auth/rolePermissions';
import { getApiErrorMessage } from '../api/contracts';
import RowActions from './RowActions';
import SlidingDrawer from './SlidingDrawer';

type Account = { id: number; name: string; email: string; role: Role; customer_id: number | null; status: string; designation_id?: number | null; designation?: Designation; workforce_type?: string | null; client_admin_count?: number };
type Props = { role: Role; customerId: number | null; customers: RecordItem[] };
const blankEdit = { name: '', email: '', password: '', password_confirmation: '', current_password: '', otp_code: '', designation_id: '' };

function errorText(error: unknown): string {
  return getApiErrorMessage(error, 'The account action could not be completed. Please try again.');
}

export default function UserDirectory({ role, customerId, customers }: Props) {
  const roles: Role[] = role === 'admin' ? ['technician', 'customer_admin', 'customer_user']
    : role === 'customer_admin' ? ['customer_user'] : [];
  const [assetIds, setAssetIds] = useState<number[]>([]);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [assetTarget, setAssetTarget] = useState<Account | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Account | null>(null);
  const [deleteMethods, setDeleteMethods] = useState<{ totp: boolean; email: boolean } | null>(null);
  const [deleteTotpCode, setDeleteTotpCode] = useState('');
  const [deleteEmailCode, setDeleteEmailCode] = useState('');
  const [deleteOtpSent, setDeleteOtpSent] = useState(false);
  const [deleteError, setDeleteError] = useState('');
  const [workforceTarget, setWorkforceTarget] = useState<Account | null>(null);
  const [assignmentLimitTarget, setAssignmentLimitTarget] = useState<Account | null>(null);
  const [designationFilter, setDesignationFilter] = useState('');
  const [designations, setDesignations] = useState<Designation[] | null>(null);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [search, setSearch] = useState('');
  const [clientFilter, setClientFilter] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [pageNumber, setPageNumber] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [listRevision, setListRevision] = useState(0);
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', role: (role === 'admin' ? '' : roles[0] ?? 'customer_user') as Role | '', customer_id: '', designation_id: '', workforce_type: '' });
  const [busy, setBusy] = useState(false);
  const [updatingUser, setUpdatingUser] = useState<number | null>(null);
  const [error, setError] = useFeedbackState('error');
  const [, setMessage] = useFeedbackState('success');
  const [recoveryTarget, setRecoveryTarget] = useState<Account | null>(null);
  const [recoveryReason, setRecoveryReason] = useState('');
  const [recoveryPassword, setRecoveryPassword] = useState('');
  const [recoveryError, setRecoveryError] = useFeedbackState('error');
  const [editTarget, setEditTarget] = useState<Account | null>(null);
  const [edit, setEdit] = useState(blankEdit);
  const [otpSent, setOtpSent] = useState(false);
  const [editFeedback, setEditFeedback] = useFeedbackState('error');
  const [emailOtpRequired, setEmailOtpRequired] = useState<boolean | null>(null);
  const identityChanged = Boolean(editTarget && (edit.name !== editTarget.name || edit.email !== editTarget.email));
  const editChanged = identityChanged || Boolean(edit.password) || Boolean(editTarget?.role === 'technician' && Number(edit.designation_id) !== editTarget.designation_id);
  useEffect(() => {
    if (role !== 'admin') return;
    const controller = new AbortController();
    client.get<{ data: Designation[] }>('/designations', { signal: controller.signal }).then(({ data }) => setDesignations(data.data)).catch(e => { if (!controller.signal.aborted) { setDesignations([]); setError(errorText(e)); } });
    return () => controller.abort();
  }, [role]);
  useEffect(() => {
    if (!editTarget && !deleteTarget) return;
    const controller = new AbortController();
    setEmailOtpRequired(null);
    setDeleteMethods(null); setDeleteTotpCode(''); setDeleteEmailCode(''); setDeleteOtpSent(false); setDeleteError('');
    void client.get('/auth/mfa/status', { signal: controller.signal }).then(({ data }) => {
      if (typeof data.available?.email_enabled !== 'boolean' || typeof data.available?.totp_enabled !== 'boolean' || typeof data.totp_enabled !== 'boolean') {
        throw new Error('Verification requirements could not be confirmed.');
      }
      setEmailOtpRequired(data.available.email_enabled);
      setDeleteMethods({ totp: data.available.totp_enabled && data.totp_enabled, email: data.available.email_enabled });
    }).catch(failure => {
      if (controller.signal.aborted) return;
      const message = errorText(failure);
      if (editTarget) setEditFeedback(message);
      if (deleteTarget) setDeleteError(message);
    });
    return () => controller.abort();
  }, [editTarget, deleteTarget]);
  const [sort, setSort] = useState<{sort?: string; direction?: string}>({});
  async function load(targetPage = pageNumber, signal?: AbortSignal) {
    const params: Record<string, string | number | boolean> = { per_page: 25, page: targetPage };
    if (sort.sort) { params.sort = sort.sort; params.direction = sort.direction!; }
    if (search.trim()) params.search = search.trim();
    if (role === 'admin' && clientFilter === 'workforce') params.workforce_only = 1;
    else if (role === 'admin' && clientFilter) params.customer_id = Number(clientFilter);
    if (role === 'admin' && roleFilter) params.role = roleFilter;
    if (role === 'admin' && roleFilter === 'technician' && designationFilter) params.designation_id = designationFilter;
    if (statusFilter) params.status = statusFilter;
    const { data } = await client.get<{ data: Account[]; total: number; last_page: number }>('/users', { params, signal });
    setAccounts(data.data);
    setTotal(data.total);
    setLastPage(data.last_page);
    setError('');
  }
  useEffect(() => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => { void load(pageNumber, controller.signal).catch(e => { if (!controller.signal.aborted) setError(errorText(e)); }); }, 250);
    return () => { window.clearTimeout(timeout); controller.abort(); };
    // load is intentionally refetched whenever the selected filters or page change.
  }, [role, search, clientFilter, roleFilter, statusFilter, designationFilter, pageNumber, sort, listRevision]);
  async function create(e: React.FormEvent) {
    e.preventDefault();
    if (busy || (e.nativeEvent as SubmitEvent).submitter?.getAttribute('name') !== 'save-user') return;
    setBusy(true); setError(''); setMessage('');
    try {
      const requiresCustomer = form.role === 'customer_admin' || form.role === 'customer_user';
      const payload = {
        ...form,
        designation_id: form.role === 'technician' ? Number(form.designation_id) : undefined,
        workforce_type: form.role === 'technician' ? form.workforce_type : undefined,
        ...(role === 'customer_admin' && form.role === 'customer_user' ? { asset_ids: assetIds } : {}),
        ...(role === 'customer_admin' ? { customer_id: undefined } : { customer_id: requiresCustomer ? Number(form.customer_id) : undefined }),
      };
      if (requiresCustomer && role !== 'customer_admin' && !payload.customer_id) throw new Error('Choose a client company.');
      await client.post('/users', payload);
      setAssetIds([]);
      setForm(p => ({ ...p, name: '', email: '', password: '', password_confirmation: '' }));
      await load(); setMessage('User created. Share initial credentials securely and require a password change.');
    } catch (e: any) {
      setError(e.message === 'Choose a client company.' ? e.message
        : form.role === 'technician' ? getWorkforceErrorMessage(e, 'Technician could not be created.') : errorText(e));
    }
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
  async function removeUser() {
    if (!deleteTarget || !deleteMethods || busy) return;
    setBusy(true); setDeleteError('');
    try {
      await client.delete(`/users/${deleteTarget.id}`, { data: {
        ...(deleteMethods.totp ? { totp_code: deleteTotpCode } : {}),
        ...(deleteMethods.email ? { email_code: deleteEmailCode } : {}),
      } });
      setDeleteTarget(null);
      await load();
      setMessage('User deleted. Current assignments have been cleared.');
    } catch (failure) { setDeleteError(errorText(failure)); }
    finally { setBusy(false); }
  }
  async function sendDeleteOtp() {
    if (!deleteTarget || busy) return;
    setBusy(true); setDeleteError('');
    try {
      await client.post(`/users/${deleteTarget.id}/deletion/otp`);
      setDeleteOtpSent(true); setDeleteEmailCode('');
      setMessage('Check your inbox for the verification code. It may take a moment to arrive.');
    } catch (failure) { setDeleteError(errorText(failure)); }
    finally { setBusy(false); }
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
    setBusy(true); setRecoveryError('');
    try {
      await client.post(`/users/${recoveryTarget.id}/mfa/authenticator/reset`, {
        current_password: recoveryPassword, reason: recoveryReason,
      });
      setMessage('Authenticator reset. The user can set up a replacement in My Security.');
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
      ...(editTarget.role === 'technician' ? { designation_id: Number(edit.designation_id) } : {}),
      ...(edit.name !== editTarget.name ? { name: edit.name } : {}),
      ...(edit.email !== editTarget.email ? { email: edit.email } : {}),
      ...(edit.password ? { password: edit.password, password_confirmation: edit.password_confirmation } : {}),
      ...(identityChanged ? { current_password: edit.current_password } : {}),
      ...(emailOtpRequired ? { otp_code: edit.otp_code } : {}),
    };
    try {
      await client.patch(`/users/${editTarget.id}/details`, payload);
      close(); setEditTarget(null);
      setEdit(blankEdit);
      setOtpSent(false); setMessage('User details updated.');
      void load().catch(failure => setError(errorText(failure)));
    }
    catch (e: any) { setEditFeedback(errorText(e)); }
    finally { setBusy(false); }
  }
  function userActions(u: Account, fromDetails = false) {
    const actions = [
        ...(role === 'admin' && u.role === 'technician' ? [{ label: 'Edit designation and classification', icon: <Pencil size={16}/>, disabled: busy, onClick: () => setWorkforceTarget(u) }] : []),
        ...(role === 'admin' && u.role === 'technician' ? [{ label: 'Set assignment limit', icon: <Pencil size={16}/>, disabled: busy, onClick: () => setAssignmentLimitTarget(u) }] : []),
        ...(role === 'admin' && u.role === 'customer_admin' ? [{label: 'Go to dashboard', icon: <LayoutDashboard size={16}/>, disabled: busy || updatingUser !== null || u.status !== 'active', onClick: () => void goToDashboard(u)}] : []),
        ...(role === 'customer_admin' && u.role === 'customer_user' ? [{ label: 'Assign assets', icon: <HardDrive size={16}/>, onClick: () => setAssetTarget(u), disabled: busy }] : []),
        ...(u.role !== 'super_admin' ? [{ label: u.status === 'active' ? 'Deactivate account' : 'Activate account', icon: u.status === 'active' ? <UserX size={16}/> : <UserCheck size={16}/>, danger: u.status === 'active', disabled: updatingUser !== null || busy, onClick: () => { void toggle(u); } }] : []),
        ...(['technician', 'customer_admin', 'customer_user'].includes(u.role) ? [{ label: 'Reset authenticator', icon: <ShieldCheck size={16}/>, disabled: busy, onClick: () => { setRecoveryTarget(u); setRecoveryReason(''); setRecoveryPassword(''); setRecoveryError(''); } }] : []),
        ...(['technician', 'customer_admin', 'customer_user'].includes(u.role) ? [{ label: 'Edit details', icon: <Pencil size={16}/>, disabled: busy || updatingUser !== null, onClick: () => { setEditTarget(u); setEdit({ ...blankEdit, name: u.name, email: u.email, designation_id: String(u.designation_id || '') }); setOtpSent(false); setEditFeedback(''); } }] : []),
        ...(role === 'admin' && ['technician', 'customer_admin', 'customer_user'].includes(u.role) && (u.role !== 'customer_admin' || Number(u.client_admin_count) > 1)
          ? [{ label: 'Delete user', icon: <Trash2 size={16}/>, danger: true, disabled: busy || updatingUser !== null, onClick: () => setDeleteTarget(u) }] : []),
      ];
    return fromDetails ? actions.map(action => ({ ...action, onClick: () => { setDetailId(null); action.onClick(); } })) : actions;
  }
  const detailAccount = accounts.find(user => user.id === detailId);
  if (roles.length === 0) return <Card className="panel user-management"><p>Not authorized to manage client users.</p></Card>;
  return <Card className="panel user-management user-directory tickets-panel">
    <div className="panel-head"><h3>{role === 'customer_admin' ? 'My client users' : 'User administration'}</h3><RefreshButton label="Refresh users" disabled={busy} onClick={() => setListRevision(value => value + 1)}/></div>

    <form className="admin-form" onSubmit={create} onKeyDown={event => { if (event.key === 'Enter' && event.target instanceof HTMLInputElement) event.preventDefault(); }}>
      {role === 'admin' && <Field>User<SearchableDropdown required value={form.role} onChange={e => { setForm(p => ({ ...p, role: e.target.value as Role, designation_id: '', workforce_type: '' })); setAssetIds([]); }}>
        <option value="">Select user type</option>
        {roles.map(r => <option value={r} key={r}>{labels[r]}</option>)}
      </SearchableDropdown></Field>}
      {(form.role === 'customer_admin' || form.role === 'customer_user') && role !== 'customer_admin' &&
        <Field>Client<SearchableDropdown required value={form.customer_id} onChange={e => { setForm(p => ({ ...p, customer_id: e.target.value })); setAssetIds([]); }}>
          <option value="">Select company</option>{customers.map(c => <option value={c.id} key={c.id}>{String(c.company_name)}</option>)}
        </SearchableDropdown></Field>}

      <Field>Full name<Input required maxLength={255} value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))}/></Field>
      <Field>Email address<Input required type="email" autoComplete="off" value={form.email} onChange={e => setForm(p => ({ ...p, email: e.target.value }))}/></Field>
      {form.role === 'technician' && role === 'admin' && <TechnicianFields designations={designations} designationId={form.designation_id} onDesignation={value => setForm(p => ({ ...p, designation_id: value }))} workforceType={form.workforce_type} onWorkforce={value => setForm(p => ({ ...p, workforce_type: value }))} disabled={busy}/>}
      <Field>Temporary password<Input required type="password" minLength={8} autoComplete="new-password" value={form.password} onChange={e => setForm(p => ({ ...p, password: e.target.value }))}/></Field>
      <Field>Confirm password<Input required type="password" minLength={8} autoComplete="new-password" value={form.password_confirmation} onChange={e => setForm(p => ({ ...p, password_confirmation: e.target.value }))}/></Field>
      {role === 'customer_admin' && form.role === 'customer_user' && <UserAssetPicker customerId={customerId} value={assetIds} onChange={setAssetIds} disabled={busy}/>}
      <div className="ui-actions"><Button className="btn btn-primary" disabled={busy || !form.role || (form.role === 'technician' && (!form.designation_id || !form.workforce_type || !designations?.some(item => item.is_active && String(item.id) === form.designation_id)))} type="submit" name="save-user">{busy ? 'Saving…' : 'Save user'}</Button></div>
    </form>
    {error && <p role="alert" className="error">{error}</p>}
    {workforceTarget && <TechnicianWorkforceEditor designations={designations} user={workforceTarget} onClose={() => setWorkforceTarget(null)} onSaved={() => { void load().catch(e => setError(errorText(e))); }}/>}
    {assignmentLimitTarget && <TechnicianAssignmentLimitSettings technician={assignmentLimitTarget} onClose={() => setAssignmentLimitTarget(null)}/>}
    {detailId !== null && <UserDetails hideAssets={role === 'admin'} hideRole={role === 'customer_admin'} hideCompany={role === 'customer_admin'} key={detailId} id={detailId} onClose={() => setDetailId(null)}
      actions={detailAccount && <RowActions name={detailAccount.name} triggerLabel="Actions" actions={userActions(detailAccount, true)}/>}/>}
    {assetTarget && <SlidingDrawer title={`Assign assets · ${assetTarget.name}`} onClose={() => setAssetTarget(null)}>{close => <div className="drawer-body user-action-panel"><UserAssetEditor key={assetTarget.id} user={assetTarget} onClose={close}/></div>}</SlidingDrawer>}
    {deleteTarget && <ConfirmationDialog title="Delete user?" confirmLabel="Delete user" busy={busy}
      confirmDisabled={!deleteMethods || (deleteMethods.totp && !/^\d{6}$/.test(deleteTotpCode)) || (deleteMethods.email && (!deleteOtpSent || !/^\d{6}$/.test(deleteEmailCode)))}
      onConfirm={() => void removeUser()} onClose={() => { if (!busy) setDeleteTarget(null); }}>
      <p>Delete <strong>{deleteTarget.name}</strong>? Account access and current assignments will be removed.</p>
      {!deleteMethods && !deleteError && <p role="status">Loading verification requirements…</p>}
      {(deleteMethods?.totp || deleteMethods?.email) && <div className="user-action-panel"><div className="admin-form">
        {deleteMethods.totp && <Field>Authenticator code<Input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={deleteTotpCode} onChange={e => setDeleteTotpCode(e.target.value)}/></Field>}
        {deleteMethods.email && <>
          <Field>Code from your account email<Input inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={deleteEmailCode} onChange={e => setDeleteEmailCode(e.target.value)}/></Field>
          <Button type="button" className="btn btn-secondary" disabled={busy} onClick={() => void sendDeleteOtp()}>{deleteOtpSent ? 'Resend code' : 'Send email code'}</Button>
        </>}
      </div></div>}
      {deleteError && <InlineNotice tone="error">{deleteError}</InlineNotice>}
    </ConfirmationDialog>}
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
    {editTarget && <SlidingDrawer title={`Edit details · ${editTarget.name}`} onClose={() => { setEditTarget(null); setOtpSent(false); setEditFeedback(''); setEdit(blankEdit); }}>{close => <div className="drawer-body user-action-panel"><form className="admin-form" onSubmit={event => void submitEdit(event, close)}>
      <Field>Full name<Input required maxLength={255} value={edit.name} onChange={e => setEdit(p => ({ ...p, name: e.target.value }))}/></Field>
      <Field>Email address<Input required type="email" maxLength={255} value={edit.email} onChange={e => setEdit(p => ({ ...p, email: e.target.value }))}/></Field>
      {editTarget.role === 'technician' && <TechnicianFields designations={designations} designationId={edit.designation_id} onDesignation={value => setEdit(p => ({ ...p, designation_id: value }))} disabled={busy}/>}
      <Field>New password<Input type="password" minLength={8} autoComplete="new-password" value={edit.password} onChange={e => setEdit(p => ({ ...p, password: e.target.value }))}/></Field>
      <Field>Confirm new password<Input required={Boolean(edit.password)} type="password" minLength={8} autoComplete="new-password" value={edit.password_confirmation} onChange={e => setEdit(p => ({ ...p, password_confirmation: e.target.value }))}/></Field>
      {identityChanged && <Field>Your account password<Input required type="password" autoComplete="current-password" value={edit.current_password} onChange={e => setEdit(p => ({ ...p, current_password: e.target.value }))}/></Field>}
      {emailOtpRequired && <>
        <Field>Code from your account email<Input required inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} value={edit.otp_code} onChange={e => setEdit(p => ({ ...p, otp_code: e.target.value }))}/></Field>
        <Button type="button" className="btn btn-secondary" disabled={busy} onClick={() => void sendEditOtp()}>{otpSent ? 'Resend code to my email' : 'Send code to my email'}</Button>
      </>}
      {emailOtpRequired === null && <p role="status">{editFeedback ? 'Unable to load verification requirements. Close this panel and try again.' : 'Loading verification requirements…'}</p>}
      <div className="ui-actions"><Button type="submit" variant="primary" disabled={busy || emailOtpRequired === null || !editChanged || (editTarget.role === 'technician' && !edit.designation_id)}>{busy ? 'Saving…' : 'Save details'}</Button>
      <Button type="button" disabled={busy} onClick={close}>Cancel</Button></div>
    </form></div>}</SlidingDrawer>}
    <div className="amc-user-filters" role="search" aria-label="Filter user accounts">
      <Field>Search users<Input type="search" maxLength={100} value={search} onChange={e => { setSearch(e.target.value); setPageNumber(1); }}/></Field>
      {role === 'admin' && <Field>Client<SearchableDropdown value={clientFilter} onChange={e => { setClientFilter(e.target.value); setPageNumber(1); }}>
        <option value="">All accounts</option><option value="workforce">Contractor workforce</option>
        {customers.map(c => <option key={c.id} value={c.id}>{String(c.company_name)}</option>)}
      </SearchableDropdown></Field>}
      {role === 'admin' && <Field>User<SearchableDropdown value={roleFilter} onChange={e => { setRoleFilter(e.target.value); setDesignationFilter(''); setPageNumber(1); }}>
        <option value="">All user types</option><option value="admin">Contractor Admin</option>{roles.map(r => <option key={r} value={r}>{labels[r] || r}</option>)}
      </SearchableDropdown></Field>}
      {role === 'admin' && roleFilter === 'technician' && <Field>Designation<SearchableDropdown value={designationFilter} onChange={e => { setDesignationFilter(e.target.value); setPageNumber(1); }}><option value="">All designations</option>{(designations ?? []).map(item => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (inactive)'}</option>)}</SearchableDropdown></Field>}
      <Field>Status<SearchableDropdown value={statusFilter} onChange={e => { setStatusFilter(e.target.value); setPageNumber(1); }}>
        <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
      </SearchableDropdown></Field>
      <Button className="btn btn-secondary" type="button" onClick={() => { setSearch(''); setClientFilter(''); setRoleFilter(''); setStatusFilter(''); setDesignationFilter(''); setPageNumber(1); }}>Clear filters</Button>
      <span className="result-count">{total} users</span>
    </div>
    <div className="table-scroll"><SortableTable onSort={({column, direction}) => { setSort({sort: (role === 'customer_admin' ? ['name','email','status'] : ['name','email','role','customer','status'])[column], direction}); setPageNumber(1); }}><thead><tr><th>Name</th><th>Email</th>{role !== 'customer_admin' && <th>User</th>}{role !== 'customer_admin' && <th>Client</th>}<th>Status</th><th>Action</th></tr></thead>
      <tbody>{accounts.map(u => <tr key={u.id} className="record-row" onClick={() => setDetailId(u.id)}><td><button type="button" className="record-link" onClick={event => { event.stopPropagation(); setDetailId(u.id); }}>{u.name}</button>{u.designation && <div>{u.designation.name}</div>}</td><td>{u.email}</td>{role !== 'customer_admin' && <td>{labels[u.role] || u.role}</td>}{role !== 'customer_admin' && <td>{u.customer_id ? String(customers.find(c => Number(c.id) === Number(u.customer_id))?.company_name || 'Client #' + u.customer_id) : 'Contractor workforce'}</td>}<td><span className={`record-status ${u.status === 'active' ? 'is-active' : ''}`}>{updatingUser === u.id ? 'Updating…' : u.status}</span></td><td onClick={event => event.stopPropagation()}><RowActions name={u.name} actions={userActions(u)}/></td></tr>)}</tbody></SortableTable>{accounts.length === 0 && <div className="amc-muted-empty">No matching accounts.</div>}</div>
    <Pagination page={pageNumber} last={lastPage} onPage={setPageNumber} label="User list pages"/>

  </Card>;
}
