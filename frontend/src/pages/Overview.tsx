import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ColumnChart, HBarChart, LineChart } from '../components/charts'
import { qs } from '../api'
import type { Availability, ErrorGroup, Health, IncidentRow, RequestSummary, SeriesPoint } from '../types'
import { Badge, Card, CIcon, DataTable, Empty, ErrorState, Kpi, Loading, PageHeader, PeriodPicker, Status } from '../ui'
import { fmtDate, fmtDuration, fmtMs, fmtNum, fmtRel, LEVEL_LABEL, LEVEL_TONE, trend, useData, type Tone } from '../format'
import { VIZ } from '../format'

interface OverviewData {
  generated_at: string
  collected_since: string | null
  last_check: string | null
  period: { from: string; to: string; step: 'hour' | 'day' }
  health: Health
  uptime: { up_since: string | null; server_seconds: number | null; deployed_at: string | null; availability: Record<'24h' | '7d' | '30d', Availability>
    response_series: { at: string; label: string; status: string; ms: number | null }[] }
  metrics_readable: boolean
  incidents: { open: number; critical: number; list: (IncidentRow & { cause?: string | null })[] }
  requests: RequestSummary
  requests_previous: RequestSummary
  series: SeriesPoint[]
  by_service: { service: string; label: string; hits: number; errors: number; slow: number; avg_ms: number | null }[]
  by_action: { action: string; label: string; hits: number; failed: number }[]
  users: { total: number; active_status: number; blocked: number; today: number; last_7_days: number; last_30_days: number; seen_30_days: number; in_period: number; new_accounts: number; logins: number; failed_codes: number }
  user_series: { key: string; label: string; active: number; logins: number; new_accounts: number }[]
  top_errors: ErrorGroup[]
  recent_events: { id: number; created_at: string; level: string; service: string; type: string; message: string }[]
  integrations: Record<string, { ok: number; failed: number; avg_ms: number | null }>
  jobs: Record<string, { runs: number; failed: number; avg_ms: number; last_at: string | null }>
}

const GLOBAL: Record<Health['status'], [string, string]> = {
  ok: ['Services opérationnels', 'Tous les contrôles de santé sont au vert.'],
  degraded: ['Fonctionnement dégradé', 'Un ou plusieurs services demandent attention ; les membres peuvent utiliser l\'application.'],
  down: ['Plateforme indisponible', 'Un élément essentiel ne répond plus.'],
}

const fmtPct = (v: number | null | undefined) => (v === null || v === undefined ? '-' : `${v.toLocaleString('fr-CA')} %`)

export default function Overview() {
  const [period, setPeriod] = useState('24h')
  // Temps reel : mise a jour toute seule toutes les 30 secondes (onglet visible).
  const { data, error, loading, reload } = useData<OverviewData>(`/overview${qs({ period })}`, 30000)

  return (
    <>
      <PageHeader
        title="Tableau de bord"
        sub={data ? <><span className="cx-live">En direct</span> Mis à jour {fmtRel(data.generated_at)}, données collectées depuis le {fmtDate(data.collected_since, false)}</> : 'État actuel de la plateforme'}
        actions={<>
          <PeriodPicker value={period} onChange={setPeriod} />
          <button className="cx-btn cx-btn-ghost" onClick={reload} disabled={loading}><CIcon name="refresh" size={15} />Actualiser</button>
        </>}
      />
      {error && <ErrorState message={error} onRetry={reload} />}
      {!data && loading && <Loading />}
      {data && <Content d={data} />}
    </>
  )
}

