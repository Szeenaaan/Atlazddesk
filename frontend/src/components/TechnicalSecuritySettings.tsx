import { useEffect, useState, type FormEvent } from 'react';
import { client } from '../api/client';
import FeedbackToast, { type Feedback } from './FeedbackToast';

type Policy = { totp_enabled: boolean; email_enabled: boolean; require_mfa?: boolean };
type Provider = { enabled: boolean; driver: 'smtp' | 'resend' | 'postmark' | null; from_name: string | null; from_email: string | null; credential_configured: boolean };
type Configuration = { mfa: Policy; mail: Provider };
const readableError = (e: unknown): string => {
  const error = e as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
  return Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Unable to complete request.';
};

export default function TechnicalSecuritySettings() {
  const [config, setConfig] = useState<Configuration | null>(null);
  const [form, setForm] = useState({ driver: 'smtp', from_name: '', from_email: '', host: '', port: '587', encryption: 'tls', username: '', password: '', api_key: '' });
  const [mfaPassword, setMfaPassword] = useState('');
  const [mailPassword, setMailPassword] = useState('');
  const [testPassword, setTestPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [feedbackArea, setFeedbackArea] = useState<'mfa' | 'mail' | 'test'>('mfa');
  const [reauthRequired, setReauthRequired] = useState(false);
  const [savedMfa, setSavedMfa] = useState<Policy | null>(null);

  const load = async () => {
    const { data } = await client.get<{ data: Configuration }>('/system/security');
    setConfig(data.data);
    setSavedMfa(data.data.mfa);
    setForm(p => ({ ...p, driver: data.data.mail.driver || 'smtp', from_name: data.data.mail.from_name || '', from_email: data.data.mail.from_email || '' }));
  };
  useEffect(() => { void load().catch(e => setFeedback({ tone: 'error', message: readableError(e) })); }, []);
  async function run(job: () => Promise<void>, area: 'mfa' | 'mail' | 'test') {
    setFeedbackArea(area);
    setBusy(true); setError(''); setFeedback(null);
    try { await job(); }
    catch (e) {
      const message = readableError(e);
      const status = (e as {response?: {status?: number}}).response?.status;
      if ((status === 422 || status === 401) && /password/i.test(message)) setError(message);
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
        ...(changedDriver || secretEntered || !config.mail.credential_configured ? { credentials } : {}),
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
    <section className="panel support-section">
      <h3>Super Admin account protection</h3>
      <p>Enable sign-in methods for technical administrators of this installation. Contractor and client MFA policies remain separate.</p>
      {!config ? <p>Loading account policy…</p> : <form className="support-form" onSubmit={saveMfa}>
        <label className="mfa-switch"><span><b>Google Authenticator</b><small>Each Super Admin generates their own setup key in My Security.</small></span>
          <input type="checkbox" checked={config.mfa.totp_enabled} onChange={e => setConfig(p => p && ({ ...p, mfa: { ...p.mfa, totp_enabled: e.target.checked } }))}/></label>
        <label className="mfa-switch"><span><b>Email OTP</b><small>Requires your technical email provider to be enabled and working.</small></span>
          <input type="checkbox" checked={config.mfa.email_enabled} onChange={e => setConfig(p => p && ({ ...p, mfa: { ...p.mfa, email_enabled: e.target.checked } }))}/></label>
        <label>Current password<input type="password" autoComplete="current-password" required value={mfaPassword} onChange={e => setMfaPassword(e.target.value)}/>{error && feedbackArea === 'mfa' && <span className="amc-field-error" role="alert">{error}</span>}</label>
        <button type="submit" className="btn btn-primary" disabled={busy}>Save MFA settings</button>
        {reauthRequired && <div className="amc-inline-warning" role="status">Your MFA policy has changed. Sign in again to enroll or verify every required method.<div><button type="button" className="btn btn-secondary" onClick={() => window.location.reload()}>Continue to sign-in</button></div></div>} 
        <p className="hint">Each enabled method is required on every login. Enabling both requires Google Authenticator AND Email OTP. Users enroll individually in My Security or during sign-in. Confirm delivery before enabling Email OTP.</p>
      </form>}
    </section>
    <section className="panel support-section">
      <h3>Technical email provider</h3>
      <p>Used only for Super Admin security codes and technical test messages. Client notification providers are managed by Contractor Admin.</p>
      {config && <form className="support-form" onSubmit={saveMail}>
        <label className="mfa-switch"><span><b>Enable technical email delivery</b><small>Turn off only after technical Email OTP has been disabled.</small></span>
          <input type="checkbox" checked={config.mail.enabled} onChange={e => setConfig(p => p && ({ ...p, mail: { ...p.mail, enabled: e.target.checked } }))}/></label>
        <label>Provider<select value={form.driver} onChange={e => setForm(p => ({ ...p, driver: e.target.value }))}>
          <option value="smtp">SMTP</option><option value="resend">Resend</option><option value="postmark">Postmark</option>
        </select></label>
        <label>Sender name<input required maxLength={120} value={form.from_name} onChange={e => setForm(p => ({ ...p, from_name: e.target.value }))}/></label>
        <label>Sender email<input required type="email" value={form.from_email} onChange={e => setForm(p => ({ ...p, from_email: e.target.value }))}/></label>
        {form.driver === 'smtp' ? <>
          <label>SMTP host<input placeholder="mail.example.org" value={form.host} onChange={e => setForm(p => ({ ...p, host: e.target.value }))}/></label>
          <label>SMTP port<input type="number" min={1} max={65535} value={form.port} onChange={e => setForm(p => ({ ...p, port: e.target.value }))}/></label>
          <label>TLS mode<select value={form.encryption} onChange={e => setForm(p => ({ ...p, encryption: e.target.value }))}><option value="tls">STARTTLS</option><option value="ssl">SSL/TLS</option></select></label>
          <label>Username<input autoComplete="off" value={form.username} onChange={e => setForm(p => ({ ...p, username: e.target.value }))}/></label>
          <label>Password<input type="password" autoComplete="new-password" value={form.password} onChange={e => setForm(p => ({ ...p, password: e.target.value }))}/></label>
        </> : <label>Provider API key<input type="password" autoComplete="new-password" value={form.api_key} onChange={e => setForm(p => ({ ...p, api_key: e.target.value }))}/></label>}
        {config.mail.credential_configured && <small>Credentials securely stored. Leave secret fields empty to keep existing settings.</small>}
        <label>Confirm Super Admin password<input type="password" autoComplete="current-password" required value={mailPassword} onChange={e => setMailPassword(e.target.value)}/>{error && feedbackArea === 'mail' && <span className="amc-field-error" role="alert">{error}</span>}</label>
        <button type="submit" className="btn btn-primary" disabled={busy}>Save provider</button>
      </form>}
      {config?.mail.enabled && <div className="support-form">
        <label>Confirm password to send test<input type="password" autoComplete="current-password" value={testPassword} onChange={e => setTestPassword(e.target.value)}/>{error && feedbackArea === 'test' && <span className="amc-field-error" role="alert">{error}</span>}</label>
        <button type="button" className="btn btn-secondary" disabled={busy || !testPassword} onClick={testMail}>Send test to my email</button>
      </div>}
    </section>
    <FeedbackToast feedback={feedback} onDismiss={() => setFeedback(null)}/>
  </div>;
}
