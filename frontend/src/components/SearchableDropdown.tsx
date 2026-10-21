import { Children, isValidElement, useEffect, useId, useRef, useState, type ReactNode, type SelectHTMLAttributes } from 'react';
import SearchableSelect, { type SelectOption } from './SearchableSelect';
function labelText(node: ReactNode): string {
  if (node == null || typeof node === 'boolean') return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (isValidElement(node)) return labelText((node.props as any).children);
  return Children.toArray(node).map(labelText).join('');
}
function optionsFrom(children: ReactNode, disabled = false): SelectOption[] {
  return Children.toArray(children).flatMap(child => {
    if (!isValidElement(child)) return [];
    const props = child.props as any;
    if (child.type === 'option') return [{value: String(props.value ?? labelText(props.children)), label: labelText(props.children), disabled: disabled || props.disabled}];
    return optionsFrom(props.children, disabled || props.disabled);
  });
}
/** Keep native form values, change events and required validation behind a searchable control. */
export default function SearchableDropdown({children, ...props}: SelectHTMLAttributes<HTMLSelectElement>) {
  const generatedId = useId();
  const inputId = props.id || generatedId;
  const native = useRef<HTMLSelectElement>(null);
  const root = useRef<HTMLDivElement>(null);
  const [uncontrolled, setUncontrolled] = useState(String(props.defaultValue ?? ''));
  const [labelName, setLabelName] = useState('');
  const [invalid, setInvalid] = useState(false);
  useEffect(() => {
    const label = root.current?.closest('label');
    if (!label) return;
    setLabelName(Array.from(label.childNodes).filter(node => node !== root.current && !(node instanceof Element && node.contains(root.current))).map(node => node.textContent || '').join(' ').trim().replace(/\s*\*\s*$/, ''));
    const previous = label.htmlFor;
    label.htmlFor = inputId;
    return () => { label.htmlFor = previous; };
  }, [inputId]);
  const options = optionsFrom(children);
  const value = String(props.value ?? uncontrolled);
  return <div className={`searchable-dropdown ${props.className || ''}`} style={props.style} ref={root}>
    <select {...props} id={undefined} style={undefined} className="searchable-dropdown-native" tabIndex={-1} aria-hidden="true" ref={native}
      onInvalid={event => { event.preventDefault(); setInvalid(true); root.current?.querySelector('input')?.focus(); props.onInvalid?.(event); }}>
      {children}
    </select>
    <SearchableSelect value={value} options={options} placeholder="Search options…" disabled={props.disabled}
      inputId={inputId} ariaLabel={props['aria-label'] || labelName || undefined} ariaLabelledBy={props['aria-labelledby']} required={props.required} invalid={invalid}
      onChange={next => {
        if (!native.current) return;
        native.current.value = next;
        setUncontrolled(next); setInvalid(false);
        native.current.dispatchEvent(new Event('change', {bubbles: true}));
      }}/>
    {invalid && <span className="amc-field-error" role="alert">Please select an option.</span>}
  </div>;
}
