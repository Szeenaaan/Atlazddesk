import { formatInstallationDateTime } from '../utils/installationTime';
import { useEffect, useState } from 'react';
import { Bell, Check, RefreshCw } from 'lucide-react';
import { client, DEMO } from '../api/client';
import type { ApiResponse } from '../api/contracts';

type Notice = { id: number; ticket_id: number | null; event: string; title: string; message: string; read_at: string | null; created_at: string };
type Props = { onTicket: (id: number) => void };
export default function NotificationsPanel({ onTicket }: Props) {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<Notice[]>([]);
  const [unread, setUnread] = useState(0);
  const [error, setError] = useState('');
  async function refresh() {
    if (DEMO) return;
    try {
      const { data } = await client.get<ApiResponse<{ unread_count: number; items: Notice[] }>>('/notifications');
      setItems(data.data?.items ?? []); setUnread(data.data?.unread_count ?? 0); setError('');
    } catch { setError('Notifications could not be loaded.'); }
  }
  useEffect(() => {
    if (DEMO) return;
    void refresh();
    const interval = window.setInterval(() => void refresh(), 30000);
    return () => window.clearInterval(interval);
  }, []);
  async function openNotice(note: Notice) {
    if (!note.read_at) {
      try { await client.patch(`/notifications/${note.id}/read`); await refresh(); }
      catch { setError('Could not mark this notification as read.'); }
    }
    if (note.ticket_id) { onTicket(note.ticket_id); setOpen(false); }
  }
  return <div className="notification-holder">
    <button className="icon-button notification-toggle" type="button" aria-label={`Notifications, ${unread} unread`}
      aria-expanded={open} onClick={() => setOpen(v => !v)}><Bell size={19}/>
      {unread > 0 && <span className="notification-count">{Math.min(unread, 99)}</span>}
    </button>
    {open && <div className="notification-popover" role="dialog" aria-label="Your notifications">
      <div className="popover-head"><b>Notifications</b><button className="icon-button" title="Refresh" onClick={() => void refresh()}><RefreshCw size={17}/></button></div>
      {error && <p role="alert" className="error">{error}</p>}
      {!items.length ? <p className="muted">No notifications yet.</p> :
        <div className="notice-list">{items.map(note => <button key={note.id} type="button" className={`notice-item ${!note.read_at ? 'unread' : ''}`}
          onClick={() => void openNotice(note)}><b>{note.title}</b><span>{note.message}</span><small>{formatInstallationDateTime(note.created_at)} {!note.read_at && <Check size={12}/>}</small></button>)}</div>}
      <button className="text-button" type="button" onClick={() => setOpen(false)}>Close</button>
    </div>}
  </div>;
}
