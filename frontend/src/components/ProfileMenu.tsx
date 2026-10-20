import { useEffect, useRef, useState } from 'react';
import { ChevronDown, LogOut, LockKeyhole, Settings, UserCircle, X } from 'lucide-react';

type Props = { name: string; email: string; role: string; onNavigate: (page: string) => void; onLogout: () => void };
export default function ProfileMenu({ name, email, role, onNavigate, onLogout }: Props) {
  const [open, setOpen] = useState(false);
  const area = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const onPointer = (event: MouseEvent) => { if (!area.current?.contains(event.target as Node)) setOpen(false); };
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') setOpen(false); };
    document.addEventListener('pointerdown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('pointerdown', onPointer); document.removeEventListener('keydown', onKey); };
  }, []);
  function nav(page: string) { onNavigate(page); setOpen(false); }
  return <div className="account-menu-wrapper" ref={area}>
    <button type="button" className="account-trigger" aria-label="Account menu" aria-expanded={open} onClick={() => setOpen(v => !v)}>
      <span className="top-avatar">{name.split(' ').map(w => w[0]).slice(0,2).join('').toUpperCase()}</span><ChevronDown size={15}/>
    </button>
    {open && <div className="account-popover" role="menu" aria-label="Account actions">
      <div className="account-summary"><UserCircle size={23}/><div><b>{name}</b><small>{email}</small><small>{role.replaceAll('_', ' ')}</small></div><button className="icon-button" aria-label="Close menu" onClick={() => setOpen(false)}><X size={16}/></button></div>
      <button role="menuitem" onClick={() => nav('My Profile')}><UserCircle size={16}/> My profile</button>
      <button role="menuitem" onClick={() => nav('My Security')}><LockKeyhole size={16}/> My security</button>
      {role === 'admin' && <button role="menuitem" onClick={() => nav('Authentication')}><Settings size={16}/> Authentication settings</button>}
      {role === 'admin' && <button role="menuitem" onClick={() => nav('Email Delivery')}><Settings size={16}/> Email delivery</button>}
      {role === 'super_admin' && <button role="menuitem" onClick={() => nav('System Support')}><Settings size={16}/> System support</button>}
      {role === 'super_admin' && <button role="menuitem" onClick={() => nav('Admin Accounts')}><UserCircle size={16}/> Contractor admins</button>}
      <button className="logout-action" role="menuitem" onClick={() => { setOpen(false); onLogout(); }}><LogOut size={16}/> Sign out</button>
    </div>}
  </div>;
}
