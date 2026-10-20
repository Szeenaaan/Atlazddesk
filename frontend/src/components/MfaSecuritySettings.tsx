import { useEffect, useState } from 'react';
import { client } from '../api/client';
import FeedbackToast, { type Feedback } from './FeedbackToast';
import { QRCodeSVG } from 'qrcode.react';

type Flags = { totp_enabled: boolean; email_enabled: boolean };
type Status = { totp_enabled: boolean; email_enabled: boolean; email_verified: boolean; required: boolean; recovery_remaining: number; available: Flags };

function errorText(e: any): string {
  if (e.response?.status === 422 && e.response?.data?.errors) return Object.values(e.response.data.errors).flat().join(' ');
  return e.response?.data?.message || 'Request failed. Please try again.';
}

export function ContractorMfaSettings() {
  const [flags, setFlags] = useState<Flags | null>(null);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  useEffect(() => { client.get('/admin/security/mfa').then(({ data }) => setFlags(data.data)).catch(e => setError(errorText(e))); }, []);
  const save = async () => {
    if (!flags) return;
    setBusy(true); setError(''); setMessage('');
    try {
      const { data } = await client.patch('/admin/security/mfa', { ...flags, current_password: password });
      setFlags(data.data); setPassword(''); setMessage('MFA policy saved. Users will complete any newly required Email OTP or enrolled authenticator verification at their next sign-in.');
    } catch (e) { setError(errorText(e)); }
    finally { setBusy(false); }
  };
  return <section className="panel mfa-config-panel">
    <h3>Workforce authentication</h3>
    {!flags ? <p>Loading security configuration…</p> : <>
      <label className="mfa-switch"><span><b>Allow Google Authenticator enrollment</b></span>
        <input type="checkbox" checked={flags.totp_enabled} onChange={e => setFlags(f => f && ({ ...f, totp_enabled: e.target.checked }))}/></label>
      <label className="mfa-switch"><span><b>Require Email OTP</b></span>
        <input type="checkbox" checked={flags.email_enabled} onChange={e => setFlags(f => f && ({ ...f, email_enabled: e.target.checked }))}/></label>
      <label className="mfa-field">Confirm with your Contractor Admin password
        <input type="password" autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)}/>
      </label>
      <button className="btn btn-primary" type="button" disabled={!password || busy} onClick={save}>{busy ? 'Saving…' : 'Save MFA settings'}</button>
    </>}
    {error && <p className="error" role="alert">{error}</p>}
    {message && <p className="mfa-notice">{message}</p>}
  </section>;
}

