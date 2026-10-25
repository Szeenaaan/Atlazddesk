import { useState } from 'react';
import { Moon, Sun } from 'lucide-react';

export function initializeTheme() {
  let theme: string | null = null;
  try { theme = localStorage.getItem('atlazddesk-theme'); } catch { /* Storage may be disabled. */ }
  document.documentElement.dataset.theme = theme === 'light' || theme === 'dark'
    ? theme : 'light';
}

export default function ThemeToggle() {
  const [dark, setDark] = useState(() => document.documentElement.dataset.theme === 'dark');
  function toggle() {
    const next = dark ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('atlazddesk-theme', next); } catch { /* Theme still works for this session. */ }
    setDark(!dark);
  }
  return <button type="button" className="theme-toggle" onClick={toggle}
    aria-label={dark ? 'Switch to light theme' : 'Switch to dark theme'}
    title={dark ? 'Switch to light theme' : 'Switch to dark theme'}>
    {dark ? <Sun size={20}/> : <Moon size={20}/>}
  </button>;
}
