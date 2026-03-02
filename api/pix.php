<?php
/**
 * PIX API - Gera codigo PIX via FuriaPay
 */

// Desabilita exibicao de erros no output
error_reporting(0);
ini_set('display_errors', 0);

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

    // Le requisicao
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception('JSON invalido');
    }

    $amount = isset($data['amount']) ? (int)$data['amount'] : 0;
    $cnpj = isset($data['cnpj']) ? $data['cnpj'] : '';
    $nome = isset($data['nome']) ? $data['nome'] : 'Cliente';
    $periodos = isset($data['periodos']) ? $data['periodos'] : [];
    $metadataExtra = (isset($data['metadata']) && is_array($data['metadata'])) ? $data['metadata'] : [];

    $cnpjLimpo = preg_replace('/\D/', '', $cnpj);

    if ($amount < 100) {
        throw new Exception('Valor minimo: R$ 1,00');
    }

    if (strlen($cnpjLimpo) !== 14 && strlen($cnpjLimpo) !== 11) {
        throw new Exception('CNPJ/CPF invalido');
    }

    $docType = (strlen($cnpjLimpo) === 14) ? 'cnpj' : 'cpf';

    // Garante que nome nao esta vazio
    $nomeCliente = !empty($nome) && $nome !== '-' ? $nome : 'Microempreendedor Individual';

    // Gera email baseado no nome
    $nomeEmail = strtolower($nomeCliente);
    $nomeEmail = iconv('UTF-8', 'ASCII//TRANSLIT', $nomeEmail);
    $nomeEmail = preg_replace('/[^a-z0-9]/', '', $nomeEmail);
    $nomeEmail = substr($nomeEmail, 0, 20);
    $emailCliente = $nomeEmail . '@gmail.com';

    // Gera telefone aleatorio valido
    $ddds = [11,21,31,41,51,61,71,81,85,91];
    $ddd = $ddds[array_rand($ddds)];
    $telefone = sprintf('%02d9%08d', $ddd, rand(10000000, 99999999));

    // Transaction ID local
    $transactionIdLocal = time() . rand(1000, 9999);

    $gateway = 'furiapay';

    $pixCode = null;
    $transactionIdResp = null;
    $statusResp = null;
    $expiresAt = null;

    $publicKey = getenv('FURIAPAY_PUBLIC_KEY');
    $secretKey = getenv('FURIAPAY_SECRET_KEY');
    $postbackUrl = getenv('FURIAPAY_POSTBACK_URL');
    $createUrl = getenv('FURIAPAY_CREATE_URL');

    if (defined('FURIAPAY_PUBLIC_KEY') && !$publicKey) {
        $publicKey = FURIAPAY_PUBLIC_KEY;
    }
    if (defined('FURIAPAY_SECRET_KEY') && !$secretKey) {
        $secretKey = FURIAPAY_SECRET_KEY;
    }
    if (defined('FURIAPAY_POSTBACK_URL') && !$postbackUrl) {
        $postbackUrl = FURIAPAY_POSTBACK_URL;
    }
    if (defined('FURIAPAY_CREATE_URL') && !$createUrl) {
        $createUrl = FURIAPAY_CREATE_URL;
    }

    if (empty($publicKey) || empty($secretKey)) {
        throw new Exception('Credenciais FuriaPay nao configuradas');
    }

    // Autenticacao Basic: public_key:secret_key
    $auth = base64_encode("{$publicKey}:{$secretKey}");

    if (empty($createUrl)) {
        $createUrl = "https://api.fusionpay.com.br/v1/payment-transaction/create";
    }

    // Monta payload para API FuriaPay
    $payload = [
        'payment_method' => 'pix',
        'customer' => [
            'document' => [
                'type' => $docType,
                'number' => $cnpjLimpo
            ],
            'name' => $nomeCliente,
            'email' => $emailCliente,
            'phone' => $telefone
        ],
        'items' => [
            [
                'title' => 'PRODUTO01',
                'unit_price' => (int)$amount,
                'quantity' => 1,
                'tangible' => false,
                'external_ref' => 'item_' . $transactionIdLocal
            ]
        ],
        'amount' => (int)$amount,
        'metadata' => array_merge([
            'provider_name' => 'API Pix',
            'order_id' => (string)$transactionIdLocal,
            'periodos' => implode(', ', $periodos),
            'cnpj' => $cnpjLimpo
        ], $metadataExtra)
    ];

    if (!empty($postbackUrl)) {
        $payload['postback_url'] = $postbackUrl;
    }

    // Faz requisicao para API FuriaPay
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $createUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Erro de conexao: $curlError");
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['message'] ?? $errorData['error'] ?? null;
        if (!$errorMsg && isset($errorData['errors']) && is_array($errorData['errors'])) {
            $flat = [];
            foreach ($errorData['errors'] as $k => $v) {
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
        throw new Exception("Erro API ($httpCode): $errorMsg");
    }

    $responseData = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Resposta invalida da API');
    }

    $dataNode = $responseData['data'] ?? ($responseData['response']['data'] ?? null);
    $pixNode = $dataNode['pix'] ?? ($responseData['pix'] ?? null);

    $candidates = [
        $pixNode['qr_code'] ?? null,
        $pixNode['qrcode'] ?? null,
        $pixNode['code'] ?? null,
        $responseData['qr_code'] ?? null,
        $responseData['qrcode'] ?? null,
        $responseData['pixCopiaECola'] ?? null,
        $responseData['brcode'] ?? null
    ];
    foreach ($candidates as $cand) {
        if (is_string($cand) && trim($cand) !== '') {
            $pixCode = $cand;
            break;
        }
    }

    $transactionIdResp = $dataNode['id'] ?? $responseData['id'] ?? $transactionIdLocal;
    $statusResp = $dataNode['status'] ?? $responseData['status'] ?? 'pending';
    $expiresAt = $pixNode['expiration_date'] ?? $responseData['expiration_date'] ?? null;

    if (!$pixCode) {
        throw new Exception('Codigo PIX nao encontrado na resposta');
    }

    if (!$transactionIdResp) {
        $transactionIdResp = $transactionIdLocal;
    }

    if (function_exists('record_transaction_gateway') && $transactionIdResp) {
        record_transaction_gateway($transactionIdResp, $gateway);
    }

    // Retorna resposta
    echo json_encode([
        'success' => true,
        'brcode' => $pixCode,
        'pixCopiaECola' => $pixCode,
        'transactionId' => (string)$transactionIdResp,
        'id' => (string)$transactionIdResp,
        'status' => $statusResp,
        'expiresAt' => $expiresAt,
        'gateway' => $gateway
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
