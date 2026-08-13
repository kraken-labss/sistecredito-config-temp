<?php
require_once __DIR__ . '/../config.php';
sc_session_start();

// CORS para que el frontend pueda llamar la API desde cualquier pagina del sitio
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?: [];

// helper para extraer el documento guardado en sesion o body
function current_document(): ?string {
    return $_SESSION['document'] ?? $_POST['document'] ?? $_GET['document'] ?? null;
}

switch ($action) {
    case 'status':
        sc_json(['success' => true, 'servicio' => 'api-sistecredito', 'estado' => 'ok', '_TUNNEL_URL' => SC_API_BASE]);
        break;

    case 'authorizations':
        // POST: dispara OTP al celular del cliente
        // body: {documentType, documentNumber}
        if ($method !== 'POST') sc_json(['success' => false, 'error' => 'Metodo no permitido'], 405);
        $payload = [
            'documentType' => $body['documentType'] ?? 'CC',
            'documentNumber' => $body['documentNumber'] ?? '',
        ];
        $r = sc_api_call('authorizations', $payload, 'POST');
        sc_json($r['json'] ?? ['success' => false, 'error' => 'Sin respuesta'], $r['status']);
        break;

    case 'authentications':
        // POST: valida OTP y obtiene JWT
        // body: {documentType, documentNumber, token}
        if ($method !== 'POST') sc_json(['success' => false, 'error' => 'Metodo no permitido'], 405);
        $payload = [
            'documentType' => $body['documentType'] ?? 'CC',
            'documentNumber' => $body['documentNumber'] ?? '',
            'token' => $body['token'] ?? '',
        ];
        $r = sc_api_call('authentications', $payload, 'POST');
        $json = $r['json'] ?? null;
        if (is_array($json) && !empty($json['jwtToken'])) {
            $_SESSION['jwt'] = $json['jwtToken'];
            $_SESSION['jwtRefresh'] = $json['jwtRefresh'] ?? '';
            $_SESSION['documentType'] = $payload['documentType'];
            $_SESSION['documentNumber'] = $payload['documentNumber'];
        }
        sc_json($json ?? ['success' => false, 'error' => 'Sin respuesta'], $r['status']);
        break;

    case 'credits':
        // GET: lista creditos del documento (requiere JWT en sesion)
        $doc = current_document();
        $jwt = $_SESSION['jwt'] ?? '';
        if (!$jwt) sc_json(['success' => false, 'error' => 'No autenticado'], 401);
        $endpoint = 'credits/' . $doc;
        $r = sc_api_call($endpoint, null, 'GET');
        sc_json($r['json'] ?? ['success' => false, 'error' => 'Sin respuesta'], $r['status']);
        break;

    case 'fees':
        $creditId = $_GET['creditId'] ?? '';
        if (!$creditId) sc_json(['success' => false, 'error' => 'creditId requerido'], 400);
        $r = sc_api_call('fees/' . $creditId, null, 'GET');
        sc_json($r['json'] ?? ['success' => false, 'error' => 'Sin respuesta'], $r['status']);
        break;

    case 'transaction':
        // POST: crea intencion de pago, devuelve urlCheckout
        if ($method !== 'POST') sc_json(['success' => false, 'error' => 'Metodo no permitido'], 405);
        $r = sc_api_call('transaction', $body, 'POST');
        sc_json($r['json'] ?? ['success' => false, 'error' => 'Sin respuesta'], $r['status']);
        break;

    case 'transaction-information':
        $paymentRef = $_GET['paymentRef'] ?? '';
        if (!$paymentRef) sc_json(['success' => false, 'error' => 'paymentRef requerido'], 400);
        $r = sc_api_call('transaction-information?paymentRef=' . urlencode($paymentRef), null, 'GET');
        sc_json($r['json'] ?? ['success' => false, 'error' => 'Sin respuesta'], $r['status']);
        break;

    case 'refresh':
        // POST: refresca JWT usando jwtRefresh de la sesion
        if (!isset($_SESSION['jwtRefresh'])) sc_json(['success' => false, 'error' => 'No hay sesion para refrescar'], 400);
        $r = sc_api_call('refresh', ['jwtRefresh' => $_SESSION['jwtRefresh']], 'POST');
        sc_json($r['json'] ?? ['success' => false, 'error' => 'Sin respuesta'], $r['status']);
        break;

    default:
        sc_json(['success' => false, 'error' => 'Accion no reconocida', 'available' => ['status', 'authorizations', 'authentications', 'credits', 'fees', 'transaction', 'transaction-information', 'refresh']], 404);
}
