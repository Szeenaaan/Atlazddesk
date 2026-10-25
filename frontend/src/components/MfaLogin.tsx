import { Field } from './ui';
import { Button, Checkbox, Input, InlineNotice, UIState } from './ui';
import { useEffect, useState, type FormEvent } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import ThemeToggle from './ThemeToggle';
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
  return getApiErrorMessage(error, 'We could not complete verification. Please try again.');
}

/** The server, not the browser, decides which factors are required and finished. */
export default function MfaLogin({ challenge, onComplete, onCancel }: Props) {
  const [progress, setProgress] = useState<MfaChallenge>(challenge);
  const [codes, setCodes] = useState<Record<string, string>>({});
  const [emailSent, setEmailSent] = useState(false);
  const [secret, setSecret] = useState('');
  const [uri, setUri] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [nextStep, setNextStep] = useState<ChallengeResponse | null>(null);
  const [useRecovery, setUseRecovery] = useState(false);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState<Feedback | null>(null);
  const [busy, setBusy] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [verifiedUser, setVerifiedUser] = useState<MfaLoginResult | null>(null);

  useEffect(() => {
    if (verifiedUser) onComplete(verifiedUser);
  }, [verifiedUser, onComplete]);

  const required = progress.required_methods ?? (progress.setup_method ? [progress.setup_method] : progress.methods ?? []);
  const remaining = progress.remaining_methods ?? (progress.setup_method ? [progress.setup_method] : progress.methods ?? []);
  const setupMethods = progress.setup_methods ?? (progress.status === 'setup_required' ? [progress.setup_method ?? 'totp'] : []);
  const needsSetup = setupMethods.includes('totp') && remaining.includes('totp');
  const canUseRecovery = (progress.methods ?? []).includes('recovery');

  async function call(task: () => Promise<void>) {
    setBusy(true); setError(''); setFeedback(null);
    try { await task(); }
    catch (error) { setError(errorMessage(error)); }
    finally { setBusy(false); }
  }

  function applyResult(data: ChallengeResponse) {
    setCodes({}); setSecret(''); setUri(''); setUseRecovery(false);
    if (data.recovery_codes?.length) {
      setRecoveryCodes(data.recovery_codes);
      setNextStep(data);
      return;
    }
    if (data.status === 'authenticated' && data.user) {
      setVerifiedUser(data.user);
    } else if (data.status === 'mfa_required' || data.status === 'setup_required') {
      setProgress({
        ...data,
        status: data.status,
      });
    } else {
      setError('We could not complete verification. Please sign in again.');
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
    setEmailSent(true);
    setFeedback({ tone: 'success', message: 'Check your email for your verification code. It may take a moment to arrive.' });
  });

  const verify = (event: FormEvent) => {
    event.preventDefault();
    void call(async () => {
      setSubmitting(true);
      try {
        let result: ChallengeResponse | null = null;
        let issuedRecoveryCodes: string[] = [];
        for (const method of remaining) {
          const { data } = method === 'totp' && secret
            ? await client.post<ChallengeResponse>('/auth/mfa/totp/confirm', { code: codes[method] })
            : await client.post<ChallengeResponse>('/auth/mfa/verify', {
              method: method === 'totp' && useRecovery ? 'recovery' : method,
              code: codes[method],
            });
          result = data;
          issuedRecoveryCodes = [...issuedRecoveryCodes, ...(data.recovery_codes ?? [])];
          if (issuedRecoveryCodes.length) { setRecoveryCodes(issuedRecoveryCodes); setNextStep(data); }
          setCodes(previous => ({ ...previous, [method]: '' }));
          if (method === 'totp') { setSecret(''); setUri(''); }
          if (data.status === 'mfa_required' || data.status === 'setup_required') {
            setProgress({ ...data, status: data.status });
          }
        }
        if (result) applyResult({ ...result, recovery_codes: issuedRecoveryCodes });
      } finally {
        setSubmitting(false);
      }
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
    <div className="login-heading"><h1>Verify your sign-in</h1><ThemeToggle/></div>
    <p>{verifiedUser ? 'Your verification was successful.' : 'Complete all verification steps enabled for your account to continue.'}</p>
    {verifiedUser ? <UIState tone="loading">Sign-in verified. Opening your workspace…</UIState> : recoveryCodes.length > 0 ? <section aria-label="One-time recovery codes">
      <h2>Save your recovery codes</h2>
      <p>These codes are displayed once. Store them securely before continuing.</p>
      <pre className="mfa-recovery">{recoveryCodes.join('\n')}</pre>
      <Button type="button" className="btn btn-primary wide" disabled={busy} onClick={continueAfterRecovery}>
        I saved my recovery codes — Continue
      </Button>
    </section> : <>
      <form onSubmit={verify}>
      {needsSetup && !secret && <>
        <p>You must enroll your own authenticator before accessing the dashboard.</p>
        <Button type="button" className="btn btn-secondary wide" disabled={busy} onClick={setupAuthenticator}>Generate my authenticator key</Button>
      </>}
      {remaining.includes('totp') && secret && <div className="mfa-instructions">
        <p>Scan with Google Authenticator or another compatible app, or enter this time-based setup key manually.</p>
        {uri && <div className="mfa-qr"><QRCodeSVG value={uri} size={176} /></div>}
        <code className="mfa-secret">{secret}</code>
        <Button className="btn btn-secondary wide" type="button" disabled={busy} onClick={copyKey}>Copy setup key</Button>
      </div>}
      {canUseRecovery && remaining.includes('totp') && !needsSetup && <Field>
        <Checkbox type="checkbox" checked={useRecovery} disabled={busy}
          onChange={e => { setUseRecovery(e.target.checked); setCodes(previous => ({ ...previous, totp: '' })); }} />
        Use a one-time recovery code instead of Google Authenticator
      </Field>}
      {required.filter(method => method === 'email' || method === 'totp').map(method => {
        const verified = !remaining.includes(method);
        const recovery = method === 'totp' && useRecovery;
        return <Field key={method}>
          {method === 'email' ? 'Email verification code' : recovery ? 'Recovery code' : 'Google Authenticator code'}
          {verified ? <span role="status">Verified</span> : <div className={method === 'email' ? 'ui-input-action' : undefined}>
            <Input required type="text" inputMode={recovery ? 'text' : 'numeric'}
              aria-label={method === 'email' ? 'Email verification code' : recovery ? 'Recovery code' : 'Google Authenticator code'}
              autoComplete="one-time-code" value={codes[method] ?? ''} maxLength={recovery ? 30 : 6}
              pattern={recovery ? undefined : '[0-9]{6}'} disabled={busy || (method === 'totp' && needsSetup && !secret)}
              onChange={e => setCodes(previous => ({ ...previous, [method]: e.target.value }))} />
            {method === 'email' && <Button className="btn btn-secondary" type="button" disabled={busy} aria-label={emailSent ? 'Resend email verification code' : 'Send email verification code'} onClick={sendEmail}>
              {emailSent ? 'Resend' : 'Send'}
            </Button>}
          </div>}
        </Field>;
      })}
      {error && <InlineNotice tone="error">{error}</InlineNotice>}
      <Button type="submit" className="btn btn-primary wide" disabled={busy || (needsSetup && !secret)}>
        {submitting ? 'Verifying…' : 'Verify and sign in'}
      </Button>
      </form>
      <FeedbackToast feedback={feedback} onDismiss={() => setFeedback(null)}/>
      <Button type="button" className="btn btn-secondary wide" disabled={busy}
        onClick={() => void client.post('/auth/mfa/cancel').catch(() => undefined).finally(onCancel)}>
        Cancel sign-in
      </Button>
    </>}
  </div></div>;
}
