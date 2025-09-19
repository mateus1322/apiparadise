<?php
// =================== CONFIGURAÇÕES ===================
$offer_hash    = 'CODIGODOCHECKOUT'; 
$product_hash  = 'CODIGODOPRODUTO';
$access_token  = 'SEUCODIGOAPI';
$api_url       = 'https://api.paradisepagbr.com/api/public/v1/transactions?api_token=' . $access_token;
$postback_url  = '/webhook/pix_webhook.php';

$data = json_decode(file_get_contents("php://input"), true);

$name   = $data['name']  ?? 'Lucas Souza';
$email  = $data['email'] ?? 'lucas@email.com';
$cpf    = $data['cpf']   ?? '12345678900';
$phone  = $data['phone'] ?? '11999999999';
$amount = $data['amount'] ?? 990;
$utm    = $data['utm']   ?? [];

$payload = [
    "amount" => $amount,
    "offer_hash" => $offer_hash,
    "payment_method" => "pix",
    "customer" => [
        "name" => $name,
        "email" => $email,
        "phone_number" => $phone,
        "document" => $cpf,
        "street_name" => "Rua Exemplo",
        "number" => "123",
        "complement" => "Ap 101",
        "neighborhood" => "Centro",
        "city" => "São Paulo",
        "state" => "SP",
        "zip_code" => "01001000"
    ],
    "cart" => [[
        "product_hash" => $product_hash,
        "title" => "Produto Teste",
        "price" => $amount,
        "quantity" => 1,
        "operation_type" => 1,
        "tangible" => false
    ]],
    "installments" => 1,
    "expire_in_days" => 1,
    "postback_url" => $postback_url,
    "tracking" => [
        "utm_source"  => $utm['utm_source']  ?? '',
        "utm_medium"  => $utm['utm_medium']  ?? '',
        "utm_campaign"=> $utm['utm_campaign']?? '',
        "utm_term"    => $utm['utm_term']    ?? '',
        "utm_content" => $utm['utm_content'] ?? ''
    ]
];

// ========== ENVIA TRANSAÇÃO ==========
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response   = curl_exec($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ========== VERIFICA RESPOSTA ==========
if ($http_code === 200 || $http_code === 201) {
    $res = json_decode($response, true);
    $transactionId = $res['transaction'] ?? null;
    $qrCodeText = $res['pix']['pix_qr_code'] ?? '';

    // Tenta encontrar o hash na listagem
    $transaction_hash = null;

    if ($transactionId) {
        $list_url = 'https://api.paradisepagbr.com/api/public/v1/transactions?api_token=' . $access_token;
        $ch_list = curl_init($list_url);
        curl_setopt($ch_list, CURLOPT_RETURNTRANSFER, true);
        $list_response = curl_exec($ch_list);
        curl_close($ch_list);

        $transactions = json_decode($list_response, true);
        if (isset($transactions['data']) && is_array($transactions['data'])) {
            foreach ($transactions['data'] as $tx) {
                if (isset($tx['transaction']) && $tx['transaction'] === $transactionId) {
                    $transaction_hash = $tx['hash'] ?? null;
                    break;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'pix_data' => [
            'qrCode' => 'https://quickchart.io/qr?text=' . urlencode($qrCodeText),
            'qrCodeText' => $qrCodeText
        ],
        'transaction_id' => $transactionId,
        'transaction_hash' => $transaction_hash,
        'amount' => $amount
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => "Erro ao gerar pagamento. HTTP: $http_code",
        'debug' => json_decode($response, true)
    ]);
}