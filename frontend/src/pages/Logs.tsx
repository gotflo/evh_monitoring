import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { LineChart } from '../components/charts'
import { capi, ConsoleError, qs } from '../api'
import { useConsole } from '../auth'
import type { ErrorGroup, LogEvent, Option, RequestRow, RequestSummary, SeriesPoint } from '../types'
import {
  Badge, Card, CIcon, DataTable, Dialog, Empty, ErrorState, Kpi, KeyValues, Loading, Outcome, PageHeader, PeriodPicker, Status,
} from '../ui'
import { fmtBytes, fmtDate, fmtMs, fmtNum, fmtRel, LEVEL_LABEL, LEVEL_TONE, useData } from '../format'
import { VIZ } from '../format'

type Tab = 'events' | 'errors' | 'requests' | 'performance' | 'files'

export default function Logs() {
  const { can } = useConsole()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('tab') as Tab) || 'events'
  const eventId = params.get('event')
  const setTab = (t: Tab) => setParams(new URLSearchParams({ tab: t }))
  const tabs: [Tab, string][] = [['events', 'Journal'], ['errors', 'Erreurs regroupées'], ['requests', 'Requêtes en échec ou lentes'], ['performance', 'Performances']]
  if (can('admin')) tabs.push(['files', 'Fichiers du serveur'])

  return (
    <>
      <PageHeader title="Journaux et erreurs" sub="Serveur, navigateur, intégrations, tâches planifiées, base de données et sécurité, au même endroit. Les codes, numéros, jetons et valeurs privées sont masqués." />
      <div className="cx-tabs" role="tablist">
        {tabs.map(([k, l]) => <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{l}</button>)}
      </div>
      {tab === 'events' && <Events />}
      {tab === 'errors' && <Errors />}
      {tab === 'requests' && <Requests />}
      {tab === 'performance' && <Performance />}
      {tab === 'files' && can('admin') && <Files />}
      {eventId && <EventDetail id={eventId} onClose={() => { const p = new URLSearchParams(params); p.delete('event'); setParams(p) }} />}
    </>
  )
}

function useParam(name: string): [string, (v: string) => void] {
  const [params, setParams] = useSearchParams()
  return [params.get(name) ?? '', (v) => { const p = new URLSearchParams(params); if (v) p.set(name, v); else p.delete(name); p.delete('event'); setParams(p) }]
}

