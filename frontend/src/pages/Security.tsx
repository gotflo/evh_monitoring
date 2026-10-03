import { useState } from 'react'
import { Link } from 'react-router-dom'
import { capi, qs } from '../api'
import { useConsole } from '../auth'
import { Badge, Card, CIcon, Empty, ErrorState, Loading, Outcome, PageHeader, PeriodPicker, Status } from '../ui'
import { fmtDate, fmtNum, fmtRel, useData, type Tone } from '../format'

interface Vuln { checked_at: string; packages: { console: number; platform: number | null }; platform_error: string | null; source: string; advisories: { scope: 'platform' | 'console'; package: string; installed: string | null; title: string; cve: string | null; severity: string | null; affected: string; link: string | null; certain: boolean }[] }
interface Data {
  checks: { scope: 'platform' | 'console'; key: string; label: string; status: 'ok' | 'info' | 'warning' | 'critical'; detail: string; fix?: string }[]
  denied: { total: number; by_status: Record<string, number>; by_route: { route: string; service: string; status: number; count: number }[]; by_ip: { ip: string; count: number; last_at: string }[] }
  otp: { failures: number; requests: number; failures_by_ip: { ip: string; count: number; last_at: string }[] }
  suspicious: { kind: string; label: string; ip?: string; user_id?: number; count: number; last_at?: string }[]
  blocked_users: { id: number; name: string; blocked_at: string; reason: string | null }[]
  console_logins: { id: number; label: string; who: string | null; outcome: string; ip: string | null; created_at: string }[]
  vulnerabilities: Vuln | null
  vulnerability_check_enabled: boolean
  limits: string[]
  localities: { label: string; country_code: string | null; logins: number; members: number; last_at: string; unusual: boolean }[]
  geo: { enabled: boolean; provider: string; home_countries: string[] }
}

const CHECK_TONE: Record<string, [Tone, string]> = { ok: ['ok', 'Conforme'], info: ['info', 'À savoir'], warning: ['warn', 'À corriger'], critical: ['crit', 'Urgent'] }

