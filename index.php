<?php
// Puxa as credenciais protegidas da Render
$SUPABASE_URL = getenv('SUPABASE_URL');
$SUPABASE_KEY = getenv('SUPABASE_KEY');
$SECRET_KEY   = getenv('SECRET_KEY');

header('Content-Type: application/json');

$email = $_POST['email'] ?? '';
$token_recebido = $_POST['token'] ?? '';
$timestamp = $_POST['timestamp'] ?? 0;

// 1. Validar Assinatura (Seguranca contra Sniffing/Hackers)
$token_esperado = hash_hmac('sha256', $email . $timestamp, $SECRET_KEY);
if ($token_recebido !== $token_esperado || (abs(time() - $timestamp) > 60)) {
    die(json_encode(["erro" => "Assinatura invalida ou relogio do celular incorreto."]));
}

// 2. Consultar o Cliente no Supabase
$hoje = date('Y-m-d');
$ch = curl_init("$SUPABASE_URL?email=eq." . urlencode($email));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey: $SUPABASE_KEY", "Authorization: Bearer $SUPABASE_KEY"]);
$res = json_decode(curl_exec($ch), true);

if (empty($res)) {
    die(json_encode(["erro" => "E-mail nao autorizado. Fale com o administrador."]));
}

$user = $res[0];

// 3. Verificacao de Status e Manutencao
if ($user['status'] === 'manutencao') {
    die(json_encode(["erro" => "MANUTENCAO: " . $user['mensagem_status']]));
}
if ($user['status'] === 'banido') {
    die(json_encode(["erro" => "ACESSO REVOGADO: Pagamento pendente ou violacao de termos."]));
}

// 4. Lógica de Reset e Limite Vendido
$contagem = ($user['data_reset'] !== $hoje) ? 0 : $user['contagem'];
$limite = $user['limite_individual'];

if ($contagem >= $limite) {
    die(json_encode(["erro" => "Limite diario de $limite ciclos atingido.!"]));
}

// 5. Atualizar Contagem no Banco (+1)
$nova_contagem = $contagem + 1;
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "contagem" => $nova_contagem,
    "data_reset" => $hoje
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: $SUPABASE_KEY", 
    "Authorization: Bearer $SUPABASE_KEY", 
    "Content-Type: application/json"
]);
curl_exec($ch);
curl_close($ch);

// Retorna autorizacao para o bot
echo json_encode([
    "status" => "sucesso", 
    "ciclo" => $nova_contagem, 
    "total" => $limite
]);
