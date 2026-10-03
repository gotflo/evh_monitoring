import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { capi, qs } from '../api'
import type { Option } from '../types'
import { Badge, Card, Empty, ErrorState, Loading, Outcome, PageHeader, PeriodPicker } from '../ui'
import { fmtDate, useData } from '../format'

interface Item {
  id: string; source: 'audit' | 'event'; created_at: string; type: string; label: string; service: string; service_label: string
  outcome: string | null; user_id: number | null; member_user_id: number | null; user: string | null; member: string | null
  subject: string | null; ip: string | null; detail: string | null; location: string | null
}
interface Data { items: Item[]; next_before: string | null; filters: { services: Option[]; types: Option[] } }

export default function Activity() {
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(['period', 'service', 'type', 'outcome', 'user_id', 'from', 'to'].map((k) => [k, params.get(k) ?? '']))
  const set = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); if (k === 'period') { p.delete('from'); p.delete('to') } setParams(p) }
  const query = { ...f, period: f.from ? '' : f.period || '7d' }
  const base = `/activity${qs(query)}`
  const { data, error, loading, reload } = useData<Data>(base, 30000)
  const [items, setItems] = useState<Item[]>([])
  const [next, setNext] = useState<string | null>(null)
  const [more, setMore] = useState(false)

  useEffect(() => { if (data) { setItems(data.items); setNext(data.next_before) } }, [data])

  const loadMore = async () => {
    if (!next) return
    setMore(true)
    try {
      const r = await capi<Data>(`/activity${qs({ ...query, before: next })}`)
      setItems((i) => [...i, ...r.items]); setNext(r.next_before)
    } finally { setMore(false) }
  }

  return (
    <>
      <PageHeader title="Activité de la plateforme" sub="Actions des membres et des responsables (journal de l'église), connexions et événements de sécurité." />
      <div className="cx-filters">
        <PeriodPicker value={f.from ? '' : f.period || '7d'} onChange={(v) => set('period', v)} options={['24h', '7d', '30d', '90d']} />
        <div className="cx-field"><label htmlFor="af">Du</label><input id="af" type="date" className="cx-input" value={f.from} onChange={(e) => set('from', e.target.value)} /></div>
        <div className="cx-field"><label htmlFor="at">Au</label><input id="at" type="date" className="cx-input" value={f.to} onChange={(e) => set('to', e.target.value)} /></div>
        <div className="cx-field"><label htmlFor="as">Service</label>
          <select id="as" className="cx-select" value={f.service} onChange={(e) => set('service', e.target.value)}>
            <option value="">Tous</option>{data?.filters.services.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
          </select></div>
        <div className="cx-field wide"><label htmlFor="aty">Type d'action</label>
          <select id="aty" className="cx-select" value={f.type} onChange={(e) => set('type', e.target.value)}>
            <option value="">Tous</option>{data?.filters.types.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
          </select></div>
        <div className="cx-field"><label htmlFor="ao">Résultat</label>
          <select id="ao" className="cx-select" value={f.outcome} onChange={(e) => set('outcome', e.target.value)}>
            <option value="">Tous</option><option value="success">Réussi</option><option value="failure">Échec</option><option value="denied">Refusé</option>
          </select></div>
      </div>
      {f.user_id && (
        <div className="cx-head-actions" style={{ marginBottom: '0.8rem' }}>
          <Badge tone="info">Utilisateur #{f.user_id} <button className="cx-link" onClick={() => set('user_id', '')} aria-label="Retirer le filtre">x</button></Badge>
          <Link to={`/utilisateurs/${f.user_id}`} className="cx-help">Voir sa fiche</Link>
        </div>
      )}
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && (
        <Card>
          {items.length === 0 ? <Empty>Aucune activité pour ces critères.</Empty> : (
            <div className="cx-table-wrap"><table className="cx-table responsive">
              <thead><tr><th>Date</th><th>Action</th><th>Service</th><th>Par</th><th>Membre concerné</th><th>Résultat</th></tr></thead>
              <tbody>{items.map((i) => (
                <tr key={i.id}>
                  <td data-label="Date" style={{ whiteSpace: 'nowrap' }}>{fmtDate(i.created_at)}</td>
                  <td data-label="Action" className="msg"><strong>{i.label}</strong>{i.detail && <div className="cx-help">{i.detail}</div>}{i.subject && <div className="cx-help">{i.subject}</div>}</td>
                  <td data-label="Service">{i.service_label}</td>
                  <td data-label="Par">{i.user_id ? <Link to={`/utilisateurs/${i.user_id}`}>{i.user}</Link> : <span className="cx-muted">{i.source === 'audit' ? 'Système' : '-'}</span>}{i.ip && <div className="cx-help">{i.location ? `${i.location} (${i.ip})` : i.ip}</div>}</td>
                  <td data-label="Membre concerné">{i.member_user_id ? <Link to={`/utilisateurs/${i.member_user_id}`}>{i.member}</Link> : '-'}</td>
                  <td data-label="Résultat"><Outcome value={i.outcome} /></td>
                </tr>
              ))}</tbody>
            </table></div>
          )}
          {next && <div className="cx-pager"><button className="cx-btn cx-btn-ghost" onClick={loadMore} disabled={more}>{more ? <span className="cx-spinner" /> : 'Charger plus'}</button></div>}
        </Card>
      )}
    </>
  )
}
