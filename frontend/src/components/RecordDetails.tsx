import { useFeedbackState } from './feedbackContext';
import { UIState } from './ui';
import { Button } from './ui';
import { useEffect, useState } from 'react';
import { client } from '../api/client';
import { getApiErrorMessage } from '../api/contracts';
import SlidingDrawer from './SlidingDrawer';
import ContractDocuments from './ContractDocuments';
import ContractEditor from './ContractEditor';
import { formatInstallationDateTime } from '../utils/installationTime';

type Row = Record<string, any>;
type Resource = 'customers' | 'assets' | 'contracts' | 'technicians';
const fields: Record<Resource, [string, string][]> = {
  technicians: [['name', 'Name'], ['email', 'Email'], ['status', 'Status'], ['phone', 'Phone'], ['last_login_at', 'Last sign-in']],
  customers: [['company_name', 'Company'], ['customer_code', 'Customer code'], ['city', 'Location'], ['status', 'Status'], ['email', 'Email'], ['phone', 'Phone'], ['website', 'Website'], ['address', 'Address'], ['country', 'Country'], ['trade_license_no', 'Trade license'], ['vat_no', 'VAT number']],
  assets: [['name', 'Asset'], ['asset_tag', 'Asset tag'], ['category.name', 'Category'], ['customer.company_name', 'Customer'], ['status', 'Status'], ['location.name', 'Location'], ['manufacturer', 'Manufacturer'], ['model', 'Model'], ['serial_number', 'Serial number'], ['ip_address', 'IP address'], ['mac_address', 'MAC address'], ['purchase_date', 'Purchased'], ['warranty_expiry', 'Warranty expiry'], ['license_expiry', 'License expiry']],
  contracts: [['contract_number', 'Contract number'], ['customer.company_name', 'Customer'], ['contract_type', 'Contract type'], ['end_date', 'Expiry'], ['status', 'Status'], ['start_date', 'Start date'], ['contract_value', 'Value'], ['currency', 'Currency'], ['sla_policy.name', 'SLA policy']],
};

export default function RecordDetails({ resource, id, onClose, onChanged, canManageContracts = false }: {
  resource: Resource; id: number; onClose: () => void; onChanged?: () => void; canManageContracts?: boolean;
}) {
  const [record, setRecord] = useState<Row | null>(null);
  const [error, setError] = useFeedbackState('error');
  const [revision, setRevision] = useState(0);
  const [documents, setDocuments] = useState(false);
  const [editingContract, setEditingContract] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    client.get(`/directory/${resource}/${id}`, { signal: controller.signal })
      .then(({ data }) => { setRecord(data); setError(''); })
      .catch(error => { if (!controller.signal.aborted) setError(getApiErrorMessage(error, 'Could not load record details.')); });
    return () => controller.abort();
  }, [resource, id, revision]);
  const contractSections: [string, string[]][] = [
    ['Overview', ['customer.company_name', 'contract_type', 'status']],
    ['Dates', ['start_date', 'end_date']],
    ['Billing', ['contract_value', 'currency']],
    ['Coverage', ['sla_policy.name']],
  ];
  const sections: [string, [string, string][]][] = resource === 'contracts'
    ? contractSections.map(([label, keys]) => [label, fields.contracts.filter(([key]) => keys.includes(key))])
    : [['', fields[resource]]];
  const hasValue = (key: string) => {
    const value = key.split('.').reduce<any>((object, part) => object?.[part], record);
    return value != null && String(value).trim() !== '' && !(key === 'currency' && record?.contract_value == null);
  };
  const title = resource === 'customers' ? 'Customer details' : resource === 'assets' ? 'Asset details' : resource === 'technicians' ? 'Technician details' : 'Contract details';
  return <SlidingDrawer title={title} onClose={onClose}><div className="drawer-body record-details">
    {error ? <div role="alert"><p className="error">{error}</p><Button type="button" className="btn btn-secondary" onClick={() => setRevision(value => value + 1)}>Retry</Button></div> : !record ? <UIState tone="loading">Loading details…</UIState> : <>
      <h2>{record.company_name || record.name || record.contract_number}</h2>
      {sections.filter(([, entries]) => entries.some(([key]) => hasValue(key))).map(([heading, entries]) => <section key={heading} className={resource === 'contracts' ? 'contract-detail-section' : undefined}>
      {heading && <h3>{heading}</h3>}
      <dl className="record-detail-list">{entries.map(([key, label]) => {
        let value = key.split('.').reduce<any>((object, part) => object?.[part], record);
        const missing = value === null || value === undefined || value === '';
        if (missing || (typeof value === 'string' && !value.trim())) return null;
        if (key === 'currency' && record.contract_value == null) return null;
        else if (typeof value === 'boolean') value = value ? 'Yes' : 'No';
        // These are calendar dates, so changing the display timezone must not shift the day.
        if (!missing && (key.endsWith('_date') || key.endsWith('_expiry'))) {
          const date = new Date(String(value).slice(0, 10));
          if (!Number.isNaN(date.getTime())) value = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(date);
        }
        if (key === 'last_login_at') value = formatInstallationDateTime(String(value));
        return <div key={key}><dt>{label}</dt><dd>{String(value)}</dd></div>;
      })}</dl></section>)}
      {typeof record.notes === 'string' && record.notes.trim() && <section><h3>Notes</h3><p className="record-notes">{record.notes}</p></section>}
      {resource === 'contracts' && record.services?.length > 0 && <section className="contract-detail-section"><h3>Covered services</h3><ul className="contract-service-list">
        {record.services.map((service: Row) => <li key={service.id}>
          <h4>{service.name}{service.is_active === false ? ' (inactive)' : ''}</h4>
          {service.description && <p className="record-notes">{service.description}</p>}
          {service.notes && <p className="record-notes">{service.notes}</p>}
        </li>)}
      </ul></section>}
      {resource === 'contracts' && canManageContracts && <div className="contract-detail-actions">
        <Button type="button" className="btn btn-secondary" onClick={() => setEditingContract(true)}>Edit contract</Button>
        <Button type="button" className="btn btn-primary" onClick={() => setDocuments(true)}>Documents</Button>
      </div>}
      {editingContract && <ContractEditor record={record} onClose={() => setEditingContract(false)} onSaved={() => { setEditingContract(false); setRevision(v => v + 1); onChanged?.(); }}/>}
      {documents && <ContractDocuments contractId={id} contractNumber={String(record.contract_number)} onClose={() => setDocuments(false)}/>}
    </>}
  </div></SlidingDrawer>;
}
