import { useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Button, Card, Switch, Input } from './ui';
import EmailProviderFields from './EmailProviderFields';
import { useEffect, useState, type FormEvent } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import FeedbackToast, { type Feedback } from './FeedbackToast';

type Policy = { totp_enabled: boolean; email_enabled: boolean; require_mfa?: boolean };
type Provider = { enabled: boolean; driver: 'smtp' | 'resend' | 'postmark' | null; from_name: string | null; from_email: string | null; credential_configured: boolean;
  smtp?: {host?: string; port?: number; encryption?: string; username?: string} | null };
type Configuration = { mfa: Policy; mail: Provider };
const readableError = (error: unknown): string => getApiErrorMessage(error, 'The account security settings could not be updated. Please try again.');

export default function TechnicalSecuritySettings() {
  const [config, setConfig] = useState<Configuration | null>(null);
  const [form, setForm] = useState({ driver: 'smtp', from_name: '', from_email: '', host: '', port: '587', encryption: 'tls', username: '', password: '', api_key: '' });
  const [mfaPassword, setMfaPassword] = useState('');
  const [mailPassword, setMailPassword] = useState('');
  const [testPassword, setTestPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useFeedbackState('error');
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [feedbackArea, setFeedbackArea] = useState<'mfa' | 'mail' | 'test'>('mfa');
  const [reauthRequired, setReauthRequired] = useState(false);
  const [savedMfa, setSavedMfa] = useState<Policy | null>(null);

  const load = async () => {
    const { data } = await client.get<{ data: Configuration }>('/system/security');
    setConfig(data.data);
    setSavedMfa(data.data.mfa);
    const mail = data.data.mail;
    setForm(p => ({ ...p, driver: mail.driver || 'smtp', from_name: mail.from_name || '', from_email: mail.from_email || '',
      host: mail.smtp?.host || '', port: String(mail.smtp?.port || 587), encryption: mail.smtp?.encryption || 'tls',
      username: mail.smtp?.username || '', password: '', api_key: '' }));
  };
  useEffect(() => { void load().catch(e => setFeedback({ tone: 'error', message: readableError(e) })); }, []);
  async function run(job: () => Promise<void>, area: 'mfa' | 'mail' | 'test') {
    setFeedbackArea(area);
    setBusy(true); setError(''); setFeedback(null);
    try { await job(); }
    catch (e) {
      const message = readableError(e);
      const status = (e as {response?: {status?: number}}).response?.status;
      const passwordErrors = (e as {response?: {data?: {errors?: {current_password?: unknown}}}}).response?.data?.errors?.current_password;
      if ((status === 422 || status === 401) && (passwordErrors || /^Current password is incorrect\.?$/i.test(message))) setError(message);
      else if (status === 403 && (e as {response?: {data?: {code?: string}}}).response?.data?.code === 'MFA_REQUIRED') {
        setReauthRequired(true); setFeedback({ tone: 'warning', message });
      } else setFeedback({ tone: 'error', message });
    } finally { setBusy(false); }
  }
  const saveMfa = (e: FormEvent) => {
    e.preventDefault();
    if (!config) return;
    const newlyRequired = Boolean(savedMfa && (
      (config.mfa.totp_enabled && !savedMfa.totp_enabled) ||
      (config.mfa.email_enabled && !savedMfa.email_enabled)
    ));
    void run(async () => {
      await client.put('/system/security/mfa', { ...config.mfa, current_password: mfaPassword });
      setMfaPassword(''); setSavedMfa({ ...config.mfa });
      // The session may now be invalid under the newly enabled MFA policy.
      // Do not reload protected settings until the user signs in again.
      if (newlyRequired) setReauthRequired(true);
      setFeedback({ tone: 'success', message: 'MFA settings saved.' });
    }, 'mfa');
  };
  const saveMail = (e: FormEvent) => {
    e.preventDefault();
    if (!config) return;
    void run(async () => {
      const driver = form.driver;
      const changedDriver = config.mail.driver !== driver;
      const secretEntered = driver === 'smtp' ? Boolean(form.host || form.password || form.username) : Boolean(form.api_key);
      const credentials = driver === 'smtp'
        ? { host: form.host, port: Number(form.port), encryption: form.encryption, username: form.username, password: form.password }
        : { api_key: form.api_key };
      const payload = {
        enabled: config.mail.enabled, driver, from_email: form.from_email, from_name: form.from_name,
        ...(driver === 'smtp' || changedDriver || secretEntered || !config.mail.credential_configured ? { credentials } : {}),
        current_password: mailPassword,
      };
      await client.put('/system/security/mail', payload);
      setMailPassword(''); setForm(p => ({ ...p, api_key: '', password: '' }));
      await load(); setFeedback({ tone: 'success', message: 'Technical email settings saved.' });
    }, 'mail');
  };
  const testMail = () => void run(async () => {
    const { data } = await client.post<{ message: string }>('/system/security/mail/test', { current_password: testPassword });
    setTestPassword(''); setFeedback({ tone: 'success', message: data.message });
  }, 'test');

  return <div className="support-config-grid">
    <Card className="panel support-section">
      <h3>Super Admin account protection</h3>
      <p>Enable sign-in methods for technical administrators of this installation. Contractor and client MFA policies remain separate.</p>
      {!config ? <p>Loading account policy…</p> : <form className="support-form" onSubmit={saveMfa}>
        <Field className="mfa-switch"><span><b>Google Authenticator</b><small>Each Super Admin generates their own setup key in My Security.</small></span>
          <Switch type="checkbox" checked={config.mfa.totp_enabled} onChange={e => setConfig(p => p && ({ ...p, mfa: { ...p.mfa, totp_enabled: e.target.checked } }))}/></Field>
        <Field className="mfa-switch"><span><b>Email OTP</b><small>Requires your technical email provider to be enabled and working.</small></span>
          <Switch type="checkbox" checked={config.mfa.email_enabled} onChange={e => setConfig(p => p && ({ ...p, mfa: { ...p.mfa, email_enabled: e.target.checked } }))}/></Field>
        <Field>Current password<Input type="password" autoComplete="current-password" required value={mfaPassword} onChange={e => setMfaPassword(e.target.value)}/>{error && feedbackArea === 'mfa' && <span className="amc-field-error" role="alert">{error}</span>}</Field>
        <Button type="submit" className="btn btn-primary" disabled={busy}>Save MFA settings</Button>
        {reauthRequired && <div className="amc-inline-warning" role="status">Your MFA policy has changed. Sign in again to enroll or verify every required method.<div><Button type="button" className="btn btn-secondary" onClick={() => window.location.reload()}>Continue to sign-in</Button></div></div>}
        <p className="hint">Each enabled method is required on every login. Enabling both requires Google Authenticator AND Email OTP. Users enroll individually in My Security or during sign-in. Confirm delivery before enabling Email OTP.</p>
      </form>}
    </Card>
    <Card className="panel support-section email-settings">
      <h3>Technical email provider</h3>
      <p>Used only for Super Admin security codes and technical test messages. Client notification providers are managed by Contractor Admin.</p>
      {config && <form className="support-form email-provider-form" onSubmit={saveMail}>
        <Field className="mfa-switch"><span><b>Enable technical email delivery</b><small>Turn off only after technical Email OTP has been disabled.</small></span>
          <Switch type="checkbox" checked={config.mail.enabled} onChange={e => setConfig(p => p && ({ ...p, mail: { ...p.mail, enabled: e.target.checked } }))}/></Field>
        <EmailProviderFields disabled={busy} values={{...form, secret: form.driver === 'smtp' ? form.password : form.api_key}}
          onChange={(field, value) => setForm(current => field === 'secret'
            ? { ...current, [current.driver === 'smtp' ? 'password' : 'api_key']: value }
            : { ...current, [field]: value })}/>
        {config.mail.credential_configured && <small>Credentials securely stored. Leave secret fields empty to keep existing settings.</small>}
        <Field>Confirm Super Admin password<Input type="password" autoComplete="current-password" required value={mailPassword} onChange={e => setMailPassword(e.target.value)}/>{error && feedbackArea === 'mail' && <span className="amc-field-error" role="alert">{error}</span>}</Field>
        <Button type="submit" className="btn btn-primary" disabled={busy}>Save provider</Button>
      </form>}
      {config?.mail.enabled && <div className="support-form">
        <Field>Confirm password to send test<Input type="password" autoComplete="current-password" value={testPassword} onChange={e => setTestPassword(e.target.value)}/>{error && feedbackArea === 'test' && <span className="amc-field-error" role="alert">{error}</span>}</Field>
        <Button type="button" className="btn btn-secondary" disabled={busy || !testPassword} onClick={testMail}>Send test to my email</Button>
      </div>}
    </Card>
    <FeedbackToast feedback={feedback} onDismiss={() => setFeedback(null)}/>
  </div>;
}
