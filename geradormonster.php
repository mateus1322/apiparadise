<?php
// geradormonster.php
header('Content-Type: application/json; charset=utf-8');

// CONFIGURAÇÃO: ajuste se necessário
$USE_SANDBOX = false; // coloque true para usar o endpoint sandbox, se tiver
$ENDPOINT_PROD = "https://paradise-pay.com/payment/initiate";
$ENDPOINT_SANDBOX = "https://paradise-pay.com/sandbox/payment/initiate";

// Seu token (SECRET) — NÃO expor em front-end
$API_TOKEN = "5dn50nlwYcYLBB302qdExQ9rBCOOAhyb4P2bgiwq3kMkwovMmuPIZ0aqjlFe";

// Lê payload enviado pelo index.html (fetch POST JSON)
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => "Payload inválido ou vazio"]);
    exit;
}

// Mapeie os campos conforme você envia do index.html
// Seu index envia: { name, email, cpf, phone, utm, amount }
$name = $input['name'] ?? '';
$email = $input['email'] ?? '';
$cpf = $input['cpf'] ?? '';
$phone = $input['phone'] ?? '';
$amount = $input['amount'] ?? ($input['VALOR_PAGAMENTO'] ?? null); // já em centavos pelo index

// Monta payload para ParadisePay — ajuste os nomes se a docs pedir diferente
$body = [
    // campos comuns para criação de pagamento (ajuste conforme doc ParadisePay)
    "amount" => intval($amount), // em centavos
    "currency" => "BRL",
    "method" => "pix", // requer que ParadisePay suporte pix neste endpoint
    "customer" => [
        "name" => $name,
        "email" => $email,
        "document" => $cpf,
        "phone" => $phone
    ],
    "metadata" => [
        "source" => "site",
        "utms" => $input['utm'] ?? new stdClass()
    ],
    // opcional: expiration, description, etc.
];

// escolhe endpoint
$url = $USE_SANDBOX ? $ENDPOINT_SANDBOX : $ENDPOINT_PROD;

// faz requisição cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $API_TOKEN
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlErr) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "error" => "Erro ao contactar ParadisePay: " . ($curlErr ?: "sem resposta")
    ]);
    exit;
}

// tenta decodificar JSON de retorno
$data = json_decode($response, true);
if (!$data) {
    // resposta não-JSON: devolve tal qual pra debug (mas sinaliza erro)
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "raw_response" => $response,
        "error" => "Resposta da API não pôde ser decodificada como JSON (HTTP $httpcode)."
    ]);
    exit;
}

// --- Normaliza a resposta para o formato que index.html espera ---
// Queremos: success: true, pix_data: { qrCodeText: "..." }, transaction_hash: "..."
$success = ($httpcode >= 200 && $httpcode < 300);
$pixText = null;
$transactionId = null;

// Possíveis locais comuns onde o QR / copy-paste pode vir:
if (isset($data['pix']['copyPaste'])) {
    $pixText = $data['pix']['copyPaste'];
} elseif (isset($data['pix']['qrcode']) ) {
    $pixText = $data['pix']['qrcode'];
} elseif (isset($data['payment']['qr_code'])) {
    $pixText = $data['payment']['qr_code'];
} elseif (isset($data['checkout']['copyPaste'])) {
    $pixText = $data['checkout']['copyPaste'];
} elseif (isset($data['copyPaste'])) {
    $pixText = $data['copyPaste'];
} elseif (isset($data['qr_code'])) {
    $pixText = $data['qr_code'];
}

// transaction id / hash:
if (isset($data['id'])) {
    $transactionId = (string)$data['id'];
} elseif (isset($data['transaction_id'])) {
    $transactionId = (string)$data['transaction_id'];
} elseif (isset($data['charge_id'])) {
    $transactionId = (string)$data['charge_id'];
} elseif (isset($data['payment']['id'])) {
    $transactionId = (string)$data['payment']['id'];
}

// Se a API devolveu um link/checkout em vez de pix, retornamos ele também
$checkoutUrl = $data['checkout']['url'] ?? $data['payment']['url'] ?? ($data['url'] ?? null);

// Monta retorno para o front
$responseOut = [
    "success" => $success,
    // preserva a resposta crua se útil
    "api_raw" => $data
];

if ($pixText) {
    $responseOut['pix_data'] = ["qrCodeText" => $pixText];
}
if ($transactionId) {
    $responseOut['transaction_hash'] = $transactionId;
}
if ($checkoutUrl && empty($pixText)) {
    // se não há pix mas há checkout, devolve o link para o front decidir o que fazer
    $responseOut['checkout_url'] = $checkoutUrl;
}

// Se API devolveu erro padrão
if (!$success) {
    $responseOut['error'] = $data['message'] ?? ($data['error'] ?? 'Erro desconhecido da API');
    http_response_code(400);
}

echo json_encode($responseOut, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
