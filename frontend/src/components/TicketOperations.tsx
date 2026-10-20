import { useEffect, useRef, useState } from 'react';
import { client, DEMO } from '../api/client';
import type { Role } from '../data';

type Attachment = { id: number; file_name: string; file_size: number; mime_type: string };
type WorkLog = { id: number; description: string; duration_minutes: number; start_time: string };

type Props = {
  ticketId: number;
  role: Role;
};

export default function TicketOperations({ ticketId, role }: Props) {
  const isCustomer = role.startsWith('customer');
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  const [workLogs, setWorkLogs] = useState<WorkLog[]>([]);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [description, setDescription] = useState('');
  const [startTime, setStartTime] = useState('');
  const [endTime, setEndTime] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);
  const [selectedFile, setSelectedFile] = useState('');
  const [operationError, setOperationError] = useState(false);

  const load = async () => {
    if (DEMO) return;
    try {
      const files = await client.get<Attachment[]>(`/tickets/${ticketId}/attachments`);
      setAttachments(files.data);
      if (!isCustomer) {
        const logs = await client.get<{ data: WorkLog[] }>(`/tickets/${ticketId}/work-logs`);
        setWorkLogs(logs.data.data);
      }
    } catch {
      setMessage('Unable to retrieve ticket operations.');
    }
  };

  useEffect(() => {
    setAttachments([]);
    setWorkLogs([]);
    setMessage('');
    setSelectedFile('');
    setOperationError(false);
    void load();
    // This effect intentionally follows the selected ticket and signed-in role.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ticketId, role]);

  const upload = async () => {
    const file = fileRef.current?.files?.[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
      setOperationError(true);
      setMessage('The file must be 10 MB or smaller.');
      return;
    }
    const form = new FormData();
    form.append('file', file);
    setBusy(true);
    setMessage('');
    setOperationError(false);
    try {
      await client.post(`/tickets/${ticketId}/attachments`, form);
      if (fileRef.current) fileRef.current.value = '';
      setSelectedFile('');
      setMessage('Attachment uploaded.');
      await load();
    } catch (error: any) {
      setOperationError(true);
      setMessage(error.response?.data?.message || 'Unable to upload the attachment.');
    } finally {
      setBusy(false);
    }
  };

  const download = async (file: Attachment) => {
    try {
      const { data } = await client.get(`/tickets/${ticketId}/attachments/${file.id}/download`, {
        responseType: 'blob',
      });
      const url = URL.createObjectURL(data);
      const element = document.createElement('a');
      element.href = url;
      element.download = file.file_name;
      element.click();
      URL.revokeObjectURL(url);
    } catch {
      setMessage('Download failed.');
    }
  };

  const addLog = async () => {
    if (!description.trim() || !startTime || !endTime) {
      setMessage('Start, end and description are required.');
      return;
    }
    setBusy(true);
    try {
      await client.post(`/tickets/${ticketId}/work-logs`, {
        start_time: startTime,
        end_time: endTime,
        description,
      });
      setDescription('');
      setMessage('Work log recorded.');
      await load();
    } catch (error: any) {
      setMessage(error.response?.data?.message || 'Unable to save work log.');
    } finally {
      setBusy(false);
    }
  };

  if (DEMO) return null;

  return (
    <section className="ticket-operations">
      <h3>Private attachments</h3>

      <div className="amc-attachment-picker">
        <input ref={fileRef} id={`ticket-file-${ticketId}`} className="amc-file-input" type="file"
          accept=".pdf,.png,.jpg,.jpeg,.txt,.docx,.xlsx" aria-label="Select attachment"
          onChange={e => { setSelectedFile(e.target.files?.[0]?.name || ''); setOperationError(false); setMessage(''); }}/>
        <button className="btn btn-secondary" type="button" onClick={() => fileRef.current?.click()} disabled={busy}>Choose file</button>
        <span className="amc-attachment-name" title={selectedFile}>{selectedFile || 'No file selected'}</span>
        <button className="btn btn-primary" type="button" disabled={busy || !selectedFile} onClick={upload}>{busy ? 'Uploading…' : 'Upload'}</button>
      </div>
      {attachments.map(file => (
        <div className="operation-row" key={file.id}>
          <span>{file.file_name} ({Math.round(file.file_size / 1024)} KB)</span>
          <button className="btn btn-secondary" onClick={() => download(file)}>Download</button>
        </div>
      ))}
      {!isCustomer && (
        <>
          <h3>Technician work logs</h3>
          {workLogs.length === 0 && <p className="amc-muted-empty">No work logs recorded.</p>}
          <div className="amc-worklog-list">{workLogs.map(log => (
            <div className="amc-worklog-item" key={log.id}><span>{log.description}</span><small>{log.duration_minutes} min</small></div>
          ))}</div>
          {role === 'technician' && <><label>Start time<input type="datetime-local" value={startTime} onChange={e => setStartTime(e.target.value)} /></label>
          <label>End time<input type="datetime-local" value={endTime} onChange={e => setEndTime(e.target.value)} /></label>
          <label>Work description<textarea value={description} onChange={e => setDescription(e.target.value)} /></label>
          <button className="btn btn-secondary" disabled={busy} onClick={addLog}>Add work log</button></>}
        </>
      )}
      {message && <p className={operationError ? "amc-field-error" : "amc-operation-feedback"} role={operationError ? "alert" : "status"}>{message}</p>}
    </section>
  );
}
