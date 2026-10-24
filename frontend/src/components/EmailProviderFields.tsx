import type { ReactNode } from 'react';
import { Field, Input } from './ui';
import SearchableDropdown from './SearchableDropdown';

export type EmailProviderValues = {
  driver: string; from_name: string; from_email: string; host: string;
  port: string; encryption: string; username: string; secret: string;
};

/** Shared presentation only: each screen retains its own permissions, credentials and API payload. */
export default function EmailProviderFields({values, onChange, disabled = false, lockDriver = false, children}: {
  values: EmailProviderValues; onChange: (field: keyof EmailProviderValues, value: string) => void;
  disabled?: boolean; lockDriver?: boolean; children?: ReactNode;
}) {
  return <div className="mail-form-grid email-provider-fields">
    {children}
    <Field>Provider<SearchableDropdown disabled={disabled || lockDriver} value={values.driver} onChange={event => onChange('driver', event.target.value)}>
      <option value="smtp">SMTP</option><option value="resend">Resend API</option><option value="postmark">Postmark API</option>
    </SearchableDropdown></Field>
    <Field>Sender name<Input required maxLength={120} disabled={disabled} value={values.from_name} onChange={event => onChange('from_name', event.target.value)}/></Field>
    <Field>Sender email<Input required type="email" disabled={disabled} value={values.from_email} onChange={event => onChange('from_email', event.target.value)}/></Field>
    {values.driver === 'smtp' ? <>
      <Field>SMTP host<Input placeholder="mail.example.org" disabled={disabled} value={values.host} onChange={event => onChange('host', event.target.value)}/></Field>
      <Field>SMTP port<Input type="number" min={1} max={65535} disabled={disabled} value={values.port} onChange={event => onChange('port', event.target.value)}/></Field>
      <Field>TLS mode<SearchableDropdown disabled={disabled} value={values.encryption} onChange={event => onChange('encryption', event.target.value)}>
        <option value="tls">STARTTLS</option><option value="ssl">TLS (implicit)</option>
      </SearchableDropdown></Field>
      <Field>Username<Input autoComplete="off" disabled={disabled} value={values.username} onChange={event => onChange('username', event.target.value)}/></Field>
    </> : null}
    <Field>{values.driver === 'smtp' ? 'SMTP password' : 'Provider API key'}<Input type="password" autoComplete="new-password" disabled={disabled} value={values.secret} onChange={event => onChange('secret', event.target.value)}/></Field>
  </div>;
}
