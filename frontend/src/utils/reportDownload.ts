import { client } from '../api/client';

export const REPORT_EXPORTS = [
  { format: 'csv', label: 'CSV' },
  { format: 'xlsx', label: 'Excel (.xlsx)' },
  { format: 'docx', label: 'Word (.docx)' },
  { format: 'pdf', label: 'PDF' },
] as const;
export type ReportFormat = typeof REPORT_EXPORTS[number]['format'];

export function downloadBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const element = document.createElement('a');
  element.href = url;
  element.download = name;
  document.body.appendChild(element);
  element.click();
  element.remove();
  setTimeout(() => URL.revokeObjectURL(url), 60000);
}

export async function downloadReport(path: string, params: Record<string, unknown>, format: ReportFormat, name: string) {
  const response = await client.get(`${path}.${format}`, { params, responseType: 'blob', timeout: 60000 });
  downloadBlob(format === 'pdf' ? new Blob([response.data], { type: 'application/octet-stream' }) : response.data, `${name}.${format}`);
}
