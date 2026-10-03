import { useEffect, useState } from 'react'
import { capi } from '../api'
import { useConsole } from '../auth'
import { Badge, Card, ErrorState, Loading, PageHeader, Status } from '../ui'
import { fmtDate, useData } from '../format'

interface Param { key: string; label: string; unit: string; default: number; min: number; max: number }
interface Rule { key: string; label: string; help: string; severity: 'warning' | 'critical'; auto_action: string | null; params: Param[]; values: Record<string, number | boolean> }
interface Channel { key: 'app' | 'email' | 'webhook'; label: string; enabled: boolean; configured: boolean; active: boolean; detail: string }
interface Data {
  rules: Rule[]
  retention: { events_days: number; metrics_days: number; audit_days: number }
  retention_bounds: Record<string, [number, number]>
  reports: { daily: boolean; weekly: boolean; monthly: boolean }
  channels: Channel[]
  collection: { session_hours: number; collected_since: string | null; platform_url: string; agent_configured: boolean }
}

const RETENTION_LABELS: Record<string, [string, string]> = {
  events_days: ['Journaux et requêtes détaillées', 'Erreurs, connexions, événements de sécurité, requêtes notables.'],
  metrics_days: ['Mesures et incidents résolus', 'Compteurs de requêtes, disponibilité, intégrations, tâches, jours d\'activité.'],
  audit_days: ['Journal d\'audit de la console et rapports', 'Traçabilité des actions des administrateurs.'],
}

export default function Settings() {
  const { can } = useConsole()
  const { data, error, loading, reload } = useData<Data>('/settings')
  return (
    <>
      <PageHeader title="Réglages" sub="Seuils d'alerte, canaux, rapports et durées de conservation. Les identifiants secrets restent dans le fichier .env du serveur." />
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && <div className="cx-stack">
        <Channels data={data} canEdit={can('admin')} onSaved={reload} />
        <Rules rules={data.rules} canEdit={can('admin')} onSaved={reload} />
        <Retention data={data} canEdit={can('owner')} onSaved={reload} />
        <Card title="Liaison avec la plateforme (fichier .env de la console)">
          <dl className="cx-kv">
            <div><dt>Plateforme supervisée</dt><dd className="cx-mono">{data.collection.platform_url} <span className="cx-help">PLATFORM_URL</span></dd></div>
            <div><dt>Secret partagé</dt><dd>{data.collection.agent_configured ? <Status tone="ok">Configuré</Status> : <Status tone="warn">Absent ou trop court (MONITOR_AGENT_SECRET)</Status>}</dd></div>
            <div><dt>Données depuis</dt><dd>{fmtDate(data.collection.collected_since)}</dd></div>
            <div><dt>Durée d'une session de la console</dt><dd>{data.collection.session_hours} h <span className="cx-help">CONSOLE_SESSION_HOURS</span></dd></div>
          </dl>
          <p className="cx-help mt">Seuils de collecte (requêtes lentes, niveau des journaux) : variables MONITOR_* du fichier .env de la plateforme.</p>
        </Card>
      </div>}
    </>
  )
}

function Channels({ data, canEdit, onSaved }: { data: Data; canEdit: boolean; onSaved: () => void }) {
  const [busy, setBusy] = useState(false)
  const save = async (body: unknown) => {
    setBusy(true)
    try { await capi('/settings/notifications', { method: 'PUT', body }); onSaved() } catch { /* message affiche */ } finally { setBusy(false) }
  }
  return (
    <Card title="Canaux d'alerte et rapports">
      <ul className="cx-list">{data.channels.map((c) => (
        <li key={c.key}>
          <Status tone={c.active ? 'ok' : c.configured ? 'muted' : 'warn'}>{c.active ? 'Actif' : !c.configured ? 'Non configuré' : 'Désactivé'}</Status>
          <div className="grow"><div className="title">{c.label}</div><div className="meta"><span>{c.detail}</span></div></div>
          {canEdit && <label className="cx-check"><input type="checkbox" checked={c.enabled} disabled={busy} onChange={(e) => save({ channels: { [c.key]: e.target.checked } })} />Utiliser</label>}
        </li>
      ))}</ul>
      <p className="cx-help mt">Courriel : renseigner MAIL_MAILER (et ses identifiants) et MONITOR_ALERT_EMAILS. Webhook : MONITOR_ALERT_WEBHOOK_URL et MONITOR_ALERT_WEBHOOK_FORMAT (slack, discord ou json). Application : la personne de la console doit avoir un compte membre avec le même numéro. Testez depuis Dépannage.</p>
      <h3 style={{ fontSize: '0.9rem', margin: '1rem 0 0.4rem' }}>Rapports automatiques</h3>
      <div className="cx-head-actions">
        {([['daily', 'Quotidien (vers 7 h)'], ['weekly', 'Hebdomadaire (lundi, 8 h)'], ['monthly', 'Mensuel (le 1er, 8 h)']] as const).map(([k, l]) => (
          <label key={k} className="cx-check"><input type="checkbox" checked={data.reports[k]} disabled={!canEdit || busy} onChange={(e) => save({ reports: { [k]: e.target.checked } })} />{l}</label>
        ))}
      </div>
    </Card>
  )
}

