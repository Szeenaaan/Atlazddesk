import { getApiErrorMessage } from './contracts.ts';

export type Designation = { id: number; name: string; level: number; is_active: boolean };
export type Escalation = {
  id: number; reference?: string; ticket_id: number; ticket_number?: string; subject?: string;
  requester_name: string; current_designation?: Pick<Designation, 'name' | 'level'> | null;
  previous_designation_name: string; previous_level: number;
  state: 'pending' | 'approved' | 'rejected'; reason: string; admin_note?: string | null;
  selected_employee_name?: string | null; selected_designation_name: string | null;
  reviewer_name: string | null; created_at: string; decided_at: string | null;
};
export type EligibleEmployee = { id: number; name: string; designation: Designation };

export function getWorkforceErrorMessage(error: unknown, action: string): string {
  const response = (error as { response?: { status?: number; data?: { statuscode?: number; request_id?: unknown } } })?.response;
  if ((response?.status ?? response?.data?.statuscode ?? 0) < 500) {
    return getApiErrorMessage(error, `${action} Try again or contact Admin.`);
  }
  const reference = response?.data?.request_id;
  const safeReference = typeof reference === 'string' && /^[a-zA-Z0-9-]{8,64}$/.test(reference)
    ? ` Reference: ${reference}.` : '';
  return `${action} Try again. If the problem continues, contact Admin.${safeReference}`;
}

export function canRequestEscalation(role: string, userId: number | undefined, assignedTo: number | null, status: string, pending: boolean): boolean {
  return role === 'technician' && userId === assignedTo && !pending
    && ['new', 'assigned', 'in_progress', 'waiting_customer', 'waiting_vendor', 'waiting_parts'].includes(status.toLowerCase().replaceAll(' ', '_'));
}
