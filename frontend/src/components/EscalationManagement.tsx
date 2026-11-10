import { useEffect, useState, type FormEvent } from 'react';
import { client } from '../api/client';
import { getWorkforceErrorMessage, type EligibleEmployee, type Escalation } from '../api/escalations';
import { formatInstallationDateTime } from '../utils/installationTime';
import { formatTicketNumber } from '../utils/ticketNumber';
import { Button, Card, CloseButton, Field, InlineNotice, Pagination, RefreshButton, Textarea, UIState } from './ui';
import { Dialog } from './ui/Dialog';
import SearchableDropdown from './SearchableDropdown';
import SortableTable from './SortableTable';
import { useFeedbackState } from './feedbackContext';

export default function EscalationManagement({ revision, onChanged }: { revision: number; onChanged: () => void }) {
  const [items, setItems] = useState<Escalation[]>([]);
  const [page, setPage] = useState(1);
  const [last, setLast] = useState(1);
  const [retry, setRetry] = useState(0);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useFeedbackState('error');
  const [selected, setSelected] = useState<Escalation | null>(null);
  const [decision, setDecision] = useState<'approved' | 'rejected'>('approved');
  const [note, setNote] = useState('');
  const [employee, setEmployee] = useState('');
  const [eligible, setEligible] = useState<EligibleEmployee[]>([]);
  const [eligibleLoading, setEligibleLoading] = useState(false);
  const [eligibleError, setEligibleError] = useState('');
  const [eligibleRevision, setEligibleRevision] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useFeedbackState('error');
  const [, setMessage] = useFeedbackState('success');

  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setLoadError('');
    client.get<{ data: Escalation[]; meta: { last_page: number } }>('/escalations', { params: { page }, signal: controller.signal })
      .then(({ data }) => { setItems(data.data); setLast(data.meta.last_page); })
      .catch(failure => { if (!controller.signal.aborted) setLoadError(getWorkforceErrorMessage(failure, 'Escalations could not be loaded.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [page, revision, retry]);

  useEffect(() => {
    if (selected?.state !== 'pending') return;
    const controller = new AbortController(); setEligibleLoading(true); setEligibleError(''); setEligible([]); setEmployee('');
    client.get<{ data: EligibleEmployee[] }>(`/tickets/${selected.ticket_id}/escalations/eligible`, { signal: controller.signal })
      .then(({ data }) => { if (!controller.signal.aborted) setEligible(data.data); })
      .catch(failure => { if (!controller.signal.aborted) setEligibleError(getWorkforceErrorMessage(failure, 'Eligible employees could not be loaded.')); })
      .finally(() => { if (!controller.signal.aborted) setEligibleLoading(false); });
    return () => controller.abort();
  }, [selected?.id, eligibleRevision]);

  const reference = (item: Escalation) => item.reference || `Escalation #${item.id}`;
  const ticketNumber = (item: Escalation) => item.ticket_number?.startsWith('#TK') ? item.ticket_number : formatTicketNumber(item.ticket_id, item.created_at);
  function open(item: Escalation) {
    setSelected(item); setDecision('approved'); setNote(''); setEmployee(''); setError('');
  }
  function close() { if (!busy) setSelected(null); }
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!selected || selected.state !== 'pending' || busy) return;
    setError('');
    if (decision === 'rejected' && !note.trim()) { setError('Enter a note for rejection.'); return; }
    if (decision === 'approved' && !eligible.some(item => String(item.id) === employee)) { setError('Select an eligible employee at the same level or higher.'); return; }
    setBusy(true);
    try {
      await client.patch(`/tickets/${selected.ticket_id}/escalations/${selected.id}`, {
        decision, admin_note: note.trim() || null, ...(decision === 'approved' ? { selected_employee_id: Number(employee) } : {}),
      });
      setMessage(`${reference(selected)} ${decision}.`);
      setSelected(null); onChanged();
    } catch (failure) { setError(getWorkforceErrorMessage(failure, 'The escalation decision could not be saved.')); }
    finally { setBusy(false); }
  }

  return <Card className="panel escalations-panel">
    <div className="panel-head"><h3>Escalations</h3><RefreshButton label="Refresh escalations" disabled={loading} onClick={() => setRetry(value => value + 1)}/></div>
    {loadError ? <InlineNotice tone="error">{loadError}</InlineNotice>
      : loading ? <UIState tone="loading">Loading escalations…</UIState>
      : <div className="table-scroll"><SortableTable><thead><tr><th>Reference</th><th>Ticket</th><th>Technician</th><th>Designation</th><th>Requested</th><th>State</th><th>Action</th></tr></thead><tbody>
        {items.map(item => <tr key={item.id}><td>{reference(item)}</td><td>{ticketNumber(item)}<br/>{item.subject}</td><td>{item.requester_name}</td><td>{item.previous_designation_name}</td><td data-sort-value={item.created_at}>{formatInstallationDateTime(item.created_at)}</td><td>{item.state === 'pending' ? 'Pending' : item.state === 'approved' ? 'Approved' : 'Rejected'}</td><td><Button type="button" onClick={() => open(item)}>{item.state === 'pending' ? 'Review' : 'View'}</Button></td></tr>)}
        {!items.length && <tr><td colSpan={7}>No escalation requests have been recorded.</td></tr>}
      </tbody></SortableTable></div>}
    <Pagination page={page} last={last} onPage={setPage} label="Escalation pages"/>

    {selected && <Dialog label={selected.state === 'pending' ? `Review ${reference(selected)}` : reference(selected)} className={selected.state === 'pending' ? '' : 'escalation-review-dialog'} onClose={close}><form className="modal" onSubmit={submit}>
      <div className="drawer-top"><h2>{reference(selected)}</h2><CloseButton disabled={busy} aria-label="Close escalation" onClick={close}/></div>
      <div className="modal-body">
        {selected.state === 'pending' && <>
          <p>{ticketNumber(selected)} · {selected.subject}</p>
          <p>{selected.requester_name} · {selected.previous_designation_name} (level {selected.previous_level}) · {formatInstallationDateTime(selected.created_at)}</p>
          <p className="record-notes">{selected.reason}</p>
        </>}
        {selected.state === 'pending' ? <>
          <Field>Decision<SearchableDropdown value={decision} disabled={busy} onChange={event => setDecision(event.target.value as 'approved' | 'rejected')}><option value="approved">Approve and reassign</option><option value="rejected">Reject</option></SearchableDropdown></Field>
          {decision === 'approved' && <>
            <Field>Replacement employee<SearchableDropdown required value={employee} disabled={busy || eligibleLoading || !!eligibleError} onChange={event => setEmployee(event.target.value)}>
              <option value="">Select an employee</option>{eligible.map(item => <option key={item.id} value={item.id}>{item.name} · {item.designation.name} (level {item.designation.level})</option>)}
            </SearchableDropdown></Field>
            {eligibleLoading && <UIState tone="loading">Loading eligible employees…</UIState>}
            {eligibleError && <InlineNotice tone="error">{eligibleError} <RefreshButton label="Retry eligible employees" onClick={() => setEligibleRevision(value => value + 1)}/></InlineNotice>}
            {!eligibleLoading && !eligibleError && !eligible.length && <InlineNotice>No eligible employee is available at this level or higher. Check technician designations before approving.</InlineNotice>}
          </>}
          <Field>Administrative note<Textarea required={decision === 'rejected'} maxLength={5000} disabled={busy} value={note} onChange={event => setNote(event.target.value)}/></Field>
        </> : <dl className="record-detail-list">
          <div><dt>Ticket</dt><dd>{ticketNumber(selected)} · {selected.subject}</dd></div>
          <div><dt>Requested by</dt><dd>{selected.requester_name} · {selected.previous_designation_name} (level {selected.previous_level})</dd></div>
          <div><dt>Requested</dt><dd>{formatInstallationDateTime(selected.created_at)}</dd></div>
          <div><dt>Reason</dt><dd>{selected.reason}</dd></div>
          <div><dt>Decision</dt><dd>{selected.state === 'approved' ? `Reassigned to ${selected.selected_employee_name || 'an employee'}` : 'Rejected'} · {selected.reviewer_name} · {selected.decided_at && formatInstallationDateTime(selected.decided_at)}</dd></div>
          {selected.admin_note && <div><dt>Note</dt><dd>{selected.admin_note}</dd></div>}
        </dl>}
        {error && <InlineNotice tone="error">{error}</InlineNotice>}
      </div>
      <div className="modal-actions"><Button type="button" disabled={busy} onClick={close}>Close</Button>{selected.state === 'pending' && <Button type="submit" variant="primary" disabled={busy || (decision === 'approved' && (eligibleLoading || !!eligibleError || !employee))}>{busy ? 'Saving…' : decision === 'approved' ? 'Approve and reassign' : 'Reject request'}</Button>}</div>
    </form></Dialog>}
  </Card>;
}
