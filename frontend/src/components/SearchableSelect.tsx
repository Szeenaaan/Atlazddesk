import { useEffect, useRef, useState } from 'react';
import { ChevronDown, Search } from 'lucide-react';

export type SelectOption = { value: string; label: string };
type Props = { disabled?: boolean; value: string; options: SelectOption[]; placeholder: string; onChange: (value: string) => void };
export default function SearchableSelect({ value, options, placeholder, onChange, disabled = false }: Props) {
  const [term, setTerm] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const root = useRef<HTMLDivElement>(null);
  const choice = options.find(o => o.value === value);
  const matches = options.filter(o => o.label.toLowerCase().includes(term.toLowerCase())).slice(0, 35);
  useEffect(() => {
    const outside = (event: PointerEvent) => { if (!root.current?.contains(event.target as Node)) setOpen(false); };
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, []);
  function choose(option: SelectOption) { onChange(option.value); setTerm(''); setOpen(false); setActive(0); }
  return <div className="select-search" ref={root}>
    <div className="select-search-input"><Search size={15}/><input disabled={disabled} role="combobox" aria-expanded={open} aria-autocomplete="list"
      placeholder={choice?.label || placeholder} value={open ? term : choice?.label ?? ''}
      onFocus={() => { setTerm(''); setOpen(true); }}
      onChange={e => { setTerm(e.target.value); setActive(0); setOpen(true); }}
      onKeyDown={e => {
        if (e.key === 'Escape') { setOpen(false); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(v => Math.min(v+1,matches.length-1)); }
        if (e.key === 'ArrowUp') { e.preventDefault(); setActive(v => Math.max(0,v-1)); }
        if (e.key === 'Enter' && open && matches[active]) { e.preventDefault(); choose(matches[active]); }
      }}/><ChevronDown size={15}/></div>
    {open && !disabled && <div className="select-search-results" role="listbox">{matches.length ? matches.map((option,i) =>
      <button type="button" role="option" aria-selected={option.value === value} className={i===active ? 'active' : ''}
        key={option.value} onMouseDown={e => e.preventDefault()} onClick={() => choose(option)}>{option.label}</button>) :
      <p className="muted">No matching option</p>}</div>}
  </div>;
}
