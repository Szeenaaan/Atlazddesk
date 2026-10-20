import { formatInstallationDateTime } from '../utils/installationTime';
import { useEffect, useState } from 'react';
import { client, DEMO } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import type { ApiResponse } from '../api/contracts';
import type { Role } from '../data';

type WorkReport = {
  id: number;
  progress: string;
  outcome: string;
  diagnosis: string;
  work_performed: string | null;
  recommendation: string | null;
  parts_required: string | null;
  created_at: string;
  technician?: { id: number; name: string };
};

type Props = { ticketId: number; role: Role; ticketStatus: string };
const outcomes = [
  ['success','Resolved / successful'], ['failed','Repair attempt failed'],
  ['repairable','Repairable — additional work required'], ['unrepairable','Unrepairable — replacement advised'],
  ['warning','Warning / risk identified'], ['waiting_parts','Waiting for spare parts'], ['other','Other findings'],
] as const;
const progressStates = [['in_progress','In progress'], ['completed','Work completed'], ['follow_up','Follow-up required']] as const;
const readable = (v: string) => v.replaceAll('_',' ').replace(/\b\w/g, x => x.toUpperCase());
const blank = { progress:'in_progress', outcome:'warning', diagnosis:'', work_performed:'', recommendation:'', parts_required:'' };

export default function TicketWorkReports({ ticketId, role, ticketStatus }: Props) {
  const [reports, setReports] = useState<WorkReport[]>([]);
  const [form, setForm] = useState(blank);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(true);
  const allowed = role === 'technician' || role === 'admin';
  const closed = ['closed', 'cancelled'].includes(ticketStatus.toLowerCase());

  async function load() {
    if (!allowed || DEMO) { setLoading(false); return; }
    try {
      const { data } = await client.get<ApiResponse<WorkReport[]>>(`/tickets/${ticketId}/work-reports`);
      setReports(data.data || []);
      setError('');
    } catch (error: unknown) { setError(getApiErrorMessage(error, 'Unable to load technician reports.')); }
    finally { setLoading(false); }
  }
  useEffect(() => {
    setReports([]); setForm(blank); setError(''); setSuccess(''); setLoading(true);
    void load();
    // Reset when the assigned ticket changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ticketId, role]);

  async function submit(e: React.FormEvent) {
    e.preventDefault(); setBusy(true); setError(''); setSuccess('');
    try {
      await client.post(`/tickets/${ticketId}/work-reports`, form);
      setForm(blank);
      setSuccess('Report submitted. The contractor Admin has been notified. Ticket status was not changed.');
      await load();
    } catch (error: unknown) {
      setError(getApiErrorMessage(error, 'Unable to submit report.'));
    } finally { setBusy(false); }
  }
  if (!allowed || DEMO) return null;
  return <section className="ticket-work-reports" aria-label="Technician work reports">
    <h3>Technician work reports</h3>
    <p className="muted">Internal contractor records. Work results do not automatically resolve or close the customer ticket.</p>
    {loading && <p className="muted">Loading reports…</p>}
    {!loading && !reports.length && <p className="muted">No work reports submitted yet.</p>}
    <div className="report-history">
      {reports.map(r => <article key={r.id} className="report-entry">
        <div className="report-meta"><b>{readable(r.outcome)}</b><span>{readable(r.progress)}</span></div>
        <small className="muted">{r.technician?.name || 'Technician'} · {formatInstallationDateTime(r.created_at)}</small>
        <p><b>Diagnosis:</b> {r.diagnosis}</p>
        {r.work_performed && <p><b>Work performed:</b> {r.work_performed}</p>}
        {r.parts_required && <p><b>Parts needed:</b> {r.parts_required}</p>}
        {r.recommendation && <p><b>Next steps:</b> {r.recommendation}</p>}
      </article>)}
    </div>
    {role === 'technician' && !closed && <form className="report-form" onSubmit={submit}>
      <h4>Submit a work report</h4>
      <div className="report-form-grid">
        <label>Work progress<select value={form.progress} onChange={e => setForm(p => ({...p, progress:e.target.value}))}>
          {progressStates.map(([value,label]) => <option key={value} value={value}>{label}</option>)}
        </select></label>
        <label>Result / finding<select value={form.outcome} onChange={e => setForm(p => ({...p, outcome:e.target.value}))}>
          {outcomes.map(([value,label]) => <option key={value} value={value}>{label}</option>)}
        </select></label>
      </div>
      <label>Problem / diagnosis *<textarea required maxLength={10000} rows={3} value={form.diagnosis} onChange={e => setForm(p => ({...p, diagnosis:e.target.value}))} placeholder="Describe what you found, possible cause, troubleshooting…" /></label>
      <label>Work performed {form.progress === 'completed' ? '*' : ''}<textarea required={form.progress === 'completed'} maxLength={10000} rows={3} value={form.work_performed} onChange={e => setForm(p => ({...p, work_performed:e.target.value}))} placeholder="Checks, repairs, replacements and tests completed" /></label>
      <label>Parts needed<input maxLength={500} value={form.parts_required} onChange={e => setForm(p => ({...p, parts_required:e.target.value}))} placeholder="Optional part names or specifications" /></label>
      <label>Recommendation / follow-up<textarea maxLength={10000} rows={2} value={form.recommendation} onChange={e => setForm(p => ({...p, recommendation:e.target.value}))} placeholder="Replacement, return visit, escalations, risk mitigation" /></label>
      <button className="btn btn-primary" type="submit" disabled={busy || !form.diagnosis.trim() || (form.progress === 'completed' && !form.work_performed.trim())}>{busy ? 'Submitting…' : 'Submit report'}</button>
    </form>}
    {role === 'technician' && closed && <p className="muted">This ticket is closed; no further work reports can be submitted.</p>}
    {success && <p className="success-note" role="status">{success}</p>}
    {error && <p className="error" role="alert">{error}</p>}
  </section>;
}