export function MyMfaSettings() {
  const [status, setStatus] = useState<Status | null>(null);
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [secret, setSecret] = useState('');
  const [provisioningUri, setProvisioningUri] = useState('');
  const [recovery, setRecovery] = useState<string[]>([]);
  const [replacePassword, setReplacePassword] = useState('');
  const [replaceOldCode, setReplaceOldCode] = useState('');
  const [replaceNewCode, setReplaceNewCode] = useState('');
  const [replaceSecret, setReplaceSecret] = useState('');
  const [replaceUri, setReplaceUri] = useState('');
  const [replaceBusy, setReplaceBusy] = useState(false);
  const [replaceError, setReplaceError] = useState('');
  const [disableMethod, setDisableMethod] = useState<'totp' | 'email'>('totp');
  const [disablePassword, setDisablePassword] = useState('');
  const [disableCode, setDisableCode] = useState('');
  const [notice, setNotice] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [recoveryFeedback, setRecoveryFeedback] = useState<Feedback | null>(null);
  const reload = async () => { const { data } = await client.get('/auth/mfa/status'); setStatus(data); };
  useEffect(() => { void reload().catch(e => setError(errorText(e))); }, []);
  async function call(task: () => Promise<void>) {
    setError(''); setNotice(''); setBusy(true);
    try { await task(); } catch (e) { setError(errorText(e)); } finally { setBusy(false); }
  }
  const setupTotp = () => void call(async () => {
    const { data } = await client.post('/auth/mfa/totp/setup', { password });
    setSecret(data.secret); setProvisioningUri(data.provisioning_uri); setCode('');
  });
  const confirmTotp = () => void call(async () => {
    const { data } = await client.post('/auth/mfa/totp/confirm', { code });
    setRecovery(data.recovery_codes || []); setSecret(''); setProvisioningUri(''); setCode(''); await reload();
    setNotice('Authenticator enabled. Store your recovery codes securely.');
  });
  const regenerateCodes = () => void call(async () => {
    const { data } = await client.post('/auth/mfa/recovery/regenerate', { password });
    setRecovery(data.recovery_codes || []);
    setPassword('');
    await reload();
    setRecoveryFeedback({ tone: 'success', message: 'New recovery codes generated. Old codes no longer work.' });
  });
  const startReplacement = async () => {
    setReplaceBusy(true); setReplaceError('');
    try {
      const { data } = await client.post('/auth/mfa/totp/replace/start', {
        password: replacePassword, current_code: replaceOldCode,
      });
      setReplaceSecret(data.secret); setReplaceUri(data.provisioning_uri);
      setReplaceOldCode(''); setReplacePassword(''); setReplaceNewCode('');
    } catch (e) { setReplaceError(errorText(e)); }
    finally { setReplaceBusy(false); }
  };
  const confirmReplacement = async () => {
    setReplaceBusy(true); setReplaceError('');
    try {
      const { data } = await client.post('/auth/mfa/totp/replace/confirm', { code: replaceNewCode });
      setRecovery(data.recovery_codes || []);
      setReplaceSecret(''); setReplaceUri(''); setReplaceNewCode('');
      await reload();
      setRecoveryFeedback({ tone: 'success', message: 'Authenticator replaced. Save the new recovery codes shown below.' });
    } catch (e) { setReplaceError(errorText(e)); }
    finally { setReplaceBusy(false); }
  };
  const cancelReplacement = async () => {
    setReplaceBusy(true); setReplaceError('');
    try {
      await client.post('/auth/mfa/totp/replace/cancel');
      setReplaceSecret(''); setReplaceUri(''); setReplaceNewCode('');
      setRecoveryFeedback({ tone: 'success', message: 'Replacement cancelled. Your existing authenticator is still active.' });
    } catch (e) { setReplaceError(errorText(e)); }
    finally { setReplaceBusy(false); }
  };
  const sendEmail = () => void call(async () => {
    await client.post('/auth/mfa/email/enroll/send', { password });
    setNotice('Verification email sent.');
  });
  const confirmEmail = () => void call(async () => {
    const { data } = await client.post('/auth/mfa/email/enroll/confirm', { code });
    if (data.recovery_codes?.length) setRecovery(data.recovery_codes);
    setCode(''); await reload(); setNotice('Email OTP enabled. Save your recovery codes if shown.');
  });
  const disableMfa = () => void call(async () => {
    await client.post('/auth/mfa/disable', { method: status?.totp_enabled ? disableMethod : 'email', password: disablePassword, code: disableCode });
    setDisableCode(''); setDisablePassword(''); await reload();
    setNotice('MFA method disabled for your account.');
  });
  const sendDisableEmail = () => void call(async () => {
    await client.post('/auth/mfa/email/disable/send', { password: disablePassword });
    setNotice('Email verification code sent.');
  });
  return <section className="panel mfa-config-panel">
    {status && (status.available.totp_enabled || status.available.email_enabled || status.totp_enabled || status.email_enabled) && <h3>My account security</h3>}
    {status && (status.available.totp_enabled || status.available.email_enabled || status.totp_enabled || status.email_enabled) &&
      <p>Manage your sign-in verification methods. Your password is required for enrollment and recovery-code changes.</p>}
    {!status ? <p>Loading account security…</p> : <>
      {(status.available.totp_enabled || status.available.email_enabled || status.totp_enabled || status.email_enabled) && <p className="mfa-enrollment-summary">
        {status.available.totp_enabled || status.totp_enabled ? <span>Google Authenticator: <b>{status.totp_enabled ? 'Enrolled' : 'Not enrolled'}</b>{status.totp_enabled && !status.available.totp_enabled && <small> (currently not required)</small>}</span> : null}
        {status.available.email_enabled || status.email_enabled ? <span>Email OTP: <b>{status.email_enabled ? 'Enrolled' : 'Not enrolled'}</b>{status.email_enabled && !status.available.email_enabled && <small> (currently not required)</small>}</span> : null}
      </p>}
      {(status.available.totp_enabled || status.available.email_enabled || status.totp_enabled || status.email_enabled) && <label className="mfa-field">Current password
        <input type="password" autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)}/>
      </label>}
      {status.available.totp_enabled && !status.totp_enabled && <div className="mfa-method-card">
        <b>Google Authenticator</b>
        {!secret ? <button className="btn btn-secondary" type="button" disabled={!password || busy} onClick={setupTotp}>Set up</button>
          : <><p>Scan this QR code with Google Authenticator, or choose Enter a setup key (time-based) and copy the key below.</p>
            {provisioningUri && <div className="mfa-qr"><QRCodeSVG value={provisioningUri} size={184}/></div>}
            <code className="mfa-secret">{secret}</code><button className="btn btn-secondary" type="button" onClick={() => { void navigator.clipboard.writeText(secret).then(() => setNotice('Setup key copied.')).catch(() => setError('Unable to copy the key. Select it manually.')); }}>Copy setup key</button>
            <label className="mfa-field">Six-digit code<input value={code} inputMode="numeric" onChange={e => setCode(e.target.value)}/></label>
            <button className="btn btn-primary" disabled={busy} onClick={confirmTotp}>Confirm authenticator</button></>}
      </div>}
      {status.available.email_enabled && !status.email_enabled && <div className="mfa-method-card">
        <b>Email OTP</b>
        <p>Send a verification code to your account email, then enter it here.</p>
        <button className="btn btn-secondary" disabled={!password || busy} onClick={sendEmail}>Send code</button>
        <label className="mfa-field">Email code<input value={code} inputMode="numeric" onChange={e => setCode(e.target.value)}/></label>
        <button className="btn btn-primary" disabled={!code || busy} onClick={confirmEmail}>Confirm email OTP</button>
      </div>}
      {status.available.totp_enabled && status.totp_enabled && <div className="mfa-method-card mfa-replace-card">
        <h3>Replace Google Authenticator</h3>
        <p>Moving to a new phone? Replace your own authenticator without switching off the administrator's MFA policy. Your current device works until the new one is confirmed.</p>
        {!replaceSecret ? <div className="mfa-replace-form">
          <label className="mfa-field">Current password
            <input type="password" autoComplete="current-password" value={replacePassword} onChange={e => setReplacePassword(e.target.value)}/>
          </label>
          <label className="mfa-field">Current authenticator code or unused recovery code
            <input type="text" autoComplete="one-time-code" value={replaceOldCode} onChange={e => setReplaceOldCode(e.target.value)}/>
          </label>
          <button type="button" className="btn btn-secondary" disabled={replaceBusy || !replacePassword || !replaceOldCode} onClick={() => void startReplacement()}>
            {replaceBusy ? 'Verifying…' : 'Start authenticator replacement'}
          </button>
        </div> : <div className="mfa-replace-form">
          <p>Scan the NEW QR code and enter the code shown by your new authenticator. The existing device remains active until verification succeeds.</p>
          {replaceUri && <div className="mfa-qr"><QRCodeSVG value={replaceUri} size={184}/></div>}
          <code className="mfa-secret">{replaceSecret}</code>
          <button type="button" className="btn btn-secondary" onClick={() => { void navigator.clipboard.writeText(replaceSecret).catch(() => setReplaceError('Cannot copy automatically. Select the setup key manually.')); }}>Copy new setup key</button>
          <label className="mfa-field">Six-digit code from your NEW authenticator
            <input type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={replaceNewCode} onChange={e => setReplaceNewCode(e.target.value)}/>
          </label>
          <div className="mfa-replace-actions">
            <button type="button" className="btn btn-primary" disabled={replaceBusy || !/^\d{6}$/.test(replaceNewCode)} onClick={() => void confirmReplacement()}>
              {replaceBusy ? 'Verifying…' : 'Confirm new authenticator'}
            </button>
            <button type="button" className="btn btn-secondary" disabled={replaceBusy} onClick={() => void cancelReplacement()}>Cancel replacement</button>
          </div>
        </div>}
        {replaceError && <p className="error" role="alert">{replaceError}</p>}
      </div>}
      {(status.totp_enabled || status.email_enabled) && <div className="mfa-method-card">
        <b>Recovery codes</b>
        <p>{status.recovery_remaining} unused recovery code{status.recovery_remaining === 1 ? '' : 's'} remain.</p>
        <p>Replace your recovery codes after signing in and confirming your password. Old codes will stop working.</p>
        <button className="btn btn-secondary" type="button" disabled={!password || busy} onClick={regenerateCodes}>
          Generate new recovery codes
        </button>
      </div>}
      {(!status.available.totp_enabled && !status.available.email_enabled && !status.totp_enabled && !status.email_enabled) && <div className="mfa-policy-empty" role="status">
        <h4>No additional verification methods available</h4>
        <p>Additional sign-in verification is not currently enabled for your account. When an administrator makes a method available, you can manage it here.</p>
      </div>}
      {(status.totp_enabled || status.email_enabled) && <div className="mfa-method-card">
        <h4>Remove an enrolled method</h4>
        <p>Removing enrollment affects only your account and is allowed only when the administrator policy permits it. To change phones while Google Authenticator is required, use Replace Google Authenticator above.</p>
        <label>Method<select value={status.totp_enabled ? disableMethod : 'email'} onChange={e => setDisableMethod(e.target.value as 'totp' | 'email')}>
          {status.totp_enabled && <option value="totp">Google Authenticator</option>}
          {status.email_enabled && <option value="email">Email OTP</option>}
        </select></label>
        <label>Current password<input type="password" autoComplete="current-password" value={disablePassword} onChange={e => setDisablePassword(e.target.value)}/></label>
        {disableMethod === 'email' && !status.totp_enabled && <button type="button" className="btn btn-secondary" disabled={!disablePassword || busy} onClick={sendDisableEmail}>Send disable code</button>}
        <label>Verification or recovery code<input autoComplete="one-time-code" value={disableCode} onChange={e => setDisableCode(e.target.value)}/></label>
        <button type="button" className="btn btn-secondary" disabled={!disablePassword || !disableCode || busy} onClick={disableMfa}>Remove selected method</button>
      </div>}
      {recovery.length > 0 && <div className="mfa-method-card"><b>Save recovery codes now — they will not be shown again.</b><pre className="mfa-recovery">{recovery.join('\n')}</pre><button className="btn btn-secondary" onClick={() => setRecovery([])}>I've saved the codes</button></div>}
    </>}
    <FeedbackToast feedback={recoveryFeedback} onDismiss={() => setRecoveryFeedback(null)}/>
    {notice && <p className="mfa-notice">{notice}</p>}
    {error && <p className="error" role="alert">{error}</p>}
  </section>;
}
