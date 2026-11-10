import { useEffect, useState, type FormEvent } from 'react';
import { client } from '../api/client';
import { getWorkforceErrorMessage } from '../api/escalations';
import { Button, CloseButton, Field, InlineNotice, Input, RefreshButton, UIState } from './ui';
import { Dialog } from './ui/Dialog';
import SearchableDropdown from './SearchableDropdown';
import { useFeedbackState } from './feedbackContext';

type Capacity = { default_limit: number | null; override_limit: number | null; effective_limit: number | null; active_tickets: number };

export default function TechnicianAssignmentLimitSettings({ technician, onClose, onSaved }: {
  technician?: { id: number; name: string }; onClose: () => void; onSaved?: () => void;
}) {
  const [capacity, setCapacity] = useState<Capacity | null>(null);
  const [custom, setCustom] = useState(false);
  const [limit, setLimit] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [revision, setRevision] = useState(0);
  const [error, setError] = useFeedbackState('error');
  const [, setMessage] = useFeedbackState('success');
  const url = technician ? `/technician-assignment-limits/${technician.id}` : '/technician-assignment-limits';
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    client.get<{ data: Capacity }>(url, { signal: controller.signal }).then(({ data }) => {
      if (controller.signal.aborted) return;
      setCapacity(data.data);
      const current = technician ? data.data.override_limit : data.data.default_limit;
      setCustom(current !== null);
      setLimit(current === null ? '' : String(current));
    }).catch(failure => { if (!controller.signal.aborted) setError(getWorkforceErrorMessage(failure, 'Assignment limits could not be loaded.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [url, technician, revision]);
  const valid = !custom || /^\d+$/.test(limit) && Number(limit) >= 1 && Number(limit) <= 10000;
  async function save(event: FormEvent) {
    event.preventDefault(); if (busy || loading || !valid) return;
    setBusy(true); setError('');
    try {
      await client.put(url, { limit: custom ? Number(limit) : null });
      setMessage(technician ? `${technician.name}'s assignment limit saved.` : 'Default technician assignment limit saved.');
      onSaved?.(); onClose();
    } catch (failure) { setError(getWorkforceErrorMessage(failure, 'Assignment limit could not be saved.')); }
    finally { setBusy(false); }
  }
  return <Dialog label={technician ? `Assignment limit for ${technician.name}` : 'Technician assignment limits'} onClose={() => { if (!busy) onClose(); }}>
    <form onSubmit={save}>
      <div className="drawer-top"><h2>{technician ? 'Technician assignment limit' : 'Technician assignment limits'}</h2>
        <CloseButton disabled={busy} onClick={onClose} aria-label="Close assignment limits"/></div>
      <div className="ui-dialog-body"><div className="support-form">
        {loading ? <UIState tone="loading">Loading assignment limits…</UIState> : capacity && <>
          {technician && <p>{technician.name} · {capacity.active_tickets} active tickets · Default: {capacity.default_limit ?? 'Unlimited'}</p>}
          <Field>{technician ? 'Technician limit' : 'Default limit'}<SearchableDropdown value={custom ? 'custom' : 'default'} disabled={busy}
            onChange={event => setCustom(event.target.value === 'custom')}>
            <option value="default">{technician ? 'Use default limit' : 'Unlimited'}</option>
            <option value="custom">Set a limit</option>
          </SearchableDropdown></Field>
          {custom && <Field>Maximum active tickets<Input type="number" required min={1} max={10000} step={1} value={limit} disabled={busy}
            onChange={event => setLimit(event.target.value)}/></Field>}
        </>}
        {error && <InlineNotice tone="error">{error} {!loading && <RefreshButton label="Retry loading assignment limits" onClick={() => setRevision(value => value + 1)}/>}</InlineNotice>}
      </div><div className="ui-actions"><Button type="button" disabled={busy} onClick={onClose}>Cancel</Button>
        <Button type="submit" variant="primary" disabled={busy || loading || !capacity || !valid}>{busy ? 'Saving…' : 'Save limit'}</Button></div></div>
    </form>
  </Dialog>;
}
