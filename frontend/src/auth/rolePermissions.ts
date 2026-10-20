import type { Role } from '../data';

/** Navigation is presentation-only; Laravel middleware and service policies authorize all requests. */
export const ROLE_PAGES: Readonly<Record<Role, readonly string[]>> = {
  super_admin: ['Monitoring', 'System Support', 'Admin Accounts', 'My Profile', 'My Security', 'About'],
  admin: ['Overview', 'Tickets', 'Customers', 'Assets', 'Contracts', 'Maintenance', 'Technicians', 'User Management', 'Client Security', 'Authentication', 'Email Delivery', 'Reports', 'My Profile', 'My Security', 'About'],
  technician: ['Overview', 'My Inbox', 'Assets', 'Maintenance', 'My Profile', 'My Security', 'About'],
  customer_admin: ['Overview', 'Tickets', 'Assets', 'User Management', 'Reports', 'My Profile', 'My Security', 'About'],
  customer_user: ['Overview', 'Tickets', 'My Assets', 'My Profile', 'My Security', 'About'],
};

export function canViewPage(role: Role, page: string): boolean {
  return ROLE_PAGES[role]?.includes(page) ?? false;
}
