import { Field } from './ui';
import { Button, Card, Checkbox, Input } from './ui';
import SearchableDropdown from './SearchableDropdown';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import type { RecordItem } from '../data';

type Driver = 'smtp' | 'resend' | 'postmark';
type Provider = {
  id: number;
  name: string;
  driver: Driver;
  from_email: string;
  from_name: string;
  is_active: boolean;
  credential_configured: boolean;
};
type MailState = { providers: Provider[]; default_provider_id: number | null; notifications_enabled: boolean };
type ClientState = { customer_id: number; provider_id: number | null; notifications_enabled: boolean | null;
  effective_provider_id: number | null; effective_notifications_enabled: boolean };

/** Contractor Admin only. Secret values are never returned or displayed after saving. */
export default function EmailDeliverySettings({ customers }: { customers: RecordItem[] }) {
  const [data, setData] = useState<MailState | null>(null);
  const [name, setName] = useState('');
  const [driver, setDriver] = useState<Driver>('resend');
  const [fromEmail, setFromEmail] = useState('');
  const [fromName, setFromName] = useState('');
  const [host, setHost] = useState('');
  const [port, setPort] = useState('587');
  const [encryption, setEncryption] = useState('tls');
  const [username, setUsername] = useState('');
  const [secret, setSecret] = useState('');
  const [password, setPassword] = useState('');
  const [editingKeyId, setEditingKeyId] = useState<number | null>(null);
  const [replacementKey, setReplacementKey] = useState('');
  const [customerId, setCustomerId] = useState('');
  const [clientRoute, setClientRoute] = useState<ClientState | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  async function reload() {
    const response = await client.get<{ data: MailState }>('/admin/mail/providers');
    setData(response.data.data);
  }
  useEffect(() => { void reload().catch(e => setError(getApiErrorMessage(e))); }, []);
  useEffect(() => {
    setClientRoute(null);
    if (!customerId) return;
    client.get<{ data: ClientState }>(`/admin/mail/clients/${customerId}`)
      .then(({ data: response }) => setClientRoute(response.data))
      .catch(e => setError(getApiErrorMessage(e)));
  }, [customerId]);

  async function run(task: () => Promise<void>) {
    setBusy(true); setError(''); setNotice('');
    try { await task(); } catch (e) { setError(getApiErrorMessage(e)); } finally { setBusy(false); }
  }
  function requirePassword(): boolean {
    if (password) return true;
    setError('Enter your current Contractor Admin password to save changes.');
    return false;
  }
  function createProvider() {
    if (!requirePassword()) return;
    void run(async () => {
      const credentials = driver === 'smtp'
        ? { host, port: Number(port), encryption, username, password: secret }
        : { api_key: secret };
      await client.post('/admin/mail/providers', {
        name, driver, from_email: fromEmail, from_name: fromName, credentials,
        current_password: password,
      });
      setName(''); setSecret(''); setHost(''); setUsername('');
      await reload(); setNotice('Email provider created. Send a test before enabling delivery.');
    });
  }
  function setDefault(providerId: number | null, enabled: boolean) {
    if (!requirePassword()) return;
    void run(async () => {
      await client.put('/admin/mail/default', {
        default_provider_id: providerId, notifications_enabled: enabled,
        current_password: password,
      });
      await reload(); setNotice('Installation email settings saved.');
    });
  }
  function toggleProvider(provider: Provider) {
    if (!requirePassword()) return;
    void run(async () => {
      await client.patch(`/admin/mail/providers/${provider.id}`, {
        is_active: !provider.is_active, current_password: password,
      });
      await reload(); setNotice('Provider availability updated.');
    });
  }
  function rotateSecret(provider: Provider) {
    if (!requirePassword() || !replacementKey) return;
    void run(async () => {
      await client.patch(`/admin/mail/providers/${provider.id}`, {
        credentials: provider.driver === 'smtp'
          ? { password: replacementKey }
          : { api_key: replacementKey },
        current_password: password,
      });
      setEditingKeyId(null); setReplacementKey('');
      await reload(); setNotice('Provider credentials updated. Run a new delivery test.');
    });
  }
  function testProvider(providerId: number) {
    if (!requirePassword()) return;
    void run(async () => {
      await client.post(`/admin/mail/providers/${providerId}/test`, { current_password: password });
      setNotice('The provider accepted the test request. Confirm arrival in your own email inbox.');
    });
  }
  function saveClient() {
    if (!requirePassword() || !clientRoute) return;
    void run(async () => {
      const response = await client.put<{ data: ClientState }>(`/admin/mail/clients/${customerId}`, {
        provider_id: clientRoute.provider_id,
        notifications_enabled: clientRoute.notifications_enabled,
        current_password: password,
      });
      setClientRoute(response.data.data); setNotice('Client email route saved.');
    });
  }

  const providers = data?.providers ?? [];
  return <Card className="panel amc-mail-settings">
    <h3>Email delivery providers</h3>

    {error && <p className="error" role="alert">{error}</p>}
    {notice && <p className="mfa-notice" role="status">{notice}</p>}
    {!data ? <p>Loading email settings…</p> : <>
      <div className="mail-section">
        <h4>Installation default</h4>
        <div className="mail-form-grid">
          <Field>Default email provider
            <SearchableDropdown value={data.default_provider_id ?? ''} onChange={e => setData(previous => previous && ({ ...previous, default_provider_id: e.target.value ? Number(e.target.value) : null }))}>
              <option value="">Not configured</option>
              {providers.filter(p => p.is_active).map(p => <option key={p.id} value={p.id}>{p.name} ({p.driver})</option>)}
            </SearchableDropdown>
          </Field>
          <Field className="mfa-switch"><span><b>Ticket and AMC email notifications</b></span>
            <Checkbox type="checkbox" checked={data.notifications_enabled} onChange={e => setData(previous => previous && ({ ...previous, notifications_enabled: e.target.checked }))}/>
          </Field>
        </div>
        <Button type="button" className="btn btn-primary" disabled={busy} onClick={() => setDefault(data.default_provider_id, data.notifications_enabled)}>Save email defaults</Button>
      </div>
      <div className="mail-section">
        <h4>Configured providers</h4>
        {providers.length === 0 ? <p>No email providers configured. Add one below to enable outgoing mail.</p>
          : <div className="mail-providers">{providers.map(provider => <div className="mail-provider-row" key={provider.id}>
            <div><b>{provider.name}</b><small>{provider.driver.toUpperCase()} · {provider.from_email} · {provider.is_active ? 'Active' : 'Disabled'}</small></div>
            <div className="mail-provider-actions">
              <Button type="button" className="btn btn-secondary" disabled={busy || !provider.is_active} onClick={() => testProvider(provider.id)}>Test</Button>
              <Button type="button" className="btn btn-secondary" disabled={busy} onClick={() => { setEditingKeyId(provider.id); setReplacementKey(''); }}>Replace secret</Button>
              <Button type="button" className="btn btn-secondary" disabled={busy} onClick={() => toggleProvider(provider)}>{provider.is_active ? 'Disable' : 'Enable'}</Button>
            </div>
            {editingKeyId === provider.id && <div className="mail-replace-secret">
              <Field>New {provider.driver === 'smtp' ? 'SMTP password' : 'API key'}
                <Input type="password" autoComplete="off" value={replacementKey} onChange={e => setReplacementKey(e.target.value)}/>
              </Field>
              <Button type="button" className="btn btn-primary" disabled={!replacementKey || busy} onClick={() => rotateSecret(provider)}>Save new secret</Button>
              <Button type="button" className="btn btn-secondary" onClick={() => { setEditingKeyId(null); setReplacementKey(''); }}>Cancel</Button>
            </div>}
          </div>)}</div>}
      </div>
      <div className="mail-section">
        <h4>Add a provider</h4>
        <div className="mail-form-grid">
          <Field>Provider name<Input value={name} maxLength={120} onChange={e => setName(e.target.value)}/></Field>
          <Field>Transport<SearchableDropdown value={driver} onChange={e => { setDriver(e.target.value as Driver); setSecret(''); }}>
            <option value="resend">Resend API</option><option value="postmark">Postmark API</option><option value="smtp">SMTP</option>
          </SearchableDropdown></Field>
          <Field>From email<Input type="email" value={fromEmail} onChange={e => setFromEmail(e.target.value)}/></Field>
          <Field>From display name<Input value={fromName} onChange={e => setFromName(e.target.value)}/></Field>
          {driver === 'smtp' && <>
            <Field>SMTP hostname<Input value={host} onChange={e => setHost(e.target.value)}/></Field>
            <Field>SMTP port<Input type="number" min={1} max={65535} value={port} onChange={e => setPort(e.target.value)}/></Field>
            <Field>Encryption<SearchableDropdown value={encryption} onChange={e => setEncryption(e.target.value)}><option value="tls">STARTTLS</option><option value="ssl">TLS (implicit)</option></SearchableDropdown></Field>
            <Field>SMTP username<Input value={username} onChange={e => setUsername(e.target.value)}/></Field>
          </>}
          <Field>{driver === 'smtp' ? 'SMTP password' : 'Provider API key'}
            <Input type="password" autoComplete="off" value={secret} onChange={e => setSecret(e.target.value)}/></Field>
        </div>
        <Button type="button" className="btn btn-primary" disabled={busy || !name || !fromName || !fromEmail || !secret} onClick={createProvider}>Add provider</Button>
      </div>
      <div className="mail-section">
        <h4>Client-specific email routing</h4>

        <Field>Client organization<SearchableDropdown value={customerId} onChange={e => setCustomerId(e.target.value)}>
          <option value="">Select a client</option>
          {customers.map(c => <option key={c.id} value={c.id}>{String(c.company_name || c.name || `Client ${c.id}`)}</option>)}
        </SearchableDropdown></Field>
        {clientRoute && <div className="mail-form-grid">
          <Field>Delivery provider<SearchableDropdown value={clientRoute.provider_id ?? ''} onChange={e => setClientRoute(r => r && ({ ...r, provider_id: e.target.value ? Number(e.target.value) : null }))}>
            <option value="">Use installation default</option>
            {providers.filter(p => p.is_active).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
          </SearchableDropdown></Field>
          <Field>Email notifications<SearchableDropdown value={clientRoute.notifications_enabled === null ? 'inherit' : clientRoute.notifications_enabled ? 'yes' : 'no'}
            onChange={e => setClientRoute(r => r && ({ ...r, notifications_enabled: e.target.value === 'inherit' ? null : e.target.value === 'yes' }))}>
            <option value="inherit">Inherit installation default</option>
            <option value="yes">Enabled for this client</option>
            <option value="no">Disabled for this client</option>
          </SearchableDropdown></Field>
          <Button type="button" className="btn btn-primary" disabled={busy} onClick={saveClient}>Save client routing</Button>
        </div>}
      </div>
      <div className="mail-section">
        <Field>Confirm changes with your current password
          <Input type="password" autoComplete="current-password" value={password} onChange={e => setPassword(e.target.value)}/>
        </Field>

      </div>
    </>}
  </Card>;
}
