<?php

$api_token = 'CODIGOAPIDAPARADISE'; 
$base_url = 'https://api.paradisepagbr.com/api/public/v1/transactions/';


$hash = $_GET['hash'] ?? null;
if (!$hash) {
    http_response_code(400);
    echo json_encode(['error' => 'Hash não informado']);
    exit;
}

$url = $base_url . urlencode($hash) . '?api_token=' . $api_token;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    echo $response;
} else {
    echo json_encode([
        'error' => 'Erro na consulta',
        'status_code' => $http_code
    ]);
}