import { CloseButton } from './components/ui';
import { Field } from './components/ui';
import { Dialog } from './components/ui/Dialog';
import { Badge } from './components/ui';
import { Button, Card, Checkbox, Input, Textarea } from './components/ui';
import { DASHBOARD_PERIODS, inDashboardPeriod } from './utils/dashboardPeriod';
import SearchableDropdown from './components/SearchableDropdown';
import SortableTable from './components/SortableTable';
import ContractEditor from './components/ContractEditor';
import ContractCatalog from './components/ContractCatalog';
import SlidingDrawer from './components/SlidingDrawer';
import RecordDetails from './components/RecordDetails';
import ContractDocuments from './components/ContractDocuments';
import ThemeToggle from './components/ThemeToggle';
import About from './components/About';
import CategoryManager from './components/CategoryManager';
import DirectoryList from './components/DirectoryList';
import React, { useEffect, useState } from 'react';
import { DEMO, client, getCsrfCookie } from './api/client';
import { getApiErrorMessage } from './api/contracts';
import TicketOperations from './components/TicketOperations';
import TicketWorkReports from './components/TicketWorkReports';
import ContractorAdminAccounts from './components/ContractorAdminAccounts';
import SystemSupport from './components/SystemSupport';
import RetentionSettings from './components/RetentionSettings';
import { Info, LayoutDashboard, Ticket as TicketIcon, Building2, HardDrive, FileText, Wrench, Users, BarChart3, Activity, Settings, Search, ChevronRight, Menu, X, LogOut, ArrowUpRight, AlertTriangle, CheckCircle2, ShieldCheck, RefreshCw, Send, Database, Server, Wifi, CalendarDays, Command, UserCircle, LockKeyhole } from 'lucide-react';
import { AreaChart, Area, ResponsiveContainer, Tooltip, XAxis, CartesianGrid, PieChart, Pie, Cell } from 'recharts';
import { assets, contracts, customers, maintenance, seedTickets, technicians, Ticket, RecordItem, Role } from './data';
import MfaLogin, { type MfaChallenge, type MfaLoginResult } from './components/MfaLogin';
import FeedbackToast, { type FeedbackTone } from './components/FeedbackToast';
import { ContractorMfaSettings, MyMfaSettings } from './components/MfaSecuritySettings';
import UserDirectory from './components/UserDirectory';
import ClientOnboarding from './components/ClientOnboarding';
import ClientMfaPolicies from './components/ClientMfaPolicies';
import EmailDeliverySettings from './components/EmailDeliverySettings';
import NotificationsPanel from './components/NotificationsPanel';
import ProfileMenu from './components/ProfileMenu';
import QuickNavigator from './components/QuickNavigator';
import MyProfile from './components/MyProfile';
import SearchableSelect, { type SelectOption } from './components/SearchableSelect';
import { canViewPage } from './auth/rolePermissions';
import { formatInstallationDate, formatInstallationDateTime, formatInstallationTime, getInstallationTimezone, setInstallationTimezone } from './utils/installationTime';
import { statusOptions } from './tickets/statusTransitions';
const AMC_BRAND_NAME = String(import.meta.env.VITE_APP_NAME || 'Atlazddesk').trim() || 'Atlazddesk';
const WORKSPACE_LABELS: Record<Role, [string, string]> = {
    super_admin: ['Technical administration', 'Diagnostics & configuration'],
    admin: ['Contractor operations', 'Clients & field services'],
    customer_admin: ['Organization workspace', 'Assets, users & service requests'],
    customer_user: ['Service portal', 'Your service requests'],
    technician: ['Field service', 'Assigned jobs & work reports'],
};
const PAGE_DESCRIPTIONS: Record<string, string> = {
    Tickets: 'Track service requests and their progress.',
    'My Inbox': 'Review assigned jobs and submit work updates.',
    Customers: 'Onboard and manage client organizations.',
    Assets: 'Maintain equipment records and service history.',
    'My Assets': 'Equipment assigned to you.',
    'Contracts': 'Manage maintenance agreements and coverage terms.',
    Maintenance: 'Plan and track maintenance visits.',
    Technicians: 'Manage staff and freelance service technicians.',
    'User Management': 'Manage authorized accounts and access.',
    'Client Security': 'Configure Google Authenticator and Email OTP for individual clients.',
    'Authentication': 'Enable sign-in protection and authenticator enrollment for your workforce.',
    'Email Delivery': 'Manage email providers and choose delivery routes for clients.',
    Reports: 'Review service activity and export ticket records.',
    Monitoring: 'Monitor application health, incidents and availability.',
    'System Support': 'Inspect diagnostics and troubleshoot installation services.',
    Settings: 'Configure system authentication and preferences.',
    'Admin Accounts': 'Provision contractor administrators for this installation.',
    About: 'Product information and contact details.',
    'My Profile': 'View and update your account information.',
    'My Security': 'Manage your sign-in security and MFA enrollment.',
};
const nav = [{ group: 'WORKSPACE' , items: [['Overview', LayoutDashboard], ['My Inbox', TicketIcon], ['Tickets', TicketIcon], ['Customers', Building2], ['Assets', HardDrive], ['My Assets', HardDrive], ['Contracts', FileText]] }, { group: 'OPERATIONS', items: [['Maintenance', Wrench], ['Technicians', Users], ['User Management', Users], ['Client Security', ShieldCheck], ['Authentication', LockKeyhole], ['Email Delivery', Send], ['Reports', BarChart3], ['Monitoring', Activity]] }, { group: 'SYSTEM', items: [['Settings', Settings], ['System Support', Wrench], ['Admin Accounts', Users], ['My Profile', UserCircle], ['My Security', LockKeyhole], ['About', Info]] }] as const;
const initialTickets = () => {
    try {
        return JSON.parse(localStorage.getItem('amc_demo_tickets') || 'null') || seedTickets;
    }
    catch {
        return seedTickets;
    }
};
const initials = (s: string) => s.split(' ').map(x => x[0]).slice(0, 2).join('').toUpperCase();
const dt = (s: string) => {
    try {
        return formatInstallationDateTime(s);
    }
    catch {
        return s;
    }
};
const calendarDate = (value: unknown): string => {
    if (value === null || value === undefined || value === '') return '—';
    const match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})/.exec(String(value));
    if (!match) return '—';
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' });
};
const badge = (v: string) => { const k = v.toLowerCase().replace(/\s/g, '-'); return <Badge className={'badge ' + (k.includes('critical') || k.includes('overdue') || k.includes('expiring') ? 'red' : k.includes('high') || k.includes('waiting') || k.includes('due') ? 'amber' : k.includes('resolved') || k.includes('active') || k.includes('operational') || k.includes('healthy') || k.includes('closed') ? 'green' : k.includes('progress') ? 'blue' : 'gray')}>{v}</Badge>; };
function App() {
    const [workspaceLoaded, setWorkspaceLoaded] = useState(DEMO);
    const [incidents, setIncidents] = useState<any[]>([]);
    const [incidentError, setIncidentError] = useState('');
    const [quickOpen, setQuickOpen] = useState(false);
    const [remoteTickets, setRemoteTickets] = useState<Ticket[] | null>(null);
    const [searchBusy, setSearchBusy] = useState(false);
    const [workspaceError, setWorkspaceError] = useState('');
    const [priorityFilter, setPriorityFilter] = useState('All priorities');
    const [recordDetails, setRecordDetails] = useState<{ resource: 'customers' | 'assets' | 'contracts' | 'technicians'; record: RecordItem } | null>(null);
    const [contractDocuments, setContractDocuments] = useState<{ id: number; number: string } | null>(null);
    const [systemMetrics, setSystemMetrics] = useState<Record<string, string | number> | null>(null);
    const [page, setPage] = useState('Overview'), [tickets, setTickets] = useState<Ticket[]>(() => DEMO ? initialTickets() : []), [records, setRecords] = useState<Record<string, RecordItem[]>>(() => DEMO ? { Customers: customers, Assets: assets, 'Contracts': contracts, Maintenance: maintenance, Technicians: technicians } : { Customers: [], Assets: [], 'Contracts': [], Maintenance: [], Technicians: [] }), [role, setRole] = useState<Role>('super_admin'), [logged, setLogged] = useState(DEMO), [restoring, setRestoring] = useState(!DEMO), [currentUser, setCurrentUser] = useState<{
        id: number;
        name: string;
        email: string;
        role: Role;
        customer_id: number | null;
    } | null>(null), [lookups, setLookups] = useState<any>({ customers: [], categories: [], priorities: [], asset_types: [], sla_policies: [], technicians: [] }), [auth, setAuth] = useState({ email: '', password: '' }), [mfaPending, setMfaPending] = useState<MfaChallenge | null>(null), [authError, setAuthError] = useState(''), [query, setQuery] = useState(''), [statusFilter, setStatusFilter] = useState('All statuses'), [selected, setSelected] = useState<Ticket | null>(null), [modal, setModal] = useState<string | null>(null), [draft, setDraft] = useState<Record<string, string>>({}), [side, setSide] = useState(false), [notice, setNotice] = useState(''), [health, setHealth] = useState<any>(null), [loading, setLoading] = useState(false), [report, setReport] = useState<{
        timeframe?: string;
        priorities: Record<string, number>;
        recent_tickets: Ticket[];
        total: number;
        open: number;
        resolved: number;
        critical_open: number;
        unassigned_open: number;
        daily: {
            day: string;
            at?: string;
            tickets: number;
            resolved: number;
        }[];
    } | null>(null);
    const [, refreshDisplayTime] = useState(0);
    useEffect(() => {
        const rerender = () => refreshDisplayTime(n => n + 1);
        window.addEventListener('amc:timezone-updated', rerender);
        return () => window.removeEventListener('amc:timezone-updated', rerender);
    }, []);
    useEffect(() => {
        if (!logged || DEMO) return;
        let cancelled = false;
        const updateTimezone = async () => {
            try {
                const {data} = await client.get('/installation/timezone');
                if (!cancelled) setInstallationTimezone(data.data.timezone, data.data.country_code);
            } catch { if (!cancelled) setWorkspaceError('Could not refresh installation timezone. Display retains the last loaded timezone.'); }
        };
        const onVisible = () => { if (document.visibilityState === 'visible') void updateTimezone(); };
        void updateTimezone();
        const interval = window.setInterval(() => { if (document.visibilityState === 'visible') void updateTimezone(); }, 60000);
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('focus', onVisible);
        return () => { cancelled = true; window.clearInterval(interval); document.removeEventListener('visibilitychange', onVisible); window.removeEventListener('focus', onVisible); };
    }, [logged]);
    useEffect(() => {
        const interval = window.setInterval(() => refreshDisplayTime(v => v + 1), 60000);
        return () => window.clearInterval(interval);
    }, []);
    const [noticeTone, setNoticeTone] = useState<FeedbackTone>('success');
    const customerId = currentUser?.customer_id ?? 1;
    const visibleTickets = DEMO ? tickets.filter(t => role.startsWith('customer') ? t.customer_id === customerId : role === 'technician' ? t.assigned_to === 2 : true) : tickets;
    const [directoryGrid, setDirectoryGrid] = useState<{page: string; rows: RecordItem[]; last: number; number: number} | null>(null);
    const [directorySort, setDirectorySort] = useState<{page: string; sort: string; direction: string} | null>(null);
    const [directoryPage, setDirectoryPage] = useState(1);
    useEffect(() => { setDirectoryPage(1); }, [page, query]);
    useEffect(() => {
        if (DEMO || !logged || !['Contracts', 'Maintenance', 'Technicians'].includes(page)) return;
        let cancelled = false;
        const timer = window.setTimeout(async () => {
            try {
                const { data } = await client.get(`/directory/${page.toLowerCase()}`, { params: {
                    page: directoryPage, per_page: 25, status: page === 'Technicians' ? 'active' : undefined, search: query.trim() || undefined,
                    ...(directorySort?.page === page ? {sort: directorySort.sort, direction: directorySort.direction} : {}),
                }});
                if (!cancelled) setDirectoryGrid({page, rows: data.data.map((row: any) => ({...row, customer_name: row.customer?.company_name})), last: data.last_page, number: directoryPage});
            } catch { if (!cancelled) { setDirectoryGrid({page, rows: [], last: 1, number: 1}); setWorkspaceError('Unable to load records. Please retry.'); } }
        }, 250);
        return () => { cancelled = true; window.clearTimeout(timer); };
    }, [page, query, directorySort, directoryPage, logged, records]);
    const visibleRecords = (key: string) => DEMO ? (records[key]?.filter(r => role.startsWith('customer') ? r.customer_id === customerId : true) || []) : (directoryGrid?.page === key ? directoryGrid.rows : records[key] || []);
    // React visibility is presentation only. All privileges are enforced again in Laravel.
    const isStaff = role === 'admin';
    const canCreateTicket = isStaff || role === 'customer_admin' || role === 'customer_user';
    const [features, setFeatures] = useState<{maintenance_enabled?: boolean}>({});
    const canAccessPage = (name: string): boolean => canViewPage(role, name, features);
    const maintenanceDisabled = (name: string): boolean => name === 'Maintenance' && !features.maintenance_enabled;
    useEffect(() => {
        if (DEMO)
            localStorage.setItem('amc_demo_tickets', JSON.stringify(tickets));
    }, [tickets]);
    // Refresh assignable users when opening a ticket; the directory also includes inactive accounts.
    useEffect(() => {
        if (DEMO || !selected || role !== 'admin') return;
        let cancelled = false;
        setLookups((current: any) => ({ ...current, technicians: [] }));
        client.get('/lookups').then(({data}) => {
            if (!cancelled) setLookups((current: any) => ({ ...current, technicians: data.technicians || [] }));
        }).catch(() => { if (!cancelled) setNotice('Unable to load active technicians. Reopen the ticket to retry.'); });
        return () => { cancelled = true; };
    }, [selected?.id, role]);
    const refreshWorkspace = async () => { if (role === 'super_admin') return; const [{ data }, { data: options }] = await Promise.all([client.get('/bootstrap'), client.get('/lookups')]); setFeatures(data.features || {}); setTickets(data.tickets || []); setRecords({ Customers: data.customers || [], Assets: data.assets || [], 'Contracts': data.contracts || [], Maintenance: data.maintenance || [], Technicians: data.technicians || [] }); setRole(data.user.role); setCurrentUser(data.user); setLookups(options); setWorkspaceLoaded(true); };
    useEffect(() => {
        if (DEMO)
            return;
        client.get('/auth/me').then(({ data }) => { setRole(data.user.role); setCurrentUser(data.user); setPage(data.user.role === 'super_admin' ? 'Monitoring' : data.user.role === 'technician' ? 'My Inbox' : 'Overview'); setLogged(true); }).catch((error: unknown) => {
          const code = (error as { response?: { data?: { code?: string } } }).response?.data?.code;
          setLogged(false);
          const status = (error as { response?: { status?: number } }).response?.status;
          setAuthError(status === 401 ? '' : code === 'MFA_REQUIRED'
              ? 'Additional verification is required. Sign in to continue.'
              : 'Could not restore your session. Sign in or check backend availability.');
        }).finally(() => setRestoring(false));
    }, []);
    useEffect(() => {
        if (!DEMO && logged) {
            if (role !== 'super_admin') refreshWorkspace().then(() => setWorkspaceError('')).catch(() => setWorkspaceError('Workspace could not be refreshed. Check the API connection and retry.'));
            else client.get('/admin/metrics').then(({ data }) => setSystemMetrics(data)).catch(() => setWorkspaceError('System metrics unavailable.'));
        }
    }, [logged, role]);
    const alert = (s: string, tone: FeedbackTone = 'success') => { setNoticeTone(tone); setNotice(s); };
    const go = (p: string) => {
        if (maintenanceDisabled(p) || (!DEMO && !canAccessPage(p))) return;
        setPage(p); setSelected(null); setRecordDetails(null); setQuery(''); setSide(false); setQuickOpen(false);
    };
    const allowedPages = nav.flatMap(g => g.items.map(([name]) => name)).filter(name => !maintenanceDisabled(name) && (DEMO || canAccessPage(name)));
    useEffect(() => {
        const shortcut = (event: KeyboardEvent) => {
            if (logged && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault(); setQuickOpen(v => !v);
            }
            if (event.key === 'Escape') { setQuickOpen(false); setSide(false); }
        };
        window.addEventListener('keydown', shortcut);
        return () => window.removeEventListener('keydown', shortcut);
    }, [logged]);
    const [ticketSort, setTicketSort] = useState<{sort?: string; direction?: string}>({});
    useEffect(() => {
        if (DEMO || !logged || !['Tickets','My Inbox'].includes(page)) { setRemoteTickets(null); return; }
        const hasFilter = query.trim() || statusFilter !== 'All statuses' || priorityFilter !== 'All priorities';
        if (!hasFilter && !ticketSort.sort) { setRemoteTickets(null); setSearchBusy(false); return; }
        let cancelled = false;
        const timer = window.setTimeout(async () => {
            setSearchBusy(true);
            try {
                const { data } = await client.get(page === 'My Inbox' ? '/tickets/inbox' : '/tickets', {
                    params: { ...ticketSort, search: query.trim() || undefined,
                        status: statusFilter === 'All statuses' ? undefined : statusFilter,
                        priority: priorityFilter === 'All priorities' ? undefined : priorityFilter,
                        per_page: 100 },
                });
                if (!cancelled) setRemoteTickets(data.data ?? []);
            } catch { if (!cancelled) setWorkspaceError('Ticket search failed. Please retry.'); }
            finally { if (!cancelled) setSearchBusy(false); }
        }, 250);
        return () => { cancelled = true; window.clearTimeout(timer); };
    }, [query, statusFilter, priorityFilter, page, logged, role, ticketSort]);
    const [ticketAssets, setTicketAssets] = useState<RecordItem[]>([]);
    const [assetChoicesBusy, setAssetChoicesBusy] = useState(false);
    useEffect(() => {
        let cancelled = false;
        setTicketAssets([]);
        const customer = draft.customer_id || (role.startsWith('customer') ? currentUser?.customer_id : null);
        const category = lookups.categories.find((c: any) => c.name === draft.category);
        if (DEMO || modal !== 'New ticket' || !customer || !category) { setAssetChoicesBusy(false); return; }
        setAssetChoicesBusy(true);
        void (async () => {
            try {
                const rows: RecordItem[] = [];
                let page = 1, last = 1;
                do {
                    const { data } = await client.get('/directory/assets', { params: { customer_id: customer, category_id: category.id, per_page: 100, page } });
                    rows.push(...data.data); last = data.last_page; page++;
                } while (!cancelled && page <= last);
                if (!cancelled) setTicketAssets(rows);
            } catch { if (!cancelled) setWorkspaceError('Unable to load compatible assets.'); }
            finally { if (!cancelled) setAssetChoicesBusy(false); }
        })();
        return () => { cancelled = true; };
    }, [modal, draft.customer_id, draft.category, currentUser?.customer_id, role, lookups]);
    const filtered = (remoteTickets || visibleTickets).filter(t => (statusFilter === 'All statuses' || t.status === statusFilter) && (priorityFilter === 'All priorities' || t.priority === priorityFilter) && [t.subject, t.ticket_number, t.customer, t.assignee, t.category].join(' ').toLowerCase().includes(query.toLowerCase()));
    const active = visibleTickets.filter(t => !['Closed', 'Resolved', 'Cancelled'].includes(t.status));
    const critical = active.filter(t => t.priority === 'Critical');
    const unassigned = active.filter(t => !t.assigned_to);
    const [dashboardPeriod, setDashboardPeriod] = useState('week');
    const dashboardTickets = DEMO ? visibleTickets.filter(t => inDashboardPeriod(t.created_at, dashboardPeriod)) : report?.recent_tickets || [];
    const count = (p: string) => DEMO ? dashboardTickets.filter(t => t.priority === p).length : Number(report?.priorities?.[p] ?? 0);
    const periodLabel = ({'1h':'Last 1 hour','4h':'Last 4 hours',day:'Last 24 hours',week:'Last 7 days',month:'Last 30 days',year:'Last 365 days'} as Record<string,string>)[dashboardPeriod];
    useEffect(() => {
        if (DEMO || !logged || role === 'super_admin')
            return;
        const controller = new AbortController();
        setReport(null);
        client.get('/reports/summary', {params: page === 'Overview' ? {timeframe: dashboardPeriod} : {}, signal: controller.signal})
            .then(({ data }) => { setReport(data); })
            .catch(() => { if (!controller.signal.aborted) { setReport(null); setWorkspaceError('Could not load ticket statistics. Please retry.'); } });
        return () => controller.abort();
    }, [logged, tickets, role, page, dashboardPeriod]);
    const exportCsv = async () => {
        if (DEMO) {
            const safe = (value: unknown) => {
                const string = String(value ?? '');
                return '"' + (/^[\s]*[=+\-@]/.test(string) ? "'" + string : string).replaceAll('"', '""') + '"';
            };
            const lines = [['Ticket', 'Subject', 'Customer', 'Priority', 'Status', 'Assigned'],
                ...visibleTickets.map(t => [t.ticket_number, t.subject, t.customer, t.priority, t.status, t.assignee])]
                .map(row => row.map(safe).join(',')).join('\n');
            downloadBlob(new Blob([lines], { type: 'text/csv' }), 'atlazddesk-demo-tickets.csv');
            return;
        }
        try {
            const response = await client.get('/reports/tickets.csv', { responseType: 'blob', timeout: 30000 });
            downloadBlob(response.data, 'amc-tickets.csv');
        }
        catch {
            alert('CSV export failed.', 'error');
        }
    };
    const downloadBlob = (blob: Blob, name: string) => {
        const url = URL.createObjectURL(blob);
        const element = document.createElement('a');
        element.href = url;
        element.download = name;
        element.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    const chart = DEMO ? [{day: periodLabel, tickets: dashboardTickets.length, resolved: dashboardTickets.filter(t => ['Resolved','Closed'].includes(t.status)).length}] : (report?.daily || []).map(point => ({...point, day: point.at ? ['1h','4h','day'].includes(dashboardPeriod) ? formatInstallationTime(point.at) : formatInstallationDate(point.at) : point.day}));
    const updateTicket = async (id: number, change: Partial<Ticket>, action?: string) => {
        if (!DEMO) {
            try {
                const { data } = await client.patch(`/tickets/${id}`, change);
                const updated: Ticket = data.data;
                setTickets(prev => prev.map(t => t.id === id ? updated : t));
                setSelected(prev => prev?.id === id ? updated : prev);
                setRemoteTickets(prev => prev?.map(t => t.id === id ? updated : t) ?? null);
                alert(action || 'Ticket updated');
                return;
            }
            catch (e: any) {
                alert(e.response?.data?.message || 'Update failed', 'error');
                return;
            }
        }
        setTickets(prev => prev.map(t => t.id === id ? { ...t, ...change } : t));
        setSelected(prev => prev?.id === id ? { ...prev, ...change } : prev);
        alert(action || 'Ticket updated successfully');
    };
    const save = async () => {
        setLoading(true);
        try {
            if (modal === 'New ticket') {
                if (!draft.subject?.trim() || !draft.description?.trim()) {
                    alert('Subject and description are required', 'error');
                    return;
                }
                if (!draft.asset_id) { alert('Select an asset.', 'error'); return; }
                if (DEMO) {
                    const newT: Ticket = { id: Date.now(), ticket_number: `INC-${new Date().getFullYear()}-${tickets.length + 1}`, subject: draft.subject, description: draft.description, customer_id: Number(draft.customer_id || customerId), customer: customers.find(c => c.id === Number(draft.customer_id || customerId))?.company_name as string || 'Customer', category: draft.category || 'Network', priority: (draft.priority || 'Medium') as Ticket['priority'], status: 'New', assigned_to: null, assignee: 'Unassigned', created_at: new Date().toISOString(), sla_resolution_due_at: new Date(Date.now() + 86400000).toISOString(), asset: '—', comments: [] };
                    setTickets(p => [newT, ...p]);
                }
                else {
                    const category = lookups.categories.find((c: any) => c.name === draft.category);
                    const priority = lookups.priorities.find((c: any) => c.name === draft.priority);
                    await client.post('/tickets', { subject: draft.subject, description: draft.description, customer_id: isStaff ? Number(draft.customer_id) : undefined, priority_id: priority?.id, category_id: category?.id, asset_id: Number(draft.asset_id) });
                    await refreshWorkspace();
                }
                alert('Ticket created successfully');
            }
            else if (modal) {
                const label = modal;
                const arr = records[label] || [];
                if (DEMO) {
                    setRecords(p => ({ ...p, [label]: [{ ...draft, id: Date.now() }, ...arr] }));
                }
                else if (label === 'Technicians') {
                    await client.post('/users', { name: draft.name, email: draft.email, password: draft.password, password_confirmation: draft.password_confirmation, role: 'technician' });
                    await refreshWorkspace();
                }
                else {
                    const endpoint = label === 'Contracts' ? 'contracts' : label.toLowerCase();
                    const body: any = { ...draft };
                    if (endpoint === 'assets') {
                        if (role === 'customer_admin') delete body.customer_id;
                        delete body.type;
                    }
                    if (endpoint === 'maintenance' && body.frequency)
                        body.frequency = body.frequency.toLowerCase();
                    if (body.customer_id)
                        body.customer_id = Number(body.customer_id);
                    if (body.sla_policy_id)
                        body.sla_policy_id = Number(body.sla_policy_id);
                    await client.post(`/directory/${endpoint}`, body);
                    await refreshWorkspace();
                }
                alert(label + ' record created');
            }
            setModal(null);
            setDraft({});
        }
        catch (e: any) {
            const errors = e.response?.data?.errors;
            alert(errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message || 'Save failed', 'error');
        }
        finally {
            setLoading(false);
        }
    };
    const completeSignIn = (user: MfaLoginResult) => {
        setRole(user.role as Role);
        setPage(user.role === 'super_admin' ? 'Monitoring' : user.role === 'technician' ? 'My Inbox' : 'Overview');
        setCurrentUser(user as typeof currentUser);
        setMfaPending(null);
        setWorkspaceLoaded(DEMO);
        setLogged(true);
    };
    const cancelMfa = () => { setMfaPending(null); setAuthError(''); };
    const login = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            setAuthError('');
            await getCsrfCookie();
            const { data } = await client.post('/auth/login', auth);
            setAuth(p => ({ ...p, password: '' }));
            if (data.status === 'mfa_required' || data.status === 'setup_required') {
                setMfaPending(data as MfaChallenge);
                return;
            }
            if (!data.user) throw new Error('Login response did not include a user.');
            completeSignIn(data.user);
        }
        catch (error: unknown) {
            setAuthError(getApiErrorMessage(error, 'Login failed. Check your credentials or connection.'));
        }
    };
    const logout = async () => {
        if (!DEMO) {
            try {
                await client.post('/auth/logout');
            }
            catch (e: any) {
                alert(e.response?.data?.message || 'Logout failed', 'error');
                return;
            }
        }
        setLogged(false);
        setPage('Overview');
        setSelected(null);
        setRecordDetails(null);
        setDraft({});
        setRemoteTickets(null);
        setReport(null);
        setIncidents([]);
        setHealth(null);
        setSystemMetrics(null);
        setWorkspaceError('');
        setMfaPending(null);
        setCurrentUser(null);
        setTickets([]);
        setRecords({ Customers: [], Assets: [], 'Contracts': [], Maintenance: [], Technicians: [] });
    };
    const loadIncidents = async () => {
        if (DEMO || role !== 'super_admin') return;
        try {
            const {data} = await client.get('/admin/incidents?state=open');
            setIncidents(data.data || []);
            setIncidentError('');
        } catch {
            setIncidentError('Could not load incident history.');
        }
    };
    const resolveIncident = async (id: number) => {
        try { await client.patch(`/admin/incidents/${id}/resolve`); await loadIncidents(); }
        catch { setIncidentError('Failed to resolve incident.'); }
    };
    const openNotificationTicket = async (id: number) => {
        if (role === 'technician') go('My Inbox'); else go('Tickets');
        try {
            const { data } = await client.get(`/tickets/${id}`);
            setSelected(data.data);
        } catch { alert('Ticket is no longer available in your assigned workspace.', 'error'); }
    };
    const healthCheck = async () => {
        if (DEMO) {
            setHealth({ status: 'healthy', checks: { application: { status: 'healthy' }, database: { status: 'healthy' }, redis: { status: 'healthy' }, queue: { status: 'healthy' } } });
            return;
        }
        try {
            const { data } = await client.get('/health');
            setHealth(data);
        }
        catch {
            setHealth({ status: 'unhealthy', checks: { api: { status: 'unhealthy' } } });
        }
    };
    useEffect(() => {
        if (page === 'Monitoring') {
            healthCheck();
            loadIncidents();
        }
    }, [page, role]);
    const button = (name: string, fn: () => void, secondary = false) => <Button className={'btn ' + (secondary ? 'btn-secondary' : 'btn-primary')} onClick={fn}>{name}</Button>;
    const openTicket = async (t: Ticket) => {
        if (DEMO) {
            setSelected(t);
            return;
        }
        try {
            const { data } = await client.get(`/tickets/${t.id}`);
            setSelected(data.data);
        }
        catch (e: any) {
            alert(e.response?.data?.message || 'Could not load ticket', 'error');
        }
    };
    const tableTickets = (rows: Ticket[]) => <div className="table-scroll"><SortableTable onSort={!DEMO && ['Tickets', 'My Inbox'].includes(page) ? ({column, direction}) => setTicketSort({sort: ['ticket_number','customer','priority','status','assignee','created_at'][column], direction}) : undefined}><thead><tr><th>Ticket</th><th>Customer</th><th>Priority</th><th>Status</th><th>Assigned to</th><th>Created</th><th></th></tr></thead><tbody>{rows.map(t => <tr key={t.id} onClick={() => openTicket(t)} className="clickable"><td data-sort-value={t.ticket_number}><div className="ticket-id">{t.ticket_number}</div><div className="ticket-subject">{t.subject}</div></td><td>{t.customer}</td><td data-sort-value={{Low: 1, Medium: 2, High: 3, Critical: 4}[t.priority]}>{badge(t.priority)}</td><td>{badge(t.status)}</td><td data-sort-value={t.assignee}><span className="assignee"><span className="avatar tiny">{initials(t.assignee)}</span>{t.assignee}</span></td><td className="muted" data-sort-value={t.created_at}>{dt(t.created_at)}</td><td><ChevronRight size={16}/></td></tr>)}</tbody></SortableTable>{rows.length === 0 && <div className="empty">No tickets match your filters.</div>}</div>;
    const fieldOptions = (type: string): SelectOption[] => {
        const convert = (list: { id: number; name: string }[]) => list.map(c => ({ value: String(c.id), label: String(c.name) }));
        if (type === 'customers') return (DEMO ? customers : lookups.customers || []).map((c: any) => ({ value: String(c.id), label: String(c.company_name) }));
        if (type === 'assetcategories') return convert(lookups.categories || []);
        if (type === 'frequency') return ['daily','weekly','monthly','quarterly','yearly'].map(s => ({ value:s, label:s }));
        if (type === 'category') return (DEMO ? ['Network','Server','Microsoft 365','Firewall','Wi-Fi','Hardware','Other'].map(s => ({ value:s, label:s }))
            : (lookups.categories || []).map((c: {name:string}) => ({ value:c.name, label:c.name })));
        if (type === 'priority') return (DEMO ? ['Low','Medium','High','Critical'].map(s => ({ value:s, label:s }))
            : (lookups.priorities || []).map((c: {name:string}) => ({ value:c.name, label:c.name })));
        return [];
    };
    const openModal = (name: string) => { setDraft({}); setModal(name); };
    const headerAction = () => {
        if (page === 'Tickets' && canCreateTicket) return button('+ Create ticket', () => openModal('New ticket'));
        if (page === 'Customers' && isStaff) return button('+ Register client', () => openModal('Register client'));
        if (page === 'Assets' && (isStaff || role === 'customer_admin')) return button('+ Add asset', () => openModal('Assets'));
        if (page === 'Technicians' && isStaff) return button('+ Add technician', () => openModal('Technicians'));
        if (page === 'Contracts' && isStaff) return button('Add contract', () => openModal(page));
        if (['Maintenance'].includes(page) && isStaff && !maintenanceDisabled(page)) return button('+ Add ' + page.slice(0, -1), () => openModal(page));
        return null;
    };
    if (restoring)
        return <div className="login-bg"><div className="login-panel"><h2>Connecting to Atlazddesk...</h2></div></div>;
    if (!logged && mfaPending)
        return <MfaLogin challenge={mfaPending} onComplete={completeSignIn} onCancel={cancelMfa}/>;
    if (!logged)
        return <div className="login-bg"><div className="login-panel"><div className="brand"><div className="brand-icon"><Command size={24}/></div><div className="amc-brand-title">{AMC_BRAND_NAME}</div></div><div className="login-heading"><h1>Sign in</h1><ThemeToggle/></div><form onSubmit={login}><Field>Email address<Input type="email" name="email" autoComplete="username" required value={auth.email} onChange={e => { setAuth(p => ({ ...p, email: e.target.value })); setAuthError(''); }}/></Field><Field>Password<Input type="password" name="password" autoComplete="current-password" required value={auth.password} onChange={e => { setAuth(p => ({ ...p, password: e.target.value })); setAuthError(''); }}/></Field>{authError && <div role="alert" className={authError.startsWith('Additional verification is required') ? 'amc-inline-warning' : 'error'}>{authError}</div>}<Button className="btn btn-primary wide" type="submit">Sign in</Button></form></div></div>;
    return <div className="app"><aside className={'sidebar ' + (side ? 'shown' : '')}><div className="brand"><div className="brand-icon"><Command size={24}/></div><div className="amc-brand-title">{AMC_BRAND_NAME}</div><button className="close-mobile" onClick={() => setSide(false)}><X size={20}/></button></div><div className="workspace"><span className="workspace-icon" aria-hidden="true">{WORKSPACE_LABELS[role][0].slice(0, 1)}</span><div><b>{WORKSPACE_LABELS[role][0]}</b><small>{WORKSPACE_LABELS[role][1]}</small></div></div><nav>{nav.filter(group => group.items.some(([name]) => !maintenanceDisabled(name) && (DEMO || canAccessPage(name)))).map(group => <div className="nav-group" key={group.group}><div className="nav-label">{group.group}</div>{group.items.filter(([name]) => !maintenanceDisabled(name) && (DEMO || canAccessPage(name))).map(([name, Icon]) => <button key={name} className={'nav-item ' + (page === name ? 'active' : '')} onClick={() => go(name)}><Icon size={16}/><span>{name}</span>{name === 'Tickets' && <i>{active.length}</i>}</button>)}</div>)}</nav><div className="sidebar-bottom"><div className="profile"><span className="avatar">{role === 'technician' ? 'TE' : role === 'admin' ? 'AD' : role === 'customer_admin' ? 'CA' : role === 'customer_user' ? 'CU' : 'SA'}</span><div><b>{currentUser?.name || (role === 'technician' ? 'Technician' : role === 'admin' ? 'Admin' : role === 'customer_admin' ? 'Customer Admin' : role === 'customer_user' ? 'Customer User' : 'Super Admin')}</b><small>{role.replaceAll('_', ' ')}</small></div><button title="Log out" onClick={logout}><LogOut size={16}/></button></div></div></aside>{side && <div className="backdrop" onClick={() => setSide(false)}/>}<div className="main"><header className="topbar"><div className="crumb"><button className="mobile-menu" onClick={() => setSide(true)}><Menu size={20}/></button><span>Workspace</span><ChevronRight size={16}/><b>{page}</b></div><div className="top-actions"><ThemeToggle/><span className="today" title={`Installation timezone: ${getInstallationTimezone()}`}><CalendarDays size={16}/> {formatInstallationDate(new Date()) + ' · ' + formatInstallationTime(new Date())} · {getInstallationTimezone()}</span>{role !== 'super_admin' && !DEMO && <NotificationsPanel onTicket={id => { void openNotificationTicket(id); }}/>}
