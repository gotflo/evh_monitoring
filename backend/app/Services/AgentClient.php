<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client de l'API de controle de la plateforme (agent de supervision, PLATFORM_URL/api/agent/*).
 * Chaque requete est signee : HMAC-SHA256 du secret partage MONITOR_AGENT_SECRET sur
 *   horodatage \n METHODE \n chemin \n parametres tries \n auteur \n sha256(corps)
 * (meme construction que VerifyAgentSignature cote plateforme). Le secret ne circule jamais.
 */
class AgentClient
{
    public function configured(): bool
    {
        return strlen((string) config('monitoring.platform.agent_secret')) >= 32;
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query = [], ?string $actor = null): array
    {
        return $this->send('GET', $path, $query, null, $actor);
    }

    /** @param array<string, mixed> $body */
    public function post(string $path, array $body = [], ?string $actor = null): array
    {
        return $this->send('POST', $path, [], $body, $actor);
    }

    public function delete(string $path, ?string $actor = null): array
    {
        return $this->send('DELETE', $path, [], null, $actor);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, ?array $body, ?string $actor): array
    {
        if (! $this->configured()) {
            throw new AgentException('Liaison avec la plateforme non configurée (MONITOR_AGENT_SECRET).', 0, configuration: true);
        }
        $path = '/api/agent/'.ltrim($path, '/');
        ksort($query);
        $content = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $actorHeader = $actor ? rawurlencode(mb_substr($actor, 0, 120)) : '';
        $canonical = implode("\n", [$timestamp, $method, $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $actorHeader, hash('sha256', $content)]);
        $signature = hash_hmac('sha256', $canonical, (string) config('monitoring.platform.agent_secret'));

        $url = config('monitoring.platform.url').$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $request = Http::acceptJson()
            ->withHeaders(['X-Agent-Timestamp' => $timestamp, 'X-Agent-Signature' => $signature, 'X-Agent-Actor' => $actorHeader])
            ->connectTimeout(5)->timeout(max(5, (int) config('monitoring.platform.timeout', 10)) + ($method === 'GET' ? 0 : 50));
        try {
            /** @var Response $response */
            $response = $body === null
                ? $request->send($method, $url)
                : $request->withBody($content, 'application/json')->send($method, $url);
        } catch (ConnectionException $e) {
            throw new AgentException('Plateforme injoignable : '.mb_substr($e->getMessage(), 0, 160), 0);
        }

        if (! $response->successful()) {
            $message = (string) ($response->json('message') ?: 'Réponse HTTP '.$response->status());
            $errors = $response->json('errors');
            if (is_array($errors) && $errors) {
                $message = (string) (array_values($errors)[0][0] ?? $message);
            }
            throw new AgentException($message, $response->status(), configuration: in_array($response->status(), [401, 403, 503], true));
        }

        return (array) $response->json();
    }
}
