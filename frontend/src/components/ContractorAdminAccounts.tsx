import { useFeedbackState } from './feedbackContext';
import { Field, InlineNotice } from './ui';
import RowActions from './RowActions';
import { UserCheck, UserX } from 'lucide-react';
import { Button, Card, Input } from './ui';
import SortableTable from './SortableTable';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import type { ApiResponse } from '../api/contracts';

type Admin = { id: number; name: string; email: string; status: string; created_at: string };
const blank = { name:'',email:'',password:'',password_confirmation:'' };
export default function ContractorAdminAccounts() {
  const [admins, setAdmins] = useState<Admin[]>([]);
  const [form, setForm] = useState(blank);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useFeedbackState('error');
  const [, setNotice] = useFeedbackState('success');
  const [sort, setSort] = useState<{sort?: string; direction?: string}>({});
  async function load() {
    const { data } = await client.get<ApiResponse<Admin[]>>('/system/admins', {params: sort});
    setAdmins(data.data || []);
  }
  useEffect(() => { void load().catch(() => setError('Unable to load contractor Admin accounts.')); }, [sort]);
  async function create(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setError(''); setNotice('');
    try {
      await client.post('/system/admins', form);
      setForm(blank); await load();
      setNotice('Contractor Admin created. Share initial credentials securely and require a password change.');
    } catch (error: unknown) { setError(getApiErrorMessage(error, 'Cannot create Admin.')); }
    finally { setBusy(false); }
  }
  async function toggle(admin: Admin) {
    setBusy(true); setError(''); setNotice('');
    try { await client.patch(`/system/admins/${admin.id}/status`, {status:admin.status === 'active' ? 'inactive' : 'active'}); await load(); setNotice('Account status updated.'); }
    catch (error: unknown) { setError(getApiErrorMessage(error, 'Cannot change status.')); }
    finally { setBusy(false); }
  }
  return <Card className="panel user-management contractor-admin-accounts">
    <h3>Contractor Admin accounts</h3>
    <p>Create or disable contractor Admin accounts. This system-level provisioning does not grant access to client operations.</p>
    <form className="admin-form" onSubmit={create}>
      <Field>Full name<Input required maxLength={255} value={form.name} onChange={e => setForm(p => ({...p,name:e.target.value}))} /></Field>
      <Field>Email<Input required type="email" autoComplete="off" value={form.email} onChange={e => setForm(p => ({...p,email:e.target.value}))} /></Field>
      <Field>Temporary password<Input required type="password" minLength={8} autoComplete="new-password" value={form.password} onChange={e => setForm(p => ({...p,password:e.target.value}))} /></Field>
      <Field>Confirm password<Input required type="password" minLength={8} autoComplete="new-password" value={form.password_confirmation} onChange={e => setForm(p => ({...p,password_confirmation:e.target.value}))} /></Field>
      <Button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Create Contractor Admin'}</Button>
    </form>
    {error && <InlineNotice tone="error">{error}</InlineNotice>}
    <div className="table-scroll"><SortableTable onSort={({column, direction}) => setSort({sort: ['name','email','status'][column], direction})}><thead><tr><th>Admin</th><th>Email</th><th>Status</th><th>Action</th></tr></thead><tbody>
      {admins.map(admin => <tr key={admin.id}><td>{admin.name}</td><td>{admin.email}</td><td>{admin.status}</td><td><RowActions name={admin.name} actions={[{label: admin.status === 'active' ? 'Deactivate' : 'Activate', icon: admin.status === 'active' ? <UserX size={16}/> : <UserCheck size={16}/>, disabled: busy, onClick: () => void toggle(admin)}]}/></td></tr>)}
    </tbody></SortableTable></div>
  </Card>;
}
