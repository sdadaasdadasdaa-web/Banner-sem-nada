<?php
/**
 * PIX API - Verificar Status de Pagamento (Furiapay)
 */

// Desabilita exibicao de erros
error_reporting(0);
ini_set('display_errors', 0);

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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
    if (empty($transactionId)) {
        throw new Exception('ID da transacao e obrigatorio');
    }

    // Gateway unico
    $gateway = 'furiapay';

    $paidStatuses = ['paid', 'approved', 'confirmed', 'success', 'succeeded', 'paid_out', 'completed', 'settled'];
    $unpaidStatuses = ['pending', 'waiting', 'waiting_payment', 'processing', 'created', 'unpaid', 'canceled', 'cancelled', 'expired', 'refused', 'failed', 'chargeback', 'refunded', 'error'];
    $paid = false;
    $status = 'pending';
    $data = null;

    $publicKey = getenv('FURIAPAY_PUBLIC_KEY');
    $secretKey = getenv('FURIAPAY_SECRET_KEY');
    $statusTemplate = getenv('FURIAPAY_STATUS_URL_TEMPLATE');

    if (defined('FURIAPAY_PUBLIC_KEY') && !$publicKey) {
        $publicKey = FURIAPAY_PUBLIC_KEY;
    }
    if (defined('FURIAPAY_SECRET_KEY') && !$secretKey) {
        $secretKey = FURIAPAY_SECRET_KEY;
    }
    if (defined('FURIAPAY_STATUS_URL_TEMPLATE') && !$statusTemplate) {
        $statusTemplate = FURIAPAY_STATUS_URL_TEMPLATE;
    }

    if (empty($publicKey) || empty($secretKey)) {
        throw new Exception('Credenciais FuriaPay nao configuradas');
    }

    // Autenticacao
    $auth = base64_encode("{$publicKey}:{$secretKey}");

    // Endpoint de status (FusionPay). Se precisar, sobrescreva via env var FURIAPAY_STATUS_URL_TEMPLATE.
    if (!$statusTemplate) {
        $statusTemplate = 'https://api.fusionpay.com.br/v1/payment-transaction/info/{id}';
    }
    $primaryUrl = str_replace('{id}', urlencode($transactionId), $statusTemplate);
    $fallbackTemplate = 'https://api.fusionpay.com.br/v1/payment-transaction/info/{id}';
    $fallbackUrl = str_replace('{id}', urlencode($transactionId), $fallbackTemplate);

    $urls = [$primaryUrl];
    if ($fallbackUrl !== $primaryUrl) {
        $urls[] = $fallbackUrl;
    }

    $response = null;
    $httpCode = 0;
    $curlError = '';
    foreach ($urls as $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
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
            continue;
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            break;
        }
    }

    if ($curlError && ($httpCode < 200 || $httpCode >= 300)) {
        throw new Exception('Erro ao conectar: ' . $curlError);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        // Retorna status pendente se nao conseguir verificar
        echo json_encode([
            'success' => true,
            'status' => 'pending',
            'paid' => false,
            'message' => 'Aguardando confirmacao',
            'gateway' => $gateway
        ]);
        exit;
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Resposta invalida da API');
    }

    $dataNode = $data['data'] ?? ($data['response']['data'] ?? null);
    $status = $dataNode['status'] ?? $data['status'] ?? 'pending';
    $status = is_string($status) ? strtolower($status) : $status;
    $statusStr = is_string($status) ? strtolower($status) : '';
    $explicitPaid = $statusStr !== '' && in_array($statusStr, $paidStatuses, true);
    $explicitUnpaid = $statusStr !== '' && in_array($statusStr, $unpaidStatuses, true);
    $paidAt = $dataNode['paid_at'] ?? ($data['paid_at'] ?? ($dataNode['paidAt'] ?? ($data['paidAt'] ?? null)));
    $paidAmount = $dataNode['paid_amount'] ?? ($data['paid_amount'] ?? ($dataNode['paidAmount'] ?? ($data['paidAmount'] ?? null)));

    if ($explicitPaid) {
        $paid = true;
    } elseif ($explicitUnpaid) {
        $paid = false;
    } else {
        if (!empty($paidAt)) {
            $paid = true;
        } elseif (is_numeric($paidAmount) && (float)$paidAmount > 0) {
            $paid = true;
        }
    }

    if ($paid && function_exists('mark_transaction_paid_and_toggle')) {
        mark_transaction_paid_and_toggle($transactionId, $gateway);
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'paid' => $paid,
        'data' => $data,
        'gateway' => $gateway
    ]);

} catch (Exception $e) {
    // Em caso de erro, retorna status pendente para nao quebrar o fluxo
    echo json_encode([
        'success' => true,
        'status' => 'pending',
        'paid' => false,
        'error' => $e->getMessage()
    ]);
}