function Rules({ rules, canEdit, onSaved }: { rules: Rule[]; canEdit: boolean; onSaved: () => void }) {
  const [values, setValues] = useState<Record<string, Record<string, number | boolean>>>({})
  const [busy, setBusy] = useState(false)
  useEffect(() => { setValues(Object.fromEntries(rules.map((r) => [r.key, { ...r.values }]))) }, [rules])
  const dirty = rules.some((r) => JSON.stringify(r.values) !== JSON.stringify(values[r.key]))
  const invalid = rules.some((r) => r.params.some((p) => { const v = Number(values[r.key]?.[p.key]); return Number.isNaN(v) || v < p.min || v > p.max }))

  const save = async () => {
    setBusy(true)
    try { await capi('/settings/rules', { method: 'PUT', body: { rules: values } }); onSaved() } catch { /* message affiche */ } finally { setBusy(false) }
  }
  const set = (rule: string, key: string, v: number | boolean) => setValues((s) => ({ ...s, [rule]: { ...s[rule], [key]: v } }))

  return (
    <Card title="Détection automatique et seuils d'alerte" actions={canEdit && (
      <button className="cx-btn cx-btn-primary cx-btn-sm" onClick={save} disabled={!dirty || invalid || busy}>{busy ? <span className="cx-spinner" /> : 'Enregistrer'}</button>
    )}>
      <ul className="cx-list">{rules.map((r) => (
        <li key={r.key} style={{ flexDirection: 'column', gap: '0.45rem' }}>
          <div style={{ display: 'flex', gap: '0.6rem', alignItems: 'center', flexWrap: 'wrap' }}>
            <label className="cx-check"><input type="checkbox" checked={!!values[r.key]?.enabled} disabled={!canEdit} onChange={(e) => set(r.key, 'enabled', e.target.checked)} /><strong>{r.label}</strong></label>
            <Badge tone={r.severity === 'critical' ? 'crit' : 'warn'}>{r.severity === 'critical' ? 'Critique' : 'Attention'}</Badge>
            {r.auto_action && (
              <label className="cx-check"><input type="checkbox" checked={!!values[r.key]?.auto} disabled={!canEdit || !values[r.key]?.enabled}
                onChange={(e) => set(r.key, 'auto', e.target.checked)} />Action automatique : {r.auto_action}</label>
            )}
          </div>
          <p className="cx-help">{r.help}</p>
          {r.params.length > 0 && (
            <div className="cx-filters" style={{ marginBottom: 0 }}>
              {r.params.map((p) => (
                <div className="cx-field" key={p.key}>
                  <label htmlFor={`${r.key}-${p.key}`}>{p.label}{p.unit ? ` (${p.unit})` : ''}</label>
                  <input id={`${r.key}-${p.key}`} className="cx-input" type="number" min={p.min} max={p.max} disabled={!canEdit || !values[r.key]?.enabled}
                    value={String(values[r.key]?.[p.key] ?? '')} onChange={(e) => set(r.key, p.key, e.target.value === '' ? NaN : Number(e.target.value))} />
                  <span className="cx-help">{p.min} à {p.max} - défaut {p.default}</span>
                </div>
              ))}
            </div>
          )}
        </li>
      ))}</ul>
      {!canEdit && <p className="cx-help mt">Lecture seule : un administrateur peut modifier les seuils.</p>}
    </Card>
  )
}

function Retention({ data, canEdit, onSaved }: { data: Data; canEdit: boolean; onSaved: () => void }) {
  const [v, setV] = useState<Record<string, number>>(data.retention)
  const [busy, setBusy] = useState(false)
  useEffect(() => { setV(data.retention) }, [data.retention])
  const invalid = Object.entries(data.retention_bounds).some(([k, [min, max]]) => !(v[k] >= min && v[k] <= max))
  const save = async () => {
    setBusy(true)
    try { await capi('/settings/retention', { method: 'PUT', body: v }); onSaved() } catch { /* message affiche */ } finally { setBusy(false) }
  }
  return (
    <Card title="Durées de conservation" actions={canEdit && <button className="cx-btn cx-btn-primary cx-btn-sm" onClick={save} disabled={busy || invalid}>Enregistrer</button>}>
      <div className="cx-filters" style={{ marginBottom: 0 }}>
        {Object.entries(RETENTION_LABELS).map(([k, [label, help]]) => (
          <div className="cx-field wide" key={k}>
            <label htmlFor={`ret-${k}`}>{label} (jours)</label>
            <input id={`ret-${k}`} className="cx-input" type="number" min={data.retention_bounds[k][0]} max={data.retention_bounds[k][1]} disabled={!canEdit}
              value={Number.isNaN(v[k]) ? '' : v[k]} onChange={(e) => setV((s) => ({ ...s, [k]: e.target.value === '' ? NaN : Number(e.target.value) }))} />
            <span className="cx-help">{help} Entre {data.retention_bounds[k][0]} et {data.retention_bounds[k][1]}.</span>
          </div>
        ))}
      </div>
      <p className="cx-help mt">Nettoyage automatique une fois par jour. {canEdit ? 'Pour l\'appliquer tout de suite : Dépannage, « Appliquer la conservation ».' : 'Seul un propriétaire peut modifier ces durées.'}</p>
    </Card>
  )
}
