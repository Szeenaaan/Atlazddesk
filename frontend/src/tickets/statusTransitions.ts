import type { Role } from '../data';

/** Mirrors TicketService::TRANSITIONS. UI affordances never replace backend authorization. */
const next: Record<string, string[]> = {
  new: ['assigned','in_progress','cancelled'],
  assigned: ['in_progress','waiting_customer','waiting_vendor','waiting_parts','cancelled'],
  in_progress: ['waiting_customer','waiting_vendor','waiting_parts','resolved'],
  waiting_customer: ['in_progress','resolved'],
  waiting_vendor: ['in_progress','resolved'],
  waiting_parts: ['in_progress','resolved'],
  resolved: ['customer_confirmation','in_progress','closed'],
  customer_confirmation: ['closed','in_progress'],
  closed: [], cancelled: [],
};
const title = (slug: string) => slug.split('_').map(w => w[0].toUpperCase()+w.slice(1)).join(' ');
export function statusOptions(role: Role, current: string): string[] {
  const key = current.toLowerCase().trim().replaceAll(' ','_');
  let allowed = next[key] || [];
  if (role === 'technician') allowed = allowed.filter(v => !['cancelled','closed'].includes(v));
  else if (role === 'customer_user' || role === 'customer_admin') {
    allowed = key === 'resolved' ? ['customer_confirmation','in_progress']
      : key === 'customer_confirmation' ? ['closed','in_progress'] : [];
  }
  else if (role !== 'admin') allowed = [];
  return [current, ...allowed.map(title)];
}
