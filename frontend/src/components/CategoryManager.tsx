import { useEffect, useState } from 'react';
import { client } from '../api/client';
export default function CategoryManager({ onChange }: { onChange: () => void }) {
  const [saving, setSaving] = useState(false);
  const [rows, setRows] = useState<{ id: number; name: string; is_active: boolean }[]>([]), [name, setName] = useState(''), [error, setError] = useState('');
  async function load() { const { data } = await client.get('/categories'); setRows(data); }
  useEffect(() => { void load().catch(() => setError('Unable to load categories.')); }, []);
  async function save(id?: number, active?: boolean) {
    if (saving) return;
    setSaving(true);
    try {
      if (id) await client.patch(`/categories/${id}`, active === undefined ? { name: rows.find(r => r.id === id)?.name } : { is_active: active });
      else { await client.post('/categories', { name }); setName(''); }
      await load(); onChange(); setError('');
    } catch (e: any) { setError(e.response?.data?.message || 'Unable to save category.'); }
    finally { setSaving(false); }
  }
  return <details className="panel category-manager contractor-forms"><summary>Manage categories</summary>
    {error && <p className="amc-field-error" role="alert">{error}</p>}
    <form className="category-create" onSubmit={e => { e.preventDefault(); void save(); }}><label>Category name<input required maxLength={100} disabled={saving} value={name} onChange={e => setName(e.target.value)}/></label><button disabled={saving} className="btn btn-primary">Add category</button></form>
    {rows.map(r => <div className="category-row" key={r.id}><input aria-label={`Category name: ${r.name}`} disabled={saving} value={r.name} maxLength={100} onChange={e => setRows(all => all.map(c => c.id === r.id ? { ...c, name: e.target.value } : c))}/><button disabled={saving} className="btn btn-secondary" onClick={() => void save(r.id)}>Save</button><button disabled={saving} className="btn btn-secondary" onClick={() => void save(r.id, !r.is_active)}>{r.is_active ? 'Deactivate' : 'Activate'}</button></div>)}
  </details>;
}
