import { useState, type FormEvent } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { client } from '../api/client';
import FeedbackToast, { type Feedback } from './FeedbackToast';

export type MfaLoginResult = {
  id: number; name: string; email: string; role: string; customer_id: number | null;
};

export type MfaChallenge = {
  status: 'mfa_required' | 'setup_required';
  required_methods?: string[];
  verified_methods?: string[];
  remaining_methods?: string[];
  setup_methods?: string[];
  methods?: string[];
  setup_method?: string | null;
};

type ChallengeResponse = Omit<MfaChallenge, 'status'> & {
  status: 'mfa_required' | 'setup_required' | 'authenticated';
  user?: MfaLoginResult;
  recovery_codes?: string[];
};

type Props = {
  challenge: MfaChallenge;
  onComplete: (user: MfaLoginResult) => void;
  onCancel: () => void;
};

function errorMessage(error: unknown): string {
  const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
  return Object.values(response?.data?.errors ?? {}).flat().join(' ')
    || response?.data?.message
    || 'Verification failed. Please try again.';
}

/** The server, not the browser, decides which factors are required and finished. */
export default function MfaLogin({ challenge, onComplete, onCancel }: Props) {
  const [progress, setProgress] = useState<MfaChallenge>(challenge);
  const [code, setCode] = useState('');
  const [secret, setSecret] = useState('');
  const [uri, setUri] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [nextStep, setNextStep] = useState<ChallengeResponse | null>(null);
  const [useRecovery, setUseRecovery] = useState(false);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [busy, setBusy] = useState(false);

  const required = progress.required_methods ?? (progress.setup_method ? [progress.setup_method] : progress.methods ?? []);
  const remaining = progress.remaining_methods ?? (progress.setup_method ? [progress.setup_method] : progress.methods ?? []);
  const current = remaining[0] ?? 'totp';
  const needsSetup = (progress.setup_methods ?? (progress.status === 'setup_required' ? [progress.setup_method ?? 'totp'] : [])).includes(current);
  const finished = Math.max(0, required.length - remaining.length);
  const factorName = current === 'email' ? 'Email OTP' : 'Google Authenticator';
  const canUseRecovery = (progress.methods ?? []).includes('recovery');

  async function call(task: () => Promise<void>) {
    setBusy(true); setError(''); setFeedback(null);
    try { await task(); }
    catch (error) { setError(errorMessage(error)); }
    finally { setBusy(false); }
  }

  function applyResult(data: ChallengeResponse) {
    setCode(''); setSecret(''); setUri(''); setUseRecovery(false);
    if (data.recovery_codes?.length) {
      setRecoveryCodes(data.recovery_codes);
      setNextStep(data);
      return;
    }
    if (data.status === 'authenticated' && data.user) {
      onComplete(data.user);
    } else if (data.status === 'mfa_required' || data.status === 'setup_required') {
      setProgress({
        ...data,
        status: data.status,
      });
    } else {
      setError('Unexpected verification response. Please sign in again.');
    }
  }

  const setupAuthenticator = () => void call(async () => {
    const { data } = await client.post('/auth/mfa/totp/setup');
    setSecret(data.secret);
    setUri(data.provisioning_uri);
  });

  const copyKey = () => void call(async () => {
    await navigator.clipboard.writeText(secret);
    setFeedback({ tone: 'success', message: 'Setup key copied.' });
  });

  const sendEmail = () => void call(async () => {
    await client.post('/auth/mfa/email/send');
    setFeedback({ tone: 'success', message: 'Verification code sent to your registered email.' });
  });

  const verify = (event: FormEvent) => {
    event.preventDefault();
    void call(async () => {
      const { data } = secret
        ? await client.post<ChallengeResponse>('/auth/mfa/totp/confirm', { code })
        : await client.post<ChallengeResponse>('/auth/mfa/verify', {
          method: useRecovery ? 'recovery' : current,
          code,
        });
      applyResult(data);
    });
  };

  function continueAfterRecovery() {
    const next = nextStep;
    setRecoveryCodes([]); setNextStep(null);
    if (next) {
      applyResult({ ...next, recovery_codes: [] });
    }
  }

  return <div className="login-bg"><div className="login-panel mfa-login-panel">
    <h1>Verify your sign-in</h1>
    <p>Complete all verification steps enabled for your account to continue.</p>
    {recoveryCodes.length > 0 ? <section aria-label="One-time recovery codes">
      <h2>Save your recovery codes</h2>
      <p>These codes are displayed once. Store them securely before continuing.</p>
      <pre className="mfa-recovery">{recoveryCodes.join('\n')}</pre>
      <button type="button" className="btn btn-primary wide" onClick={continueAfterRecovery}>
        I saved my recovery codes — Continue
      </button>
    </section> : <>
      <p className="mfa-notice" role="status">
        Step {Math.min(finished + 1, Math.max(required.length, 1))} of {Math.max(required.length, 1)} · {factorName}
      </p>
      {required.length > 1 && <p>
        {required.map(method => `${method === 'totp' ? 'Google Authenticator' : 'Email OTP'}: ${remaining.includes(method) ? 'Pending' : 'Verified'}`).join(' · ')}
      </p>}
      {current === 'totp' && needsSetup && !secret && <>
        <p>You must enroll your own authenticator before accessing the dashboard.</p>
        <button type="button" className="btn btn-secondary wide" disabled={busy} onClick={setupAuthenticator}>Generate my authenticator key</button>
      </>}
      {current === 'totp' && secret && <div className="mfa-instructions">
        <p>Scan with Google Authenticator or another compatible app, or enter this time-based setup key manually.</p>
        {uri && <div className="mfa-qr"><QRCodeSVG value={uri} size={176} /></div>}
        <code className="mfa-secret">{secret}</code>
        <button className="btn btn-secondary wide" type="button" disabled={busy} onClick={copyKey}>Copy setup key</button>
      </div>}
      {current === 'email' && <>
        <p>{needsSetup ? 'Verify your registered email to activate Email OTP.' : 'Enter a one-time code from your registered email.'}</p>
        <button className="btn btn-secondary wide" type="button" disabled={busy} onClick={sendEmail}>Send email verification code</button>
      </>}
      {(current === 'email' || !needsSetup || Boolean(secret)) && <form onSubmit={verify}>
        {canUseRecovery && (current === 'totp' || required.length === 1) && !secret && <label>
          <input type="checkbox" checked={useRecovery} onChange={e => { setUseRecovery(e.target.checked); setCode(''); }} />
          Use a one-time recovery code instead
        </label>}
        <label>{useRecovery ? 'Recovery code' : 'Six-digit verification code'}
          <input autoFocus required type="text" inputMode={useRecovery ? 'text' : 'numeric'}
            autoComplete="one-time-code" value={code} maxLength={useRecovery ? 30 : 6}
            onChange={e => setCode(e.target.value)} />
          {error && <span className="amc-field-error" role="alert">{error}</span>}
        </label>
        <button type="submit" className="btn btn-primary wide" disabled={busy}>
          {busy ? 'Verifying…' : remaining.length > 1 ? 'Verify and continue' : 'Verify and sign in'}
        </button>
      </form>}
      {error && !(current === 'email' || !needsSetup || Boolean(secret)) && <p className="amc-field-error" role="alert">{error}</p>}
      <FeedbackToast feedback={feedback} onDismiss={() => setFeedback(null)}/>
      <button type="button" className="btn btn-secondary wide" disabled={busy}
        onClick={() => void client.post('/auth/mfa/cancel').catch(() => undefined).finally(onCancel)}>
        Cancel sign-in
      </button>
    </>}
  </div></div>;
}
