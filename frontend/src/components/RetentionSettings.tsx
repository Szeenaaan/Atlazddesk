import { Field } from './ui';
import { Button, Card, Switch, Input } from './ui';
import { useCallback, useEffect, useState } from 'react';
import { client } from '../api/client';
import FeedbackToast, { type Feedback } from './FeedbackToast';

type Policy = {
  category: string;
  label: string;
  minimum_days: number;
  enabled: boolean;
  retention_days: number;
  run_every_days: number;
  batch_size: number;
  max_batches: number;
  eligible_count: number;
  last_run_at: string | null;
  last_deleted_count: number;
};

type Scope = 'technical' | 'business';

const apiPath = (scope: Scope) => scope === 'technical' ? '/system/support/retention' : '/admin/retention';

function readError(error: unknown): string {
  const data = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  return Object.values(data?.errors || {}).flat().join(' ') || data?.message || 'Could not update retention settings.';
}

export default function RetentionSettings({ scope }: { scope: Scope }) {
  const [policies, setPolicies] = useState<Policy[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [passwordError, setPasswordError] = useState('');
  const [password, setPassword] = useState('');
  const [feedback, setFeedback] = useState<Feedback | null>(null);

  const load = useCallback(async () => {
    setBusy(true);
    setError('');
    try {
      const { data } = await client.get<{ data: Policy[] }>(apiPath(scope));
      setPolicies(data.data);
    } catch (err) { setError(readError(err)); }
    finally { setBusy(false); }
  }, [scope]);

  useEffect(() => { void load(); }, [load]);

  function update(category: string, changes: Partial<Policy>) {
    setPolicies(current => current.map(p => p.category === category ? { ...p, ...changes } : p));
  }

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true); setError(''); setPasswordError('');
    try {
      const { data } = await client.put<{ data: Policy[] }>(apiPath(scope), {
        current_password: password,
        policies: policies.map(({ category, enabled, retention_days, run_every_days, batch_size, max_batches }) => ({
          category, enabled, retention_days, run_every_days, batch_size, max_batches,
        })),
      });
      setPolicies(data.data);
      setPassword('');
      setFeedback({ tone: 'success', message: 'Retention settings saved. The scheduler will clean only enabled, due categories.' });
    } catch (err) {
      const message = readError(err);
      if (/password/i.test(message)) setPasswordError(message);
      else { setError(message); setFeedback({ tone: 'error', message }); }
    } finally { setBusy(false); }
  }

  return <Card className="panel amc-retention" aria-label="Data retention and scheduled cleanup">
    <div className="amc-retention-head">
      <div>
        <h3>{scope === 'technical' ? 'Technical data retention' : 'Notification retention'}</h3>

      </div>
      <Button className="btn btn-secondary" type="button" disabled={busy} onClick={() => void load()}>Refresh</Button>
    </div>
    {error && <p className="amc-retention-error" role="alert">{error}</p>}
    <form onSubmit={e => void save(e)}>
      <div className="amc-retention-policies">
        {policies.map(policy => <section className="amc-retention-card" key={policy.category}>
          <Field className="amc-retention-toggle">
            <span><strong>{policy.label}</strong><small>{policy.eligible_count.toLocaleString()} currently eligible for removal</small></span>
            <Switch type="checkbox" checked={policy.enabled} disabled={busy}
              onChange={e => update(policy.category, { enabled: e.target.checked })} />
          </Field>
          <div className="amc-retention-fields">
            <Field>Keep records for (days)
              <Input type="number" min={policy.minimum_days} max={policy.category === 'rotated_logs' ? 365 : 3650} step={1} required disabled={busy || !policy.enabled}
                value={policy.retention_days} onChange={e => update(policy.category, { retention_days: Number(e.target.value) })}/>
            </Field>
            <Field>Run every (days)
              <Input type="number" min={1} max={30} step={1} required disabled={busy || !policy.enabled}
                value={policy.run_every_days} onChange={e => update(policy.category, { run_every_days: Number(e.target.value) })}/>
            </Field>
            <Field>Items per batch
              <Input type="number" min={25} max={1000} step={1} required disabled={busy || !policy.enabled}
                value={policy.batch_size} onChange={e => update(policy.category, { batch_size: Number(e.target.value) })}/>
            </Field>
            <Field>Max batches per run
              <Input type="number" min={1} max={20} step={1} required disabled={busy || !policy.enabled}
                value={policy.max_batches} onChange={e => update(policy.category, { max_batches: Number(e.target.value) })}/>
            </Field>
          </div>
          <p className="amc-retention-meta">Maximum per run: {(policy.batch_size * policy.max_batches).toLocaleString()} items
            {policy.last_run_at ? ` · Last run: ${new Date(policy.last_run_at).toLocaleString()} · Deleted: ${policy.last_deleted_count}` : ' · Not yet run'}</p>
        </section>)}
      </div>
      {policies.length > 0 && <div className="amc-retention-save">
        <Field>Confirm your current password
          <Input type="password" autoComplete="current-password" required value={password} disabled={busy}
            onChange={e => { setPassword(e.target.value); setPasswordError(''); }} />
          {passwordError && <span className="amc-retention-error" role="alert">{passwordError}</span>}
        </Field>
        <Button type="submit" className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save cleanup settings'}</Button>
      </div>}
    </form>
    <FeedbackToast feedback={feedback} onDismiss={() => setFeedback(null)} />
  </Card>;
}
