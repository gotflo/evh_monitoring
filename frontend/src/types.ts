// Formes des reponses de l'API de la console.

export interface RequestSummary {
  hits: number; errors: number; client_errors: number; denied: number; throttled: number
  error_rate: number | null; avg_ms: number | null; p95_ms: number | null; max_ms: number | null
  slow: number; avg_queries: number | null; avg_db_ms: number | null
}

export interface SeriesPoint { key: string; label: string; hits: number; errors: number; client_errors: number; slow: number; avg_ms: number | null }

export interface Availability { samples: number; ok: number; degraded: number; down: number; coverage: number; availability: number | null; fully_ok: number | null }

export interface HealthCheck { ok: boolean; [k: string]: unknown }

export interface Health {
  status: 'ok' | 'degraded' | 'down'
  essential: boolean
  checks: Record<string, HealthCheck>
  php?: string; laravel?: string; environment?: string
}

export interface ErrorGroup {
  fingerprint: string; count: number; users: number; first_at: string; last_at: string; last_id: number
  service: string | null; type: string | null; level: string | null; message: string
}

export interface IncidentRow {
  id: number; rule: string; rule_label?: string; severity: 'warning' | 'critical'; status: 'open' | 'acknowledged' | 'resolved'
  title: string; summary: string | null; occurrences?: number; first_seen_at: string | null; last_seen_at: string | null
  notified_at?: string | null; acknowledged_at?: string | null; resolved_at?: string | null; resolved_automatically?: boolean
  cause?: string | null; confidence?: string | null
}

export interface LogEvent {
  id: number; created_at: string; level: string; service: string; service_label: string; type: string; type_label: string
  outcome: string | null; message: string; user_id: number | null; operator_id: number | null; request_id: string | null
  route: string | null; fingerprint: string | null
}

export interface RequestRow {
  id: number; created_at: string; request_id: string; reason: string; method: string; route: string; service: string
  status: number; duration_ms: number; queries: number; db_ms: number; user_id: number | null; ip: string | null
}

export interface Option { key: string; label: string }

export interface Investigation {
  cause: string
  confidence: 'élevée' | 'moyenne' | 'faible' | string
  evidence: { label: string; value: string }[]
  recommendations: { text: string; action?: string; link?: string }[]
  investigated_at: string
}
