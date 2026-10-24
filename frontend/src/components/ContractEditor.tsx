import { useDashboardFeedback, useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Button, Checkbox, Input, Textarea } from './ui';
import SearchableDropdown from './SearchableDropdown';
import { CURRENCIES, currencyLabel, defaultCurrency } from '../utils/currency';
import { getInstallationCountry } from '../utils/installationTime';
import SortableTable from './SortableTable';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import SlidingDrawer from './SlidingDrawer';
import ContractCatalog, { type ContractOptions } from './ContractCatalog';

// Labels only: stored in the existing contract_type column, never a separate table.
export const CONTRACT_TYPES = ['Annual maintenance', 'Comprehensive', 'Labor only', 'Standard AMC', 'Premium', 'Standard', 'Basic'];
type Row = Record<string, any>;
export default function ContractEditor({ record, onClose, onSaved }: { record?: Row; onClose: () => void; onSaved: () => void }) {
  const feedback = useDashboardFeedback();
  const [form, setForm] = useState({ contract_number: record?.contract_number || '', customer_id: String(record?.customer?.id || ''), contract_type: record?.contract_type || '',
    start_date: String(record?.start_date || '').slice(0, 10), end_date: String(record?.end_date || '').slice(0, 10),
    sla_policy_id: String(record?.sla_policy?.id || ''), contract_value: record?.contract_value ?? '', currency: record ? record.currency || '' : defaultCurrency(getInstallationCountry()), notes: record?.notes || '', status: record?.status || 'active' });
  const [currencyChosen, setCurrencyChosen] = useState(false);
  useEffect(() => {
    if (record || currencyChosen) return;
    const updateDefault = () => setForm(current => ({...current, currency: defaultCurrency(getInstallationCountry())}));
    updateDefault();
    window.addEventListener('amc:timezone-updated', updateDefault);
    return () => window.removeEventListener('amc:timezone-updated', updateDefault);
  }, [record, currencyChosen]);
  const currencies = form.currency && !CURRENCIES.includes(form.currency) ? [form.currency, ...CURRENCIES] : CURRENCIES;
  const [selected, setSelected] = useState<number[]>((record?.services || []).map((s: Row) => s.id));
  const [options, setOptions] = useState<ContractOptions>({ policies: [], services: [] }), [customers, setCustomers] = useState<Row[]>([]);
  const [loading, setLoading] = useState(true), [busy, setBusy] = useState(false), [error, setError] = useFeedbackState('error');
  const [manage, setManage] = useState(false);
  async function reload() { const { data } = await client.get<ContractOptions>('/contract-options'); setOptions(data); }
  useEffect(() => {
    void Promise.all([reload(), client.get('/lookups').then(({ data }) => setCustomers(data.customers))])
      .catch(e => setError(getApiErrorMessage(e, 'Could not load contract options.'))).finally(() => setLoading(false));
  }, []);
  const set = (key: keyof typeof form, value: string) => setForm(current => ({ ...current, [key]: value }));
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (busy || loading) return;
    setBusy(true); setError('');
    try {
      const body = { ...form, customer_id: Number(form.customer_id), sla_policy_id: form.sla_policy_id ? Number(form.sla_policy_id) : null,
        service_ids: selected, contract_value: form.contract_value === '' ? null : Number(form.contract_value) };
      if (record) await client.patch(`/directory/contracts/${record.id}`, body); else await client.post('/directory/contracts', body);
      feedback?.({tone: 'success', message: record ? 'Contract updated.' : 'Contract created.'}); onSaved();
    } catch (e) { setError(getApiErrorMessage(e, 'Could not save the contract.')); }
    finally { setBusy(false); }
  }
  const types = record?.contract_type && !CONTRACT_TYPES.includes(record.contract_type) ? [record.contract_type, ...CONTRACT_TYPES] : CONTRACT_TYPES;
  return <SlidingDrawer title={record ? 'Edit contract' : 'Add contract'} onClose={onClose}><div className="drawer-body contract-editor">
    <Button type="button" className="btn btn-secondary" onClick={() => setManage(!manage)}>{manage ? 'Hide' : 'Manage'} SLA policies &amp; services</Button>
    {manage && <ContractCatalog onChanged={() => void reload().catch(e => setError(getApiErrorMessage(e)))}/>}
    {error && <p className="error" role="alert">{error}</p>}
    {loading ? <p role="status">Loading contract options…</p> : <form onSubmit={event => void save(event)}><fieldset disabled={busy}>
      <Field>Contract number<Input required maxLength={80} value={form.contract_number} onChange={e => set('contract_number', e.target.value)}/></Field>
      <Field>Customer<SearchableDropdown required value={form.customer_id} onChange={e => set('customer_id', e.target.value)}><option value="">Select customer</option>{customers.map(c => <option key={c.id} value={c.id}>{c.company_name}</option>)}</SearchableDropdown></Field>
      <Field>Contract type<SearchableDropdown required value={form.contract_type} onChange={e => set('contract_type', e.target.value)}><option value="">Select type</option>{types.map(type => <option key={type}>{type}</option>)}</SearchableDropdown></Field>
      <p>Type describes the agreement. The selected SLA policy sets service targets.</p>
      <Field>Start date<Input required type="date" value={form.start_date} onChange={e => set('start_date', e.target.value)}/></Field>
      <Field>Expiry date<Input required type="date" min={form.start_date || undefined} value={form.end_date} onChange={e => set('end_date', e.target.value)}/></Field>
      <Field>SLA policy<SearchableDropdown value={form.sla_policy_id} onChange={e => set('sla_policy_id', e.target.value)}><option value="">No contract-specific SLA policy</option>{options.policies.filter(p => p.is_active || String(p.id) === form.sla_policy_id).map(p => <option key={p.id} value={p.id}>{p.name}{!p.is_active ? ' (inactive)' : ''}</option>)}</SearchableDropdown></Field>
      {options.policies.filter(p => String(p.id) === form.sla_policy_id).map(p => <div className="table-scroll" key={p.id}><SortableTable><caption>{p.name} targets (minutes)</caption><thead><tr><th>Priority</th><th>Response</th><th>Resolution</th></tr></thead><tbody>{['critical', 'high', 'medium', 'low'].map(priority => <tr key={priority}><td>{priority}</td><td>{String(p[`${priority}_response_minutes`])}</td><td>{String(p[`${priority}_resolution_minutes`])}</td></tr>)}</tbody></SortableTable></div>)}
      <p>Without a contract policy, new tickets use the fallback SLA. Existing deadlines stay unchanged.</p>
      <fieldset className="contract-services"><legend>Covered services</legend>{options.services.filter(s => s.is_active || selected.includes(s.id)).map(s => <Field className="contract-check" key={s.id}><Checkbox type="checkbox" checked={selected.includes(s.id)} onChange={e => setSelected(ids => e.target.checked ? [...ids, s.id] : ids.filter(id => id !== s.id))}/>{s.name}{!s.is_active ? ' (inactive)' : ''}</Field>)}{!options.services.length && <p>Create services using Manage SLA policies &amp; services above.</p>}</fieldset>
      <Field>Contract value<Input type="number" min={0} max="999999999999.99" step="0.01" value={form.contract_value} onChange={e => set('contract_value', e.target.value)}/></Field>
      <Field>Currency<SearchableDropdown required value={form.currency} onChange={e => { setCurrencyChosen(true); set('currency', e.target.value); }}><option value="">Select currency</option>{currencies.map(code => <option key={code} value={code}>{currencyLabel(code)}</option>)}</SearchableDropdown></Field>
      <Field>Status<SearchableDropdown value={form.status} onChange={e => set('status', e.target.value)}>{['active', 'inactive', 'expired'].map(s => <option key={s}>{s}</option>)}</SearchableDropdown></Field>
      <Field>Notes<Textarea maxLength={10000} value={form.notes} onChange={e => set('notes', e.target.value)}/></Field>
      <div className="catalog-items"><Button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save contract'}</Button><Button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</Button></div>
    </fieldset></form>}
  </div></SlidingDrawer>;
}
