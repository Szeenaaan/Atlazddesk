import { useEffect, useState, type FormEvent } from 'react';
import { client } from '../api/client';
import { getWorkforceErrorMessage, type Designation } from '../api/escalations';
import { Button, CloseButton, Field, Input, Checkbox, InlineNotice, RefreshButton, UIState } from './ui';
import { ConfirmationDialog, Dialog } from './ui/Dialog';
import SortableTable from './SortableTable';
import RowActions from './RowActions';
import { Pencil, Trash2 } from 'lucide-react';
import { useFeedbackState } from './feedbackContext';

export default function DesignationManagement({ onClose }: { onClose: () => void }) {
  const [items, setItems] = useState<Designation[]>([]);
  const [editId, setEditId] = useState<number | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Designation | null>(null);
  const [name, setName] = useState('');
  const [level, setLevel] = useState('1');
  const [active, setActive] = useState(true);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  const [error, setError] = useFeedbackState('error');
  const [, setMessage] = useFeedbackState('success');
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    client.get<{ data: Designation[] }>('/designations', { signal: controller.signal }).then(({ data }) => setItems(data.data))
      .catch(e => { if (!controller.signal.aborted) setError(getWorkforceErrorMessage(e, 'Designations could not be loaded.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [revision]);
  function reset() { setEditId(null); setName(''); setLevel('1'); setActive(true); }
  async function save(event: FormEvent) {
    event.preventDefault(); if (busy) return;
    setBusy(true); setError('');
    try {
      const payload = { name: name.trim(), level: Number(level), is_active: active };
      if (editId) await client.patch(`/designations/${editId}`, payload); else await client.post('/designations', payload);
      reset(); setRevision(value => value + 1); setMessage('Designation saved.');
    } catch (e) { setError(getWorkforceErrorMessage(e, 'Designation could not be saved.')); }
    finally { setBusy(false); }
  }
  async function remove(item: Designation) {
    if (busy) return; setBusy(true); setError('');
    try { await client.delete(`/designations/${item.id}`); if (editId === item.id) reset(); setDeleteTarget(null); setRevision(v => v + 1); setMessage('Designation deleted.'); }
    catch (e) { setDeleteTarget(null); setError(getWorkforceErrorMessage(e, 'Designation could not be deleted.')); }
    finally { setBusy(false); }
  }
  const close = () => { if (!busy) onClose(); };
  return <Dialog label="Manage designations" className="designation-dialog" onClose={close}><div className="modal designation-management">
    <div className="drawer-top"><h2>Manage designations</h2><CloseButton disabled={busy} aria-label="Close designations" onClick={close}/></div>
    <div className="modal-body">
      <form className="admin-form" onSubmit={save}>
        <Field>Name<Input required maxLength={100} value={name} disabled={busy} onChange={e => setName(e.target.value)}/></Field>
        <Field>Level<Input required type="number" min={0} max={15} step={1} value={level} disabled={busy} onChange={e => setLevel(e.target.value)}/></Field>
        <Field><Checkbox checked={active} disabled={busy} onChange={e => setActive(e.target.checked)}/>Active</Field>
        <div className="ui-actions">
          <Button type="submit" variant="primary" disabled={busy || loading || !name.trim()}>{busy ? 'Saving…' : editId ? 'Save designation' : 'Add designation'}</Button>
          {editId && <Button type="button" disabled={busy} onClick={reset}>Cancel edit</Button>}
          {editId && <Button type="button" variant="danger" disabled={busy} onClick={() => {
            const item = items.find(candidate => candidate.id === editId);
            if (item) setDeleteTarget(item);
          }}>Delete designation</Button>}
        </div>
      </form>
      <p>Higher levels represent higher positions. Designations may share a level.</p>
      {error && <InlineNotice tone="error">{error} <RefreshButton label="Reload designations" disabled={busy} onClick={() => setRevision(v => v + 1)}/></InlineNotice>}
    </div>
    {loading ? <UIState tone="loading">Loading designations…</UIState> : <SortableTable><thead><tr><th>Name</th><th>Level</th><th>Status</th><th>Action</th></tr></thead><tbody>
        {items.map(item => <tr key={item.id}><td>{item.name}</td><td>{item.level}</td><td>{item.is_active ? 'Active' : 'Inactive'}</td><td><RowActions name={item.name} actions={[
          { label: 'Edit', icon: <Pencil size={16}/>, disabled: busy, onClick: () => { setEditId(item.id); setName(item.name); setLevel(String(item.level)); setActive(item.is_active); } },
          { label: 'Delete', icon: <Trash2 size={16}/>, danger: true, disabled: busy, onClick: () => setDeleteTarget(item) },
        ]}/></td></tr>)}
        {!items.length && <tr><td colSpan={4}>No designations yet. Add a designation to create technicians.</td></tr>}
      </tbody></SortableTable>}
    {deleteTarget && <ConfirmationDialog title="Delete designation?" confirmLabel="Delete designation" busy={busy} onConfirm={() => void remove(deleteTarget)} onClose={() => { if (!busy) setDeleteTarget(null); }}>
      <p>Delete {deleteTarget.name}? Designations assigned to technicians or used in escalation history must be reassigned or retained.</p>
    </ConfirmationDialog>}
  </div></Dialog>;
}
