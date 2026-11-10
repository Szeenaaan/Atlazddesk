import { useDashboardFeedback, useFeedbackState } from './feedbackContext';
import { Field } from './ui';
import { Pagination, UIState } from './ui';
import { Button, Card, Input, RefreshButton } from './ui';
import SearchableDropdown from './SearchableDropdown';
import SortableTable from './SortableTable';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import RecordDetails from './RecordDetails';
import SlidingDrawer from './SlidingDrawer';
import RowActions from './RowActions';
import { ConfirmationDialog } from './ui/Dialog';
import { Pencil, ToggleLeft, ToggleRight, Trash2 } from 'lucide-react';

type Row = Record<string, any>;
export default function DirectoryList({ resource, lookups, canEdit, isCustomerAdmin = false }: { canEdit: boolean; isCustomerAdmin?: boolean; resource: 'customers' | 'assets'; lookups: any }) {
  const feedback = useDashboardFeedback();
  const [sort, setSort] = useState<{sort?: string; direction?: string}>({});
  const [filters, setFilters] = useState({ search: '', city: '', status: '', customer_id: '', category_id: '' });
  const [page, setPage] = useState(1), [rows, setRows] = useState<Row[]>([]), [total, setTotal] = useState(0), [last, setLast] = useState(1);
  const [saving, setSaving] = useState(false);
  const [revision, setRevision] = useState(0);
  const [editing, setEditing] = useState<Row | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Row | null>(null);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [error, setError] = useFeedbackState('error'), [busy, setBusy] = useState(false);
  useEffect(() => {
    let cancelled = false;
    setBusy(true);
    const timer = window.setTimeout(async () => {
      try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
        const { data } = await client.get(`/directory/${resource}`, { params: { ...params, ...sort, page, per_page: 25 } });
        if (!cancelled) { setRows(data.data); setTotal(data.total); setLast(data.last_page); setError(''); }
      } catch { if (!cancelled) { setRows([]); setTotal(0); setError('Unable to load records. Please retry.'); } }
      finally { if (!cancelled) setBusy(false); }
    }, 250);
    return () => { cancelled = true; window.clearTimeout(timer); };
  }, [resource, filters, page, revision, lookups, sort]);
  function update(key: keyof typeof filters, value: string) { setFilters(f => ({ ...f, [key]: value })); setPage(1); }
  const assets = resource === 'assets';
  const assetColumns = ['Asset', 'Asset tag', 'Category', ...(!isCustomerAdmin ? ['Client'] : []), 'Assignment type', 'Status', ...(canEdit ? ['Actions'] : [])];
  const assetSortColumns = ['name', 'asset_tag', 'category', ...(!isCustomerAdmin ? ['customer'] : []), 'assignment_mode', 'status'];
  async function changeAssetStatus(row: Row) {
    setSaving(true);
    try {
      await client.patch(`/directory/assets/${row.id}`, { status: row.status === 'active' ? 'inactive' : 'active' });
      feedback?.({tone: 'success', message: row.status === 'active' ? 'Asset deactivated.' : 'Asset activated.'});
      setRevision(value => value + 1);
    } catch (cause: any) { setError(cause.response?.data?.message || 'Unable to change asset status.'); }
    finally { setSaving(false); }
  }
  async function deleteAsset() {
    if (!deleteTarget) return;
    setSaving(true);
    try {
      await client.delete(`/directory/assets/${deleteTarget.id}`, { data: { confirm_assigned: true } });
      feedback?.({tone: 'success', message: 'Asset deleted.'});
      if (detailId === deleteTarget.id) setDetailId(null);
      setDeleteTarget(null);
      setRevision(value => value + 1);
    } catch (cause: any) { setError(cause.response?.data?.message || 'Unable to delete asset.'); }
    finally { setSaving(false); }
  }
  return <><Card className="panel tickets-panel contractor-directory contractor-forms" aria-busy={busy}>
    <div className="toolbar directory-filters">
      <div className="search-box"><Input aria-label={`Search ${resource}`} placeholder={`Search ${resource}…`} maxLength={100} value={filters.search} onChange={e => update('search', e.target.value)}/></div>
      {!assets && <SearchableDropdown aria-label="Location" value={filters.city} onChange={e => update('city', e.target.value)}><option value="">All locations</option>{(lookups.customer_locations || []).map((city: string) => <option key={city}>{city}</option>)}</SearchableDropdown>}
      {assets && <>{!isCustomerAdmin && <SearchableDropdown aria-label="Client" value={filters.customer_id} onChange={e => update('customer_id', e.target.value)}><option value="">All clients</option>{(lookups.customers || []).map((c: Row) => <option key={c.id} value={c.id}>{c.company_name}</option>)}</SearchableDropdown>}
      <SearchableDropdown aria-label="Category" value={filters.category_id} onChange={e => update('category_id', e.target.value)}><option value="">All categories</option>{(lookups.categories || []).map((t: Row) => <option key={t.id} value={t.id}>{t.name}</option>)}</SearchableDropdown></>}
      <SearchableDropdown aria-label="Status" value={filters.status} onChange={e => update('status', e.target.value)}><option value="">All statuses</option>{(assets ? ['active', 'inactive', 'maintenance', 'retired'] : ['active', 'inactive']).map(s => <option key={s} value={s}>{s.replaceAll('_', ' ')}</option>)}</SearchableDropdown>
      <Button className="btn btn-secondary" onClick={() => { setFilters({ search: '', city: '', status: '', customer_id: '', category_id: '' }); setPage(1); }}>Clear filters</Button>
      <span className="result-count">{busy ? 'Loading…' : `${total} ${assets ? 'assets' : 'clients'}`}</span>
      <RefreshButton label={`Refresh ${assets ? 'assets' : 'clients'}`} disabled={busy} onClick={() => setRevision(value => value + 1)}/>
    </div>
    {editing && <SlidingDrawer title="Edit asset" onClose={() => setEditing(null)}>{close => <div className="drawer-body"><form className="asset-edit-form drawer-edit-form" onSubmit={async e => {
      e.preventDefault();
      if (saving) return;
      setSaving(true);
      try { await client.patch(`/directory/assets/${editing.id}`, { name: editing.name, asset_tag: editing.asset_tag, ...(!isCustomerAdmin ? { customer_id: Number(editing.customer_id) } : {}), category_id: Number(editing.category_id), assignment_mode: editing.assignment_mode }); feedback?.({tone: 'success', message: 'Asset updated.'}); close(); setRevision(v => v + 1); }
      catch (e: any) { setError(e.response?.data?.message || 'Unable to update asset.'); }
      finally { setSaving(false); }
    }}>
      <h3>Edit asset</h3>
      <fieldset disabled={saving}>
      <Field>Asset name<Input required value={editing.name} onChange={e => setEditing({ ...editing, name: e.target.value })}/></Field>
      <Field>Asset tag<Input required value={editing.asset_tag} onChange={e => setEditing({ ...editing, asset_tag: e.target.value })}/></Field>
      {(!isCustomerAdmin ? ['customer_id', 'category_id'] as const : ['category_id'] as const).map(key => <Field key={key}>{key === 'customer_id' ? 'Client' : 'Category'}<SearchableDropdown required value={editing[key] || ''} onChange={e => setEditing({ ...editing, [key]: e.target.value })}><option value="">Select</option>{(key === 'customer_id' ? lookups.customers : lookups.categories).map((r: Row) => <option key={r.id} value={r.id}>{r.company_name || r.name}</option>)}</SearchableDropdown></Field>)}
      <Field>Assignment type<SearchableDropdown value={editing.assignment_mode || 'shared'} onChange={e => setEditing({ ...editing, assignment_mode: e.target.value })}>
        <option value="shared">Shared</option>
        <option value="individual">Individual</option>
        <option value="common">Common</option>
      </SearchableDropdown></Field>
      </fieldset>
      {error && <p className="error" role="alert">{error}</p>}
      <div className="asset-edit-actions"><Button disabled={saving} className="btn btn-primary">{saving ? 'Saving…' : 'Save asset'}</Button><Button disabled={saving} type="button" className="btn btn-secondary" onClick={close}>Cancel</Button></div>
    </form></div>}</SlidingDrawer>}
    {error && <p className="amc-field-error directory-error" role="alert">{error}</p>}
    <div className="table-scroll"><SortableTable onSort={({column, direction}) => { setSort({sort: (assets ? assetSortColumns : ['company_name','customer_code','city','status'])[column], direction}); setPage(1); }}><thead><tr>{(assets ? assetColumns : ['Company', 'Code', 'Location', 'Status']).map(h => <th key={h}>{h}</th>)}</tr></thead>
      <tbody>{rows.map(r => <tr key={r.id} className="record-row" onClick={() => setDetailId(r.id)}>{(assets ? [r.name, r.asset_tag, r.category?.name, ...(!isCustomerAdmin ? [r.customer?.company_name] : []), r.assignment_mode, r.status] : [r.company_name, r.customer_code, r.city, r.status]).map((v, i) => <td key={i}>{i === 0 ? <button type="button" className="record-link" onClick={event => { event.stopPropagation(); setDetailId(r.id); }}>{String(v || '—')}</button> : String(v || '—')}</td>)}{assets && canEdit && <td onClick={event => event.stopPropagation()}><RowActions name={String(r.name)} actions={[
        { label: 'Edit asset', icon: <Pencil size={16}/>, disabled: saving, onClick: () => setEditing(r) },
        { label: r.status === 'active' ? 'Deactivate' : 'Activate', icon: r.status === 'active' ? <ToggleLeft size={16}/> : <ToggleRight size={16}/>, disabled: saving, onClick: () => void changeAssetStatus(r) },
        { label: 'Delete asset', icon: <Trash2 size={16}/>, danger: true, disabled: saving, onClick: () => { setError(''); setDeleteTarget(r); } },
      ]}/></td>}</tr>)}</tbody></SortableTable>
      {!busy && !error && rows.length === 0 && <UIState>No matching {resource} found.</UIState>}
    </div>
    <Pagination page={page} last={last} busy={busy} onPage={setPage}/>

  </Card>{deleteTarget && <ConfirmationDialog title="Delete asset?" confirmLabel="Delete asset" busy={saving} onConfirm={() => void deleteAsset()} onClose={() => { if (!saving) setDeleteTarget(null); }}>
    <p>{deleteTarget.assigned_users_count ? `${deleteTarget.name} is linked to ${deleteTarget.assigned_users_count} ${deleteTarget.assigned_users_count === 1 ? 'user' : 'users'}. Deleting it will remove those assignments. Do you want to delete it?` : `Delete ${deleteTarget.name}? This action cannot be undone.`}</p>
    {error && <p className="error" role="alert">{error}</p>}
  </ConfirmationDialog>}{detailId !== null && <RecordDetails key={detailId} resource={resource} id={detailId} canManageContracts={canEdit} onChanged={() => setRevision(v => v + 1)} onClose={() => setDetailId(null)}/>}</>;
}