function Events() {
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(['level', 'service', 'type', 'q', 'request_id', 'user_id', 'fingerprint', 'period'].map((k) => [k, params.get(k) ?? '']))
  const [q, setQ] = useState(f.q)
  const [rid, setRid] = useState(f.request_id)
  const set = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); setParams(p) }
  const query = { ...f, period: f.period || '24h' }
  const base = `/logs${qs(query)}`
  const [pages, setPages] = useState<{ events: LogEvent[]; next: number | null }>({ events: [], next: null })
  // En direct : nouveaux evenements toutes les 10 secondes.
  const [live, setLive] = useState(false)
  const { data, error, loading, reload } = useData<{ events: LogEvent[]; next_before_id: number | null; filters: { services: Option[]; types: Option[] } }>(base, live ? 10000 : 0)
  const [more, setMore] = useState(false)

  useEffect(() => { if (data) setPages({ events: data.events, next: data.next_before_id }) }, [data])

  const loadMore = async () => {
    setMore(true)
    try {
      const r = await capi<{ events: LogEvent[]; next_before_id: number | null }>(`/logs${qs({ ...query, before_id: pages.next })}`)
      setPages((p) => ({ events: [...p.events, ...r.events], next: r.next_before_id }))
    } finally { setMore(false) }
  }

  return (
    <>
      <div className="cx-filters">
        <PeriodPicker value={f.period || '24h'} onChange={(v) => set('period', v)} options={['24h', '7d', '30d', '90d']} />
        <button className={`cx-btn ${live ? 'cx-btn-primary' : 'cx-btn-ghost'}`} aria-pressed={live} onClick={() => setLive((l) => !l)}>
          <span className={`cx-live ${live ? '' : 'off'}`} />{live ? 'En direct' : 'Passer en direct'}
        </button>
        <div className="cx-field"><label htmlFor="lv">Niveau minimal</label>
          <select id="lv" className="cx-select" value={f.level} onChange={(e) => set('level', e.target.value)}>
            <option value="">Tous</option>
            {['info', 'notice', 'warning', 'error', 'critical'].map((l) => <option key={l} value={l}>{LEVEL_LABEL[l]}</option>)}
          </select></div>
        <div className="cx-field"><label htmlFor="sv">Source</label>
          <select id="sv" className="cx-select" value={f.service} onChange={(e) => set('service', e.target.value)}>
            <option value="">Toutes</option>{data?.filters.services.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
          </select></div>
        <div className="cx-field"><label htmlFor="tp">Type</label>
          <select id="tp" className="cx-select" value={f.type} onChange={(e) => set('type', e.target.value)}>
            <option value="">Tous</option>{data?.filters.types.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
          </select></div>
        <form className="cx-field wide" onSubmit={(e) => { e.preventDefault(); set('q', q.trim()) }}>
          <label htmlFor="lq">Rechercher dans les messages</label>
          <input id="lq" className="cx-input" value={q} onChange={(e) => setQ(e.target.value)} placeholder="ex. Twilio, SQL, Members..." onBlur={() => q.trim() !== f.q && set('q', q.trim())} />
        </form>
        <form className="cx-field" onSubmit={(e) => { e.preventDefault(); set('request_id', rid.trim()) }}>
          <label htmlFor="rid">Identifiant de requête</label>
          <input id="rid" className="cx-input cx-mono" value={rid} onChange={(e) => setRid(e.target.value)} placeholder="X-Request-Id" onBlur={() => rid.trim() !== f.request_id && set('request_id', rid.trim())} />
        </form>
      </div>
      {(f.user_id || f.fingerprint) && (
        <div className="cx-head-actions" style={{ marginBottom: '0.8rem' }}>
          {f.user_id && <Badge tone="info">Membre #{f.user_id} <button className="cx-link" onClick={() => set('user_id', '')} aria-label="Retirer le filtre">x</button></Badge>}
          {f.fingerprint && <Badge tone="info">Même erreur <button className="cx-link" onClick={() => set('fingerprint', '')} aria-label="Retirer le filtre">x</button></Badge>}
        </div>
      )}
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && (
        <Card>
          {pages.events.length === 0 ? <Empty>Aucun événement pour ces critères.</Empty> : (
            <div className="cx-table-wrap">
              <table className="cx-table responsive">
                <thead><tr><th>Date</th><th>Niveau</th><th>Source</th><th>Message</th><th>Résultat</th></tr></thead>
                <tbody>
                  {pages.events.map((e) => (
                    <tr key={e.id} className="clickable" onClick={() => set('event', String(e.id))} tabIndex={0} onKeyDown={(k) => { if (k.key === 'Enter') set('event', String(e.id)) }}>
                      <td data-label="Date" style={{ whiteSpace: 'nowrap' }}>{fmtDate(e.created_at)}</td>
                      <td data-label="Niveau"><Status tone={LEVEL_TONE[e.level] ?? 'muted'}>{LEVEL_LABEL[e.level] ?? e.level}</Status></td>
                      <td data-label="Source">{e.service_label}<div className="cx-help">{e.type_label}</div></td>
                      <td data-label="Message" className="msg">{e.message}{e.route && <div className="cx-help cx-mono">{e.route}</div>}</td>
                      <td data-label="Résultat"><Outcome value={e.outcome} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {pages.next && <div className="cx-pager"><button className="cx-btn cx-btn-ghost" onClick={loadMore} disabled={more}>{more ? <span className="cx-spinner" /> : 'Charger plus'}</button></div>}
        </Card>
      )}
    </>
  )
}

interface EventDetailData {
  event: LogEvent & { context: Record<string, unknown> | null; ip: string | null }
  request: RequestRow | null
  related: LogEvent[]
  similar: { total: number; last_24h: number; last_7d: number; users: number; first_at: string | null; last_at: string | null; recent: { id: number; created_at: string; user_id: number | null; request_id: string | null; route: string | null }[] } | null
}

function EventDetail({ id, onClose }: { id: string; onClose: () => void }) {
  const { data, error, loading, reload } = useData<EventDetailData>(`/logs/${id}`)
  const [, setParams] = useSearchParams()
  const e = data?.event
  const ctx = e?.context ?? {}
  const { trace, ...rest } = ctx as { trace?: string[] } & Record<string, unknown>
  const open = (p: Record<string, string>) => setParams(new URLSearchParams(p))

  return (
    <Dialog title="Détail de l'événement" onClose={onClose} wide>
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !e && <Loading />}
      {e && <>
        <div className="cx-head-actions">
          <Status tone={LEVEL_TONE[e.level] ?? 'muted'}>{LEVEL_LABEL[e.level] ?? e.level}</Status>
          <Badge>{e.service_label}</Badge><Badge>{e.type_label}</Badge><Outcome value={e.outcome} />
        </div>
        <p style={{ fontWeight: 600, overflowWrap: 'anywhere' }}>{e.message}</p>
        <dl className="cx-kv">
          <div><dt>Date</dt><dd>{fmtDate(e.created_at)} ({fmtRel(e.created_at)})</dd></div>
          {e.route && <div><dt>Route</dt><dd className="cx-mono">{e.route}</dd></div>}
          {e.request_id && <div><dt>Requête</dt><dd className="cx-mono">{e.request_id}</dd></div>}
          {e.user_id && <div><dt>Membre</dt><dd><Link to={`/utilisateurs/${e.user_id}`}>Compte #{e.user_id}</Link></dd></div>}
          {e.ip && <div><dt>Adresse IP</dt><dd>{e.ip}</dd></div>}
        </dl>
        {data!.request && (
          <Card title="Requête HTTP">
            <dl className="cx-kv">
              <div><dt>Appel</dt><dd className="cx-mono">{data!.request.method} {data!.request.route}</dd></div>
              <div><dt>Réponse</dt><dd>HTTP {data!.request.status} en {fmtMs(data!.request.duration_ms)} - {data!.request.queries} requête(s) SQL ({fmtMs(data!.request.db_ms)})</dd></div>
            </dl>
          </Card>
        )}
        {Array.isArray(trace) && trace.length > 0 && (
          <div><h3 style={{ fontSize: '0.9rem', marginBottom: '0.3rem' }}>Pile d'appels (application)</h3><pre className="cx-pre">{trace.join('\n')}</pre></div>
        )}
        <div><h3 style={{ fontSize: '0.9rem', marginBottom: '0.3rem' }}>Contexte</h3><KeyValues data={rest} /></div>
        {data!.related.length > 0 && (
          <div>
            <h3 style={{ fontSize: '0.9rem', marginBottom: '0.3rem' }}>Événements de la même requête</h3>
            <ul className="cx-list">{data!.related.map((r) => (
              <li key={r.id}><Status tone={LEVEL_TONE[r.level] ?? 'muted'}>{LEVEL_LABEL[r.level] ?? r.level}</Status>
                <div className="grow"><button className="cx-link title" onClick={() => open({ event: String(r.id) })}>{r.message}</button><div className="meta">{r.type_label}</div></div></li>
            ))}</ul>
          </div>
        )}
        {data!.similar && (
          <div className="cx-note">
            Même erreur : <strong>{fmtNum(data!.similar.total)}</strong> fois en tout ({fmtNum(data!.similar.last_24h)} en 24 h, {fmtNum(data!.similar.last_7d)} en 7 jours),
            {' '}{fmtNum(data!.similar.users)} membre(s) touché(s), de {fmtDate(data!.similar.first_at)} à {fmtDate(data!.similar.last_at)}.
            {' '}<button className="cx-link" onClick={() => open({ fingerprint: e.fingerprint ?? '', period: '90d' })}>Voir toutes les occurrences</button>
          </div>
        )}
      </>}
    </Dialog>
  )
}

function Errors() {
  const [period, setPeriod] = useParam('period')
  const [service, setService] = useParam('service')
  const { data, error, loading, reload } = useData<{ groups: ErrorGroup[]; series: { key: string; label: string; errors: number; warnings: number }[] }>(`/logs/errors${qs({ period: period || '7d', service })}`)
  return (
    <>
      <div className="cx-filters">
        <PeriodPicker value={period || '7d'} onChange={setPeriod} options={['24h', '7d', '30d', '90d']} />
        <div className="cx-field"><label htmlFor="es">Source</label>
          <select id="es" className="cx-select" value={service} onChange={(e) => setService(e.target.value)}>
            <option value="">Toutes</option>
            {[['api', 'Serveur (API)'], ['browser', 'Navigateur'], ['database', 'Base de données'], ['sms', 'SMS (Twilio Verify)'], ['push', 'Notifications push'], ['automation', 'Tâches planifiées']].map(([k, l]) => <option key={k} value={k}>{l}</option>)}
          </select></div>
      </div>
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && <div className="cx-stack">
        <Card title="Erreurs et avertissements par jour">
          {data.series.every((s) => !s.errors && !s.warnings) ? <Empty>Aucune erreur ni avertissement sur la période.</Empty> : <>
            <LineChart ariaLabel="Erreurs et avertissements par jour" unit="" labels={data.series.map((s) => s.label)}
              series={[{ key: 'e', label: 'Erreurs', color: VIZ.s2, values: data.series.map((s) => s.errors) }, { key: 'w', label: 'Avertissements', color: VIZ.s1, values: data.series.map((s) => s.warnings) }]} />
            <DataTable head={['Jour', 'Erreurs', 'Avertissements']} rows={data.series.map((s) => [s.label, s.errors, s.warnings])} />
          </>}
        </Card>
        <Card title="Erreurs regroupées par cause">
          {data.groups.length === 0 ? <Empty>Aucune erreur sur la période.</Empty> : (
            <div className="cx-table-wrap"><table className="cx-table responsive">
              <thead><tr><th>Erreur</th><th>Source</th><th className="num">Occurrences</th><th className="num">Membres</th><th>Première</th><th>Dernière</th></tr></thead>
              <tbody>{data.groups.map((g) => (
                <tr key={g.fingerprint}>
                  <td data-label="Erreur" className="msg"><Link to={`/journaux?fingerprint=${g.fingerprint}&period=90d`}>{g.message}</Link></td>
                  <td data-label="Source">{g.service}</td>
                  <td data-label="Occurrences" className="num">{fmtNum(g.count)}</td>
                  <td data-label="Membres" className="num">{fmtNum(g.users)}</td>
                  <td data-label="Première">{fmtDate(g.first_at)}</td>
                  <td data-label="Dernière">{fmtRel(g.last_at)}</td>
                </tr>
              ))}</tbody>
            </table></div>
          )}
        </Card>
      </div>}
    </>
  )
}

function Requests() {
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(['reason', 'status', 'route', 'request_id', 'user_id', 'period'].map((k) => [k, params.get(k) ?? '']))
  const set = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); setParams(p) }
  const [route, setRoute] = useState(f.route)
  const { data, error, loading, reload } = useData<{ requests: RequestRow[]; next_before_id: number | null }>(`/logs/requests${qs({ ...f, period: f.period || '24h' })}`)
  const REASON: Record<string, [string, 'crit' | 'warn' | 'info']> = { error: ['Erreur serveur', 'crit'], slow: ['Lente', 'warn'], denied: ['Refusée', 'warn'], throttled: ['Limitée', 'info'] }
  return (
    <>
      <div className="cx-filters">
        <PeriodPicker value={f.period || '24h'} onChange={(v) => set('period', v)} options={['24h', '7d', '30d']} />
        <div className="cx-field"><label htmlFor="rr">Motif</label>
          <select id="rr" className="cx-select" value={f.reason} onChange={(e) => set('reason', e.target.value)}>
            <option value="">Tous</option>{Object.entries(REASON).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}
          </select></div>
        <form className="cx-field wide" onSubmit={(e) => { e.preventDefault(); set('route', route.trim()) }}>
          <label htmlFor="rt">Route contient</label>
          <input id="rt" className="cx-input cx-mono" value={route} onChange={(e) => setRoute(e.target.value)} onBlur={() => route.trim() !== f.route && set('route', route.trim())} placeholder="admin/members" />
        </form>
      </div>
      <p className="cx-help" style={{ marginBottom: '0.6rem' }}>Seules les requêtes notables sont conservées en détail (erreurs serveur, refus 401/403, limites 429, lenteurs) ; toutes les autres sont comptées dans les statistiques.</p>
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && <Card>
        {data.requests.length === 0 ? <Empty>Aucune requête notable sur la période.</Empty> : (
          <div className="cx-table-wrap"><table className="cx-table responsive">
            <thead><tr><th>Date</th><th>Motif</th><th>Appel</th><th className="num">Statut</th><th className="num">Durée</th><th className="num">SQL</th><th>Membre</th><th>Requête</th></tr></thead>
            <tbody>{data.requests.map((r) => (
              <tr key={r.id}>
                <td data-label="Date" style={{ whiteSpace: 'nowrap' }}>{fmtDate(r.created_at)}</td>
                <td data-label="Motif"><Status tone={REASON[r.reason]?.[1] ?? 'muted'}>{REASON[r.reason]?.[0] ?? r.reason}</Status></td>
                <td data-label="Appel" className="cx-mono msg">{r.method} {r.route}<div className="cx-help">{r.service}</div></td>
                <td data-label="Statut" className="num">{r.status}</td>
                <td data-label="Durée" className="num">{fmtMs(r.duration_ms)}</td>
                <td data-label="SQL" className="num">{r.queries}</td>
                <td data-label="Membre">{r.user_id ? <Link to={`/utilisateurs/${r.user_id}`}>#{r.user_id}</Link> : '-'}</td>
                <td data-label="Requête"><Link className="cx-mono" to={`/journaux?request_id=${r.request_id}`}>{r.request_id}</Link></td>
              </tr>
            ))}</tbody>
          </table></div>
        )}
      </Card>}
    </>
  )
}

function Performance() {
  const [period, setPeriod] = useParam('period')
  const { data, error, loading, reload } = useData<{
    summary: RequestSummary; series: SeriesPoint[]; slow_threshold_ms: number; slow_query_ms: number
    routes: { route: string; method: string; service: string; hits: number; avg_ms: number | null; p95_ms: number | null; max_ms: number; slow: number; errors: number; avg_queries: number | null }[]
    slow_queries: { id: number; created_at: string; message: string; sql: string | null; route: string | null }[]
  }>(`/logs/performance${qs({ period: period || '24h' })}`)
  return (
    <>
      <div className="cx-filters"><PeriodPicker value={period || '24h'} onChange={setPeriod} options={['24h', '7d', '30d']} /></div>
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && <div className="cx-stack">
        <div className="cx-grid k">
          <Kpi label="Temps moyen" value={fmtMs(data.summary.avg_ms)} />
          <Kpi label="95 % des requêtes sous" value={data.summary.p95_ms !== null ? `environ ${fmtMs(data.summary.p95_ms)}` : '-'} hint="estimation par tranches" />
          <Kpi label="Plus longue" value={fmtMs(data.summary.max_ms)} />
          <Kpi label="Requêtes lentes" value={fmtNum(data.summary.slow)} hint={`au-delà de ${fmtMs(data.slow_threshold_ms)}`} tone={data.summary.slow ? 'warn' : undefined} />
          <Kpi label="Requêtes SQL par appel" value={data.summary.avg_queries ?? '-'} hint={data.summary.avg_db_ms !== null ? `${fmtMs(data.summary.avg_db_ms)} en base` : undefined} />
        </div>
        <Card title="Temps de réponse moyen et requêtes lentes">
          {data.summary.hits === 0 ? <Empty>Aucune mesure sur la période.</Empty> : <>
            <LineChart ariaLabel="Temps de réponse moyen" unit=" ms" labels={data.series.map((s) => s.label)} series={[{ key: 'avg', label: 'Moyenne (ms)', color: VIZ.s1, values: data.series.map((s) => s.avg_ms) }]} />
            <DataTable head={['Période', 'Requêtes', 'Moyenne', 'Lentes']} rows={data.series.map((s) => [s.label, s.hits, fmtMs(s.avg_ms), s.slow])} />
          </>}
        </Card>
        <Card title="Écrans et actions les plus lents">
          {data.routes.length === 0 ? <Empty>Aucune donnée.</Empty> : (
            <div className="cx-table-wrap"><table className="cx-table responsive">
              <thead><tr><th>Route</th><th>Service</th><th className="num">Appels</th><th className="num">Moyenne</th><th className="num">95 %</th><th className="num">Max</th><th className="num">Lentes</th><th className="num">Erreurs</th><th className="num">SQL</th></tr></thead>
              <tbody>{data.routes.map((r) => (
                <tr key={r.method + r.route}>
                  <td data-label="Route" className="cx-mono msg">{r.method} {r.route}</td><td data-label="Service">{r.service}</td>
                  <td data-label="Appels" className="num">{fmtNum(r.hits)}</td><td data-label="Moyenne" className="num">{fmtMs(r.avg_ms)}</td>
                  <td data-label="95 %" className="num">{fmtMs(r.p95_ms)}</td><td data-label="Max" className="num">{fmtMs(r.max_ms)}</td>
                  <td data-label="Lentes" className="num">{fmtNum(r.slow)}</td><td data-label="Erreurs" className="num">{fmtNum(r.errors)}</td>
                  <td data-label="SQL" className="num">{r.avg_queries ?? '-'}</td>
                </tr>
              ))}</tbody>
            </table></div>
          )}
        </Card>
        <Card title={`Requêtes SQL lentes (au-delà de ${fmtMs(data.slow_query_ms)})`}>
          {data.slow_queries.length === 0 ? <Empty>Aucune requête SQL lente sur la période.</Empty> : (
            <ul className="cx-list">{data.slow_queries.map((s) => (
              <li key={s.id}><div className="grow"><div className="title">{s.message}</div><div className="meta"><span>{fmtDate(s.created_at)}</span>{s.route && <span className="cx-mono">{s.route}</span>}</div>
                {s.sql && <pre className="cx-pre">{s.sql}</pre>}</div></li>
            ))}</ul>
          )}
        </Card>
      </div>}
    </>
  )
}

function Files() {
  const [source, setSource] = useState<'platform' | 'console'>('platform')
  const { data, error, loading, reload } = useData<{ files: { name: string; size: number; modified_at: string }[] }>(`/logs/files${qs({ source })}`)
  const [name, setName] = useState('')
  const [q, setQ] = useState('')
  const [lines, setLines] = useState<string[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const open = async (file: string, search = q) => {
    setName(file); setBusy(true); setErr('')
    try {
      const r = await capi<{ lines: string[] }>(`/logs/files/${encodeURIComponent(file)}${qs({ lines: 300, q: search.trim(), source })}`)
      setLines(r.lines)
    } catch (e) { setErr(e instanceof ConsoleError ? e.firstMessage : 'Lecture impossible.'); setLines(null) } finally { setBusy(false) }
  }

  return (
    <div className="cx-stack">
      <div className="cx-segment" role="group" aria-label="Serveur">
        {([['platform', 'Plateforme'], ['console', 'Console']] as const).map(([k, l]) => (
          <button key={k} className={source === k ? 'on' : ''} aria-pressed={source === k} onClick={() => { setSource(k); setName(''); setLines(null) }}>{l}</button>
        ))}
      </div>
      <div className="cx-note">Lecture seule des fichiers <span className="cx-mono">storage/logs</span> {source === 'platform' ? 'de la plateforme (transmis par l\'agent, masqués à la source)' : 'de la console'}. Chaque consultation est consignée dans le journal d'audit.</div>
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && (data.files.length === 0 ? <Card><Empty>Aucun fichier journal sur le serveur.</Empty></Card> : (
        <Card title="Fichiers">
          <ul className="cx-list">{data.files.map((f) => (
            <li key={f.name}><CIcon name="logs" /><div className="grow"><button className="cx-link title" onClick={() => open(f.name)}>{f.name}</button>
              <div className="meta"><span>{fmtBytes(f.size)}</span><span>modifié {fmtRel(f.modified_at)}</span></div></div></li>
          ))}</ul>
        </Card>
      ))}
      {name && (
        <Card title={name} actions={(
          <form onSubmit={(e) => { e.preventDefault(); void open(name) }} className="cx-head-actions">
            <input className="cx-input" style={{ width: 220 }} value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filtrer (ex. ERROR)" aria-label="Filtrer les lignes" />
            <button className="cx-btn cx-btn-ghost cx-btn-sm" disabled={busy}>Filtrer</button>
          </form>
        )}>
          {err && <ErrorState message={err} />}
          {busy ? <Loading /> : lines && (lines.length === 0 ? <Empty>Aucune ligne.</Empty>
            : <div className="cx-pre cx-logfile">{lines.slice().reverse().map((l, i) => <div className="entry" key={i}>{l}</div>)}</div>)}
          <p className="cx-help mt">Les 300 dernières entrées, de la plus récente à la plus ancienne.</p>
        </Card>
      )}
    </div>
  )
}
