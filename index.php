<?php
// Define o fuso horário para evitar reset precoce às 21h (Horário de Londres)
date_default_timezone_set('America/Sao_Paulo');

// Credenciais da Render
$SUPABASE_URL = rtrim(getenv('SUPABASE_URL'), '/');
$SUPABASE_KEY = getenv('SUPABASE_KEY');
$SECRET_KEY   = getenv('SECRET_KEY');

header('Content-Type: application/json');

$email = $_POST['email'] ?? '';
$token_recebido = $_POST['token'] ?? '';
$timestamp = $_POST['timestamp'] ?? 0;

// 1. Validar Assinatura
$token_esperado = hash_hmac('sha256', $email . $timestamp, $SECRET_KEY);
if ($token_recebido !== $token_esperado || (abs(time() - $timestamp) > 120)) {
    echo json_encode(["erro" => "Assinatura invalida ou atraso no relogio."]);
    exit;
}

// 2. Consultar Usuário
$url_consulta = "{$SUPABASE_URL}/rest/v1/bots_usuarios?email=eq." . urlencode($email);
$ch = curl_init($url_consulta);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: $SUPABASE_KEY", "Authorization: Bearer $SUPABASE_KEY"]);
$res = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($res[0])) {
    echo json_encode(["erro" => "Usuario nao encontrado."]);
    exit;
}

$user = $res[0];
$hoje = date('Y-m-d');

// 3. Status
if (($user['status'] ?? '') === 'manutencao') {
    echo json_encode(["erro" => "MANUTENCAO: " . ($user['mensagem_status'] ?? 'Aguarde')]);
    exit;
}
if (($user['status'] ?? '') === 'banido') {
    echo json_encode(["erro" => "ACESSO REVOGADO."]);
    exit;
}

// 4. Lógica de Reset (Agora baseada no Horário de Brasília)
$contagem = (($user['data_reset'] ?? '') !== $hoje) ? 0 : ($user['contagem'] ?? 0);
$limite = $user['limite_individual'] ?? 0;

if ($contagem >= $limite && $limite > 0) {
    echo json_encode(["erro" => "Limite de $limite ciclos atingido hoje!"]);
    exit;
}

// 5. Atualizar Contagem
$nova_contagem = $contagem + 1;
$url_patch = "{$SUPABASE_URL}/rest/v1/bots_usuarios?id=eq." . $user['id'];

$ch_patch = curl_init($url_patch);
curl_setopt($ch_patch, CURLOPT_CUSTOMREQUEST, "PATCH");
curl_setopt($ch_patch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_patch, CURLOPT_POSTFIELDS, json_encode(["contagem" => $nova_contagem, "data_reset" => $hoje]));
curl_setopt($ch_patch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY", 
    "Authorization: Bearer $SUPABASE_KEY", 
    "Content-Type: application/json"
]);
curl_exec($ch_patch);
curl_close($ch_patch);

// 6. Retorno
echo json_encode([
    "status" => "sucesso", 
    "ciclo" => (int)$nova_contagem, 
    "total" => (int)$limite
]);