export default function Security() {
  const { can } = useConsole()
  const [period, setPeriod] = useState('7d')
  const { data, error, loading, reload, setData } = useData<Data>(`/security${qs({ period })}`, 60000)
  const [busy, setBusy] = useState(false)

  const checkVulns = async () => {
    setBusy(true)
    try {
      const r = await capi<{ result: Vuln }>('/security/vulnerabilities', { method: 'POST' })
      setData((d) => (d ? { ...d, vulnerabilities: r.result } : d))
    } catch { /* message affiche */ } finally { setBusy(false) }
  }

  return (
    <>
      <PageHeader title="Sécurité" sub="Contrôles de configuration, accès refusés, comportements suspects et vulnérabilités connues."
        actions={<PeriodPicker value={period} onChange={setPeriod} options={['24h', '7d', '30d']} />} />
      {error && <ErrorState message={error} onRetry={reload} />}
      {loading && !data && <Loading />}
      {data && <div className="cx-stack">
        <Card title="Comportements à vérifier">
          {data.suspicious.length === 0 ? <Empty>Rien de suspect détecté sur la période.</Empty> : (
            <ul className="cx-list">{data.suspicious.map((s, i) => (
              <li key={i}><Status tone="warn">À vérifier</Status><div className="grow"><div className="title">{s.label}</div>
                <div className="meta">{s.last_at && <span>dernier {fmtRel(s.last_at)}</span>}{s.user_id && <Link to={`/utilisateurs/${s.user_id}`}>Voir le compte</Link>}
                  {s.ip && <Link to={`/activite?service=securite&period=${period}`}>Voir l'activité</Link>}</div></div></li>
            ))}</ul>
          )}
        </Card>

        <div className="cx-grid two">
          {([['platform', 'Configuration de la plateforme'], ['console', 'Configuration de la console']] as const).map(([scope, title]) => (
            <Card key={scope} title={title}>
              <ul className="cx-list">{data.checks.filter((c) => c.scope === scope).map((c) => (
                <li key={c.key}><Status tone={CHECK_TONE[c.status][0]}>{CHECK_TONE[c.status][1]}</Status>
                  <div className="grow"><div className="title">{c.label}</div><div className="meta"><span>{c.detail}</span></div>{c.fix && <div className="cx-help">À faire : {c.fix}</div>}</div></li>
              ))}</ul>
            </Card>
          ))}
        </div>

        <div className="cx-grid two">
          <Card title={`Accès refusés (${fmtNum(data.denied.total)})`}>
            {data.denied.total === 0 ? <Empty>Aucun accès refusé sur la période.</Empty> : <>
              <div className="cx-head-actions" style={{ marginBottom: '0.6rem' }}>
                {Object.entries(data.denied.by_status).map(([s, n]) => <Badge key={s} tone="warn">HTTP {s} : {fmtNum(n)}</Badge>)}
              </div>
              <div className="cx-table-wrap"><table className="cx-table compact"><thead><tr><th>Route</th><th className="num">Statut</th><th className="num">Nombre</th></tr></thead>
                <tbody>{data.denied.by_route.map((r) => <tr key={r.route + r.status}><td className="cx-mono">{r.route}<div className="cx-help">{r.service}</div></td><td className="num">{r.status}</td><td className="num">{fmtNum(r.count)}</td></tr>)}</tbody></table></div>
              <p className="cx-help mt">401 : session expirée ou absente - 403 : droits insuffisants - 429 : trop de requêtes. <Link to="/journaux?tab=requests&reason=denied">Détail</Link></p>
            </>}
          </Card>
          <Card title="Codes de connexion">
            <p style={{ fontSize: '0.88rem' }}>{fmtNum(data.otp.requests)} code(s) demandé(s), {fmtNum(data.otp.failures)} code(s) erroné(s) sur la période.</p>
            {data.otp.failures_by_ip.length > 0 && <div className="cx-table-wrap"><table className="cx-table compact mt"><thead><tr><th>Adresse IP</th><th className="num">Codes erronés</th><th>Dernier</th></tr></thead>
              <tbody>{data.otp.failures_by_ip.map((r) => <tr key={r.ip}><td className="cx-mono">{r.ip}</td><td className="num">{fmtNum(r.count)}</td><td>{fmtRel(r.last_at)}</td></tr>)}</tbody></table></div>}
          </Card>
        </div>

        <div className="cx-grid two">
          <Card title="Connexions à la console">
            {data.console_logins.length === 0 ? <Empty>Aucune connexion enregistrée.</Empty> : (
              <ul className="cx-list">{data.console_logins.map((l) => (
                <li key={l.id}><Outcome value={l.outcome} /><div className="grow"><div className="title">{l.label}</div><div className="meta"><span>{l.who ?? '-'}</span><span>{fmtDate(l.created_at)}</span>{l.ip && <span>{l.ip}</span>}</div></div></li>
              ))}</ul>
            )}
          </Card>
          <Card title={`Comptes bloqués (${data.blocked_users.length})`}>
            {data.blocked_users.length === 0 ? <Empty>Aucun compte bloqué.</Empty> : (
              <ul className="cx-list">{data.blocked_users.map((u) => (
                <li key={u.id}><div className="grow"><Link className="title" to={`/utilisateurs/${u.id}`}>{u.name}</Link><div className="meta"><span>depuis le {fmtDate(u.blocked_at)}</span>{u.reason && <span>{u.reason}</span>}</div></div></li>
              ))}</ul>
            )}
          </Card>
        </div>

        <Card title="Vulnérabilités connues des composants PHP (plateforme et console)" actions={can('admin') && data.vulnerability_check_enabled && (
          <button className="cx-btn cx-btn-ghost cx-btn-sm" onClick={checkVulns} disabled={busy}>{busy ? <span className="cx-spinner" /> : <><CIcon name="refresh" size={14} />Vérifier maintenant</>}</button>
        )}>
          {!data.vulnerability_check_enabled && <p className="cx-note warn">Vérification désactivée (MONITOR_VULNERABILITY_CHECK=false).</p>}
          {!data.vulnerabilities ? <Empty>Pas encore vérifié. {can('admin') ? 'Lancez une vérification : seuls les noms des paquets sont envoyés à Packagist.' : ''}</Empty> : <>
            <p className="cx-help">Vérifié le {fmtDate(data.vulnerabilities.checked_at)} : {data.vulnerabilities.packages.platform ?? '?'} paquets de la plateforme, {data.vulnerabilities.packages.console} de la console. Source : {data.vulnerabilities.source}.</p>
            {data.vulnerabilities.platform_error && <p className="cx-note warn">Paquets de la plateforme non vérifiés : {data.vulnerabilities.platform_error}</p>}
            {data.vulnerabilities.advisories.length === 0 ? <div className="mt"><Status tone="ok">Aucune vulnérabilité connue pour les versions installées</Status></div> : (
              <ul className="cx-list mt">{data.vulnerabilities.advisories.map((a, i) => (
                <li key={i}><Status tone={a.certain ? 'crit' : 'warn'}>{a.certain ? 'Concerné' : 'À vérifier'}</Status>
                  <div className="grow"><div className="title">{a.scope === 'platform' ? 'Plateforme' : 'Console'} : {a.package} {a.installed}, {a.title}</div>
                    <div className="meta">{a.cve && <span>{a.cve}</span>}{a.severity && <span>gravité {a.severity}</span>}<span>versions touchées {a.affected}</span>{a.link && <a href={a.link} target="_blank" rel="noreferrer noopener">Avis</a>}</div>
                    <div className="cx-help">Correctif : mettre à jour le paquet (composer update {a.package}) puis redéployer.</div></div></li>
              ))}</ul>
            )}
          </>}
        </Card>

        <Card title="Localités des connexions" actions={<span className="cx-help">{data.geo.enabled ? `pays habituels : ${data.geo.home_countries.join(', ')}` : 'localisation désactivée'}</span>}>
          {data.localities.length === 0 ? <Empty>Aucune connexion sur la période.</Empty> : (
            <div className="cx-table-wrap"><table className="cx-table responsive">
              <thead><tr><th>Localité</th><th className="num">Connexions</th><th className="num">Membres</th><th>Dernière</th></tr></thead>
              <tbody>{data.localities.map((l) => (
                <tr key={l.label}>
                  <td data-label="Localité">{l.unusual ? <Status tone="warn">Inhabituel</Status> : null} {l.label}</td>
                  <td data-label="Connexions" className="num">{fmtNum(l.logins)}</td>
                  <td data-label="Membres" className="num">{fmtNum(l.members)}</td>
                  <td data-label="Dernière">{fmtRel(l.last_at)}</td>
                </tr>
              ))}</tbody>
            </table></div>
          )}
          <p className="cx-help mt">Localisation approximative d'après l'adresse IP (souvent la ville du fournisseur d'accès). Une connexion depuis un pays inhabituel ouvre un incident.</p>
        </Card>

        <Card title="Limites et éléments à configurer à l'extérieur">
          <ul style={{ paddingLeft: '1.1rem', fontSize: '0.86rem', display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
            {data.limits.map((l) => <li key={l}>{l}</li>)}
          </ul>
        </Card>
      </div>}
    </>
  )
}
