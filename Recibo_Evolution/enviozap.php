<?php
// Defina a chave de criptografia (deve ser a mesma usada no arquivo de configuração)
$chave_criptografia = '3NyBm8aa54eg8jeE';

// Função para desencriptar os dados
function desencriptar($dados, $chave) {
    return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

// Carrega e desencripta configurações de token e IP
$configFile = '/opt/mk-auth/dados/Recibo_Whatsapp/config.php';
if (file_exists($configFile)) {
    $config = include($configFile);
    $ip = desencriptar($config['ip'], $chave_criptografia);
    $user = desencriptar($config['user'], $chave_criptografia);
    $token = desencriptar($config['token'], $chave_criptografia);

    if ($token && $ip) {
        $apiBaseURL = "https://$ip/message/sendText/$user"; // URL da Evolution API
    } else {
        die("Erro: Falha ao desencriptar o token ou IP.");
    }
} else {
    die("Erro: Arquivo de configuração não encontrado.");
}

// Configurações do banco de dados
$host = "localhost";
$usuario = "root";
$senha = "vertrigo";
$db = "mkradius";

// Conexão com o banco de dados
$con = new mysqli($host, $usuario, $senha, $db);
if ($con->connect_error) {
    die("Erro ao conectar ao banco de dados: " . $con->connect_error);
}

// Arquivo de log
$logFile = '/opt/mk-auth/dados/Recibo_Whatsapp/log_pagamentos.txt';

// Função para enviar a mensagem com detecção automática da versão
function enviarMensagemEvolutionAPI($celular, $mensagem) {
    global $apiBaseURL, $token;

    // Tenta enviar usando o formato da API v1
    $postDataV1 = json_encode([
        'number' => $celular,
        'textMessage' => ['text' => $mensagem]
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiBaseURL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataV1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Se o envio com a v1 for bem-sucedido, retorna true
    if ($httpCode === 201 || $httpCode === 200) {
        return true;
    }

    // Se v1 falhou, tenta o formato da API v2
    $postDataV2 = json_encode([
        'number' => $celular,
        'text' => $mensagem
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiBaseURL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postDataV2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $token
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Se o envio com a v2 for bem-sucedido, retorna true
    if ($httpCode === 201 || $httpCode === 200) {
        return true;
    }

    // Registra erro se ambas as tentativas falharem
    escreverLog("Erro ao enviar mensagem para $celular com ambas as versões da API. Código HTTP final: $httpCode");
    return false;
}

// Função para formatar o número de celular
function formatarNumero($numero) {
    $numero = preg_replace('/\D/', '', $numero);
    if (strlen($numero) == 10) {
        $numero = '55' . substr($numero, 0, 2) . '9' . substr($numero, 2);
    } elseif (strlen($numero) == 11) {
        $numero = '55' . $numero;
    }
    return $numero;
}

// Função para escrever no arquivo de log
function escreverLog($mensagem) {
    global $logFile;
    $dataHora = date('d/m/Y H:i:s');
    $logMensagem = "[$dataHora] $mensagem" . PHP_EOL;
    file_put_contents($logFile, $logMensagem, FILE_APPEND);
}

// Processamento dos registros não enviados
$query = "SELECT * FROM brl_pago WHERE envio = 0";
$stmt = $con->prepare($query);

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Extrai e formata os dados
        $login = $row['login'];
        $datapag = date('d/m/Y', strtotime($row['datapag']));
        $datavenc = date('d/m/Y', strtotime($row['datavenc']));
        $valor = number_format($row['valor'], 2, ',', '.');
        $valorpag = number_format($row['valorpag'], 2, ',', '.');

        // Busca o nome e o número de celular do cliente com base no login
        $clienteQuery = "SELECT nome, celular FROM sis_cliente WHERE login = ?";
        $clienteStmt = $con->prepare($clienteQuery);
        
        if ($clienteStmt) {
            $clienteStmt->bind_param('s', $login);
            $clienteStmt->execute();
            $clienteResult = $clienteStmt->get_result();
            $celular = "";
            $nome = "";

            if ($clienteRow = $clienteResult->fetch_assoc()) {
                $nome = $clienteRow['nome'];
                $celular = formatarNumero($clienteRow['celular']);
            }

            // Define a mensagem com o texto e emojis
            $mensagem = "💵 *CONFIRMAÇÃO DE PAGAMENTO*\n\n".
                        "👤 *Cliente*: $nome\n".
                        "✅ *Pagamento recebido em*: $datapag\n".
                        "📅 *Fatura com vencimento em*: $datavenc\n".
                        "💰 *Valor da fatura*: R$ $valor\n".
                        "💸 *Valor do pagamento*: R$ $valorpag\n\n".               
                        "*Atenciosamente, Nome do Seu Provedor Aqui* 🤝\n".
                        "••••••••••••••••••••••••••••••••••\n".
                        "_Mensagem gerada automaticamente pelo sistema._";

            // Verifica o número de celular antes de enviar
            if ($celular && strlen($celular) >= 12) {
                if (enviarMensagemEvolutionAPI($celular, $mensagem)) {
                    // Marca o registro como enviado na tabela brl_pago
                    $updateQuery = "UPDATE brl_pago SET envio = 1 WHERE id = ?";
                    $updateStmt = $con->prepare($updateQuery);
                    if ($updateStmt) {
                        $updateStmt->bind_param('i', $row['id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    // Escreve no log o sucesso do envio
                    escreverLog("Mensagem enviada com sucesso para $nome ($celular)");
                } else {
                    // Escreve no log o erro do envio
                    escreverLog("Erro ao enviar mensagem para $nome ($celular)");
                }
            } else {
                // Loga um erro se o número for inválido
                escreverLog("Número de telefone inválido para $nome");
            }

            $clienteStmt->close();
        } else {
            escreverLog("Erro ao preparar a consulta para cliente: " . $con->error);
        }
    }
    
    $stmt->close();
} else {
    escreverLog("Erro ao preparar a consulta: " . $con->error);
}

// Fecha a conexão ao final de todas as operações
$con->close();
?>
