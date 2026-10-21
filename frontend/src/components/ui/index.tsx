import { X } from 'lucide-react';
import { Children, isValidElement, forwardRef, type ButtonHTMLAttributes, type InputHTMLAttributes, type TextareaHTMLAttributes, type HTMLAttributes, type ReactNode } from 'react';

export const Button = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement> & {variant?: 'primary'|'secondary'|'danger'}>(function Button({variant, className = '', ...props}, ref) {
  const chosen = variant ?? (className.includes('btn-primary') ? 'primary' : className.includes('btn-danger') ? 'danger' : 'secondary');
  return <button {...props} ref={ref} className={`btn ui-button btn-${chosen} ${className}`}/>;
});
export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Input({className = '', ...props}, ref) {
  return <input {...props} ref={ref} className={`ui-input ${className}`}/>;
});
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea({className = '', ...props}, ref) {
  return <textarea {...props} ref={ref} className={`ui-textarea ${className}`}/>;
});
export const Checkbox = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Checkbox(props, ref) {
  return <Input {...props} type="checkbox" ref={ref}/>;
});
export const Switch = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function Switch(props, ref) {
  return <Checkbox {...props} role="switch" ref={ref}/>;
});
export const FileUpload = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function FileUpload(props, ref) {
  return <Input {...props} type="file" ref={ref}/>;
});
export function Card({className = '', ...props}: HTMLAttributes<HTMLElement>) { return <section {...props} className={`panel ${className}`}/>; }
export function Badge({children, tone = 'neutral', className = ''}: {children: ReactNode; tone?: 'neutral'|'success'|'warning'|'error'|'info'; className?: string}) {
  return <span className={`badge ui-badge ui-badge-${tone} ${className}`}>{children}</span>;
}
export function UIState({children, tone = 'empty', action}: {children: ReactNode; tone?: 'loading'|'empty'|'error'|'success'; action?: ReactNode}) {
  return <div className={`ui-state ui-state-${tone}`} role={tone === 'error' ? 'alert' : 'status'} aria-live={tone === 'error' ? 'assertive' : 'polite'}>{children}{action}</div>;
}
export function Pagination({page, last, busy = false, onPage, label = 'Record pages'}: {page:number;last:number;busy?:boolean;onPage:(page:number)=>void;label?:string}) {
  if (last <= 1) return null;
  return <nav className="ui-pagination" aria-label={label}><Button disabled={busy || page <= 1} onClick={() => onPage(page-1)}>Previous</Button><span aria-live="polite">Page {page} of {last}</span><Button disabled={busy || page >= last} onClick={() => onPage(page+1)}>Next</Button></nav>;
}
export function FilterRow({className = '', ...props}: HTMLAttributes<HTMLDivElement>) {return <div {...props} className={`toolbar ui-filters ${className}`}/>;}
export function Tabs<T extends string>({items,value,onChange,label}: {items:readonly (readonly [T,string])[];value:T;onChange:(value:T)=>void;label:string}) {
  return <div className="support-tabs ui-tabs" role="tablist" aria-label={label}>{items.map(([key,title],index) => <button type="button" key={key} role="tab" aria-selected={value===key} tabIndex={value===key?0:-1} className={value===key?'active':''} onClick={()=>onChange(key)}
    onKeyDown={event=>{let next=index;if(event.key==='ArrowRight')next=(index+1)%items.length;else if(event.key==='ArrowLeft')next=(index+items.length-1)%items.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=items.length-1;else return;event.preventDefault();onChange(items[next][0]);(event.currentTarget.parentElement?.querySelectorAll('button')[next] as HTMLElement)?.focus();}}>{title}</button>)}</div>;
}

/** Keep the label and native control connected; mark required fields without duplicating existing marks. */
export function Field({children, ...props}: import('react').LabelHTMLAttributes<HTMLLabelElement>) {
  const nodes = Children.toArray(children);
  const index = nodes.findIndex(node => isValidElement(node));
  const required = nodes.some(node => isValidElement(node) && Boolean((node.props as {required?:boolean}).required));
  const text = nodes.slice(0,index).join('');
  return <label {...props}>{index > 0 ? <><span className="ui-field-label">{nodes.slice(0,index)}{required && !text.includes('*') && !/optional/i.test(text) && <span className="ui-required" aria-hidden="true"> *</span>}</span>{nodes.slice(index)}</> : children}</label>;
}

/** Inline feedback shares one flat layout; tone carries meaning without extra cards. */
export function InlineNotice({children, tone = 'info'}: {children: ReactNode; tone?: 'info'|'error'|'warning'|'success'}) {
  return <div className={`ui-notice ui-notice-${tone}`} role={tone === 'error' ? 'alert' : 'status'}>{children}</div>;
}

export const CloseButton = forwardRef<HTMLButtonElement, ButtonHTMLAttributes<HTMLButtonElement>>(function CloseButton({className = '', ...props}, ref) {
  return <button type="button" aria-label="Close" {...props} ref={ref} className={`ui-close-button ${className}`}><X size={24} aria-hidden="true"/></button>;
});
