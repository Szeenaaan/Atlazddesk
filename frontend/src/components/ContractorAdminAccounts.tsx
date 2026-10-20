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
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  async function load() {
    const { data } = await client.get<ApiResponse<Admin[]>>('/system/admins');
    setAdmins(data.data || []);
  }
  useEffect(() => { void load().catch(() => setError('Unable to load contractor Admin accounts.')); }, []);
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
    try { await client.patch(`/system/admins/${admin.id}/status`, {status:admin.status === 'active' ? 'inactive' : 'active'}); await load(); }
    catch (error: unknown) { setError(getApiErrorMessage(error, 'Cannot change status.')); }
    finally { setBusy(false); }
  }
  return <section className="panel user-management">
    <h3>Contractor Admin accounts</h3>
    <p>Create or disable contractor Admin accounts. This system-level provisioning does not grant access to client operations.</p>
    <form className="admin-form" onSubmit={create}>
      <label>Full name<input required maxLength={255} value={form.name} onChange={e => setForm(p => ({...p,name:e.target.value}))} /></label>
      <label>Email<input required type="email" autoComplete="off" value={form.email} onChange={e => setForm(p => ({...p,email:e.target.value}))} /></label>
      <label>Temporary password<input required type="password" minLength={14} autoComplete="new-password" value={form.password} onChange={e => setForm(p => ({...p,password:e.target.value}))} /></label>
      <label>Confirm password<input required type="password" minLength={14} autoComplete="new-password" value={form.password_confirmation} onChange={e => setForm(p => ({...p,password_confirmation:e.target.value}))} /></label>
      <button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Create Contractor Admin'}</button>
    </form>
    {error && <p className="error" role="alert">{error}</p>}{notice && <p className="success-note" role="status">{notice}</p>}
    <div className="table-scroll"><table><thead><tr><th>Admin</th><th>Email</th><th>Status</th><th>Action</th></tr></thead><tbody>
      {admins.map(admin => <tr key={admin.id}><td>{admin.name}</td><td>{admin.email}</td><td>{admin.status}</td><td><button type="button" className="btn btn-secondary" disabled={busy} onClick={() => toggle(admin)}>{admin.status === 'active' ? 'Deactivate' : 'Activate'}</button></td></tr>)}
    </tbody></table></div>
  </section>;
}
