import { useState } from 'react';
import { client } from '../api/client';

type Props = { onClose: () => void; onCreated: () => Promise<void> };

const empty = {
  company_name: '', customer_code: '', email: '', phone: '', city: '', country: '',
  customer_admin_name: '', customer_admin_email: '', customer_admin_password: '', customer_admin_password_confirmation: '',
};

export default function ClientOnboarding({ onClose, onCreated }: Props) {
  const [form, setForm] = useState(empty);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const fields: Array<{ key: keyof typeof form; label: string; required: boolean; type?: string }> = [
    { key: 'company_name', label: 'Client organization name', required: true },
    { key: 'customer_code', label: 'Unique client code', required: true },
    { key: 'email', label: 'Company contact email', required: false, type: 'email' },
    { key: 'phone', label: 'Contact number', required: false },
    { key: 'city', label: 'City', required: false },
    { key: 'country', label: 'Country', required: false },
    { key: 'customer_admin_name', label: 'Customer Admin full name', required: true },
    { key: 'customer_admin_email', label: 'Customer Admin login email', required: true, type: 'email' },
    { key: 'customer_admin_password', label: 'Customer Admin password', required: true, type: 'password' },
    { key: 'customer_admin_password_confirmation', label: 'Confirm password', required: true, type: 'password' },
  ];
  async function submit(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setError('');
    try { await client.post('/admin/customers/onboard', form); await onCreated(); }
    catch (e: any) {
      const errors = e.response?.data?.errors;
      setError(errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message || 'Client registration failed.');
    } finally { setBusy(false); }
  }
  return <div className="overlay" onMouseDown={onClose}><div className="modal onboarding-modal" onMouseDown={e => e.stopPropagation()}>
    <div className="drawer-top"><span>Register client + Customer Admin</span><button type="button" onClick={onClose} aria-label="Close">×</button></div>
    <form onSubmit={submit}>
      <div className="modal-body onboarding-fields">{fields.map(f => <label key={f.key}>{f.label}
        <input type={f.type || 'text'} required={f.required} maxLength={f.type === 'password' ? 200 : 255}
          minLength={f.type === 'password' ? 12 : undefined} autoComplete={f.type === 'password' ? 'new-password' : 'off'}
          value={form[f.key]} onChange={e => setForm(p => ({ ...p, [f.key]: e.target.value }))}/>
      </label>)}
      <p className="hint">The client and login account are committed in one database transaction. Credentials are not emailed automatically.</p>
      {error && <p className="error" role="alert">{error}</p>}</div>
      <div className="modal-actions"><button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
        <button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Registering…' : 'Register client'}</button></div>
    </form></div></div>;
}
