<?php
require_once '../config.php';

// Headers per API
header('Content-Type: application/json');

// CORS: l'API usa cookie di sessione, quindi non può usare Allow-Origin: *
// con credenziali. Si riflette solo un origin presente in allowlist.
$allowedOrigins = [
    // Aggiungere qui gli origin consentiti (es. 'https://meethub.example').
];
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

// Gestisci preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Helper per risposte JSON
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($message, $status = 400, $code = null) {
    $response = ['success' => false, 'error' => $message];
    if ($code) $response['code'] = $code;
    jsonResponse($response, $status);
}

// Routing manuale - supporta sia PATH_INFO che query string
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];
$path = str_replace($scriptName, '', $requestUri);
$path = strtok($path, '?');
$path = trim($path, '/');

$segments = explode('/', $path);
$resource = $segments[0] ?? '';
$id = $segments[1] ?? null;
$subresource = $segments[2] ?? null;

// Verifica autenticazione (eccetto per auth)
$publicEndpoints = ['auth'];
if (!in_array($resource, $publicEndpoints) && !isset($_SESSION['user_id'])) {
    jsonError('Non autenticato', 401, 'UNAUTHORIZED');
}

// Routing - passa $id e $subresource ai file inclusi
switch ($resource) {
    case 'auth':
        require_once __DIR__ . '/auth.php';
        break;
    case 'users':
        require_once __DIR__ . '/users.php';
        break;
    case 'messages':
        require_once __DIR__ . '/messages.php';
        break;
    case 'interactions':
        require_once __DIR__ . '/interactions.php';
        break;
    case 'matches':
        require_once __DIR__ . '/matches.php';
        break;
    default:
        jsonError('Endpoint non trovato', 404, 'NOT_FOUND');
}
?>