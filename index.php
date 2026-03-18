<?php
// Puxa as credenciais das Variáveis de Ambiente da Render
$SUPABASE_URL = rtrim(getenv('SUPABASE_URL'), '/'); // Remove barra final se houver
$SUPABASE_KEY = getenv('SUPABASE_KEY');
$SECRET_KEY   = getenv('SECRET_KEY');

header('Content-Type: application/json');

// Captura os dados do POST
$email = $_POST['email'] ?? '';
$token_recebido = $_POST['token'] ?? '';
$timestamp = $_POST['timestamp'] ?? 0;

// 1. Validar Assinatura (Segurança)
$token_esperado = hash_hmac('sha256', $email . $timestamp, $SECRET_KEY);
if ($token_recebido !== $token_esperado || (abs(time() - $timestamp) > 120)) {
    echo json_encode(["erro" => "Assinatura invalida ou relogio do celular atrasado."]);
    exit;
}

// 2. Consultar o Usuário no Supabase (Caminho completo da Tabela)
$url_consulta = "{$SUPABASE_URL}/rest/v1/bots_usuarios?email=eq." . urlencode($email);

$ch = curl_init($url_consulta);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY",
    "Authorization: Bearer $SUPABASE_KEY"
]);

$exec_consulta = curl_exec($ch);
$res = json_decode($exec_consulta, true);
curl_close($ch);

if (empty($res) || !isset($res[0])) {
    echo json_encode(["erro" => "E-mail nao autorizado ou nao encontrado."]);
    exit;
}

$user = $res[0];
$hoje = date('Y-m-d');

// 3. Verificação de Status
if (($user['status'] ?? '') === 'manutencao') {
    echo json_encode(["erro" => "MANUTENCAO: " . ($user['mensagem_status'] ?? 'Aguarde')]);
    exit;
}
if (($user['status'] ?? '') === 'banido') {
    echo json_encode(["erro" => "ACESSO REVOGADO: Violacao de termos."]);
    exit;
}

// 4. Lógica de Reset Diário e Limite
$contagem = (($user['data_reset'] ?? '') !== $hoje) ? 0 : ($user['contagem'] ?? 0);
$limite = $user['limite_individual'] ?? 0;

if ($contagem >= $limite && $limite > 0) {
    echo json_encode(["erro" => "Limite diario de $limite ciclos atingido!"]);
    exit;
}

// 5. Atualizar Contagem no Banco (+1) usando o ID
$nova_contagem = $contagem + 1;
$url_patch = "{$SUPABASE_URL}/rest/v1/bots_usuarios?id=eq." . $user['id'];

$ch_patch = curl_init($url_patch);
curl_setopt($ch_patch, CURLOPT_CUSTOMREQUEST, "PATCH");
curl_setopt($ch_patch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_patch, CURLOPT_POSTFIELDS, json_encode([
    "contagem" => $nova_contagem,
    "data_reset" => $hoje
]));
curl_setopt($ch_patch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY", 
    "Authorization: Bearer $SUPABASE_KEY", 
    "Content-Type: application/json"
]);

curl_exec($ch_patch);
curl_close($ch_patch);

// 6. Retorno de Sucesso para o Bot
echo json_encode([
    "status" => "sucesso", 
    "ciclo" => (int)$nova_contagem, 
    "total" => (int)$limite
]);
