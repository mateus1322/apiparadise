<?php
// verificar_status.php
header('Content-Type: application/json; charset=utf-8');

// CONFIGURAÇÃO: ajuste se necessário
$USE_SANDBOX = false;
$ENDPOINT_STATUS_PROD_TRY1 = "https://paradise-pay.com/payment/"; // tentativa: GET {base}{id}
$ENDPOINT_STATUS_PROD_TRY2 = "https://paradise-pay.com/payment/status/"; // tentativa alternativa
$ENDPOINT_STATUS_SANDBOX_TRY1 = "https://paradise-pay.com/sandbox/payment/";
$ENDPOINT_STATUS_SANDBOX_TRY2 = "https://paradise-pay.com/sandbox/payment/status/";

// Seu token (SECRET)
$API_TOKEN = "5dn50nlwYcYLBB302qdExQ9rBCOOAhyb4P2bgiwq3kMkwovMmuPIZ0aqjlFe";

$transactionId = $_GET['hash'] ?? null;
if (!$transactionId) {
    http_response_code(400);
    echo json_encode(["error" => "ID da transação (hash) não informado"]);
    exit;
}

// escolhe endpoints
if ($USE_SANDBOX) {
    $bases = [$ENDPOINT_STATUS_SANDBOX_TRY1, $ENDPOINT_STATUS_SANDBOX_TRY2];
} else {
    $bases = [$ENDPOINT_STATUS_PROD_TRY1, $ENDPOINT_STATUS_PROD_TRY2];
}

$finalData = null;
$httpcode = null;
$curlErr = null;

// tenta ambos os estilos (algumas APIs usam /payment/{id}, outras /payment/status/{id})
foreach ($bases as $base) {
    $url = rtrim($base, '/') . '/' . rawurlencode($transactionId);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $API_TOKEN
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlErr) {
        // tenta próximo
        continue;
    }

    $data = json_decode($response, true);
    if ($data) {
        $finalData = $data;
        break;
    }
}

// Se não obteve resposta JSON, devolve erro
if (!$finalData) {
    http_response_code(502);
    echo json_encode([
        "payment_status" => "error",
        "error" => "Não foi possível obter status da ParadisePay (HTTP $httpcode).",
        "curl_error" => $curlErr ?? null
    ]);
    exit;
}

// Tenta interpretar o status em campos comuns
$statusRaw = null;
if (isset($finalData['status'])) $statusRaw = strtolower($finalData['status']);
elseif (isset($finalData['payment_status'])) $statusRaw = strtolower($finalData['payment_status']);
elseif (isset($finalData['state'])) $statusRaw = strtolower($finalData['state']);
elseif (isset($finalData['data']['status'])) $statusRaw = strtolower($finalData['data']['status']);

// Mapeamento: considera 'paid', 'completed', 'confirmed' como pago
$paidKeywords = ['paid','completed','confirmed','approved','success'];
$isPaid = false;
if ($statusRaw) {
    foreach ($paidKeywords as $k) {
        if (strpos($statusRaw, $k) !== false) {
            $isPaid = true;
            break;
        }
    }
}

// Retorna pagamento simplificado que index.html espera
echo json_encode([
    "payment_status" => $isPaid ? 'paid' : 'pending',
    "raw" => $finalData
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
