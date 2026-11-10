import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getWorkforceErrorMessage, type Designation } from '../api/escalations';
import { Field, InlineNotice, RefreshButton, UIState } from './ui';
import SearchableDropdown from './SearchableDropdown';
import { useFeedbackState } from './feedbackContext';

type Props = { designationId: string; onDesignation: (value: string) => void; workforceType?: string;
  onWorkforce?: (value: string) => void; disabled?: boolean; designations?: Designation[] | null };
export default function TechnicianFields({ designationId, onDesignation, workforceType, onWorkforce, disabled, designations }: Props) {
  const [options, setOptions] = useState<Designation[]>([]);
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  const [error, setError] = useFeedbackState('error');
  useEffect(() => {
    if (designations !== undefined) return;
    const controller = new AbortController();
    setLoading(true); setError('');
    client.get<{ data: Designation[] }>('/designations', { signal: controller.signal }).then(({ data }) => setOptions(data.data))
      .catch(e => { if (!controller.signal.aborted) setError(getWorkforceErrorMessage(e, 'Designations could not be loaded.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [revision, designations]);
  const choices = designations ?? options;
  const pending = designations === undefined ? loading : designations === null;
  const active = choices.filter(option => option.is_active);
  return <>
    <Field>Designation<SearchableDropdown required disabled={disabled || pending || !!error} value={designationId} onChange={e => onDesignation(e.target.value)}>
      <option value="">Select designation</option>
      {choices.filter(option => option.is_active || String(option.id) === designationId).map(option =>
        <option key={option.id} value={option.id} disabled={!option.is_active}>{option.name} · Level {option.level}{!option.is_active ? ' (inactive — select another)' : ''}</option>)}
    </SearchableDropdown></Field>
    {pending && <UIState tone="loading">Loading designations…</UIState>}
    {error && <InlineNotice tone="error">{error} <RefreshButton label="Retry loading designations" onClick={() => setRevision(value => value + 1)}/></InlineNotice>}
    {!pending && !error && active.length === 0 && <InlineNotice>Create an active designation in Technicians → Manage designations before saving a technician.</InlineNotice>}
    {onWorkforce && <Field>Workforce classification<SearchableDropdown required disabled={disabled} value={workforceType || ''} onChange={e => onWorkforce(e.target.value)}>
      <option value="">Select classification</option><option value="employee">Employee</option><option value="freelancer">Freelancer</option>
    </SearchableDropdown></Field>}
  </>;
}
