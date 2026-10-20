import { useEffect, useState } from 'react';
import { client } from '../api/client';
import UserAssetPicker from './UserAssetPicker';
export default function UserAssetEditor({ user, onClose }: { user: { id: number; name: string; customer_id: number | null }; onClose: () => void }) {
  const [ids, setIds] = useState<number[]>([]), [loading, setLoading] = useState(true), [busy, setBusy] = useState(false), [error, setError] = useState('');
  useEffect(() => {
    let cancelled = false;
    void client.get(`/users/${user.id}/assets`).then(({ data }) => { if (!cancelled) { setIds(data.asset_ids); setLoading(false); } }).catch(() => { if (!cancelled) setError('Unable to load assignments. Close and retry.'); });
    return () => { cancelled = true; };
  }, [user.id]);
  return <form className="asset-assignment-editor" onSubmit={async e => {
    e.preventDefault(); if (loading || busy) return; setBusy(true); setError('');
    try { await client.put(`/users/${user.id}/assets`, { asset_ids: ids }); onClose(); }
    catch (e: any) { setError(e.response?.data?.message || 'Unable to save assignments.'); }
    finally { setBusy(false); }
  }}><h3>Assign assets — {user.name}</h3>{error && <p className="error" role="alert">{error}</p>}
    {!loading && <UserAssetPicker customerId={user.customer_id} value={ids} onChange={setIds} disabled={busy}/>}
    <div className="asset-edit-actions"><button className="btn btn-primary" disabled={loading || busy}>{busy ? 'Saving…' : 'Save assignments'}</button><button type="button" className="btn btn-secondary" disabled={busy} onClick={onClose}>Cancel</button></div>
  </form>;
}
