import { useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Button, Card, Checkbox, InlineNotice, Input, UIState } from './ui';
import EmailProviderFields, { type EmailProviderValues } from './EmailProviderFields';
import SearchableDropdown from './SearchableDropdown';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import type { RecordItem } from '../data';
import FeedbackToast from './FeedbackToast';
import { ConfirmationDialog } from './ui/Dialog';

type Driver = 'smtp' | 'resend' | 'postmark';
type Provider = {
  id: number;
  name: string;
  driver: Driver;
  from_email: string;
  from_name: string;
  is_active: boolean;
  credential_configured: boolean;
  smtp?: {host?: string; port?: number; encryption?: string; username?: string} | null;
};
type MailState = { providers: Provider[]; default_provider_id: number | null; notifications_enabled: boolean; notification_email?: string | null;
  last_ticket_delivery?: {status: string; attempts: number; sent_at: string | null; failure_category: string | null} | null };
type ClientState = { customer_id: number; provider_id: number | null; notifications_enabled: boolean | null;
  effective_provider_id: number | null; effective_notifications_enabled: boolean };

/** Contractor Admin only. Secret values are never returned or displayed after saving. */
export default function EmailDeliverySettings({ customers }: { customers: RecordItem[] }) {
  const [data, setData] = useState<MailState | null>(null);
  const [savedDefaultId, setSavedDefaultId] = useState<number | null>(null);
  const [name, setName] = useState('');
  const [driver, setDriver] = useState<Driver>('smtp');
  const [fromEmail, setFromEmail] = useState('');
  const [fromName, setFromName] = useState('');
  const [host, setHost] = useState('');
  const [port, setPort] = useState('587');
  const [encryption, setEncryption] = useState('tls');
  const [username, setUsername] = useState('');
  const [secret, setSecret] = useState('');
  const [password, setPassword] = useState('');
  const [editingProvider, setEditingProvider] = useState<Provider | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Provider | null>(null);
  const [customerId, setCustomerId] = useState('');
  const [clientRoute, setClientRoute] = useState<ClientState | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useFeedbackState('error');
  const [notice, setNotice] = useFeedbackState('success');
  const [loading, setLoading] = useState(true);

  async function reload() {
    const response = await client.get<{ data: MailState }>('/admin/mail/providers');
    setData(response.data.data);
    setSavedDefaultId(response.data.data.default_provider_id);
  }
  async function loadSettings() {
    setLoading(true); setError('');
    try { await reload(); } catch (e) { setError(getApiErrorMessage(e)); }
    finally { setLoading(false); }
  }
  useEffect(() => { void loadSettings(); }, []);
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
      const payload = {
        name, driver, from_email: fromEmail, from_name: fromName, credentials,
        current_password: password,
      };
      if (editingProvider) await client.patch(`/admin/mail/providers/${editingProvider.id}`, payload);
      else await client.post('/admin/mail/providers', payload);
      resetProvider();
      await reload(); setNotice(driver === 'smtp' ? 'SMTP credentials verified and settings saved. Send a test email to confirm delivery.' : 'Email provider saved. Send a test to confirm delivery.');
    });
  }
  function setDefault(providerId: number | null, enabled: boolean) {
    if (!requirePassword()) return;
    void run(async () => {
      await client.put('/admin/mail/default', {
        default_provider_id: providerId, notifications_enabled: enabled,
        notification_email: data?.notification_email?.trim() || null,
        current_password: password,
      });
      await reload(); setNotice('Default email settings saved.');
    });
  }
  function toggleProvider(provider: Provider) {
    if (!requirePassword()) return;
    void run(async () => {
      await client.patch(`/admin/mail/providers/${provider.id}`, {
        is_active: !provider.is_active, current_password: password,
      });
      await reload(); setNotice('Email provider status updated.');
    });
  }
  function deleteProvider() {
    if (!deleteTarget || !requirePassword()) return;
    const provider = deleteTarget;
    void run(async () => {
      await client.delete(`/admin/mail/providers/${provider.id}`, {data: {current_password: password}});
      if (editingProvider?.id === provider.id) resetProvider();
      setDeleteTarget(null); setPassword('');
      await reload(); setNotice('Email provider deleted.');
    });
  }
  function resetProvider() {
    setEditingProvider(null); setName(''); setDriver('smtp'); setFromEmail(''); setFromName('');
    setHost(''); setPort('587'); setEncryption('tls'); setUsername(''); setSecret('');
  }
  function editProvider(provider: Provider) {
    setEditingProvider(provider); setName(provider.name); setDriver(provider.driver);
    setFromEmail(provider.from_email); setFromName(provider.from_name); setSecret('');
    setHost(provider.smtp?.host || ''); setPort(String(provider.smtp?.port || 587));
    setEncryption(provider.smtp?.encryption || 'tls'); setUsername(provider.smtp?.username || '');
  }
  function testProvider(providerId: number) {
    if (!requirePassword()) return;
    void run(async () => {
      await client.post(`/admin/mail/providers/${providerId}/test`, { current_password: password });
      setNotice('Check your email for the test message. It may take a moment to arrive.');
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
      setClientRoute(response.data.data); setNotice('Customer email settings saved.');
    });
  }

  const providers = data?.providers ?? [];
  return <Card className="panel amc-mail-settings email-settings">
    <h3>Business email delivery</h3>

    {error && <InlineNotice tone="error">{error}</InlineNotice>}
    <FeedbackToast feedback={notice ? {tone: 'success', message: notice} : null} onDismiss={() => setNotice('')}/>
    {!data ? loading ? <UIState tone="loading">Loading email settings…</UIState> : <Button type="button" onClick={() => void loadSettings()}>Retry</Button> : <>
      <div className="mail-section">
        <h4>Default sender and notification inbox</h4>
        {!savedDefaultId && providers.some(provider => provider.is_active) && <InlineNotice tone="info">A provider has been added, but no default sender is selected. Choose it below and save email defaults before enabling Email OTP. Sending a test email does not select the default sender.</InlineNotice>}
        <div className="mail-form-grid">
          <Field>Default email provider
            <SearchableDropdown value={data.default_provider_id ?? ''} onChange={e => setData(previous => previous && ({ ...previous, default_provider_id: e.target.value ? Number(e.target.value) : null }))}>
              <option value="">Not configured</option>
              {providers.filter(p => p.is_active).map(p => <option key={p.id} value={p.id}>{p.name} ({p.driver})</option>)}
            </SearchableDropdown>
          </Field>
          <Field>Shared ticket notification inbox<Input type="email" maxLength={255} disabled={busy} value={data.notification_email || ''} onChange={e => setData(previous => previous && ({...previous, notification_email: e.target.value}))}/></Field>
          <Field className="mail-notification-choice"><span>Ticket email notifications</span>
            <Checkbox type="checkbox" disabled={busy} checked={data.notifications_enabled} onChange={e => setData(previous => previous && ({ ...previous, notifications_enabled: e.target.checked }))}/>
          </Field>
        </div>
        <p>OTPs go only to each user’s registered email. Ticket updates, including internal staff events, go to the shared inbox.</p>
        <Button type="button" className="btn btn-primary" disabled={busy} onClick={() => setDefault(data.default_provider_id, data.notifications_enabled)}>Save email defaults</Button>
        {data.last_ticket_delivery && <p role="status">Last ticket email: {data.last_ticket_delivery.status === 'sent' ? 'Accepted by provider' : data.last_ticket_delivery.status === 'sending' ? 'Sending or awaiting confirmation' : data.last_ticket_delivery.status === 'uncertain' ? 'Delivery unconfirmed; check the inbox before retrying' : data.last_ticket_delivery.status === 'failed' ? 'Delivery failed. Check your email settings or contact your server administrator' : data.last_ticket_delivery.status}.</p>}
      </div>
      <div className="mail-section">
        <h4>Configured providers</h4>
        {providers.length === 0 ? <p>No email providers configured. Add one below to enable outgoing mail.</p>
          : <div className="mail-providers">{providers.map(provider => <div className="mail-provider-row" key={provider.id}>
            <div><b>{provider.name}</b><small>{provider.driver.toUpperCase()} · {provider.from_email} · {provider.is_active ? 'Active' : 'Disabled'}{savedDefaultId === provider.id ? ' · Default sender' : ''}</small></div>
            <div className="mail-provider-actions">
              <Button type="button" className="btn btn-secondary" disabled={busy || !provider.is_active} onClick={() => testProvider(provider.id)}>Test</Button>
              {provider.is_active && savedDefaultId !== provider.id && <Button type="button" disabled={busy} onClick={() => setDefault(provider.id, data.notifications_enabled)}>Use as default</Button>}
              <Button type="button" className="btn btn-secondary" disabled={busy} onClick={() => editProvider(provider)}>Edit provider</Button>
              <Button type="button" className="btn btn-secondary" disabled={busy} onClick={() => toggleProvider(provider)}>{provider.is_active ? 'Disable' : 'Enable'}</Button>
              <Button type="button" variant="danger" disabled={busy} onClick={() => setDeleteTarget(provider)}>Delete provider</Button>
            </div>
          </div>)}</div>}
      </div>
      <div className="mail-section">
        <h4>{editingProvider ? 'Edit provider' : 'Add a provider'}</h4>
        {driver === 'smtp' && <p>Your SMTP connection and credentials are verified before changes are saved. This check does not send an email.</p>}
        <EmailProviderFields disabled={busy} lockDriver={!!editingProvider} values={{driver, from_name: fromName, from_email: fromEmail, host, port, encryption, username, secret}}
          onChange={(field: keyof EmailProviderValues, value: string) => {
            if (field === 'driver') { setDriver(value as Driver); setSecret(''); }
            else ({from_name: setFromName, from_email: setFromEmail, host: setHost, port: setPort, encryption: setEncryption, username: setUsername, secret: setSecret})[field](value);
          }}>
          <Field>Provider name<Input required disabled={busy} value={name} maxLength={120} onChange={e => setName(e.target.value)}/></Field>
        </EmailProviderFields>
        {editingProvider && <p>Saved secret is hidden. Leave the password or API key blank to keep it.</p>}
        <div className="mail-provider-actions"><Button type="button" className="btn btn-primary" disabled={busy || !name || !fromName || !fromEmail || (!editingProvider && !secret)} onClick={createProvider}>{editingProvider ? 'Save provider' : 'Add provider'}</Button>{editingProvider && <Button type="button" disabled={busy} onClick={resetProvider}>Cancel editing</Button>}</div>
      </div>
      <div className="mail-section">
        <h4>Customer-specific senders (optional)</h4>
        <p>Leave these settings unchanged to use the default sender. Choose another provider only when a customer needs a different sender for contract reminders. Sign-in codes use the default sender when selected; ticket emails always use the shared notification inbox.</p>

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
    {deleteTarget && <ConfirmationDialog title="Delete email provider?" confirmLabel="Delete provider" busy={busy}
      onConfirm={deleteProvider} onClose={() => {if (!busy) setDeleteTarget(null);}}>
      <p>Delete {deleteTarget.name} and its saved credentials? Providers used for default or customer email delivery must be replaced first.</p>
      <Field>Current Contractor Admin password<Input type="password" autoComplete="current-password" required disabled={busy} value={password} onChange={event => setPassword(event.target.value)}/></Field>
    </ConfirmationDialog>}
  </Card>;
}
