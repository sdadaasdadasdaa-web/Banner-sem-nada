<?php
/**
 * PIX API - Buscar informacoes da transacao (Furiapay)
 */

// Desabilita exibicao de erros
error_reporting(0);
ini_set('display_errors', 0);

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['error' => 'Metodo nao permitido']);
    exit;
}

try {
    // Carrega credenciais (se existir)
    $configPath = __DIR__ . '/../config/pix-credentials.php';
    if (file_exists($configPath)) {
        @include_once $configPath;
    }

    // Helpers de gateway
    $gatewayHelperPath = __DIR__ . '/../includes/gateway-state.php';
    if (file_exists($gatewayHelperPath)) {
        @include_once $gatewayHelperPath;
    }

    // Pega ID da transacao
    $transactionId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
    if ($transactionId === '') {
        throw new Exception('ID da transacao e obrigatorio');
    }

    $gateway = 'furiapay';

    $publicKey = getenv('FURIAPAY_PUBLIC_KEY');
    $secretKey = getenv('FURIAPAY_SECRET_KEY');
    $infoTemplate = getenv('FURIAPAY_STATUS_URL_TEMPLATE');

    if (defined('FURIAPAY_PUBLIC_KEY') && !$publicKey) {
        $publicKey = FURIAPAY_PUBLIC_KEY;
    }
    if (defined('FURIAPAY_SECRET_KEY') && !$secretKey) {
        $secretKey = FURIAPAY_SECRET_KEY;
    }
    if (defined('FURIAPAY_STATUS_URL_TEMPLATE') && !$infoTemplate) {
        $infoTemplate = FURIAPAY_STATUS_URL_TEMPLATE;
    }

    if (empty($publicKey) || empty($secretKey)) {
        throw new Exception('Credenciais FuriaPay nao configuradas');
    }

    $auth = base64_encode("{$publicKey}:{$secretKey}");

    if (!$infoTemplate) {
        $infoTemplate = 'https://api.fusionpay.com.br/v1/payment-transaction/info/{id}';
    }
    $apiUrl = str_replace('{id}', urlencode($transactionId), $infoTemplate);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $auth,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Erro ao conectar: ' . $curlError);
    }

    $data = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $errorMsg = is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null;
        if (!$errorMsg && is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
            $flat = [];
            foreach ($data['errors'] as $k => $v) {
                if (is_array($v)) {
                    $flat[] = $k . ': ' . implode(', ', $v);
                } else {
                    $flat[] = $k . ': ' . $v;
                }
            }
            $errorMsg = implode(' | ', $flat);
        }
        if (!$errorMsg) {
            $errorMsg = 'Erro desconhecido';
        }
        http_response_code($httpCode ?: 500);
        echo json_encode([
            'success' => false,
            'error' => $errorMsg,
            'http_code' => $httpCode,
            'response' => $data
        ]);
        exit;
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Resposta invalida da API');
    }

    $dataNode = $data['data'] ?? ($data['response']['data'] ?? null);
    if (!$dataNode && is_array($data) && isset($data['id'])) {
        $dataNode = $data;
    }
    $status = $dataNode['status'] ?? ($data['status'] ?? null);
    if (is_string($status)) {
        $status = strtolower($status);
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'data' => $dataNode ?? $data,
        'gateway' => $gateway
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
