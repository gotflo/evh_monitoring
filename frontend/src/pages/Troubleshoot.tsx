import { useState } from 'react'
import { capi } from '../api'
import { useConsole, type ConsoleRole } from '../auth'
import { Badge, Card, Confirm, ErrorState, KeyValues, Loading, Outcome, PageHeader } from '../ui'
import { fmtRel, useData } from '../format'

interface Action { key: string; label: string; help: string; role: ConsoleRole; impact: string; where: 'platform' | 'console'; available: boolean; last: { outcome: string; by: string | null; at: string } | null }

const ROLE: Record<ConsoleRole, string> = { owner: 'Propriétaire', admin: 'Administrateur', viewer: 'Lecture seule' }

export default function Troubleshoot() {
  const { can } = useConsole()
  const { data, error, loading, reload } = useData<{ actions: Action[] }>('/actions')
  const [current, setCurrent] = useState<Action | null>(null)
  const [result, setResult] = useState<{ action: string; message: string; details: Record<string, unknown> } | null>(null)

  const run = async () => {
    if (!current) return
    const r = await capi<{ message: string; details: Record<string, unknown> }>(`/actions/${current.key}`, { method: 'POST', body: { confirm: true } })
    setResult({ action: current.label, ...r })
    reload()
  }

  return (
    <>
      <PageHeader title="Dépannage" sub="Actions sûres, adaptées aux fonctions de la plateforme. Chacune demande une confirmation et est consignée dans le journal d'audit." />
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {result && (
        <Card title={`Résultat : ${result.action}`} className="mt" actions={<button className="cx-btn cx-btn-ghost cx-btn-sm" onClick={() => setResult(null)}>Fermer</button>}>
          <p style={{ marginBottom: '0.5rem' }}>{result.message}</p>
          {Object.keys(result.details ?? {}).length > 0 && <details><summary className="cx-help" style={{ cursor: 'pointer' }}>Détails</summary><KeyValues data={result.details} /></details>}
        </Card>
      )}
      {data && (
        <div className="cx-grid two mt">
          {data.actions.map((a) => (
            <Card key={a.key} title={a.label} actions={<><Badge tone="info">{a.where === 'platform' ? 'Sur la plateforme' : 'Dans la console'}</Badge><Badge tone={a.role === 'owner' ? 'warn' : 'muted'}>{ROLE[a.role]}</Badge></>}>
              <p style={{ fontSize: '0.88rem' }}>{a.help}</p>
              <p className="cx-help mt">Effet : {a.impact}</p>
              <div className="cx-head-actions mt" style={{ justifyContent: 'space-between' }}>
                <span className="cx-help">{a.last ? <>Dernière fois {fmtRel(a.last.at)} par {a.last.by ?? '-'} - <Outcome value={a.last.outcome} /></> : 'Jamais lancée'}</span>
                <button className="cx-btn cx-btn-primary cx-btn-sm" disabled={!can(a.role) || !a.available} title={!a.available ? 'Liaison avec la plateforme non configurée' : can(a.role) ? undefined : `Réservé au rôle ${ROLE[a.role]}`} onClick={() => setCurrent(a)}>Lancer...</button>
              </div>
            </Card>
          ))}
        </div>
      )}
      {current && (
        <Confirm title={current.label} confirmLabel="Confirmer et lancer" danger={current.role === 'owner'} onClose={() => setCurrent(null)} onConfirm={run}>
          <p>{current.help}</p>
          <p className="cx-note">{current.impact}</p>
        </Confirm>
      )}
    </>
  )
}
