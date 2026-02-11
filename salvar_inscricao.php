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

// --- FUNÇÃO DE LIMPEZA (SANITIZAÇÃO) ---
function limpar($dado) {
    if (is_array($dado)) {
        return array_map('limpar', $dado);
    }
    // Remove tags HTML e espaços extras
    return trim(strip_tags($dado));
}
// Aplica a limpeza em tudo o que chegou
if ($dados) {
    $dados = limpar($dados);
}

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
        // --- 4. ENVIO DE E-MAILS (CONFIRMAÇÃO COMPLETA) ---
    $emailOrganizacao = 'organizacao@osfic.com.br'; 
    $emailRemetente = 'nao-responda@osfic.com.br'; 

    // --- MONTAGEM DO RESUMO DETALHADO DOS GRUPOS ---
    $resumoGruposHtml = "";
    $categoriasNomes = [
        'ponte' => 'Ponte de Palitos',
        'catapulta' => 'Lançador de Projéteis',
        'carrinho' => 'Carrinho Elétrico Solar'
    ];

    foreach ($dados['grupos'] as $grupo) {
        $nomeCat = $categoriasNomes[$grupo['categoria']] ?? $grupo['categoria'];
        $resumoGruposHtml .= "
        <div style='margin-bottom: 15px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 8px; background-color: #ffffff;'>
            <b style='color: #1a73e8; font-size: 16px; display: block; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;'>
                Categoria: " . $nomeCat . "
            </b>";
        
        foreach ($grupo['alunos'] as $idx => $aluno) {
            $resumoGruposHtml .= "
            <div style='margin-bottom: 8px; font-size: 14px;'>
                <span style='color: #555;'>Aluno " . ($idx + 1) . ":</span> <b>" . $aluno['nome'] . "</b><br>
                <span style='font-size: 12px; color: #777;'>
                    CPF: " . $aluno['cpf'] . " | Série: " . $aluno['serie'] . " | Nível: " . $aluno['nivel'] . "
                </span>
            </div>";
        }
        $resumoGruposHtml .= "</div>";
    }

    // --- MONTAGEM DOS DADOS DO ORIENTADOR ---
    $conteudoDadosGerais = "
        <div style='background-color: #f8f9fa; padding: 20px; border-radius: 10px; border: 1px solid #dee2e6;'>
            <p><strong>Protocolo de Registro:</strong> #" . $orientadorId . "</p>
            <p><strong>Nome do Orientador:</strong> " . $dados['orientador']['nome'] . "</p>
            <p><strong>CPF:</strong> " . $dados['orientador']['cpf'] . "</p>
            <p><strong>E-mail:</strong> " . $dados['orientador']['email'] . "</p>
            <p><strong>WhatsApp:</strong> " . $dados['orientador']['whatsapp'] . "</p>
            <p><strong>Escola:</strong> " . $dados['orientador']['escola'] . "</p>
            <p><strong>Disciplina:</strong> " . $dados['orientador']['disciplina'] . "</p>
        </div>
        <h3 style='color: #0056b3; margin-top: 25px;'>Equipes e Integrantes:</h3>
        " . $resumoGruposHtml;

    // --- CABEÇALHOS (HEADERS) ---
    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
    $headers .= "From: OSFIC <$emailRemetente>" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // --- E-MAIL PARA O ORIENTADOR ---
    $assuntoOrientador = "Confirmação de Inscrição - OSFIC 2026";
    $msgOrientador = "<html><body style='font-family: Arial, sans-serif; max-width: 600px; margin: auto;'>
        <h2 style='color: #2e7d32;'>Inscrição Confirmada - OSFIC 2026</h2>
        <p>Olá, <b>" . $dados['orientador']['nome'] . "</b>. Seus dados foram processados com sucesso.</p>
        " . $conteudoDadosGerais . "
        <p style='margin-top: 20px; font-size: 12px; color: #888;'>Este comprovante foi gerado automaticamente pelo sistema da Olimpíada Sergipana Fundamental de Inovação e Ciências.</p>
    </body></html>";

    // --- E-MAIL PARA A ORGANIZAÇÃO ---
    $acao = (!empty($dados['id_orientador']) ? 'CADASTRO ATUALIZADO' : 'NOVO CADASTRO');
    $assuntoOrg = "[$acao] " . $dados['orientador']['nome'];
    $msgOrg = "<html><body style='font-family: Arial, sans-serif; max-width: 600px; margin: auto;'>
        <h2 style='color: #d32f2f;'>$acao - SISTEMA OSFIC</h2>
        " . $conteudoDadosGerais . "
        <p style='background: #fff3cd; padding: 10px; border-radius: 5px; margin-top: 15px;'>
            <strong>Aviso:</strong> Verifique se os dados acima estão em conformidade com o edital.
        </p>
    </body></html>";

    // --- ENVIO ---
    @mail($dados['orientador']['email'], $assuntoOrientador, $msgOrientador, $headers);
    @mail($emailOrganizacao, $assuntoOrg, $msgOrg, $headers);
    
    echo json_encode(['sucesso' => true, 'protocolo' => $orientadorId, 'modo' => !empty($dados['id_orientador']) ? 'atualizacao' : 'criacao']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro: ' . $e->getMessage()]);
}
?>
