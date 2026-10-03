import { lazy, Suspense, useEffect, useState, type FormEvent } from 'react'
import { Navigate, NavLink, Route, Routes, useLocation } from 'react-router-dom'
import { AsYouType, isValidPhoneNumber, type CountryCode } from 'libphonenumber-js'
import { CountrySelect } from './components/CountrySelect'
import { DEFAULT_COUNTRY, type Country } from './data/countries'
import { Toaster } from './components/Toaster'
import { capi, ConsoleError } from './api'
import { ConsoleAuthProvider, useConsole, type ConsolePayload, type ConsoleRole } from './auth'
import { CIcon, Loading } from './ui'
import { fmtDate, type CxIcon } from './format'

const PLATFORM_NAME = 'My vasesdhonneur'

const Overview = lazy(() => import('./pages/Overview'))
const Activity = lazy(() => import('./pages/Activity'))
const Users = lazy(() => import('./pages/Users'))
const UserDetail = lazy(() => import('./pages/UserDetail'))
const Logs = lazy(() => import('./pages/Logs'))
const Incidents = lazy(() => import('./pages/Incidents'))
const Reports = lazy(() => import('./pages/Reports'))
const Security = lazy(() => import('./pages/Security'))
const Troubleshoot = lazy(() => import('./pages/Troubleshoot'))
const Access = lazy(() => import('./pages/Access'))
const Settings = lazy(() => import('./pages/Settings'))
const Audit = lazy(() => import('./pages/Audit'))

interface NavItem { to: string; label: string; icon: CxIcon; role?: ConsoleRole; end?: boolean }

const NAV: { group: string; items: NavItem[] }[] = [
  { group: 'Supervision', items: [
    { to: '/', label: 'Tableau de bord', icon: 'dashboard', end: true },
    { to: '/incidents', label: 'Incidents et alertes', icon: 'bell' },
    { to: '/journaux', label: 'Journaux et erreurs', icon: 'logs' },
    { to: '/rapports', label: 'Rapports', icon: 'report' },
  ] },
  { group: 'Administration', items: [
    { to: '/activite', label: 'Activité', icon: 'pulse' },
    { to: '/utilisateurs', label: 'Utilisateurs', icon: 'users' },
    { to: '/depannage', label: 'Dépannage', icon: 'tool' },
  ] },
  { group: 'Sécurité', items: [
    { to: '/securite', label: 'Sécurité', icon: 'shield' },
    { to: '/audit', label: "Journal d'audit", icon: 'audit' },
    { to: '/acces', label: 'Accès à la console', icon: 'key', role: 'admin' },
    { to: '/reglages', label: 'Réglages', icon: 'settings' },
  ] },
]

/** Console de supervision : session propre, ecrans charges a la demande. */
export default function ConsoleApp() {
  return (
    <ConsoleAuthProvider>
      <div className="cx-root">
        <Toaster />
        <Gate />
      </div>
    </ConsoleAuthProvider>
  )
}

function Gate() {
  const { loading, operator } = useConsole()
  useEffect(() => {
    const previous = document.title
    document.title = `Console - ${PLATFORM_NAME}`
    return () => { document.title = previous }
  }, [])
  if (loading) return <Loading label="Vérification de la session..." />
  if (!operator) return <ConsoleLogin />
  return <Shell />
}

