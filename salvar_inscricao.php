<?php
header('Content-Type: application/json');

// --- 1. CONFIGURAÇÕES DO BANCO DE DADOS ---
$host = 'localhost';
$db   = 'joao4312_osfic';
$user = 'joao4312_osfic';
$pass = '81@c?W8B^VGm';
$charset = 'utf8mb4';
// ----------------------------------------------

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro Conexão: ' . $e->getMessage()]); exit;
}

$inputJSON = file_get_contents('php://input');
$dados = json_decode($inputJSON, true);

if (!$dados) { echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos.']); exit; }

try {
    $pdo->beginTransaction();

    // 1. Pega ID da Escola
    $stmtEscola = $pdo->prepare("SELECT id FROM escolas WHERE sigla = ?");
    $stmtEscola->execute([$dados['orientador']['escola']]);
    $escola = $stmtEscola->fetch();
    if (!$escola) throw new Exception("Escola inválida.");
    $escolaId = $escola['id'];

    $orientadorId = null;

    // --- LÓGICA DE DECISÃO: NOVO ou ATUALIZAÇÃO ---
    if (!empty($dados['id_orientador'])) {
        // MODO ATUALIZAÇÃO (UPDATE)
        $orientadorId = $dados['id_orientador'];
        
        // Atualiza dados pessoais
        $sqlUp = "UPDATE orientadores SET escola_id=?, nome=?, disciplina=?, email=?, cpf=?, whatsapp=? WHERE id=?";
        $stmtUp = $pdo->prepare($sqlUp);
        $stmtUp->execute([
            $escolaId, $dados['orientador']['nome'], $dados['orientador']['disciplina'], 
            $dados['orientador']['email'], $dados['orientador']['cpf'], $dados['orientador']['whatsapp'], 
            $orientadorId
        ]);

        // ESTRATÉGIA SEGURA: Remove grupos antigos e recria (garante sincronia total com o form)
        // Isso evita lógica complexa de verificar qual aluno foi removido ou adicionado
        $pdo->prepare("DELETE FROM grupos WHERE orientador_id = ?")->execute([$orientadorId]); 
        // (O Delete Cascade no banco vai apagar os alunos automaticamente)

    } else {
        // MODO CRIAÇÃO (INSERT)
        $sqlIns = "INSERT INTO orientadores (escola_id, nome, disciplina, email, cpf, whatsapp) VALUES (?, ?, ?, ?, ?, ?)";
        $stmtIns = $pdo->prepare($sqlIns);
        $stmtIns->execute([
            $escolaId, $dados['orientador']['nome'], $dados['orientador']['disciplina'], 
            $dados['orientador']['email'], $dados['orientador']['cpf'], $dados['orientador']['whatsapp']
        ]);
        $orientadorId = $pdo->lastInsertId();
    }

    // 2. Salvar Grupos e Alunos (Igual para os dois casos agora)
    $sqlGrupo = "INSERT INTO grupos (orientador_id, categoria) VALUES (?, ?)";
    $stmtGrupo = $pdo->prepare($sqlGrupo);
    $sqlAluno = "INSERT INTO alunos (grupo_id, nome, cpf, nivel_ensino, serie_ano) VALUES (?, ?, ?, ?, ?)";
    $stmtAluno = $pdo->prepare($sqlAluno);

    foreach ($dados['grupos'] as $grupo) {
        $stmtGrupo->execute([$orientadorId, $grupo['categoria']]);
        $grupoId = $pdo->lastInsertId();
        foreach ($grupo['alunos'] as $aluno) {
            $stmtAluno->execute([$grupoId, $aluno['nome'], $aluno['cpf'], $aluno['nivel'], $aluno['serie']]);
        }
    }

    $pdo->commit();
    // --- 4. ENVIO DE E-MAILS (CONFIRMAÇÃO) ---
    // CONFIGURAÇÃO: Coloque aqui o e-mail da organização que receberá os avisos
    $emailOrganizacao = 'organizacao@osfic.com.br'; 
    
    // CONFIGURAÇÃO: Este e-mail DEVE existir na sua hospedagem (ex: nao-responda@seusite.com.br)
    // Se você usar um e-mail que não é do seu domínio (como @gmail), pode cair no spam.
    $emailRemetente = 'nao-responda@osfic.com.br'; 

    // --- Montando o E-mail para o Orientador ---
    $assuntoOrientador = "Confirmação de Inscrição - OSFIC 2026";
    
    // Mensagem em HTML
    $msgOrientador = "
    <html>
    <head><title>Confirmação de Inscrição</title></head>
    <body style='font-family: Arial, sans-serif;'>
        <h2 style='color: #0284c7;'>Olá, " . $dados['orientador']['nome'] . "!</h2>
        <p>Recebemos sua inscrição/atualização com sucesso para a <b>OSFIC 2026</b>.</p>
        <p><b>Escola:</b> " . $dados['orientador']['escola'] . "<br>
        <b>Protocolo/ID:</b> " . $orientadorId . "</p>
        <hr>
        <h3>Grupos Cadastrados:</h3>
        <ul>";
        
    foreach ($dados['grupos'] as $g) {
        $msgOrientador .= "<li><b>Categoria:</b> " . ucfirst($g['categoria']) . " (" . count($g['alunos']) . " alunos)</li>";
    }

    $msgOrientador .= "
        </ul>
        <hr>
        <p style='font-size: 12px; color: #666;'>Este é um e-mail automático, por favor não responda.</p>
    </body>
    </html>
    ";

    // --- Montando o E-mail para a Organização ---
    $assuntoOrg = "Nova Inscrição: " . $dados['orientador']['nome'];
    $msgOrg = "
    <html>
    <body>
        <h3>Nova atividade no sistema OSFIC</h3>
        <p><b>Orientador:</b> " . $dados['orientador']['nome'] . "<br>
        <b>Escola:</b> " . $dados['orientador']['escola'] . "<br>
        <b>WhatsApp:</b> " . $dados['orientador']['whatsapp'] . "<br>
        <b>Ação:</b> " . (!empty($dados['id_orientador']) ? 'Atualização de Cadastro' : 'Novo Cadastro') . "</p>
    </body>
    </html>
    ";

    // --- Cabeçalhos (Headers) para aceitar HTML e definir o Remetente ---
    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: OSFIC <$emailRemetente>" . "\r\n";
    $headers .= "Reply-To: $emailRemetente" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // --- Envia os dois e-mails ---
    // O '@' na frente serve para evitar mostrar erros na tela se o servidor de e-mail falhar momentaneamente
    @mail($dados['orientador']['email'], $assuntoOrientador, $msgOrientador, $headers);
    @mail($emailOrganizacao, $assuntoOrg, $msgOrg, $headers);
    
    echo json_encode(['sucesso' => true, 'protocolo' => $orientadorId, 'modo' => !empty($dados['id_orientador']) ? 'atualizacao' : 'criacao']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro: ' . $e->getMessage()]);
}
?>
