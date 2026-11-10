import { useFeedbackState } from './feedbackContext';
import { useEffect, useId, useState } from 'react';
import { client } from '../api/client';
import SearchableSelect from './SearchableSelect';
import { Field } from './ui';

type Asset = { id: number; name: string; asset_tag: string; category?: { name: string }; assignment_mode: 'shared' | 'individual' | 'common'; assigned_users_count?: number };
export default function UserAssetPicker({ customerId, value, onChange, disabled = false, label = 'Assigned assets' }: { label?: string; customerId: number | null; value: number[]; onChange: (ids: number[]) => void; disabled?: boolean }) {
  const inputId = useId();
  const [assets, setAssets] = useState<Asset[]>([]), [loading, setLoading] = useState(false), [error, setError] = useFeedbackState('error');
  useEffect(() => {
    let cancelled = false;
    setAssets([]); setError('');
    if (!customerId) return;
    setLoading(true);
    void (async () => {
      try {
        const rows: Asset[] = []; let page = 1, last = 1;
        do {
          const { data } = await client.get('/directory/assets', { params: { customer_id: customerId, per_page: 100, page } });
          rows.push(...data.data); last = data.last_page; page++;
        } while (!cancelled && page <= last);
        if (!cancelled) setAssets(rows);
      } catch { if (!cancelled) setError('Unable to load assets. Close and retry before changing assignments.'); }
      finally { if (!cancelled) setLoading(false); }
    })();
    return () => { cancelled = true; };
  }, [customerId]);
  const options = assets.filter(asset => asset.assignment_mode !== 'common').map(asset => ({
    value: String(asset.id),
    label: asset.name,
    description: [asset.asset_tag?.trim() && asset.asset_tag.trim() !== asset.name.trim() ? asset.asset_tag.trim() : '', asset.category?.name, asset.assignment_mode === 'individual' ? 'Individual' : 'Shared'].filter(Boolean).join(' · '),
    disabled: asset.assignment_mode === 'individual' && !!asset.assigned_users_count && !value.includes(asset.id),
  }));
  return <Field htmlFor={inputId}>{label}
    <SearchableSelect multiple inputId={inputId} ariaLabel={label} disabled={disabled || loading || !customerId || !!error}
      placeholder={loading ? 'Loading assets…' : !customerId ? 'Select a client' : 'Select assets'}
      value={value.map(String)} options={options} onChange={ids => onChange(ids.map(Number))}/>
    {error && <span role="alert" className="amc-field-error">{error}</span>}
  </Field>;
}
