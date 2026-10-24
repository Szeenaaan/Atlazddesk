import { useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Button, Checkbox, Input, Textarea } from './ui';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';

export type Policy = { id: number; name: string; is_active: boolean; [key: string]: string | number | boolean };
export type Service = { id: number; name: string; description?: string; is_active: boolean };
export type ContractOptions = { policies: Policy[]; services: Service[] };
const priorities = ['critical', 'high', 'medium', 'low'];
const blankPolicy = () => Object.fromEntries([
  ['name', ''], ['is_active', true], ...priorities.flatMap(p => [[`${p}_response_minutes`, ''], [`${p}_resolution_minutes`, '']]),
]) as Record<string, string | number | boolean>;

export default function ContractCatalog({ onChanged }: { onChanged?: () => void }) {
  const [options, setOptions] = useState<ContractOptions>({ policies: [], services: [] });
  const [policy, setPolicy] = useState(blankPolicy);
  const [service, setService] = useState({ name: '', description: '', is_active: true });
  const [policyId, setPolicyId] = useState<number | null>(null), [serviceId, setServiceId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false), [error, setError] = useFeedbackState('error'), [, setNotice] = useFeedbackState('success');
  async function reload() { const { data } = await client.get<ContractOptions>('/contract-options'); setOptions(data); }
  useEffect(() => { void reload().catch(e => setError(getApiErrorMessage(e, 'Could not load policies and services.'))); }, []);
  async function save(kind: 'policy' | 'service', event: React.FormEvent) {
    event.preventDefault(); if (busy) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const id = kind === 'policy' ? policyId : serviceId;
      const endpoint = kind === 'policy' ? '/sla-policies' : '/contract-services';
      const body = kind === 'policy' ? { ...policy } : { ...service };
      delete (body as Record<string, unknown>).id;
      if (kind === 'policy') for (const p of priorities) for (const field of ['response', 'resolution']) {
        const key = `${p}_${field}_minutes`; (body as Record<string, unknown>)[key] = Number(policy[key]);
      }
      if (id) await client.patch(`${endpoint}/${id}`, body); else await client.post(endpoint, body);
      await reload(); onChanged?.();
      if (kind === 'policy') { setPolicy(blankPolicy()); setPolicyId(null); }
      else { setService({ name: '', description: '', is_active: true }); setServiceId(null); }
      setNotice(kind === 'policy' ? 'SLA policy saved.' : 'Service saved.');
    } catch (e) { setError(getApiErrorMessage(e, 'Could not save the catalog.')); }
    finally { setBusy(false); }
  }
  return <details className="panel contract-catalog"><summary>Manage SLA policies &amp; covered services</summary>
    {error && <p className="error" role="alert">{error}</p>}
    <section><h3>SLA policies</h3><p>Set response and resolution targets in minutes. Updates affect future ticket calculations; existing ticket deadlines are not recalculated.</p>
      <div className="catalog-items">{options.policies.map(p => <Button type="button" className="btn btn-secondary" disabled={busy} key={p.id} onClick={() => { setPolicy({ ...p }); setPolicyId(p.id); }}>{p.name}{!p.is_active ? ' (inactive)' : ''}</Button>)}</div>
      <form onSubmit={e => void save('policy', e)}><fieldset disabled={busy}>
        <legend>{policyId ? 'Edit SLA policy' : 'Create SLA policy'}</legend>
        <Field>Policy name<Input required maxLength={255} value={String(policy.name)} onChange={e => setPolicy({ ...policy, name: e.target.value })}/></Field>
        {priorities.map(p => <div className="sla-target-row" key={p}><b>{p[0].toUpperCase() + p.slice(1)}</b>{['response', 'resolution'].map(field => <Field key={field}>{field === 'response' ? 'Response' : 'Resolution'} (minutes)<Input type="number" min={1} max={525600} step={1} required value={String(policy[`${p}_${field}_minutes`])} onChange={e => setPolicy({ ...policy, [`${p}_${field}_minutes`]: e.target.value })}/></Field>)}</div>)}
        <Field className="contract-check"><Checkbox type="checkbox" checked={Boolean(policy.is_active)} onChange={e => setPolicy({ ...policy, is_active: e.target.checked })}/>Available for selection</Field>
        <Button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save SLA policy'}</Button>
        {policyId && <Button type="button" className="btn btn-secondary" onClick={() => { setPolicyId(null); setPolicy(blankPolicy()); }}>Cancel edit</Button>}
      </fieldset></form>
    </section>
    <section><h3>Covered service catalog</h3><div className="catalog-items">{options.services.map(s => <Button type="button" className="btn btn-secondary" disabled={busy} key={s.id} onClick={() => { setService({ name: s.name, description: s.description || '', is_active: s.is_active }); setServiceId(s.id); }}>{s.name}{!s.is_active ? ' (inactive)' : ''}</Button>)}</div>
      <form onSubmit={e => void save('service', e)}><fieldset disabled={busy}><legend>{serviceId ? 'Edit service' : 'Create service'}</legend>
        <Field>Service name<Input required maxLength={255} value={service.name} onChange={e => setService({ ...service, name: e.target.value })}/></Field>
        <Field>Description<Textarea maxLength={5000} value={service.description} onChange={e => setService({ ...service, description: e.target.value })}/></Field>
        <Field className="contract-check"><Checkbox type="checkbox" checked={service.is_active} onChange={e => setService({ ...service, is_active: e.target.checked })}/>Available for selection</Field>
        <Button className="btn btn-primary" disabled={busy}>Save service</Button>
        {serviceId && <Button type="button" className="btn btn-secondary" onClick={() => { setServiceId(null); setService({ name: '', description: '', is_active: true }); }}>Cancel edit</Button>}
      </fieldset></form>
    </section>
  </details>;
}
