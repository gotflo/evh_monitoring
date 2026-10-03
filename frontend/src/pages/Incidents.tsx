import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { capi, qs } from '../api'
import { useConsole } from '../auth'
import type { IncidentRow, Investigation, Option } from '../types'
import { Badge, Card, CIcon, Confirm, Empty, ErrorState, KeyValues, Loading, PageHeader, Status } from '../ui'
import { fmtDate, fmtNum, fmtRel, useData } from '../format'

interface ListData { total: number; page: number; incidents: IncidentRow[]; counts: { open: number; acknowledged: number; resolved_7d: number }; rules: Option[] }
interface Detail {
  incident: IncidentRow & {
    details: Record<string, unknown> | null
    investigation: Investigation | null
    auto_action: string | null
    auto_action_at: string | null
    timeline: { at: string; kind: string; text: string; by?: string }[]
    resolution_note: string | null
  }
}

const STATUS: Record<IncidentRow['status'], [string, 'crit' | 'info' | 'ok']> = { open: ['Ouvert', 'crit'], acknowledged: ['Pris en charge', 'info'], resolved: ['Résolu', 'ok'] }
const KIND: Record<string, string> = {
  opened: 'Détection', investigated: 'Enquête', notified: 'Notification', auto_action: 'Action automatique', action: 'Action',
  acknowledged: 'Prise en charge', resolved: 'Résolution', reopened: 'Réouverture', escalated: 'Aggravation', note: 'Note',
}
const ACTION_LABELS: Record<string, string> = {
  run_automation: 'Relancer les automatismes', flush_push: 'Renvoyer les notifications en attente', sms_check: 'Lancer le diagnostic SMS',
  health_check: 'Vérifier l\'état maintenant', clear_config: 'Recharger la configuration de la plateforme',
}
const CONFIDENCE: Record<string, 'ok' | 'warn' | 'muted'> = { 'élevée': 'ok', moyenne: 'warn', faible: 'muted' }

