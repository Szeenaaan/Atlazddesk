import { useFeedbackState } from './feedbackContext';
import { CloseButton } from './ui';
import { Field } from './ui';
import { Dialog } from './ui/Dialog';
import { Button, Input } from './ui';
import { useState } from 'react';
import { client } from '../api/client';

type Props = { onClose: () => void; onCreated: () => Promise<void> };

const empty = {
  company_name: '', customer_code: '', email: '', phone: '', city: '', country: '',
  customer_admin_name: '', customer_admin_email: '', customer_admin_password: '', customer_admin_password_confirmation: '',
};

export default function ClientOnboarding({ onClose, onCreated }: Props) {
  const [form, setForm] = useState(empty);
  const [error, setError] = useFeedbackState('error');
  const [busy, setBusy] = useState(false);
  const fields: Array<{ key: keyof typeof form; label: string; required: boolean; type?: string }> = [
    { key: 'company_name', label: 'Client organization name', required: true },
    { key: 'customer_code', label: 'Unique client code', required: true },
    { key: 'email', label: 'Company contact email', required: false, type: 'email' },
    { key: 'phone', label: 'Contact number', required: false },
    { key: 'city', label: 'City', required: false },
    { key: 'country', label: 'Country', required: false },
    { key: 'customer_admin_name', label: 'Client Admin full name', required: true },
    { key: 'customer_admin_email', label: 'Client Admin login email', required: true, type: 'email' },
    { key: 'customer_admin_password', label: 'Client Admin password', required: true, type: 'password' },
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
  return <Dialog label="Register client" onClose={onClose}><div className="modal onboarding-modal" onMouseDown={e => e.stopPropagation()}>
    <div className="drawer-top"><span>Register client + Client Admin</span><CloseButton type="button" onClick={onClose} aria-label="Close"/></div>
    <form onSubmit={submit}>
      <div className="modal-body onboarding-fields">{fields.map(f => <Field key={f.key}>{f.label}
        <Input type={f.type || 'text'} required={f.required} maxLength={f.type === 'password' ? 200 : 255}
          minLength={f.type === 'password' ? 8 : undefined} autoComplete={f.type === 'password' ? 'new-password' : 'off'}
          value={form[f.key]} onChange={e => setForm(p => ({ ...p, [f.key]: e.target.value }))}/>
      </Field>)}
      <p className="hint">Share the initial login credentials securely. They are not emailed automatically.</p>
      {error && <p className="error" role="alert">{error}</p>}</div>
      <div className="modal-actions"><Button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</Button>
        <Button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Registering…' : 'Register client'}</Button></div>
    </form></div></Dialog>;
}