function Shell() {
  const { operator, logout, can, platformUrl } = useConsole()
  const [open, setOpen] = useState(false)
  const [openIncidents, setOpenIncidents] = useState<number | null>(null)
  const location = useLocation()

  useEffect(() => { setOpen(false) }, [location.pathname])

  // Nombre d'incidents en cours (pastille du menu), rafraichi chaque minute.
  useEffect(() => {
    let alive = true
    const load = () => capi<{ counts: { open: number; acknowledged: number } }>('/incidents?status=open')
      .then((r) => { if (alive) setOpenIncidents(r.counts.open + r.counts.acknowledged) }).catch(() => {})
    load()
    const t = window.setInterval(load, 60000)
    return () => { alive = false; window.clearInterval(t) }
  }, [location.pathname])

  if (!operator) return null

  return (
    <div className="cx-shell">
      <div className="cx-topbar">
        <button className="cx-icon-btn" onClick={() => setOpen(true)} aria-label="Ouvrir le menu"><CIcon name="menu" /></button>
        <strong>Console - {PLATFORM_NAME}</strong>
      </div>
      {open && <div className="cx-scrim" onClick={() => setOpen(false)} />}
      <aside className={`cx-side ${open ? 'open' : ''}`} aria-label="Menu de la console">
        <div className="cx-brand">
          <div className="cx-brand-mark">VH</div>
          <div><strong>Console</strong><span>{PLATFORM_NAME}</span></div>
        </div>
        <nav className="cx-nav">
          {NAV.map((g) => (
            <div className="cx-nav-group" key={g.group}>
              <span>{g.group}</span>
              {g.items.filter((i) => !i.role || can(i.role)).map((i) => (
                <NavLink key={i.to} to={i.to} end={i.end} className={({ isActive }) => (isActive ? 'active' : '')}>
                  <CIcon name={i.icon} />{i.label}
                  {i.to === '/incidents' && !!openIncidents && <span className="cx-count" aria-label={`${openIncidents} en cours`}>{openIncidents}</span>}
                </NavLink>
              ))}
            </div>
          ))}
        </nav>
        <div className="cx-side-foot">
          <div className="who">{operator.label}</div>
          <div className="meta">{operator.role_label}{operator.is_primary ? ' - principal' : ''}</div>
          {operator.session_expires_at && <div className="meta">Session jusqu'au {fmtDate(operator.session_expires_at)}</div>}
          <div className="row">
            {platformUrl && <a href={platformUrl} target="_blank" rel="noreferrer noopener" className="cx-btn cx-btn-ghost cx-btn-sm">Plateforme</a>}
            <button className="cx-btn cx-btn-ghost cx-btn-sm" onClick={() => { void logout() }}><CIcon name="logout" size={14} />Déconnexion</button>
          </div>
        </div>
      </aside>
      <main className="cx-main">
        <Suspense fallback={<Loading />}>
          <Routes>
            <Route index element={<Overview />} />
            <Route path="incidents" element={<Incidents />} />
            <Route path="journaux" element={<Logs />} />
            <Route path="rapports" element={<Reports />} />
            <Route path="activite" element={<Activity />} />
            <Route path="utilisateurs" element={<Users />} />
            <Route path="utilisateurs/:id" element={<UserDetail />} />
            <Route path="depannage" element={<Troubleshoot />} />
            <Route path="securite" element={<Security />} />
            <Route path="audit" element={<Audit />} />
            <Route path="acces" element={can('admin') ? <Access /> : <Navigate to="/" replace />} />
            <Route path="reglages" element={<Settings />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </main>
    </div>
  )
}

/** Connexion : numero autorise + code a usage unique recu par SMS. */
function ConsoleLogin() {
  const { login } = useConsole()
  const [step, setStep] = useState<'phone' | 'code'>('phone')
  const [country, setCountry] = useState<Country>(DEFAULT_COUNTRY)
  const [national, setNational] = useState('')
  const [phone, setPhone] = useState('')
  const [code, setCode] = useState('')
  const [info, setInfo] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const valid = national.trim() !== '' && isValidPhoneNumber(national, country.iso as CountryCode)

  async function request(e: FormEvent) {
    e.preventDefault()
    setBusy(true); setError(''); setInfo('')
    try {
      const r = await capi<{ phone: string; message: string; dev_code?: string }>('/auth/request-code', {
        method: 'POST', body: { phone: national, country: country.iso }, toast: false,
      })
      setPhone(r.phone)
      setStep('code')
      if (r.dev_code) { setCode(r.dev_code); setInfo(`Code (mode test) : ${r.dev_code}`) } else setInfo(r.message)
    } catch (err) {
      setError(err instanceof ConsoleError ? err.firstMessage : 'Connexion impossible.')
    } finally { setBusy(false) }
  }

  async function verify(e: FormEvent) {
    e.preventDefault()
    setBusy(true); setError('')
    try {
      const r = await capi<ConsolePayload & { token: string }>('/auth/verify', { method: 'POST', body: { phone, code }, toast: false })
      login(r.token, r)
    } catch (err) {
      setError(err instanceof ConsoleError ? err.firstMessage : 'Connexion impossible.')
    } finally { setBusy(false) }
  }

  return (
    <div className="cx-login">
      <div className="cx-login-card">
        <div className="cx-brand" style={{ padding: 0 }}>
          <div className="cx-brand-mark">VH</div>
          <div><strong>Console de supervision</strong><span>{PLATFORM_NAME}</span></div>
        </div>
        {step === 'phone' ? (
          <form onSubmit={request} className="cx-stack">
            <div>
              <h1>Connexion</h1>
              <p className="cx-sub">Accès réservé aux numéros autorisés. Un code à usage unique vous sera envoyé par SMS.</p>
            </div>
            {error && <div className="cx-error" role="alert">{error}</div>}
            <div className="cx-field">
              <label htmlFor="cx-phone">Numéro de téléphone</label>
              <div className="phone-row">
                <CountrySelect value={country} onChange={setCountry} />
                <input id="cx-phone" className="cx-input" type="tel" inputMode="tel" autoComplete="tel" required autoFocus
                  value={national} onChange={(e) => setNational(new AsYouType(country.iso as CountryCode).input(e.target.value))}
                  placeholder={country.iso === 'CA' || country.iso === 'US' ? '418 123 4567' : 'Numéro'} />
              </div>
            </div>
            <button className="cx-btn cx-btn-primary" disabled={busy || !valid}>{busy ? <span className="cx-spinner" /> : 'Recevoir un code'}</button>
          </form>
        ) : (
          <form onSubmit={verify} className="cx-stack">
            <div>
              <h1>Vérification</h1>
              <p className="cx-sub">Numéro : <strong>{phone}</strong></p>
            </div>
            {info && !error && <div className="cx-note">{info}</div>}
            {error && <div className="cx-error" role="alert">{error}</div>}
            <div className="cx-field">
              <label htmlFor="cx-code">Code à 6 chiffres</label>
              <input id="cx-code" className="cx-input cx-otp" inputMode="numeric" autoComplete="one-time-code" maxLength={6} required autoFocus
                value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} placeholder="000000" />
            </div>
            <button className="cx-btn cx-btn-primary" disabled={busy || code.length !== 6}>{busy ? <span className="cx-spinner" /> : 'Se connecter'}</button>
            <button type="button" className="cx-link" onClick={() => { setStep('phone'); setCode(''); setError('') }}>Modifier le numéro</button>
          </form>
        )}
        <p className="cx-help">Console distincte de l'application des membres : accès strictement réservé.</p>
      </div>
    </div>
  )
}
