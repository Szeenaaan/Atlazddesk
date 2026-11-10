import { useState, type FormEvent } from 'react';
import { client } from '../api/client';
import { canRequestEscalation, getWorkforceErrorMessage } from '../api/escalations';
import type { Ticket } from '../data';
import { formatTicketNumber } from '../utils/ticketNumber';
import { Button, CloseButton, Field, InlineNotice, Textarea } from './ui';
import { Dialog } from './ui/Dialog';
import { useFeedbackState } from './feedbackContext';

export default function TicketEscalations({ ticket, userId, onChanged }: {
  ticket: Ticket; userId?: number; onChanged: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useFeedbackState('error');
  const [, setMessage] = useFeedbackState('success');
  if (!canRequestEscalation('technician', userId, ticket.assigned_to, ticket.status, !!ticket.read_only)) return null;

  const close = () => { if (!busy) setOpen(false); };
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    setBusy(true); setError('');
    try {
      await client.post(`/tickets/${ticket.id}/escalations`, { reason: reason.trim() });
      setMessage(`Your escalation request for ${formatTicketNumber(ticket.id, ticket.created_at)} is awaiting a decision.`);
      setOpen(false); setReason(''); onChanged();
    } catch (failure) { setError(getWorkforceErrorMessage(failure, 'The escalation could not be submitted.')); }
    finally { setBusy(false); }
  }
  return <section className="ticket-escalations" aria-label="Ticket escalation">
    <h3>Escalation</h3>
    <Button type="button" onClick={() => { setError(''); setOpen(true); }}>Request escalation</Button>
    {open && <Dialog label="Request escalation" onClose={close}><form className="modal" onSubmit={submit}>
      <div className="drawer-top"><h2>Request escalation</h2><CloseButton disabled={busy} aria-label="Close escalation dialog" onClick={close}/></div>
      <div className="modal-body"><p>{formatTicketNumber(ticket.id, ticket.created_at)} · {ticket.subject}</p>
        <Field>Escalation reason<Textarea required maxLength={5000} disabled={busy} value={reason} onChange={event => setReason(event.target.value)}/></Field>
        {error && <InlineNotice tone="error">{error}</InlineNotice>}
      </div>
      <div className="modal-actions"><Button type="button" disabled={busy} onClick={close}>Cancel</Button><Button type="submit" variant="primary" disabled={busy}>{busy ? 'Saving…' : 'Submit request'}</Button></div>
    </form></Dialog>}
  </section>;
}
