import { CloseButton } from './ui';
import { Field } from './ui';
import { Button, FileUpload } from './ui';
import { useEffect, useRef, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import { loadPrivateDocument } from '../utils/privateDocuments';

type Document = { id: number; file_name: string; mime_type: string; file_size: number };
const extensions = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt'];

export default function ContractDocuments({ contractId, contractNumber, onClose }: {
  contractId: number; contractNumber: string; onClose: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const [documents, setDocuments] = useState<Document[]>([]);
  const [file, setFile] = useState<File | null>(null);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const [status, setStatus] = useState('');
  const [opening, setOpening] = useState<number | null>(null);
  const [preview, setPreview] = useState<{ url: string; name: string } | null>(null);
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview.url); }, [preview]);
  const endpoint = `/contracts/${contractId}/documents`;
  useEffect(() => {
    dialog.current?.showModal();
    const controller = new AbortController();
    client.get(endpoint, { signal: controller.signal }).then(({ data }) => setDocuments(data.data))
      .catch(error => { if (!controller.signal.aborted) setError(getApiErrorMessage(error, 'Could not load contract documents.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [endpoint]);
  async function upload(event: React.FormEvent) {
    event.preventDefault();
    if (!file) return;
    setError(''); setStatus('');
    if (!extensions.includes(file.name.split('.').pop()?.toLowerCase() || '')) {
      setError('Choose a PDF, DOC, DOCX, ODT, RTF, or TXT file.'); return;
    }
    if (file.size > 10 * 1024 * 1024) { setError('The document must be 10 MB or smaller.'); return; }
    setUploading(true);
    try {
      const body = new FormData(); body.append('file', file);
      const { data } = await client.post(endpoint, body);
      setDocuments(current => [data.data, ...current]);
      setFile(null); if (input.current) input.current.value = '';
      setStatus('Document uploaded.');
    } catch (error) { setError(getApiErrorMessage(error, 'Document upload failed.')); }
    finally { setUploading(false); }
  }
  async function openDocument(document: Document, download: boolean) {
    if (opening !== null) return;
    setOpening(document.id); setError(''); setStatus('');
    try {
      const blob = await loadPrivateDocument(() => client.get<Blob>(`${endpoint}/${document.id}`, {
        responseType: 'blob', params: download ? { download: 1 } : undefined,
      }));
      const url = URL.createObjectURL(blob);
      if (!download && document.mime_type === 'application/pdf') {
        setPreview({ url, name: document.file_name });
      } else {
        const link = window.document.createElement('a');
        link.href = url; link.download = document.file_name;
        window.document.body.appendChild(link); link.click(); link.remove();
        // Give the browser time to consume the download before releasing its URL.
        window.setTimeout(() => URL.revokeObjectURL(url), 60000);
        setStatus('Download started.');
      }
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Could not open the document. Please try again.');
    } finally { setOpening(null); }
  }
  return <dialog ref={dialog} className="contract-documents" aria-labelledby="contract-documents-title" onCancel={onClose} onClose={onClose}>
    <div className="drawer-top"><h2 id="contract-documents-title">Documents · {contractNumber}</h2><CloseButton type="button" onClick={onClose} aria-label="Close contract documents"/></div>
    <div className="contract-documents-body">
      <form onSubmit={event => void upload(event)}>
        <Field htmlFor="contract-document-file">Upload document</Field>
        <FileUpload ref={input} id="contract-document-file" type="file" accept=".pdf,.doc,.docx,.odt,.rtf,.txt" required disabled={uploading} onChange={event => { setFile(event.target.files?.[0] || null); setError(''); setStatus(''); }}/>
        <p>PDF, DOC, DOCX, ODT, RTF, TXT · Maximum 10 MB per file</p>
        <Button type="submit" className="btn btn-primary" disabled={!file || uploading}>{uploading ? 'Uploading…' : 'Upload'}</Button>
      </form>
      {error && <p className="error" role="alert">{error}</p>}
      <p role="status">{status}</p>
      {loading ? <p role="status">Loading documents…</p> : documents.length === 0 ? <p>No documents attached to this contract.</p> : <ul>
        {documents.map(document => <li key={document.id}><span><b>{document.file_name}</b><small>{(document.file_size / 1024).toFixed(1)} KB</small></span>
          {document.mime_type === 'application/pdf' && <Button type="button" className="btn btn-secondary" disabled={opening !== null} onClick={() => void openDocument(document, false)}>View PDF</Button>}
          <Button type="button" className="btn btn-secondary" disabled={opening !== null} onClick={() => void openDocument(document, true)}>{opening === document.id ? 'Opening…' : 'Download'}</Button>
        </li>)}
      </ul>}
      {preview && <section className="contract-document-preview" aria-label={`Preview of ${preview.name}`}>
        <div><b>{preview.name}</b><Button type="button" className="btn btn-secondary" onClick={() => setPreview(null)}>Close preview</Button></div>
        <iframe title={`PDF preview: ${preview.name}`} src={preview.url}/>
        <p>If your browser cannot preview this PDF, use Download.</p>
      </section>}
    </div>
  </dialog>;
}
