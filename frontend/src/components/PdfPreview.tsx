import { useFeedbackState } from './feedbackContext';
import { useEffect, useRef, useState } from 'react';
import { Button, InlineNotice, UIState } from './ui';
import type { PDFDocumentProxy } from 'pdfjs-dist';

/** Canvas-only preview: no embedded browser editor, annotations, forms or PDF scripts. */
export default function PdfPreview({ url, name }: { url: string; name: string }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const container = useRef<HTMLDivElement>(null);
  const [document, setDocument] = useState<PDFDocumentProxy | null>(null);
  const [page, setPage] = useState(1);
  const [width, setWidth] = useState(0);
  const [error, setError] = useFeedbackState('error');
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    const observer = new ResizeObserver(entries => setWidth(Math.floor(entries[0].contentRect.width)));
    if (container.current) observer.observe(container.current);
    return () => observer.disconnect();
  }, []);
  useEffect(() => {
    let cancelled = false;
    let task: ReturnType<typeof import('pdfjs-dist').getDocument> | undefined;
    setDocument(null); setPage(1); setError(''); setLoading(true);
    void import('pdfjs-dist').then(async pdf => {
      if (cancelled) return;
      pdf.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).href;
      task = pdf.getDocument({ url, enableXfa: false });
      const loaded = await task.promise;
      if (!cancelled) setDocument(loaded);
    }).catch(() => { if (!cancelled) { setError('Could not preview this PDF. Use Download to open it.'); setLoading(false); } });
    return () => { cancelled = true; void task?.destroy(); };
  }, [url]);
  useEffect(() => {
    if (!document || !width || !canvas.current) return;
    let cancelled = false;
    let render: ReturnType<Awaited<ReturnType<PDFDocumentProxy['getPage']>>['render']> | undefined;
    setLoading(true);
    void document.getPage(page).then(async pdfPage => {
      if (cancelled || !canvas.current) return;
      const viewport = pdfPage.getViewport({ scale: Math.min(width / pdfPage.getViewport({ scale: 1 }).width, 2) });
      const ratio = Math.min(window.devicePixelRatio || 1, 2);
      const element = canvas.current;
      element.width = Math.floor(viewport.width * ratio); element.height = Math.floor(viewport.height * ratio);
      element.style.width = `${viewport.width}px`; element.style.height = `${viewport.height}px`;
      render = pdfPage.render({ canvas: element, viewport, transform: [ratio, 0, 0, ratio, 0, 0] });
      await render.promise;
      if (!cancelled) setLoading(false);
    }).catch(() => { if (!cancelled) { setError('Could not render this page. Use Download to open it.'); setLoading(false); } });
    return () => { cancelled = true; render?.cancel(); };
  }, [document, page, width]);
  return <div ref={container} className="pdf-preview">
    {document && <nav className="ui-pagination" aria-label="PDF pages"><Button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)}>Previous</Button><span aria-live="polite">Page {page} of {document.numPages}</span><Button type="button" disabled={page >= document.numPages} onClick={() => setPage(value => value + 1)}>Next</Button></nav>}
    {error ? <InlineNotice tone="error">{error}</InlineNotice> : <>{loading && <UIState tone="loading">Loading PDF…</UIState>}<canvas ref={canvas} role="img" aria-label={`${name}, page ${page}`} hidden={loading}/></>}
  </div>;
}
