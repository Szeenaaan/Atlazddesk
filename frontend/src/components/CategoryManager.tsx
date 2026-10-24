import { useDashboardFeedback, useFeedbackState } from './feedbackContext';
import { useEffect, useState } from 'react';
import { Save, Search, ToggleLeft, ToggleRight, Trash2 } from 'lucide-react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import { Badge, Button, Card, Field, InlineNotice, Input, Pagination, UIState } from './ui';
import SortableTable from './SortableTable';
import RowActions from './RowActions';
import SearchableDropdown from './SearchableDropdown';
import { ConfirmationDialog } from './ui/Dialog';

type Category = { id: number; name: string; is_active: boolean; assets_count: number };

export default function CategoryManager({ onChange }: { onChange: () => void }) {
  const feedback = useDashboardFeedback();
  const [rows, setRows] = useState<Category[]>([]);
  const [draftNames, setDraftNames] = useState<Record<number, string>>({});
  const [name, setName] = useState('');
  const [search, setSearch] = useState('');
  const [assetFilter, setAssetFilter] = useState('');
  const [deleteTarget, setDeleteTarget] = useState<Category | null>(null);
  const [deleteError, setDeleteError] = useFeedbackState('error');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useFeedbackState('error');
  async function load(signal?: AbortSignal) {
    const { data } = await client.get<Category[]>('/categories', { signal, params: { asset_filter: assetFilter || undefined } });
    setRows(data);
    setDraftNames({});
  }
  useEffect(() => {
    setLoading(true);
    const controller = new AbortController();
    void load(controller.signal).catch(() => {
      if (!controller.signal.aborted) setError('Unable to load categories.');
    }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [assetFilter]);
  async function remove() {
    if (!deleteTarget || saving) return;
    setSaving(true); setDeleteError('');
    try {
      await client.delete(`/categories/${deleteTarget.id}`);
      feedback?.({tone: 'success', message: 'Category deleted.'});
      setDeleteTarget(null);
      await load(); onChange();
    } catch (failure) { setDeleteError(getApiErrorMessage(failure, 'Unable to delete category.')); }
    finally { setSaving(false); }
  }
  async function retry() {
    setLoading(true);
    try { await load(); setError(''); }
    catch { setError('Unable to load categories.'); }
    finally { setLoading(false); }
  }
  async function save(id?: number, active?: boolean) {
    if (saving) return;
    setSaving(true);
    try {
      if (id !== undefined) {
        await client.patch(`/categories/${id}`, active === undefined
          ? { name: (draftNames[id] ?? rows.find(row => row.id === id)?.name ?? '').trim() } : { is_active: active });
      } else {
        await client.post('/categories', { name: name.trim() });
        setName('');
        setPage(1);
      }
      feedback?.({tone: 'success', message: id === undefined ? 'Category created.' : 'Category updated.'});
      await load();
      onChange();
      setError('');
    } catch (failure: unknown) { setError(getApiErrorMessage(failure, 'Unable to save category.')); }
    finally { setSaving(false); }
  }
  const filtered = rows.filter(row => row.name.toLowerCase().includes(search.trim().toLowerCase()));
  const last = Math.max(1, Math.ceil(filtered.length / 25));
  const currentPage = Math.min(page, last);
  const visible = filtered.slice((currentPage - 1) * 25, currentPage * 25);
  return <Card className="category-manager" aria-label="Category management">
    {error && <InlineNotice tone="error">{error} <Button type="button" disabled={loading || saving} onClick={() => void retry()}>Retry</Button></InlineNotice>}
    <form className="category-create" onSubmit={event => { event.preventDefault(); void save(); }}>
      <Field>Category name<Input required maxLength={100} disabled={loading || saving} value={name} onChange={event => setName(event.target.value)}/></Field>
      <Button type="submit" variant="primary" disabled={loading || saving || !name.trim()}>{saving ? 'Saving…' : 'Add category'}</Button>
    </form>
    <div className="category-controls">
      <div className="search-box"><Search size={20} aria-hidden="true"/><Input aria-label="Search categories" placeholder="Search categories…" value={search} onChange={event => { setSearch(event.target.value); setPage(1); }}/></div>
      <SearchableDropdown aria-label="Filter categories by assets" value={assetFilter} onChange={event => { setAssetFilter(event.target.value); setPage(1); }}>
        <option value="">All categories</option><option value="without_assets">Without assets</option><option value="with_assets">With assets</option>
      </SearchableDropdown>
      <span className="result-count">{filtered.length} {filtered.length === 1 ? 'category' : 'categories'}</span>
    </div>
    {loading ? <UIState tone="loading">Loading categories…</UIState> : <>
      <div className="table-scroll"><SortableTable>
        <thead><tr><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>{visible.map(row => <tr key={row.id}>
          <td data-sort-value={row.name}><Input aria-label={`Category name: ${row.name}`} disabled={saving} value={draftNames[row.id] ?? row.name} maxLength={100}
            onChange={event => setDraftNames(all => ({ ...all, [row.id]: event.target.value }))}/></td>
          <td><Badge tone={row.is_active ? 'success' : 'neutral'}>{row.is_active ? 'Active' : 'Inactive'}</Badge></td>
          <td><RowActions name={row.name} actions={[
            { label: 'Save changes', icon: <Save size={16}/>, disabled: saving || !(draftNames[row.id] ?? row.name).trim() || (draftNames[row.id] ?? row.name).trim() === row.name, onClick: () => void save(row.id) },
            { label: row.is_active ? 'Deactivate' : 'Activate', icon: row.is_active ? <ToggleLeft size={16}/> : <ToggleRight size={16}/>, disabled: saving, onClick: () => void save(row.id, !row.is_active) },
            ...(row.assets_count === 0 ? [{label: 'Delete category', icon: <Trash2 size={16}/>, danger: true, disabled: saving, onClick: () => { setDeleteTarget(row); setDeleteError(''); }}] : []),
          ]}/></td>
        </tr>)}</tbody>
      </SortableTable></div>
      {visible.length === 0 && <UIState>{search.trim() ? 'No matching categories found.' : 'No categories have been added yet.'}</UIState>}
      <Pagination page={currentPage} last={last} busy={saving} onPage={setPage} label="Category pages"/>
    </>}
    {deleteTarget && <ConfirmationDialog title="Delete category?" confirmLabel="Delete category" busy={saving} onConfirm={() => void remove()} onClose={() => { if (!saving) setDeleteTarget(null); }}>
      <p>Delete {deleteTarget.name}? Categories linked to assets or ticket history cannot be deleted.</p>
      {deleteError && <InlineNotice tone="error">{deleteError}</InlineNotice>}
    </ConfirmationDialog>}
  </Card>;
}