export default function Incidents() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? 'open'
  const severity = params.get('severity') ?? ''
  const selected = params.get('id')
  // Temps reel : la liste se met a jour toute seule.
  const { data, error, loading, reload } = useData<ListData>(`/incidents${qs({ status, severity })}`, 30000)

  const set = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); setParams(p) }

  return (
    <>
      <PageHeader title="Incidents et alertes"
        sub="Problèmes détectés automatiquement chaque minute, avec leur enquête (cause probable, preuves, actions à faire). Une alerte par incident, sauf aggravation." />
      {data && (
        <div className="cx-grid k" style={{ marginBottom: '1rem' }}>
          <div className="cx-kpi cx-kpi-crit"><span className="cx-kpi-label">Ouverts</span><strong className="cx-kpi-value">{data.counts.open}</strong></div>
          <div className="cx-kpi"><span className="cx-kpi-label">Pris en charge</span><strong className="cx-kpi-value">{data.counts.acknowledged}</strong></div>
          <div className="cx-kpi cx-kpi-ok"><span className="cx-kpi-label">Résolus (7 jours)</span><strong className="cx-kpi-value">{data.counts.resolved_7d}</strong></div>
        </div>
      )}
      <div className="cx-filters">
        <div className="cx-segment" role="group" aria-label="État">
          {[['open', 'En cours'], ['resolved', 'Résolus'], ['all', 'Tous']].map(([k, l]) => (
            <button key={k} className={status === k ? 'on' : ''} aria-pressed={status === k} onClick={() => set('status', k)}>{l}</button>
          ))}
        </div>
        <div className="cx-field">
          <label htmlFor="sev">Gravité</label>
          <select id="sev" className="cx-select" value={severity} onChange={(e) => set('severity', e.target.value)}>
            <option value="">Toutes</option><option value="critical">Critique</option><option value="warning">Attention</option>
          </select>
        </div>
      </div>
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && (
        <Card>
          {data.incidents.length === 0 ? <Empty>{status === 'open' ? 'Aucun incident en cours. Tout va bien.' : 'Aucun incident.'}</Empty> : (
            <ul className="cx-list">
              {data.incidents.map((i) => (
                <li key={i.id}>
                  <button className="row" onClick={() => set('id', String(i.id))}>
                    <Status tone={i.severity === 'critical' ? 'crit' : 'warn'}>{i.severity === 'critical' ? 'Critique' : 'Attention'}</Status>
                    <div className="grow">
                      <div className="title">{i.title}</div>
                      {i.cause && <div className="cx-cause">Cause probable : {i.cause}</div>}
                      <div className="meta">
                        <span>{i.rule_label}</span><span>détecté {fmtRel(i.first_seen_at)}</span><span>vu {fmtRel(i.last_seen_at)}</span>
                        <span>{fmtNum(i.occurrences ?? 1)} détection(s)</span>
                        <Badge tone={STATUS[i.status][1]}>{STATUS[i.status][0]}{i.resolved_automatically ? ' automatiquement' : ''}</Badge>
                      </div>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}
      {selected && <IncidentDetail id={selected} onClose={() => set('id', '')} onChanged={reload} />}
    </>
  )
}

function IncidentDetail({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { can } = useConsole()
  const { data, error, loading, reload } = useData<Detail>(`/incidents/${id}`, 30000)
  const [action, setAction] = useState<'acknowledge' | 'resolve' | 'notes' | 'reopen' | null>(null)
  const [fix, setFix] = useState<string | null>(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const i = data?.incident
  const inv = i?.investigation

  const run = async () => {
    const body = { note: note || undefined }
    if (action === 'acknowledge') await capi(`/incidents/${id}/acknowledge`, { method: 'POST', body })
    else if (action === 'resolve') await capi(`/incidents/${id}/resolve`, { method: 'POST', body })
    else if (action === 'notes') await capi(`/incidents/${id}/notes`, { method: 'POST', body })
    else await capi(`/incidents/${id}/reopen`, { method: 'POST' })
    setNote('')
    reload(); onChanged()
  }
  const investigate = async () => {
    setBusy(true)
    try { await capi(`/incidents/${id}/investigate`, { method: 'POST' }); reload() } catch { /* message affiche */ } finally { setBusy(false) }
  }
  const labels = { acknowledge: ['Prendre en charge', 'Note (facultative) : qui s\'en occupe, ce qui est fait'], resolve: ['Marquer comme résolu', 'Ce qui a été fait (obligatoire)'],
    notes: ['Ajouter une note', 'Note'], reopen: ['Rouvrir', ''] } as const

  return (
    <div className="cx-overlay" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <div className="cx-dialog wide" role="dialog" aria-modal="true" aria-label="Détail de l'incident">
        <div className="cx-dialog-head">
          <h2>{i?.title ?? 'Incident'}</h2>
          <button className="cx-icon-btn" onClick={onClose} aria-label="Fermer"><CIcon name="x" /></button>
        </div>
        <div className="cx-dialog-body">
          {error && <ErrorState message={error} onRetry={reload} />}
          {loading && !i && <Loading />}
          {i && <>
            <div className="cx-head-actions">
              <Status tone={i.severity === 'critical' ? 'crit' : 'warn'}>{i.severity === 'critical' ? 'Critique' : 'Attention'}</Status>
              <Badge tone={STATUS[i.status][1]}>{STATUS[i.status][0]}</Badge>
              <span className="cx-help">{i.rule_label}</span>
            </div>
            <p>{i.summary}</p>

            {inv && (
              <section className="cx-investigation" aria-label="Enquête automatique">
                <div className="cx-investigation-head">
                  <strong>Enquête automatique</strong>
                  <Status tone={CONFIDENCE[inv.confidence] ?? 'muted'}>Confiance {inv.confidence}</Status>
                  <span className="cx-help">{fmtRel(inv.investigated_at)}</span>
                  {can('admin') && <button className="cx-btn cx-btn-ghost cx-btn-sm" onClick={investigate} disabled={busy}>{busy ? <span className="cx-spinner" /> : 'Relancer'}</button>}
                </div>
                <p className="cx-investigation-cause">{inv.cause}</p>
                {inv.evidence.length > 0 && (
                  <>
                    <h3>Preuves relevées</h3>
                    <dl className="cx-kv">{inv.evidence.map((e, k) => <div key={k}><dt>{e.label}</dt><dd>{e.value}</dd></div>)}</dl>
                  </>
                )}
                {inv.recommendations.length > 0 && (
                  <>
                    <h3>À faire</h3>
                    <ol className="cx-reco">
                      {inv.recommendations.map((r, k) => (
                        <li key={k}>
                          <span>{r.text}</span>
                          {r.action && can('admin') && (
                            <button className="cx-btn cx-btn-primary cx-btn-sm" onClick={() => setFix(r.action!)}>{ACTION_LABELS[r.action] ?? 'Lancer'}</button>
                          )}
                          {r.link && <Link className="cx-btn cx-btn-ghost cx-btn-sm" to={r.link}>Ouvrir</Link>}
                        </li>
                      ))}
                    </ol>
                  </>
                )}
                {i.auto_action && (
                  <p className="cx-help">Action automatique prévue pour ce type d'incident : {ACTION_LABELS[i.auto_action] ?? i.auto_action}
                    {i.auto_action_at ? `, dernière exécution ${fmtRel(i.auto_action_at)}.` : ' (activable dans Réglages).'}</p>
                )}
              </section>
            )}

            <dl className="cx-kv">
              <div><dt>Première détection</dt><dd>{fmtDate(i.first_seen_at)}</dd></div>
              <div><dt>Dernière détection</dt><dd>{fmtDate(i.last_seen_at)} ({fmtNum(i.occurrences ?? 1)} au total)</dd></div>
              <div><dt>Alerte envoyée</dt><dd>{i.notified_at ? fmtDate(i.notified_at) : 'Non (aucun canal actif ou envoi impossible)'}</dd></div>
              {i.resolved_at && <div><dt>Résolu</dt><dd>{fmtDate(i.resolved_at)}{i.resolution_note ? ` - ${i.resolution_note}` : ''}</dd></div>}
            </dl>
            <h3 style={{ fontSize: '0.92rem' }}>Historique</h3>
            <ol className="cx-timeline">
              {data!.incident.timeline.map((t, k) => (
                <li key={k}><span className="when">{fmtDate(t.at)} - {KIND[t.kind] ?? t.kind}{t.by ? ` - ${t.by}` : ''}</span><div>{t.text}</div></li>
              ))}
            </ol>
            <details>
              <summary className="cx-help" style={{ cursor: 'pointer' }}>Détails techniques</summary>
              <KeyValues data={data!.incident.details} />
            </details>
          </>}
        </div>
        {i && can('admin') && (
          <div className="cx-dialog-foot">
            {i.status !== 'resolved' && i.status !== 'acknowledged' && <button className="cx-btn cx-btn-ghost" onClick={() => setAction('acknowledge')}>Prendre en charge</button>}
            <button className="cx-btn cx-btn-ghost" onClick={() => setAction('notes')}>Ajouter une note</button>
            {i.status !== 'resolved'
              ? <button className="cx-btn cx-btn-primary" onClick={() => setAction('resolve')}>Marquer comme résolu</button>
              : <button className="cx-btn cx-btn-ghost" onClick={() => setAction('reopen')}>Rouvrir</button>}
          </div>
        )}
      </div>
      {action && (
        <Confirm title={labels[action][0]} confirmLabel={labels[action][0]} onClose={() => setAction(null)} onConfirm={run}
          disabled={(action === 'resolve' && note.trim().length < 3) || (action === 'notes' && note.trim().length < 2)}>
          {action === 'reopen' ? <p>L'incident repassera en « ouvert ».</p> : (
            <div className="cx-field">
              <label htmlFor="inc-note">{labels[action][1]}</label>
              <textarea id="inc-note" className="cx-textarea" value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} />
              {action === 'resolve' && <span className="cx-help">S'il réapparaît, l'incident sera rouvert automatiquement.</span>}
            </div>
          )}
        </Confirm>
      )}
      {fix && (
        <Confirm title={ACTION_LABELS[fix] ?? 'Action'} confirmLabel="Confirmer et lancer" danger={fix === 'clear_config'} onClose={() => setFix(null)}
          onConfirm={async () => { await capi(`/actions/${fix}`, { method: 'POST', body: { confirm: true, incident_id: Number(id) } }); reload() }}>
          <p>L'action est exécutée tout de suite, notée dans l'historique de l'incident et dans le journal d'audit. L'état sera vérifié de nouveau à la minute suivante.</p>
        </Confirm>
      )}
    </div>
  )
}