function Content({ d }: { d: OverviewData }) {
  const [title, text] = GLOBAL[d.health.status]
  const r = d.requests
  const p = d.requests_previous
  const a24 = d.uptime.availability['24h']
  const hourly = d.period.step === 'hour'

  return (
    <div className="cx-stack">
      <div className={`cx-banner ${d.health.status}`} role="status">
        <div className="icon"><CIcon name={d.health.status === 'ok' ? 'check' : d.health.status === 'down' ? 'x' : 'alert'} size={22} /></div>
        <div className="grow">
          <strong>{title}</strong>
          <span>{text} Dernière vérification {fmtRel(d.last_check)}.</span>
        </div>
        {d.incidents.open > 0
          ? <Link to="/incidents" className="cx-btn cx-btn-ghost">{d.incidents.open} incident{d.incidents.open > 1 ? 's' : ''} en cours{d.incidents.critical ? ` dont ${d.incidents.critical} critique${d.incidents.critical > 1 ? 's' : ''}` : ''}</Link>
          : <span className="cx-muted">Aucun incident en cours</span>}
      </div>

      {!d.metrics_readable && (
        <div className="cx-note warn">Les mesures de la plateforme ne sont pas lisibles pour le moment (voir « Lecture des mesures » ci-dessous) : les chiffres d'utilisation affichés sont vides, pas nuls.</div>
      )}

      <div className="cx-grid k">
        <Kpi label="Disponibilité (24 h)" value={fmtPct(a24.availability)} tone={a24.availability === null ? undefined : a24.availability >= 99.5 ? 'ok' : a24.availability >= 97 ? 'warn' : 'crit'}
          hint={a24.samples ? `couverture des mesures ${a24.coverage} %` : 'pas encore de mesure'} />
        <Kpi label="Requêtes" value={fmtNum(r.hits)} delta={trend(r.hits, p.hits)} hint="vs période précédente" />
        <Kpi label="Erreurs serveur" value={fmtNum(r.errors)} delta={trend(r.errors, p.errors)} invert
          tone={r.error_rate !== null && r.error_rate >= 5 ? 'crit' : r.errors ? 'warn' : undefined} hint={r.error_rate !== null ? `${r.error_rate.toLocaleString('fr-CA')} % des requêtes` : undefined} />
        <Kpi label="Temps de réponse (95 %)" value={r.p95_ms !== null ? `environ ${fmtMs(r.p95_ms)}` : '-'} delta={trend(r.p95_ms, p.p95_ms)} invert
          hint={r.avg_ms !== null ? `moyenne ${fmtMs(r.avg_ms)}` : 'aucune requête'} />
        <Kpi label="Membres actifs aujourd'hui" value={fmtNum(d.users.today)} hint={`${fmtNum(d.users.last_7_days)} sur 7 j - ${fmtNum(d.users.last_30_days)} sur 30 j`} />
        <Kpi label="Connexions" value={fmtNum(d.users.logins)} hint={`${fmtNum(d.users.new_accounts)} nouveau(x) compte(s) - ${fmtNum(d.users.failed_codes)} code(s) erroné(s)`} />
      </div>

      <Card title="État des services" actions={d.health.php ? <span className="cx-help">{d.health.environment}, PHP {d.health.php}, Laravel {d.health.laravel}</span> : undefined}>
        <Services d={d} />
      </Card>

      <Card title="Temps de réponse de la plateforme vu de l'extérieur (60 dernières mesures)">
        {d.uptime.response_series.length === 0 ? <Empty>Pas encore de mesure : la première vérification a lieu dans la minute.</Empty> : <>
          <LineChart ariaLabel="Temps de réponse de la page de santé" unit=" ms" labels={d.uptime.response_series.map((s) => s.label)}
            series={[{ key: 'ms', label: 'Temps de réponse', color: VIZ.s1, values: d.uptime.response_series.map((s) => s.ms) }]} />
          <DataTable head={['Heure', 'État', 'Temps de réponse']} rows={d.uptime.response_series.map((s) => [s.label, s.status === 'ok' ? 'opérationnel' : s.status === 'down' ? 'indisponible' : 'dégradé', s.ms === null ? 'aucune réponse' : fmtMs(s.ms)])} />
        </>}
      </Card>

      <div className="cx-grid two">
        <Card title={hourly ? 'Requêtes par heure' : 'Requêtes par jour'}>
          {r.hits === 0 ? <Empty>Aucune requête mesurée sur la période.</Empty> : <>
            <LineChart ariaLabel="Requêtes et erreurs serveur" unit="" labels={d.series.map((s) => s.label)}
              series={[{ key: 'hits', label: 'Requêtes', color: VIZ.s1, values: d.series.map((s) => s.hits) },
                { key: 'errors', label: 'Erreurs serveur', color: VIZ.s2, values: d.series.map((s) => s.errors) }]} />
            <DataTable head={['Période', 'Requêtes', 'Erreurs serveur', 'Erreurs client', 'Lentes', 'Moyenne']}
              rows={d.series.map((s) => [s.label, s.hits, s.errors, s.client_errors, s.slow, fmtMs(s.avg_ms)])} />
          </>}
        </Card>
        <Card title="Temps de réponse moyen">
          {r.hits === 0 ? <Empty>Aucune mesure sur la période.</Empty>
            : <LineChart ariaLabel="Temps de réponse moyen" unit=" ms" labels={d.series.map((s) => s.label)}
              series={[{ key: 'avg', label: 'Moyenne', color: VIZ.s1, values: d.series.map((s) => s.avg_ms) }]} />}
          <p className="cx-help">Requêtes lentes (au-delà du seuil réglé) : <strong>{fmtNum(r.slow)}</strong> - plus longue : {fmtMs(r.max_ms)} - {r.avg_queries ?? '-'} requêtes SQL en moyenne.</p>
        </Card>
      </div>

      <div className="cx-grid two">
        <Card title="Utilisation par service">
          {d.by_service.length === 0 ? <Empty>Aucune utilisation mesurée.</Empty> : <>
            <HBarChart ariaLabel="Requêtes par service" unit="" max={Math.max(...d.by_service.map((s) => s.hits))}
              rows={d.by_service.slice(0, 10).map((s) => ({ label: s.label, value: s.hits, note: s.errors ? `${s.errors} err.` : undefined }))} />
            <DataTable head={['Service', 'Requêtes', 'Erreurs', 'Lentes', 'Moyenne']} rows={d.by_service.map((s) => [s.label, s.hits, s.errors, s.slow, fmtMs(s.avg_ms)])} />
          </>}
        </Card>
        <Card title="Par type d'action">
          {d.by_action.length === 0 ? <Empty>Aucune action mesurée.</Empty> : <>
            <HBarChart ariaLabel="Requêtes par type d'action" unit="" max={Math.max(...d.by_action.map((s) => s.hits))}
              rows={d.by_action.map((s) => ({ label: s.label, value: s.hits, note: s.failed ? `${s.failed} en échec` : undefined }))} />
            <p className="cx-help mt">Consultation = lecture d'un écran ; création, modification et suppression = enregistrements faits par les membres et responsables.</p>
          </>}
        </Card>
      </div>

      <Card title="Membres actifs, connexions et nouveaux comptes" actions={<span className="cx-help">{fmtNum(d.users.total)} comptes - {fmtNum(d.users.active_status)} au statut actif - {fmtNum(d.users.blocked)} bloqué(s)</span>}>
        {d.user_series.every((s) => !s.active && !s.logins && !s.new_accounts) ? <Empty>Pas encore de données d'utilisation sur la période.</Empty> : <>
          <ColumnChart ariaLabel="Membres actifs par jour" labels={d.user_series.map((s) => s.label)} values={d.user_series.map((s) => s.active)} />
          <DataTable head={['Jour', 'Membres actifs', 'Connexions', 'Nouveaux comptes']} rows={d.user_series.map((s) => [s.label, s.active, s.logins, s.new_accounts])} />
        </>}
      </Card>

      <div className="cx-grid two">
        <Card title="Incidents en cours" actions={<Link to="/incidents" className="cx-help">Tout voir</Link>}>
          {d.incidents.list.length === 0 ? <Empty>Aucun incident en cours.</Empty> : (
            <ul className="cx-list">
              {d.incidents.list.map((i) => (
                <li key={i.id}>
                  <Status tone={i.severity === 'critical' ? 'crit' : 'warn'}>{i.severity === 'critical' ? 'Critique' : 'Attention'}</Status>
                  <div className="grow">
                    <Link className="title" to={`/incidents?id=${i.id}`}>{i.title}</Link>
                    {i.cause && <div className="cx-cause">Cause probable : {i.cause}</div>}
                    <div className="meta"><span>{i.summary}</span><span>depuis {fmtRel(i.first_seen_at)}</span>{i.status === 'acknowledged' && <Badge tone="info">pris en charge</Badge>}</div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card title="Erreurs les plus fréquentes" actions={<Link to="/journaux?tab=errors" className="cx-help">Analyser</Link>}>
          {d.top_errors.length === 0 ? <Empty>Aucune erreur sur la période.</Empty> : (
            <ul className="cx-list">
              {d.top_errors.map((e) => (
                <li key={e.fingerprint}>
                  <Badge tone="crit">{e.count} fois</Badge>
                  <div className="grow">
                    <Link className="title" to={`/journaux?fingerprint=${e.fingerprint}`}>{e.message}</Link>
                    <div className="meta"><span>{e.service}</span><span>dernière {fmtRel(e.last_at)}</span>{e.users > 0 && <span>{e.users} membre(s) touché(s)</span>}</div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <div className="cx-grid two">
        <Card title="Disponibilité et fonctionnement">
          <dl className="cx-kv">
            <div><dt>En service depuis</dt><dd>{d.uptime.up_since ? `${fmtDate(d.uptime.up_since)} (sans interruption constatée)` : 'Pas encore de mesure'}</dd></div>
            <div><dt>Serveur démarré</dt><dd>{d.uptime.server_seconds !== null ? `il y a ${fmtDuration(d.uptime.server_seconds)}` : "Non disponible (l'hébergeur ne le communique pas)"}</dd></div>
            <div><dt>Version en ligne</dt><dd>{d.uptime.deployed_at ? `déployée le ${fmtDate(d.uptime.deployed_at)}` : 'Non disponible'}</dd></div>
            {(['24h', '7d', '30d'] as const).map((k) => {
              const v = d.uptime.availability[k]
              return <div key={k}><dt>Disponibilité {k === '24h' ? '24 h' : k === '7d' ? '7 jours' : '30 jours'}</dt>
                <dd>{v.availability === null ? 'Pas encore de mesure' : `${fmtPct(v.availability)} - ${v.down} mesure(s) en panne, ${v.degraded} dégradée(s) sur ${v.samples} (couverture ${v.coverage} %)`}</dd></div>
            })}
          </dl>
          <p className="cx-help mt">Mesurée par les vérifications internes (toutes les 5 minutes). Si le serveur entier s'arrête, aucune mesure n'est prise : utilisez aussi une surveillance externe de /api/health.</p>
        </Card>
        <Card title="Intégrations et tâches planifiées">
          <div className="cx-table-wrap">
          <table className="cx-table compact">
            <thead><tr><th>Élément</th><th className="num">Réussis</th><th className="num">Échecs</th><th className="num">Durée moy.</th></tr></thead>
            <tbody>
              {[['twilio_verify', 'SMS de connexion (Twilio)'], ['webpush', 'Notifications push']].map(([k, label]) => {
                const v = d.integrations[k]
                return <tr key={k}><td>{label}</td>{v ? <><td className="num">{fmtNum(v.ok)}</td><td className="num">{fmtNum(v.failed)}</td><td className="num">{fmtMs(v.avg_ms)}</td></>
                  : <td colSpan={3} className="cx-muted">aucun appel sur la période</td>}</tr>
              })}
              {[['app:tick', 'Automatismes'], ['monitor:check', 'Vérification de supervision'], ['app:push-outbox', 'Rattrapage des push']].map(([k, label]) => {
                const v = d.jobs[k]
                return <tr key={k}><td>{label}</td>{v ? <><td className="num">{fmtNum(v.runs - v.failed)}</td><td className="num">{fmtNum(v.failed)}</td><td className="num">{fmtMs(v.avg_ms)}</td></>
                  : <td colSpan={3} className="cx-muted">aucun passage enregistré</td>}</tr>
              })}
            </tbody>
          </table>
          </div>
        </Card>
      </div>

      <Card title="Dernières erreurs importantes" actions={<Link to="/journaux?level=error" className="cx-help">Journal complet</Link>}>
        {d.recent_events.length === 0 ? <Empty>Aucune erreur enregistrée.</Empty> : (
          <ul className="cx-list">
            {d.recent_events.map((e) => (
              <li key={e.id}>
                <Status tone={LEVEL_TONE[e.level] ?? 'muted'}>{LEVEL_LABEL[e.level] ?? e.level}</Status>
                <div className="grow">
                  <Link className="title" to={`/journaux?event=${e.id}`}>{e.message}</Link>
                  <div className="meta"><span>{e.service}</span><span>{fmtDate(e.created_at)}</span></div>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

/** Cartes d'etat des services : icone + libelle, detail chiffre, « non configure » explicite. */
function Services({ d }: { d: OverviewData }) {
  const c = d.health.checks
  const n = (v: unknown) => (typeof v === 'number' ? v : null)
  const pl = c.platform
  const ag = c.agent
  const db = c.app_db
  const items: { name: string; tone: Tone; state: string; detail: string }[] = [
    { name: 'Plateforme (vue de l\'extérieur)', tone: pl?.ok ? (pl.slow ? 'warn' : 'ok') : 'crit', state: pl?.ok ? (pl.slow ? 'Lente' : 'En ligne') : 'Injoignable',
      detail: pl?.http_status ? `Page de santé : HTTP ${String(pl.http_status)} en ${n(pl.ms) ?? '?'} ms.` : String(pl?.error ?? 'Aucune réponse.') },
    { name: 'Liaison sécurisée (agent)', tone: ag?.ok ? 'ok' : ag?.configured === false ? 'muted' : 'crit', state: ag?.ok ? 'Active' : ag?.configured === false ? 'Non configurée' : 'En échec',
      detail: ag?.ok ? `Requêtes signées acceptées en ${n(ag.ms) ?? '?'} ms.` : String(ag?.error ?? '') },
    { name: 'Lecture des mesures', tone: db?.ok ? 'ok' : 'crit', state: db?.ok ? 'Active' : 'Impossible',
      detail: db?.ok ? `Base de la plateforme lue en ${n(db.ms) ?? '?'} ms.` : String(db?.error ?? '') },
    { name: 'Base de données', tone: c.database?.ok ? 'ok' : 'crit', state: c.database?.ok ? 'Opérationnelle' : 'En panne',
      detail: c.database?.ok ? `Réponse en ${n(c.database.ms) ?? '?'} ms - ${String(c.database.driver ?? '')}` : 'Ne répond pas.' },
    { name: 'Cache', tone: c.cache?.ok ? 'ok' : 'crit', state: c.cache?.ok ? 'Opérationnel' : 'En panne', detail: 'Sessions de calcul, verrous, limites de tentatives.' },
    { name: 'Stockage et disque', tone: !c.storage?.ok ? 'crit' : c.disk?.ok ? 'ok' : 'warn', state: !c.storage?.ok ? 'Non accessible' : c.disk?.ok ? 'Opérationnel' : 'Espace faible',
      detail: n(c.disk?.free_percent) !== null ? `${n(c.disk.free_percent)} % libre (${n(c.disk.free_gb)} Go)` : 'Espace libre non communiqué par le serveur.' },
    { name: 'Automatismes', tone: c.automation?.ok ? 'ok' : 'warn', state: c.automation?.ok ? 'À jour' : n(c.automation?.last_run_minutes) === null ? 'Jamais lancés' : 'En retard ou en échec',
      detail: n(c.automation?.last_run_minutes) !== null
        ? `Dernier passage il y a ${n(c.automation.last_run_minutes)} min${c.automation.trigger ? ` (${c.automation.trigger === 'cron' ? 'cron' : c.automation.trigger === 'console' ? 'console' : "activité de l'application"})` : ''}${n(c.automation.failed_steps) ? ` - ${n(c.automation.failed_steps)} étape(s) en échec` : ''}`
        : 'Aucun passage enregistré.' },
    { name: 'Tâches planifiées (cron)', tone: c.scheduler?.cron_seen ? 'ok' : 'warn', state: c.scheduler?.cron_seen ? 'Actif' : 'Non détecté',
      detail: c.scheduler?.cron_seen ? 'Le cron de l\'hébergeur tourne.' : "Secours : l'activité des membres lance les automatismes." },
    { name: 'Notifications push', tone: c.push?.ok ? 'ok' : c.push?.error && String(c.push.error).includes('desactiv') ? 'muted' : 'warn',
      state: c.push?.ok ? 'Opérationnelles' : c.push?.error && String(c.push.error).includes('desactiv') ? 'Désactivées' : 'À vérifier',
      detail: `${n(c.push?.devices) ?? 0} appareil(s) abonné(s)${n(c.push?.waiting) ? ` - ${n(c.push.waiting)} en attente` : ''}${c.push?.error ? ` - ${String(c.push.error)}` : ''}` },
    { name: 'SMS de connexion', tone: c.sms?.driver !== 'twilio_verify' ? 'muted' : c.sms?.ok ? 'ok' : 'crit',
      state: c.sms?.driver !== 'twilio_verify' ? 'Mode test' : !c.sms?.configured ? 'Non configuré' : c.sms?.ok ? 'Opérationnel' : 'Échecs',
      detail: c.sms?.driver !== 'twilio_verify' ? String(c.sms?.note ?? '') : `${n(c.sms?.calls_24h) ?? 0} appel(s) en 24 h, ${n(c.sms?.failed_24h) ?? 0} en échec (Twilio Verify).` },
    { name: 'Courriel', tone: c.mail?.configured ? 'ok' : 'muted', state: c.mail?.configured ? 'Configuré' : 'Non configuré',
      detail: c.mail?.configured ? `Envoi par « ${String(c.mail.driver)} ».` : "Aucun envoi de courriel n'est configuré (MAIL_MAILER)." },
  ]
  // Service non communique (liaison avec la plateforme coupee) : « inconnu », jamais un faux « en panne ».
  const source: Record<string, string> = {
    'Base de données': 'database', Cache: 'cache', 'Stockage et disque': 'storage', Automatismes: 'automation', 'Tâches planifiées (cron)': 'scheduler',
    'Notifications push': 'push', 'SMS de connexion': 'sms', Courriel: 'mail',
  }
  const shown = items.map((s) => (source[s.name] && !c[source[s.name]]
    ? { ...s, tone: 'muted' as Tone, state: 'Inconnu', detail: 'Non communiqué : la liaison avec la plateforme ne répond pas.' }
    : s))
  return (
    <div className="cx-grid services">
      {shown.map((s) => (
        <div className="cx-service" key={s.name}>
          <div className="cx-service-head"><strong>{s.name}</strong><Status tone={s.tone}>{s.state}</Status></div>
          <p>{s.detail}</p>
        </div>
      ))}
    </div>
  )
}
