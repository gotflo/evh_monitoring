import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { HBarChart } from '../components/charts'
import { capi } from '../api'
import { useConsole } from '../auth'
import type { Availability, ErrorGroup, RequestSummary } from '../types'
import { Badge, Card, CIcon, Empty, ErrorState, Kpi, Loading, PageHeader, Status } from '../ui'
import { fmtDate, fmtMs, fmtNum, useData } from '../format'

interface ListItem { id: number; period: string; label: string; period_start: string; period_end: string; sent_at: string | null; created_at: string; manual: boolean }
interface ReportData {
  period: { from: string; to: string; days: number }
  collected_since: string | null
  availability: Availability
  requests: RequestSummary
  requests_previous: RequestSummary
  users: { active: number; logins: number; new_accounts: number; failed_codes: number; total: number; blocked: number }
  trends: Record<'requests' | 'errors' | 'avg_ms' | 'active_users' | 'logins', number | null>
  by_service: { label: string; hits: number; errors: number }[]
  top_errors: ErrorGroup[]
  slow_routes: { route: string; method: string; avg_ms: number | null; hits: number }[]
  integrations: Record<string, { ok: number; failed: number }>
  jobs: Record<string, { runs: number; failed: number }>
  incidents: { opened: number; critical: number; resolved: number; still_open: number; list: { id: number; title: string; severity: string; status: string; first_seen_at: string; resolved_at: string | null }[] }
  events: { errors: number; warnings: number; browser: number }
  security: { denied: number; throttled: number; console_denied: number }
}

export default function Reports() {
  const { can } = useConsole()
  const [params, setParams] = useSearchParams()
  const selected = params.get('id')
  const list = useData<{ reports: ListItem[] }>('/reports')
  const [from, setFrom] = useState(() => new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10))
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10))
  const [send, setSend] = useState(false)
  const [busy, setBusy] = useState(false)

  const generate = async () => {
    setBusy(true)
    try {
      const r = await capi<{ id: number }>('/reports', { method: 'POST', body: { from, to, send } })
      list.reload(); setParams({ id: String(r.id) })
    } catch { /* message affiche */ } finally { setBusy(false) }
  }

  return (
    <>
      <PageHeader title="Rapports" sub="Rapports automatiques (hebdomadaire chaque lundi, mensuel le 1er ; quotidien si activé dans Réglages) et rapports à la demande." />
      <div className="cx-grid side">
        <div className="cx-stack">
          {can('admin') && (
            <Card title="Générer un rapport">
              <div className="cx-stack">
                <div className="cx-field"><label htmlFor="rf">Du</label><input id="rf" type="date" className="cx-input" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
                <div className="cx-field"><label htmlFor="rt">Au</label><input id="rt" type="date" className="cx-input" value={to} onChange={(e) => setTo(e.target.value)} /></div>
                <label className="cx-check"><input type="checkbox" checked={send} onChange={(e) => setSend(e.target.checked)} />L'envoyer aussi par les canaux d'alerte</label>
                <button className="cx-btn cx-btn-primary" onClick={generate} disabled={busy || !from || !to}>{busy ? <span className="cx-spinner" /> : 'Générer'}</button>
              </div>
            </Card>
          )}
          <Card title="Rapports disponibles">
            {list.error && <ErrorState message={list.error} onRetry={list.reload} />}
            {list.loading && !list.data && <Loading />}
            {list.data && (list.data.reports.length === 0 ? <Empty>Aucun rapport pour l'instant. Le premier rapport hebdomadaire sera généré lundi prochain.</Empty> : (
              <ul className="cx-list">{list.data.reports.map((r) => (
                <li key={r.id}><button className="row" onClick={() => setParams({ id: String(r.id) })} aria-current={selected === String(r.id)}>
                  <CIcon name="report" />
                  <div className="grow"><div className="title" style={{ color: selected === String(r.id) ? 'var(--cx-brand)' : undefined }}>{r.label}</div>
                    <div className="meta"><span>{fmtDate(r.period_start, false)} au {fmtDate(r.period_end, false)}</span>{r.sent_at && <Badge tone="ok">envoyé</Badge>}</div></div>
                </button></li>
              ))}</ul>
            ))}
          </Card>
        </div>
        <div>{selected ? <ReportView id={selected} /> : <Card><Empty>Choisissez un rapport dans la liste.</Empty></Card>}</div>
      </div>
    </>
  )
}

