import { Fragment, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { capi, qs } from '../api'
import type { Option } from '../types'
import { Card, Empty, ErrorState, KeyValues, Loading, Outcome, PageHeader, PeriodPicker } from '../ui'
import { fmtDate, useData } from '../format'

interface Log { id: number; created_at: string; action: string; label: string; operator: string; operator_id: number | null; target_type: string | null; target_id: number | null; target: string | null; outcome: string; details: Record<string, unknown> | null; ip: string | null }
interface Data { logs: Log[]; next_before_id: number | null; actions: Option[]; operators: { id: number; label: string }[] }

const GROUPS: [string, string][] = [['', 'Toutes les actions'], ['console.', 'Connexions à la console'], ['access.', "Gestion des accès"], ['user.', 'Actions sur les comptes'], ['incident.', 'Incidents'], ['action.', 'Dépannage'], ['settings.', 'Réglages']]

export default function Audit() {
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(['period', 'action', 'operator_id', 'outcome'].map((k) => [k, params.get(k) ?? '']))
  const set = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); setParams(p) }
  const query = { ...f, period: f.period || '30d' }
  const base = `/audit${qs(query)}`
  const { data, error, loading, reload } = useData<Data>(base)
  const [logs, setLogs] = useState<Log[]>([])
  const [next, setNext] = useState<number | null>(null)
  const [open, setOpen] = useState<number | null>(null)
  useEffect(() => { if (data) { setLogs(data.logs); setNext(data.next_before_id) } }, [data])

  const more = async () => {
    const r = await capi<Data>(`/audit${qs({ ...query, before_id: next })}`)
    setLogs((l) => [...l, ...r.logs]); setNext(r.next_before_id)
  }

  return (
    <>
      <PageHeader title="Journal d'audit de la console" sub="Connexions, changements d'accès et toutes les actions faites depuis la console : qui, quand, sur quoi, avec quel résultat. Non modifiable." />
      <div className="cx-filters">
        <PeriodPicker value={f.period || '30d'} onChange={(v) => set('period', v)} options={['24h', '7d', '30d', '90d']} />
        <div className="cx-field wide"><label htmlFor="ga">Action</label>
          <select id="ga" className="cx-select" value={f.action} onChange={(e) => set('action', e.target.value)}>
            {GROUPS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            <optgroup label="Action précise">{data?.actions.map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}</optgroup>
          </select></div>
        <div className="cx-field"><label htmlFor="go">Personne</label>
          <select id="go" className="cx-select" value={f.operator_id} onChange={(e) => set('operator_id', e.target.value)}>
            <option value="">Toutes</option>{data?.operators.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
          </select></div>
        <div className="cx-field"><label htmlFor="gr">Résultat</label>
          <select id="gr" className="cx-select" value={f.outcome} onChange={(e) => set('outcome', e.target.value)}>
            <option value="">Tous</option><option value="success">Réussi</option><option value="failure">Échec</option><option value="denied">Refusé</option>
          </select></div>
      </div>
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && <Card>
        {logs.length === 0 ? <Empty>Aucune entrée pour ces critères.</Empty> : (
          <div className="cx-table-wrap"><table className="cx-table responsive">
            <thead><tr><th>Date</th><th>Action</th><th>Par</th><th>Cible</th><th>Résultat</th><th /></tr></thead>
            <tbody>{logs.map((l) => (<Fragment key={l.id}>
              <tr>
                <td data-label="Date" style={{ whiteSpace: 'nowrap' }}>{fmtDate(l.created_at)}</td>
                <td data-label="Action"><strong>{l.label}</strong></td>
                <td data-label="Par">{l.operator}{l.ip && <div className="cx-help">{l.ip}</div>}</td>
                <td data-label="Cible" className="msg">{l.target ?? '-'}</td>
                <td data-label="Résultat"><Outcome value={l.outcome} /></td>
                <td data-label="">{l.details && <button className="cx-link" onClick={() => setOpen(open === l.id ? null : l.id)} aria-expanded={open === l.id}>{open === l.id ? 'Masquer' : 'Détails'}</button>}</td>
              </tr>
              {open === l.id && <tr><td colSpan={6}><KeyValues data={l.details} /></td></tr>}
            </Fragment>))}</tbody>
          </table></div>
        )}
        {next && <div className="cx-pager"><button className="cx-btn cx-btn-ghost" onClick={more}>Charger plus</button></div>}
      </Card>}
    </>
  )
}
