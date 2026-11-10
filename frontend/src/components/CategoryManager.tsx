import { useDashboardFeedback, useFeedbackState } from './feedbackContext';
import { useEffect, useState } from 'react';
import { Pencil, Search, ToggleLeft, ToggleRight, Trash2 } from 'lucide-react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import { Badge, Button, Card, CloseButton, Field, FilterRow, InlineNotice, Input, Pagination, RefreshButton, UIState } from './ui';
import SortableTable from './SortableTable';
import RowActions from './RowActions';
import SearchableDropdown from './SearchableDropdown';
import { ConfirmationDialog, Dialog } from './ui/Dialog';

type Category = { id: number; name: string; is_active: boolean; assets_count: number };

export default function CategoryManager({ onChange }: { onChange: () => void }) {
  const feedback = useDashboardFeedback();
  const [rows, setRows] = useState<Category[]>([]);
  const [editor, setEditor] = useState<Category | null>(null);
  const [formOpen, setFormOpen] = useState(false);
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
      if (editor?.id === deleteTarget.id) { setEditor(null); setName(''); }
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
          ? { name: name.trim() } : { is_active: active });
      } else {
        await client.post('/categories', { name: name.trim() });
        setName('');
        setPage(1);
      }
      if (active === undefined) { setEditor(null); setName(''); setFormOpen(false); }
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
    {error && <InlineNotice tone="error">{error}</InlineNotice>}
    <div className="panel-head"><h3>Service categories</h3><div className="ui-actions"><Button type="button" variant="primary" disabled={loading || saving} onClick={() => { setEditor(null); setName(''); setFormOpen(true); }}>Add category</Button><RefreshButton label="Refresh categories" disabled={loading || saving} onClick={() => void retry()}/></div></div>
    <FilterRow className="category-controls">
      <div className="search-box"><Search size={20} aria-hidden="true"/><Input aria-label="Search categories" placeholder="Search categories…" value={search} onChange={event => { setSearch(event.target.value); setPage(1); }}/></div>
      <SearchableDropdown aria-label="Filter categories by assets" value={assetFilter} onChange={event => { setAssetFilter(event.target.value); setPage(1); }}>
        <option value="">All categories</option><option value="without_assets">Without assets</option><option value="with_assets">With assets</option>
      </SearchableDropdown>
      <span className="result-count">{filtered.length} {filtered.length === 1 ? 'category' : 'categories'}</span>
    </FilterRow>
    {loading ? <UIState tone="loading">Loading categories…</UIState> : <>
      <div className="table-scroll"><SortableTable>
        <thead><tr><th>Category</th><th>Assets</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>{visible.map(row => <tr key={row.id}>
          <td><Badge>{row.name}</Badge></td>
          <td>{row.assets_count}</td>
          <td><Badge tone={row.is_active ? 'success' : 'neutral'}>{row.is_active ? 'Active' : 'Inactive'}</Badge></td>
          <td><RowActions name={row.name} actions={[
            { label: 'Edit category', icon: <Pencil size={16}/>, disabled: saving, onClick: () => { setName(row.name); setEditor(row); setFormOpen(true); } },
            { label: row.is_active ? 'Deactivate' : 'Activate', icon: row.is_active ? <ToggleLeft size={16}/> : <ToggleRight size={16}/>, disabled: saving, onClick: () => void save(row.id, !row.is_active) },
            { label: 'Delete category', icon: <Trash2 size={16}/>, danger: true, disabled: saving, onClick: () => { setDeleteTarget(row); setDeleteError(''); } },
          ]}/></td>
        </tr>)}</tbody>
      </SortableTable></div>
      {visible.length === 0 && <UIState>{search.trim() || assetFilter ? 'No categories match these filters.' : 'No categories have been added yet.'}</UIState>}
      {last > 1 && <div className="toolbar directory-pagination"><Pagination page={currentPage} last={last} busy={saving} onPage={setPage} label="Category pages"/></div>}
    </>}
    {formOpen && <Dialog className="category-dialog" label={editor ? 'Edit category' : 'Add category'} onClose={() => { if (!saving) setFormOpen(false); }}><form onSubmit={event => { event.preventDefault(); void save(editor?.id); }}>
      <div className="drawer-top"><h2>{editor ? 'Edit category' : 'Add category'}</h2><CloseButton aria-label="Close category form" disabled={saving} onClick={() => setFormOpen(false)}/></div>
      <div className="ui-dialog-body"><div className="support-form"><Field>Category name<Input required maxLength={100} disabled={saving} value={name} onChange={event => setName(event.target.value)}/></Field></div>
        <div className="ui-actions"><Button type="button" disabled={saving} onClick={() => setFormOpen(false)}>Cancel</Button><Button type="submit" variant="primary" disabled={saving || !name.trim() || (editor !== null && name.trim() === editor.name)}>{saving ? 'Saving…' : editor ? 'Save category' : 'Add category'}</Button></div>
      </div>
    </form></Dialog>}
    {deleteTarget && <ConfirmationDialog title="Delete category?" confirmLabel="Delete category" busy={saving} onConfirm={() => void remove()} onClose={() => { if (!saving) setDeleteTarget(null); }}>
      <p>Delete {deleteTarget.name}? Categories linked to assets or ticket history cannot be deleted.</p>
      {deleteError && <InlineNotice tone="error">{deleteError}</InlineNotice>}
    </ConfirmationDialog>}
  </Card>;
}
