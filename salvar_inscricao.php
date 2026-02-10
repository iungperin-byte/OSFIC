<?php
header('Content-Type: application/json');

// --- CONFIGURAÇÕES DO BANCO (PREENCHA AQUI) ---
$host = 'localhost';
$db   = 'nome_do_seu_banco';
$user = 'seu_usuario_mysql';
$pass = 'sua_senha_mysql';
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
    echo json_encode(['sucesso' => true, 'protocolo' => $orientadorId, 'modo' => !empty($dados['id_orientador']) ? 'atualizacao' : 'criacao']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro: ' . $e->getMessage()]);
}
?>
