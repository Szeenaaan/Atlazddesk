import { useEffect, useState } from 'react';
import { client } from '../api/client';

type Row = Record<string, any>;
export default function DirectoryList({ resource, lookups, canEdit }: { canEdit: boolean; resource: 'customers' | 'assets'; lookups: any }) {
  const [filters, setFilters] = useState({ search: '', city: '', status: '', customer_id: '', category_id: '' });
  const [page, setPage] = useState(1), [rows, setRows] = useState<Row[]>([]), [total, setTotal] = useState(0), [last, setLast] = useState(1);
  const [saving, setSaving] = useState(false);
  const [revision, setRevision] = useState(0);
  const [editing, setEditing] = useState<Row | null>(null);
  const [error, setError] = useState(''), [busy, setBusy] = useState(false);
  useEffect(() => {
    let cancelled = false;
    setBusy(true);
    const timer = window.setTimeout(async () => {
      try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        const { data } = await client.get(`/directory/${resource}`, { params: { ...params, page, per_page: 25 } });
        if (!cancelled) { setRows(data.data); setTotal(data.total); setLast(data.last_page); setError(''); }
      } catch { if (!cancelled) { setRows([]); setTotal(0); setError('Unable to load records. Please retry.'); } }
      finally { if (!cancelled) setBusy(false); }
    }, 250);
    return () => { cancelled = true; window.clearTimeout(timer); };
  }, [resource, filters, page, revision, lookups]);
  function update(key: keyof typeof filters, value: string) { setFilters(f => ({ ...f, [key]: value })); setPage(1); }
  const assets = resource === 'assets';
  return <section className="panel tickets-panel contractor-directory contractor-forms" aria-busy={busy}>
    <div className="toolbar directory-filters">
      <div className="search-box"><input aria-label={`Search ${resource}`} placeholder={`Search ${resource}…`} maxLength={100} value={filters.search} onChange={e => update('search', e.target.value)}/></div>
      {!assets && <select aria-label="Location" value={filters.city} onChange={e => update('city', e.target.value)}><option value="">All locations</option>{(lookups.customer_locations || []).map((city: string) => <option key={city}>{city}</option>)}</select>}
      {assets && <><select aria-label="Customer" value={filters.customer_id} onChange={e => update('customer_id', e.target.value)}><option value="">All customers</option>{(lookups.customers || []).map((c: Row) => <option key={c.id} value={c.id}>{c.company_name}</option>)}</select>
      <select aria-label="Category" value={filters.category_id} onChange={e => update('category_id', e.target.value)}><option value="">All categories</option>{(lookups.categories || []).map((t: Row) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></>}
      <select aria-label="Status" value={filters.status} onChange={e => update('status', e.target.value)}><option value="">All statuses</option>{(assets ? ['active', 'inactive', 'maintenance', 'retired'] : ['active', 'inactive']).map(s => <option key={s} value={s}>{s.replaceAll('_', ' ')}</option>)}</select>
      <button className="btn btn-secondary" onClick={() => { setFilters({ search: '', city: '', status: '', customer_id: '', category_id: '' }); setPage(1); }}>Clear filters</button>
      <span className="result-count">{busy ? 'Loading…' : `${total} records`}</span>
    </div>
    {editing && <form className="asset-edit-form" onSubmit={async e => {
      e.preventDefault();
      if (saving) return;
      setSaving(true);
      try { await client.patch(`/directory/assets/${editing.id}`, { name: editing.name, asset_tag: editing.asset_tag, customer_id: Number(editing.customer_id), category_id: Number(editing.category_id) }); setEditing(null); setRevision(v => v + 1); }
      catch (e: any) { setError(e.response?.data?.message || 'Unable to update asset.'); }
      finally { setSaving(false); }
    }}>
      <h3>Edit asset</h3>
      <fieldset disabled={saving}>
      <label>Asset name<input required value={editing.name} onChange={e => setEditing({ ...editing, name: e.target.value })}/></label>
      <label>Asset tag<input required value={editing.asset_tag} onChange={e => setEditing({ ...editing, asset_tag: e.target.value })}/></label>
      {(['customer_id', 'category_id'] as const).map(key => <label key={key}>{key === 'customer_id' ? 'Customer' : 'Category'}<select required value={editing[key] || ''} onChange={e => setEditing({ ...editing, [key]: e.target.value })}><option value="">Select</option>{(key === 'customer_id' ? lookups.customers : lookups.categories).map((r: Row) => <option key={r.id} value={r.id}>{r.company_name || r.name}</option>)}</select></label>)}
      </fieldset>
      <div className="asset-edit-actions"><button disabled={saving} className="btn btn-primary">{saving ? 'Saving…' : 'Save asset'}</button><button disabled={saving} type="button" className="btn btn-secondary" onClick={() => setEditing(null)}>Cancel</button></div>
    </form>}
    {error && <p className="amc-field-error directory-error" role="alert">{error}</p>}
    <div className="table-scroll"><table><thead><tr>{(assets ? ['Asset', 'Asset tag', 'Category', 'Customer', 'Status', ...(canEdit ? ['Actions'] : [])] : ['Company', 'Code', 'Location', 'Status']).map(h => <th key={h}>{h}</th>)}</tr></thead>
      <tbody>{rows.map(r => <tr key={r.id}>{(assets ? [r.name, r.asset_tag, r.category?.name, r.customer?.company_name, r.status] : [r.company_name, r.customer_code, r.city, r.status]).map((v, i) => <td key={i}>{String(v || '—')}</td>)}{assets && canEdit && <td><button className="btn btn-secondary" onClick={() => setEditing(r)}>Edit</button></td>}</tr>)}</tbody></table>
      {!busy && !error && rows.length === 0 && <div className="empty">No matching {resource} found.</div>}
    </div>
    {last > 1 && <div className="toolbar directory-pagination"><button className="btn btn-secondary" disabled={busy || page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button><span>Page {page} of {last}</span><button className="btn btn-secondary" disabled={busy || page >= last} onClick={() => setPage(p => p + 1)}>Next</button></div>}
  </section>;
}