function ReportView({ id }: { id: string }) {
  const { data, error, loading, reload } = useData<{ report: { id: number; label: string; period_start: string; period_end: string; created_at: string; sent_at: string | null; delivery: Record<string, { ok: boolean; detail: string }> | null; data: ReportData } }>(`/reports/${id}`)
  if (error) return <ErrorState message={error} onRetry={reload} />
  if (!data) return loading ? <Loading /> : null
  const r = data.report
  const d = r.data
  const avail = d.availability

  return (
    <div className="cx-stack">
      <Card title={`${r.label} - ${fmtDate(r.period_start, false)} au ${fmtDate(r.period_end, false)}`}
        actions={<button className="cx-btn cx-btn-ghost cx-btn-sm cx-no-print" onClick={() => window.print()}><CIcon name="printer" size={14} />Imprimer</button>}>
        <p className="cx-help">Généré le {fmtDate(r.created_at)}{r.sent_at ? ` - envoyé le ${fmtDate(r.sent_at)}` : ' - non envoyé'}. Chiffres figés à la génération ; collecte depuis le {fmtDate(d.collected_since, false)}.</p>
      </Card>
      <div className="cx-grid k">
        <Kpi label="Disponibilité mesurée" value={avail.availability === null ? '-' : `${avail.availability.toLocaleString('fr-CA')} %`} hint={`couverture ${avail.coverage} %`} />
        <Kpi label="Requêtes" value={fmtNum(d.requests.hits)} delta={d.trends.requests} />
        <Kpi label="Erreurs serveur" value={fmtNum(d.requests.errors)} delta={d.trends.errors} invert hint={d.requests.error_rate !== null ? `${d.requests.error_rate} %` : undefined} />
        <Kpi label="Temps moyen" value={fmtMs(d.requests.avg_ms)} delta={d.trends.avg_ms} invert />
        <Kpi label="Membres actifs" value={fmtNum(d.users.active)} delta={d.trends.active_users} />
        <Kpi label="Connexions" value={fmtNum(d.users.logins)} delta={d.trends.logins} hint={`${fmtNum(d.users.new_accounts)} nouveau(x) compte(s)`} />
      </div>
      <div className="cx-grid two">
        <Card title="Incidents">
          <p style={{ fontSize: '0.88rem' }}>{d.incidents.opened} détecté(s) dont {d.incidents.critical} critique(s) - {d.incidents.resolved} résolu(s) - {d.incidents.still_open} encore en cours à la génération.</p>
          {d.incidents.list.length > 0 && <ul className="cx-list mt">{d.incidents.list.map((i) => (
            <li key={i.id}><Status tone={i.severity === 'critical' ? 'crit' : 'warn'}>{i.severity === 'critical' ? 'Critique' : 'Attention'}</Status>
              <div className="grow"><div className="title">{i.title}</div><div className="meta"><span>{fmtDate(i.first_seen_at)}</span><span>{i.resolved_at ? `résolu le ${fmtDate(i.resolved_at)}` : 'non résolu'}</span></div></div></li>
          ))}</ul>}
        </Card>
        <Card title="Erreurs et sécurité">
          <dl className="cx-kv">
            <div><dt>Erreurs journalisées</dt><dd>{fmtNum(d.events.errors)} (dont {fmtNum(d.events.browser)} du navigateur)</dd></div>
            <div><dt>Avertissements</dt><dd>{fmtNum(d.events.warnings)}</dd></div>
            <div><dt>Accès refusés</dt><dd>{fmtNum(d.security.denied)}</dd></div>
            <div><dt>Requêtes limitées</dt><dd>{fmtNum(d.security.throttled)}</dd></div>
            <div><dt>Refus à la console</dt><dd>{fmtNum(d.security.console_denied)}</dd></div>
            <div><dt>Codes erronés</dt><dd>{fmtNum(d.users.failed_codes)}</dd></div>
          </dl>
          {d.top_errors.length > 0 && <ul className="cx-list mt">{d.top_errors.map((e) => <li key={e.fingerprint}><Badge tone="crit">{e.count} fois</Badge><div className="grow"><div className="title">{e.message}</div></div></li>)}</ul>}
        </Card>
      </div>
      <div className="cx-grid two">
        <Card title="Utilisation par service">
          {d.by_service.length === 0 ? <Empty>Aucune donnée.</Empty> : <HBarChart ariaLabel="Requêtes par service" unit="" max={Math.max(...d.by_service.map((s) => s.hits))} rows={d.by_service.map((s) => ({ label: s.label, value: s.hits }))} />}
        </Card>
        <Card title="Intégrations, tâches et lenteurs">
          <dl className="cx-kv">
            <div><dt>SMS (Twilio)</dt><dd>{d.integrations.twilio_verify ? `${fmtNum(d.integrations.twilio_verify.ok)} réussis, ${fmtNum(d.integrations.twilio_verify.failed)} échecs` : 'aucun appel'}</dd></div>
            <div><dt>Notifications push</dt><dd>{d.integrations.webpush ? `${fmtNum(d.integrations.webpush.ok)} appareils atteints, ${fmtNum(d.integrations.webpush.failed)} échecs` : 'aucun envoi'}</dd></div>
            <div><dt>Automatismes</dt><dd>{d.jobs['app:tick'] ? `${fmtNum(d.jobs['app:tick'].runs)} passages, ${fmtNum(d.jobs['app:tick'].failed)} avec échec` : 'aucun passage enregistré'}</dd></div>
          </dl>
          {d.slow_routes.length > 0 && <ul className="cx-list mt">{d.slow_routes.map((s) => <li key={s.method + s.route}><div className="grow"><div className="title cx-mono">{s.method} {s.route}</div><div className="meta"><span>{fmtMs(s.avg_ms)} en moyenne</span><span>{fmtNum(s.hits)} appels</span></div></div></li>)}</ul>}
        </Card>
      </div>
      {r.delivery && Object.keys(r.delivery).length > 0 && (
        <Card title="Envoi">
          <ul className="cx-list">{Object.entries(r.delivery).map(([k, v]) => <li key={k}><Status tone={v.ok ? 'ok' : 'crit'}>{v.ok ? 'Envoyé' : 'Échec'}</Status><div className="grow">{k === 'app' ? 'Application' : k === 'email' ? 'Courriel' : 'Webhook'} - {v.detail}</div></li>)}</ul>
        </Card>
      )}
    </div>
  )
}
