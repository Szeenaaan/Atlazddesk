import { useEffect, useState } from 'react';
import { client } from '../api/client';

type Asset = { id: number; name: string; asset_tag: string };
export default function UserAssetPicker({ customerId, value, onChange, disabled = false }: { customerId: number | null; value: number[]; onChange: (ids: number[]) => void; disabled?: boolean }) {
  const [assets, setAssets] = useState<Asset[]>([]), [search, setSearch] = useState(''), [loading, setLoading] = useState(false), [error, setError] = useState('');
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
  const matches = assets.filter(a => `${a.name} ${a.asset_tag}`.toLowerCase().includes(search.toLowerCase()));
  return <fieldset className="user-asset-picker" disabled={disabled || loading || !customerId || !!error}>
    <legend>Assigned assets (optional)</legend>
    {error && <p role="alert" className="error">{error}</p>}
    {!customerId ? <p>Select a client company first.</p> : loading ? <p>Loading assets…</p> : <>
      <input aria-label="Search assets to assign" type="search" placeholder="Search asset name or tag…" value={search} onChange={e => setSearch(e.target.value)}/>
      <div className="user-asset-options">{matches.map(a => <label key={a.id}><input type="checkbox" checked={value.includes(a.id)} onChange={e => onChange(e.target.checked ? [...value, a.id] : value.filter(id => id !== a.id))}/><span>{a.name}<small>{a.asset_tag}</small></span></label>)}{!matches.length && <p>No matching assets.</p>}</div>
      <small>{value.length} selected</small>
    </>}
  </fieldset>;
}
