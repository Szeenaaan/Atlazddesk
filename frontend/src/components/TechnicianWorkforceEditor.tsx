import { useState, type FormEvent } from 'react';
import { client } from '../api/client';
import { getWorkforceErrorMessage, type Designation } from '../api/escalations';
import { Button, CloseButton, InlineNotice } from './ui';
import { Dialog } from './ui/Dialog';
import TechnicianFields from './TechnicianFields';
import { useDashboardFeedback, useFeedbackState } from './feedbackContext';

export default function TechnicianWorkforceEditor({ user, designations, onClose, onSaved }: {
  user: { id: number; name: string; designation_id?: number | null; workforce_type?: string | null };
  onClose: () => void; onSaved: () => void;
  designations?: Designation[] | null;
}) {
  const [designation, setDesignation] = useState(String(user.designation_id || ''));
  const [workforce, setWorkforce] = useState(user.workforce_type || '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useFeedbackState('error');
  const publish = useDashboardFeedback();
  const close = () => { if (!busy) onClose(); };
  async function save(event: FormEvent) {
    event.preventDefault(); if (busy) return;
    setBusy(true); setError('');
    try {
      await client.patch(`/users/${user.id}/workforce`, { designation_id: Number(designation), workforce_type: workforce });
      publish?.({ tone: 'success', message: 'Technician designation and classification updated.' }); onSaved(); onClose();
    } catch (e) { setError(getWorkforceErrorMessage(e, 'Technician details could not be saved.')); }
    finally { setBusy(false); }
  }
  return <Dialog label="Edit technician workforce" onClose={close}><form onSubmit={save}>
    <div className="drawer-top"><div><h2>Edit technician workforce</h2><div className="result-count">{user.name}</div></div><CloseButton disabled={busy} onClick={close} aria-label="Close technician editor"/></div>
    <div className="ui-dialog-body">
      <div className="support-form"><TechnicianFields designations={designations} designationId={designation} onDesignation={setDesignation} workforceType={workforce} onWorkforce={setWorkforce} disabled={busy}/>
        {error && <InlineNotice tone="error">{error}</InlineNotice>}</div>
      <div className="ui-actions"><Button type="button" disabled={busy} onClick={close}>Cancel</Button><Button type="submit" variant="primary" disabled={busy || !designation || !workforce}>{busy ? 'Saving…' : 'Save changes'}</Button></div>
    </div>
  </form></Dialog>;
}
