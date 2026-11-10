/** Public ticket label. The database reference remains available for older links. */
export function formatTicketNumber(id: number, createdAt: string): string {
  const year = createdAt.slice(0, 4);
  return `#TK${year}${String(id).padStart(6, '0')}`;
}