<button className="icon-button quick-nav-trigger" type="button" title="Quick navigation (Ctrl+K)" onClick={() => setQuickOpen(true)}><Search size={20}/><span>Ctrl K</span></button>
<ProfileMenu name={currentUser?.name || role.replaceAll('_', ' ')} email={currentUser?.email || ''} role={role} onNavigate={go} onLogout={() => { void logout(); }}/>
</div></header><main className="content">{workspaceError && <div className="workspace-alert" role="alert">{workspaceError} <Button className="btn btn-secondary" onClick={() => { if (role === 'super_admin') void client.get('/admin/metrics').then(({ data }) => { setSystemMetrics(data); setWorkspaceError(''); }); else void refreshWorkspace().then(() => setWorkspaceError('')); }}>Retry</Button></div>}<div className="page-header"><div><div className="eyebrow">{page === 'My Security' ? 'ACCOUNT SECURITY' : role === 'super_admin' ? 'TECHNICAL OPERATIONS' : role.startsWith('customer') ? 'CLIENT PORTAL' : role === 'technician' ? 'FIELD SERVICE' : 'CONTRACTOR OPERATIONS'}</div><h1>{page === 'Overview' ? `Welcome, ${currentUser?.name || 'Team member'}` : page}</h1><p>{page === 'Overview' ? (role === 'technician' ? 'Assigned work and recent activity.' : role.startsWith('customer') ? 'Your organization’s service requests and equipment.' : 'Service activity across your managed clients.') : PAGE_DESCRIPTIONS[page] ?? ''}</p></div><div className="header-buttons">{page === 'Overview' && canCreateTicket && button('+ New ticket', () => openModal('New ticket'))}{headerAction()}</div></div>
    {page === 'Overview' && role !== 'super_admin' && <><div className="dashboard-timeframes" role="group" aria-label="Ticket timeframe">{DASHBOARD_PERIODS.map(period => <Button type="button" key={period.value} className="btn btn-secondary" aria-pressed={dashboardPeriod === period.value} onClick={() => setDashboardPeriod(period.value)}>{period.label}</Button>)}<span>{periodLabel} · tickets created in this period</span></div><div className="kpis"><div className="kpi"><div className="kpi-top"><span>Open tickets</span><div className="kpi-icon indigo"><TicketIcon size={20}/></div></div><strong>{DEMO ? dashboardTickets.filter(t => !['Closed','Resolved','Cancelled'].includes(t.status)).length : (report?.open ?? '—')}</strong><div className="kpi-foot">Open tickets in selected period</div></div><div className="kpi"><div className="kpi-top"><span>Critical issues</span><div className="kpi-icon rose"><AlertTriangle size={20}/></div></div><strong>{DEMO ? dashboardTickets.filter(t => t.priority === 'Critical' && !['Closed','Resolved','Cancelled'].includes(t.status)).length : (report?.critical_open ?? '—')}</strong><div className="kpi-foot"><span className="red-text">Needs attention</span> · highest priority</div></div><div className="kpi"><div className="kpi-top"><span>Resolved tickets</span><div className="kpi-icon mint"><CheckCircle2 size={20}/></div></div><strong>{DEMO ? dashboardTickets.filter(t => ['Resolved','Closed'].includes(t.status)).length : (report?.resolved ?? '—')}</strong><div className="kpi-foot">Resolved tickets in selected period</div></div>{role === 'admin'
    ? <div className="kpi"><div className="kpi-top"><span>Active contracts</span><div className="kpi-icon amber-icon"><FileText size={20}/></div></div><strong>{DEMO || workspaceLoaded ? visibleRecords('Contracts').filter(c => c.status === 'Active').length : '—'}</strong><div className="kpi-foot">Across <b>{visibleRecords('Customers').length}</b> clients</div></div>
    : <div className="kpi"><div className="kpi-top"><span>{role === 'technician' ? 'Assigned tickets' : 'Registered assets'}</span><div className="kpi-icon amber-icon"><HardDrive size={20}/></div></div><strong>{role === 'technician' ? (DEMO ? dashboardTickets.length : report?.total ?? '—') : DEMO || workspaceLoaded ? visibleRecords('Assets').length : '—'}</strong><div className="kpi-foot">{role === 'technician' ? 'Assigned tickets in selected period' : 'Equipment in your organization'}</div></div>}</div><div className="dashboard-grid"><Card className="panel chart-panel"><div className="panel-head"><div><h3>Ticket activity</h3><p>{periodLabel}</p></div><span className="result-count">{periodLabel}</span></div><div className="legend"><span><i className="legend-purple"/>Created</span><span><i className="legend-green"/>Resolved</span></div><div className="chart"><ResponsiveContainer width="100%" height="100%"><AreaChart data={chart}><defs><linearGradient id="purpleGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="var(--palette-brand)" stopOpacity={.2}/><stop offset="100%" stopColor="var(--palette-brand)" stopOpacity={0}/></linearGradient></defs><CartesianGrid stroke="var(--palette-border-soft)" vertical={false}/><XAxis dataKey="day" axisLine={false} tickLine={false} tick={{ fill: 'var(--palette-subtle)', fontSize: 'var(--text-label)' }}/><Tooltip /><Area type="monotone" dataKey="tickets" stroke="var(--palette-brand)" strokeWidth={2.8} fill="url(#purpleGrad)"/><Area type="monotone" dataKey="resolved" stroke="var(--palette-success)" strokeWidth={2.5} fill="transparent"/></AreaChart></ResponsiveContainer></div></Card><Card className="panel distribution"><div className="panel-head"><div><h3>By priority</h3><p>Tickets created in selected period</p></div></div><div className="donut"><ResponsiveContainer width="100%" height="100%"><PieChart><Pie isAnimationActive={false} data={[{ name: 'Critical', value: count('Critical') }, { name: 'High', value: count('High') }, { name: 'Medium', value: count('Medium') }, { name: 'Low', value: count('Low') }]} dataKey="value" innerRadius={66} outerRadius={87} paddingAngle={0} stroke="none">{['var(--palette-critical)', 'var(--palette-warning)', 'var(--palette-brand)', 'var(--palette-low)'].map(c => <Cell key={c} fill={c}/>)}</Pie></PieChart></ResponsiveContainer><div className="donut-center"><strong>{DEMO ? dashboardTickets.length : (report?.total ?? '—')}</strong><span>Total tickets</span></div></div><div className="priority-legend">{[['Critical', 'var(--palette-critical)'], ['High', 'var(--palette-warning)'], ['Medium', 'var(--palette-brand)'], ['Low', 'var(--palette-low)']].map(([p, c]) => <div key={p}><span><i style={{ background: c }}/>{p}</span><b>{count(p)}</b></div>)}</div></Card></div><Card className="panel tickets-panel"><div className="panel-head"><div><h3>Recent tickets</h3><p>{role === 'technician' ? 'Recently assigned work' : role.startsWith('customer') ? 'Recent requests for your organization' : 'Recent client requests'}</p></div><button className="text-button" onClick={() => go(role === 'technician' ? 'My Inbox' : 'Tickets')}>View all tickets <ArrowUpRight size={16}/></button></div>{tableTickets(dashboardTickets.slice(0, 5))}</Card></>}
    {(page === 'Tickets' || page === 'My Inbox') && <Card className="panel tickets-panel"><div className="toolbar"><div className="search-box"><Search size={16}/><Input aria-label="Search tickets, customers, technicians" placeholder="Search tickets, customers, technicians..." value={query} onChange={e => setQuery(e.target.value)}/></div><div className="filter-box"><SearchableDropdown value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>{['All statuses', 'New', 'Assigned', 'In Progress', 'Waiting Customer', 'Waiting Parts', 'Resolved', 'Closed'].map(s => <option key={s}>{s}</option>)}</SearchableDropdown></div><div className="filter-box"><SearchableDropdown aria-label="Filter priority" value={priorityFilter} onChange={e => setPriorityFilter(e.target.value)}>{['All priorities','Critical','High','Medium','Low'].map(p => <option key={p}>{p}</option>)}</SearchableDropdown></div><button className="text-button" onClick={() => { setQuery(''); setStatusFilter('All statuses'); setPriorityFilter('All priorities'); }}>Clear filters</button><span className="result-count">{searchBusy ? 'Searching…' : `${filtered.length} tickets`}</span></div>{tableTickets(filtered)}</Card>}
    {!DEMO && page === 'Assets' && role === 'admin' && <CategoryManager onChange={() => void refreshWorkspace()}/>}
    {!DEMO && (page === 'Customers' || page === 'Assets' || page === 'My Assets') && <DirectoryList key={page} resource={page === 'Customers' ? 'customers' : 'assets'} lookups={lookups} canEdit={role === 'admin'}/>}
    {!DEMO && page === 'Contracts' && role === 'admin' && <ContractCatalog/>}
    {!maintenanceDisabled(page) && (DEMO ? ['Customers', 'Assets', 'Contracts', 'Maintenance', 'Technicians'] : ['Contracts', 'Maintenance', 'Technicians']).includes(page) && <Card className="panel tickets-panel"><div className="toolbar"><div className="search-box"><Search size={16}/><Input placeholder={page === 'Contracts' ? 'Search contracts…' : `Search ${page.toLowerCase()}...`} value={query} onChange={e => setQuery(e.target.value)}/></div><span className="result-count">{visibleRecords(page).length} records</span></div><div className="table-scroll"><SortableTable key={page} onSort={DEMO ? undefined : ({column, direction}) => { const columns = page === 'Contracts' ? ['contract_number','customer','contract_type','end_date','status'] : page === 'Maintenance' ? ['title','customer','frequency','next_due_date','status'] : ['name','email','role','status']; setDirectorySort({page, sort: columns[column], direction}); setDirectoryPage(1); }}><thead><tr>{(page === 'Customers' ? ['Company', 'Code', 'Location', 'Status'] : page === 'Assets' ? ['Asset', 'Type', 'Customer', 'Status'] : page === 'Contracts' ? ['Contract', 'Customer', 'Type', 'Expiry', 'Status', ...(!DEMO && role === 'admin' ? ['Documents'] : [])] : page === 'Maintenance' ? ['Task', 'Customer', 'Frequency', 'Next due', 'Status'] : ['Name', 'Email', 'Role', 'Status']).map(h => <th key={h}>{h}</th>)}</tr></thead><tbody>{visibleRecords(page).filter(r => Object.values(r).join(' ').toLowerCase().includes(query.toLowerCase())).map(r => <tr key={r.id} className={['Customers', 'Assets', 'Contracts', 'Technicians'].includes(page) ? 'record-row' : ''} onClick={() => { if (['Customers', 'Assets', 'Contracts', 'Technicians'].includes(page)) setRecordDetails({ resource: page === 'Customers' ? 'customers' : page === 'Assets' ? 'assets' : page === 'Technicians' ? 'technicians' : 'contracts', record: r }); }}>{(page === 'Customers' ? [r.company_name, r.customer_code, r.city, r.status] : page === 'Assets' ? [r.name, r.type, r.customer_name || (DEMO ? customers : records.Customers || []).find(c => c.id === r.customer_id)?.company_name, r.status] : page === 'Contracts' ? [r.contract_number, r.customer_name || (DEMO ? customers : records.Customers || []).find(c => c.id === r.customer_id)?.company_name, r.contract_type, calendarDate(r.end_date), r.status] : page === 'Maintenance' ? [r.title, r.customer_name || (DEMO ? customers : records.Customers || []).find(c => c.id === r.customer_id)?.company_name, r.frequency, calendarDate(r.next_due_date), r.status] : [r.name, r.email, r.role, r.status]).map((v, i) => <td key={i} data-sort-value={page === 'Contracts' && i === 3 ? r.end_date : page === 'Maintenance' && i === 3 ? r.next_due_date : undefined}>{i === (page === 'Contracts' ? 4 : page === 'Maintenance' ? 4 : page === 'Technicians' ? 3 : 3) ? badge(String(v || '—')) : i === 0 && ['Customers', 'Assets', 'Contracts', 'Technicians'].includes(page) ? <button type="button" className="record-link">{String(v || '—')}</button> : String(v || '—')}</td>)}{page === 'Contracts' && role === 'admin' && !DEMO && <td><Button type="button" className="btn" onClick={event => { event.stopPropagation(); setContractDocuments({ id: r.id, number: String(r.contract_number) }); }}>Documents</Button></td>}</tr>)}</tbody></SortableTable>{visibleRecords(page).filter(r => Object.values(r).join(' ').toLowerCase().includes(query.toLowerCase())).length === 0 && <div className="empty">{query ? `No matching ${page.toLowerCase()} found.` : `No ${page.toLowerCase()} have been added yet.`}</div>}</div>{!DEMO && directoryGrid?.page === page && directoryGrid.last > 1 && <div className="toolbar directory-pagination"><Button className="btn btn-secondary" disabled={directoryPage <= 1} onClick={() => setDirectoryPage(p => p - 1)}>Previous</Button><span>Page {directoryGrid.number} of {directoryGrid.last}</span><Button className="btn btn-secondary" disabled={directoryPage >= directoryGrid.last} onClick={() => setDirectoryPage(p => p + 1)}>Next</Button></div>}</Card>}
    {page === 'User Management' && canAccessPage('User Management') && !DEMO && <UserDirectory role={role} customerId={currentUser?.customer_id ?? null} customers={lookups.customers || []}/>}
    {page === 'Reports' && <div className="reports"><div className="kpis"><div className="kpi"><span>Total tickets</span><strong>{DEMO ? visibleTickets.length : (report?.total ?? '—')}</strong></div><div className="kpi"><span>Resolved / closed</span><strong>{DEMO ? visibleTickets.filter(t => ['Resolved', 'Closed'].includes(t.status)).length : (report?.resolved ?? '—')}</strong></div><div className="kpi"><span>Critical</span><strong>{DEMO ? critical.length : (report?.critical_open ?? '—')}</strong></div><div className="kpi"><span>Unassigned</span><strong>{DEMO ? unassigned.length : (report?.unassigned_open ?? '—')}</strong></div></div><Card className="panel report-panel"><div className="panel-head"><div><h3>Service desk report</h3><p>Export current ticket records to CSV</p></div>{button('↓ Export CSV', () => { void exportCsv(); })}</div>{tableTickets(visibleTickets)}</Card></div>}
    {page === 'Monitoring' && role === 'super_admin' && <>
      <Card className="panel technical-summary"><h3>Technical operations</h3><p>System-level status only. Client and ticket administration belongs to the company Admin.</p>
      <div className="technical-kpis">{Object.entries(systemMetrics || {}).filter(([key]) => ['failed_jobs','incidents_open','incidents_last_24h','queue_connection','cache_store','laravel_version','php_version'].includes(key)).map(([key,value]) => <div className="technical-kpi" key={key}><small>{key.replaceAll('_',' ')}</small><b>{String(value)}</b></div>)}</div>
      </Card><div className="health-banner"><div><div className="health-pulse"><Activity size={24}/></div><div><h2>{health?.status === 'healthy' ? 'All systems operational' : health ? 'Service degradation detected' : 'Checking service health...'}</h2><p>Live operational status from your application dependencies</p></div></div>{button('↻ Refresh', healthCheck, true)}</div><div className="health-grid">{Object.entries(health?.checks || {}).map(([name, value]: any) => <div className="panel health-card" key={name}><div className="health-service-icon">{name === 'database' ? <Database /> : name === 'redis' ? <Server /> : name === 'queue' ? <RefreshCw /> : <Wifi />}</div><div><h3>{name[0].toUpperCase() + name.slice(1)}</h3><p>{badge(value.status || 'unknown')}</p></div></div>)}</div>{!DEMO && <Card className="panel info-panel"><h3>Open application incidents ({incidents.length})</h3><p>Failures are captured without passwords, request payloads, or full stack traces. Use request IDs to correlate with server logs.</p>{incidentError && <p role="alert">{incidentError}</p>}{incidents.length === 0 ? <p>No open incidents reported.</p> : <div style={{overflowX:'auto'}}><SortableTable style={{width:'100%',textAlign:'left'}}><thead><tr><th>Last seen</th><th>Type</th><th>Exception</th><th>Count</th><th>Request ID</th><th>Action</th></tr></thead><tbody>{incidents.map((item:any)=><tr key={item.id}><td>{item.last_seen_at}</td><td>{item.kind}</td><td>{item.message}</td><td>{item.occurrences}</td><td>{item.request_id || '—'}</td><td><Button className="btn btn-secondary" onClick={()=>resolveIncident(item.id)}>Resolve</Button></td></tr>)}</tbody></SortableTable></div>}</Card>}</>}
    {recordDetails && <RecordDetails key={`${recordDetails.resource}-${recordDetails.record.id}`} resource={recordDetails.resource} id={recordDetails.record.id} canManageContracts={role === 'admin' && !DEMO} onChanged={() => void refreshWorkspace()} onClose={() => setRecordDetails(null)}/>}
    {page === 'Contracts' && role === 'admin' && contractDocuments && <ContractDocuments key={contractDocuments.id} contractId={contractDocuments.id} contractNumber={contractDocuments.number} onClose={() => setContractDocuments(null)}/>}
    {page === 'About' && <About role={role} systemMetrics={role === 'super_admin' ? systemMetrics : null}/>}
    {page === 'My Profile' && currentUser && !DEMO && <MyProfile user={currentUser} onUpdate={next => setCurrentUser(p => p ? { ...p, name: next.name, email: next.email } : p)}/>}
    {page === 'System Support' && role === 'super_admin' && !DEMO && <SystemSupport retentionSettings={<RetentionSettings scope="technical" />} />}
    {page === 'Admin Accounts' && role === 'super_admin' && !DEMO && <ContractorAdminAccounts/>}
    {page === 'Client Security' && role === 'admin' && !DEMO && <ClientMfaPolicies customers={lookups.customers || []}/>}
    {page === 'My Security' && !DEMO && <MyMfaSettings/>}
    {page === 'Authentication' && role === 'admin' && !DEMO && <ContractorMfaSettings/>}
    {/* AMC-RETENTION-V10.11: Contractor Admin may configure only read-notification retention. */}
    {page === 'Email Delivery' && role === 'admin' && !DEMO && <EmailDeliverySettings customers={lookups.customers || []}/>}

    </main></div>
    {selected && <SlidingDrawer title="Ticket details" onClose={() => setSelected(null)}><div className={`drawer-body ticket-details ${role === 'technician' ? 'technician-ticket-details' : ''}`}>
      {role === 'technician' && <nav className="ticket-section-nav" aria-label="Ticket sections">{[['summary','Summary'],['reports','Work reports'],['attachments','Attachments'],['logs','Work logs'],['activity','Activity']].map(([section,label]) => <button type="button" key={section} onClick={() => document.getElementById(`ticket-${selected.id}-${section}`)?.scrollIntoView({block:'start',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'})}>{label}</button>)}</nav>}
      <div id={`ticket-${selected.id}-summary`} className="ticket-summary"><div className="ticket-id">{selected.ticket_number}</div><h2>{selected.subject}</h2><div className="chips">{badge(selected.priority)}{badge(selected.status)}</div><div className="detail-grid"><div><small>Customer</small><b>{selected.customer}</b></div><div><small>Category</small><b>{selected.category}</b></div><div><small>Assigned to</small><b>{selected.assignee}</b></div><div><small>Created</small><b>{dt(selected.created_at)}</b></div><div><small>Resolution deadline</small><b>{dt(selected.sla_resolution_due_at)}</b></div>{selected.sla_response_due_at && <div><small>Response deadline</small><b>{dt(selected.sla_response_due_at!)}</b></div>}<div><small>Asset</small><b>{selected.asset}</b></div></div><h3>Description</h3><p className="description">{selected.description}</p>{(isStaff || role === 'technician' || role.startsWith('customer')) && <div className="edit-fields"><Field>Status<SearchableDropdown value={selected.status} onChange={e => updateTicket(selected.id, { status: e.target.value })}>{statusOptions(role, selected.status).map(s => <option key={s}>{s}</option>)}</SearchableDropdown></Field>{isStaff && <Field>Technician<SearchableDropdown value={selected.assigned_to || ''} onChange={e => { const id = Number(e.target.value); updateTicket(selected.id, { assigned_to: id || null, assignee: (DEMO ? technicians : lookups.technicians || []).find((t: RecordItem) => t.id === id)?.name as string || 'Unassigned' }); }}><option value="">Unassigned</option>{(DEMO ? technicians.filter(t => t.status === 'active') : lookups.technicians || []).map((t: RecordItem) => <option key={t.id} value={t.id}>{t.name}</option>)}</SearchableDropdown></Field>}</div>}</div><TicketWorkReports key={`reports-${selected.id}`} ticketId={selected.id} role={role} ticketStatus={selected.status}/><TicketOperations key={`operations-${selected.id}`} ticketId={selected.id} role={role}/><h3 id={`ticket-${selected.id}-activity`}>Activity &amp; comments</h3><div className="comments">{selected.comments.filter(c => !role.startsWith('customer') || !c.internal).map((c, i) => <div className="comment" key={i}><div className="avatar tiny">{initials(c.author)}</div><div><b>{c.author}</b><small>{Number.isNaN(Date.parse(c.time)) ? c.time : dt(c.time)}{c.internal ? ' · Internal note' : ''}</small><p>{c.text}</p></div></div>)}{!selected.comments.length && <p className="muted">No comments yet.</p>}</div><div className="comment-compose"><Field htmlFor={`ticket-${selected.id}-reply`}>Reply or internal note</Field><Textarea id={`ticket-${selected.id}-reply`} placeholder="Write a reply or work note..." value={draft.comment || ''} onChange={e => setDraft(p => ({ ...p, comment: e.target.value }))}/>{!role.startsWith('customer') && <Field className="check amc-private-note"><Checkbox type="checkbox" checked={draft.internal === 'yes'} onChange={e => setDraft(p => ({ ...p, internal: e.target.checked ? 'yes' : 'no' }))}/><span>Internal note — hidden from customers</span></Field>}<Button className="btn btn-primary" onClick={async () => {
                if (!draft.comment?.trim())
                    return;
                const comment = { author: currentUser?.name || 'User', text: draft.comment, internal: !role.startsWith('customer') && draft.internal === 'yes', time: formatInstallationTime(new Date()) };
                if (!DEMO)
                    try {
                        await client.post(`/tickets/${selected.id}/comments`, { comment: draft.comment, is_internal: comment.internal });
                        const { data } = await client.get(`/tickets/${selected.id}`);
                        setSelected(data.data);
                        setTickets(p => p.map(t => t.id === selected.id ? data.data : t));
                        setDraft({});
                        alert('Comment posted');
                        return;
                    }
                    catch {
                        alert('Comment failed', 'error');
                        return;
                    }
                const next = { ...selected, comments: [...selected.comments, comment] };
                setSelected(next);
                setTickets(p => p.map(t => t.id === selected.id ? next : t));
                setDraft({});
                alert('Comment posted');
            }}><Send size={16}/> Post comment</Button></div></div></SlidingDrawer>}
    {modal === 'Contracts' && role === 'admin' && !DEMO && <ContractEditor onClose={() => setModal(null)} onSaved={() => { setModal(null); void refreshWorkspace(); }}/> }
    {modal === 'Register client' && <ClientOnboarding onClose={() => setModal(null)} onCreated={async () => { setModal(null); await refreshWorkspace(); alert('Client and Customer Admin registered.'); }}/>}
    {modal && modal !== 'Register client' && modal !== 'Contracts' && <Dialog label={modal} onClose={() => setModal(null)}><div className="modal" onMouseDown={e => e.stopPropagation()}><div className="drawer-top"><span>{modal === 'New ticket' ? 'New ticket' : modal === 'Contracts' ? 'Add Contract' : 'Add ' + modal.slice(0, -1)}</span><CloseButton aria-label="Close dialog" onClick={() => setModal(null)}/></div><div className="modal-body">{(modal === 'New ticket' ? [['subject', 'Subject', 'text'], ['description', 'Description', 'textarea'], ...(isStaff ? [['customer_id', 'Customer', 'customers']] : []), ['category', 'Category', 'category'], ['asset_id', 'Asset', 'ticketasset'], ['priority', 'Priority', 'priority']] : modal === 'Customers' ? [['company_name', 'Company name', 'text'], ['customer_code', 'Customer code', 'text'], ['email', 'Email', 'text'], ['city', 'City', 'text']] : modal === 'Assets' ? [['name', 'Asset name', 'text'], ['asset_tag', 'Asset tag', 'text'], ['category_id', 'Category', 'assetcategories'], ...(role === 'customer_admin' ? [] : [['customer_id', 'Customer', 'customers']])] : modal === 'Maintenance' ? [['title', 'Task title', 'text'], ['customer_id', 'Customer', 'customers'], ['frequency', 'Frequency', 'frequency'], ['asset_id', 'Asset (optional)', 'maintenanceasset'], ['assigned_to', 'Technician (optional)', 'maintenancetech'], ['next_due_date', 'Next due', 'date']] : [['name', 'Name', 'text'], ['email', 'Email', 'text'], ['password', 'Password (8+ characters)', 'password'], ['password_confirmation', 'Confirm password', 'password']]).map(([key, label, type]) => <Field key={key}>{label}{type === 'textarea' ? <Textarea value={draft[key] || ''} onChange={e => setDraft(p => ({ ...p, [key]: e.target.value }))}/> : ['customers','category','priority','assetcategories','frequency','ticketasset','maintenanceasset','maintenancetech'].includes(type) ? <SearchableSelect disabled={type === 'ticketasset' && (assetChoicesBusy || !draft.category || !(draft.customer_id || (role.startsWith('customer') && currentUser?.customer_id)))} value={draft[key] || ''} placeholder={type === 'ticketasset' && draft.category && !assetChoicesBusy && ticketAssets.length === 0 ? 'No available assets' : `Select ${label.toLowerCase()}`} options={type === 'ticketasset' || type === 'maintenanceasset' ? (type === 'ticketasset' ? ticketAssets : (DEMO ? assets : records.Assets || [])).filter(asset => Number(asset.customer_id) === Number(draft.customer_id || (role.startsWith('customer') ? currentUser?.customer_id : 0))).map(asset => ({ value: String(asset.id), label: String(asset.name) + (asset.asset_tag ? ' · ' + String(asset.asset_tag) : '') })) : type === 'maintenancetech' ? (DEMO ? technicians : records.Technicians || []).map(t => ({ value: String(t.id), label: String(t.name) })) : fieldOptions(type)} onChange={value => setDraft(p => ({ ...p, [key]: value, ...(key === 'customer_id' || key === 'category' ? { asset_id: '' } : {}) }))}/> : <Input type={type} value={draft[key] || ''} onChange={e => setDraft(p => ({ ...p, [key]: e.target.value }))}/ >}</Field>)}</div><div className="modal-actions"><Button className="btn btn-secondary" onClick={() => setModal(null)}>Cancel</Button><Button className="btn btn-primary" disabled={loading || (modal === 'New ticket' && (!draft.asset_id || assetChoicesBusy))} onClick={save}>{loading ? 'Saving…' : modal === 'New ticket' ? 'Create ticket' : 'Save'}</Button></div></div></Dialog>}
    {quickOpen && <QuickNavigator options={allowedPages} onChoose={go} onClose={() => setQuickOpen(false)}/>}
    <FeedbackToast feedback={notice ? { tone: noticeTone, message: notice } : null} onDismiss={() => setNotice('')}/></div>;
}
export default App;
