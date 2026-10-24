import { useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Button, Card, Input } from './ui';
import { useState } from 'react';
import { client } from '../api/client';

type User = { id: number; name: string; email: string; role: string };
export default function MyProfile({ user, onUpdate }: { user: User; onUpdate: (user: User) => void }) {
  const [name, setName] = useState(user.name);
  const [email, setEmail] = useState(user.email);
  const canEditEmail = user.role === 'super_admin';
  const emailChanged = canEditEmail && email.trim().toLowerCase() !== user.email;
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [, setNotice] = useFeedbackState('success');
  const [error, setError] = useFeedbackState('error');
  const save = async (event: React.FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setNotice('');
    try {
      const { data } = await client.patch('/auth/profile', {
        name, ...(canEditEmail ? { email } : {}),
        ...(newPassword || emailChanged ? { current_password: currentPassword } : {}),
        ...(newPassword ? { new_password: newPassword, new_password_confirmation: confirmation } : {}),
      });
      onUpdate(data.user); setEmail(data.user.email); setCurrentPassword(''); setNewPassword(''); setConfirmation('');
      setNotice('Profile saved.');
    } catch (e: any) {
      const errors = e.response?.data?.errors;
      setError(errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message || 'Update failed.');
    } finally { setBusy(false); }
  };
  return <Card className="panel user-management">
    <h3>My profile</h3><p>{canEditEmail ? 'Update your display name, email address or password. Confirm your current password to change your email or password.' : 'Update your display name or change your password. Role, client assignment and email are managed by your organization.'}</p>
    <form className="admin-form" onSubmit={save}>
      <Field>Display name<Input required value={name} onChange={e => setName(e.target.value)} maxLength={255}/></Field>
      <Field>Email<Input type="email" required maxLength={255} autoComplete="email" value={email} disabled={!canEditEmail} onChange={e => setEmail(e.target.value)}/></Field>
      <Field>{canEditEmail ? 'Current password (to change email or password)' : 'Current password (to change password)'}<Input type="password" required={Boolean(newPassword || emailChanged)} autoComplete="current-password" value={currentPassword} onChange={e => setCurrentPassword(e.target.value)}/></Field>
      <Field>New password<Input type="password" autoComplete="new-password" value={newPassword} onChange={e => setNewPassword(e.target.value)} minLength={newPassword ? 8 : undefined}/></Field>
      <Field>Confirm new password<Input type="password" autoComplete="new-password" value={confirmation} onChange={e => setConfirmation(e.target.value)}/></Field>
      <Button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save profile'}</Button>
    </form>
    {error && <p className="error" role="alert">{error}</p>}
  </Card>;
}
