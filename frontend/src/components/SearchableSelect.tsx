import { Input } from './ui';
import { createPortal } from 'react-dom';
import { useEffect, useLayoutEffect, useId, useRef, useState } from 'react';
import { ChevronDown, Search } from 'lucide-react';

import { filterSelectOptions, type SelectOption } from '../utils/selectOptions';
export type { SelectOption } from '../utils/selectOptions';
type Props = { disabled?: boolean; value: string; options: SelectOption[]; placeholder: string; onChange: (value: string) => void; inputId?: string; ariaLabel?: string; ariaLabelledBy?: string; required?: boolean; invalid?: boolean };
export default function SearchableSelect({ value, options, placeholder, onChange, disabled = false, inputId, ariaLabel, ariaLabelledBy, required, invalid }: Props) {
  const [term, setTerm] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const root = useRef<HTMLDivElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const [placement, setPlacement] = useState<{left:number;top:number;width:number;maxHeight:number}>({left:8,top:8,width:240,maxHeight:240});
  const listId = useId();
  useLayoutEffect(() => {
    if (!open) return;
    const position = () => {
      const rect = root.current?.getBoundingClientRect();
      if (!rect) return;
      const below = window.innerHeight - rect.bottom - 8;
      const above = rect.top - 8;
      const upward = below < 160 && above > below;
      const height = Math.min(240, Math.max(80, upward ? above - 4 : below - 4));
      const width = Math.min(rect.width, window.innerWidth - 16);
      setPlacement({left:Math.max(8,Math.min(rect.left,window.innerWidth-width-8)),top:upward?Math.max(8,rect.top-height-4):rect.bottom+4,width,maxHeight:height});
    };
    position();
    window.addEventListener('resize',position);
    document.addEventListener('scroll',position,true);
    return () => {window.removeEventListener('resize',position);document.removeEventListener('scroll',position,true);};
  }, [open, term]);
  const choice = options.find(o => o.value === value);
  const matches = filterSelectOptions(options, term);
  useEffect(() => {
    const outside = (event: PointerEvent) => { if (!root.current?.contains(event.target as Node) && !menu.current?.contains(event.target as Node)) setOpen(false); };
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, []);
  useEffect(() => { if (open) document.getElementById(`${listId}-${active}`)?.scrollIntoView({block: 'nearest'}); }, [active, open, listId]);
  function choose(option: SelectOption) { onChange(option.value); setTerm(''); setOpen(false); setActive(0); }
  function show() { setTerm(''); setActive(0); setOpen(true); }
  return <div className="select-search" ref={root} onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget) && !menu.current?.contains(event.relatedTarget)) setOpen(false); }}>
    <div className="select-search-input"><Search size={16}/><Input id={inputId} disabled={disabled} role="combobox" aria-expanded={open && !disabled} aria-autocomplete="list"
      aria-controls={listId} aria-activedescendant={open && matches[active] ? `${listId}-${active}` : undefined}
      aria-label={ariaLabel} aria-labelledby={ariaLabelledBy} aria-required={required} aria-invalid={invalid || undefined} autoComplete="off"
      placeholder={choice?.label || placeholder} value={open ? term : choice?.label ?? ''}
      onFocus={show} onClick={() => { if (!open) show(); }}
      onChange={e => { setTerm(e.target.value); setActive(0); setOpen(true); }}
      onKeyDown={e => {
        if (e.key === 'Escape' && open) { e.preventDefault(); e.stopPropagation(); setOpen(false); return; }
        if (e.key === 'Tab') { setOpen(false); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          if (!open) { show(); return; }
          setActive(v => Math.max(0, Math.min(v + (e.key === 'ArrowDown' ? 1 : -1), matches.length - 1)));
        }
        if (e.key === 'Enter' && open) { e.preventDefault(); if (matches[active]) choose(matches[active]); }
      }}/><ChevronDown size={16} aria-hidden="true" onMouseDown={e => e.preventDefault()} onClick={() => { if (!disabled && !root.current?.querySelector('input')?.matches(':disabled')) { root.current?.querySelector('input')?.focus(); if (open) setOpen(false); else show(); } }}/></div>
    {open && !disabled && createPortal(<div ref={menu} style={placement} className="select-search-results ui-select-menu" role="listbox" aria-label={ariaLabel || placeholder} id={listId}>{matches.length ? matches.map((option,i) =>
      <button type="button" tabIndex={-1} role="option" id={`${listId}-${i}`} aria-selected={option.value === value} className={i===active ? 'active' : ''}
        key={option.value} onMouseDown={e => e.preventDefault()} onClick={() => choose(option)}>{option.label}</button>) :
      <p className="muted" role="status">No matching options</p>}</div>, root.current?.closest('dialog') || document.body)}
  </div>;
}
