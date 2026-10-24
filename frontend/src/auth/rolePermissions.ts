import type { Role } from '../data';

export const ROLE_LABELS: Readonly<Record<Role, string>> = {
  super_admin: 'Super Admin',
  admin: 'Contractor Admin',
  customer_admin: 'Customer Admin',
  customer_user: 'Customer User',
  technician: 'Technician',
};

/** Navigation is presentation-only; Laravel middleware and service policies authorize all requests. */
export const ROLE_PAGES: Readonly<Record<Role, readonly string[]>> = {
  super_admin: ['Monitoring', 'System Support', 'Admin Accounts', 'My Profile', 'My Security', 'About'],
  admin: ['Overview', 'Tickets', 'Customers', 'Categories', 'Contracts', 'Technicians', 'User Management', 'Client Security', 'Authentication', 'Email Delivery', 'Reports', 'My Profile', 'My Security', 'About'],
  technician: ['Overview', 'My Inbox', 'My Profile', 'My Security', 'About'],
  customer_admin: ['Overview', 'Tickets', 'Assets', 'User Management', 'Reports', 'My Profile', 'My Security', 'About'],
  customer_user: ['Overview', 'Tickets', 'My Assets', 'My Profile', 'My Security', 'About'],
};

export function canViewPage(role: Role, page: string, features: {maintenance_enabled?: boolean} = {}): boolean {
  if (page === 'Maintenance') return role === 'admin' && features.maintenance_enabled === true;
  return ROLE_PAGES[role]?.includes(page) ?? false;
}

export function canCreateTicket(role: Role, actorRole: Role = role): boolean {
  return ['customer_admin', 'customer_user'].includes(role) && ['customer_admin', 'customer_user'].includes(actorRole);
}
