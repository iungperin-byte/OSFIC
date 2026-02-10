<?php
// Define que a resposta será um JSON (para o Javascript entender)
header('Content-Type: application/json');

// --- 1. CONFIGURAÇÕES DO BANCO DE DADOS (Preencha aqui!) ---
$host = 'localhost';      // Geralmente é 'localhost' na HostGator
$db   = 'nome_do_seu_banco'; // Coloque o nome exato do banco que você criou
$user = 'seu_usuario_mysql'; // Seu usuário do MySQL
$pass = 'sua_senha_mysql';   // Sua senha do MySQL
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Tenta conectar ao Banco
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Se falhar a conexão, avisa o HTML
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro de conexão com o banco: ' . $e->getMessage()]);
    exit;
}

// --- 2. RECEBER OS DADOS DO HTML ---
// Pega o JSON que veio no corpo da requisição
$inputJSON = file_get_contents('php://input');
$dados = json_decode($inputJSON, true);

// Verifica se os dados chegaram
if (!$dados || !isset($dados['orientador']) || !isset($dados['grupos'])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Dados inválidos ou incompletos recebidos.']);
    exit;
}

// --- 3. SALVAR TUDO (TRANSAÇÃO) ---
try {
    // Inicia uma transação (se der erro no meio, ele desfaz tudo para não deixar dados pela metade)
    $pdo->beginTransaction();

    // A) Identificar o ID da Escola com base na Sigla
    $stmtEscola = $pdo->prepare("SELECT id FROM escolas WHERE sigla = ?");
    $stmtEscola->execute([$dados['orientador']['escola']]);
    $escola = $stmtEscola->fetch();

    if (!$escola) {
        throw new Exception("Escola inválida ou não encontrada no banco.");
    }
    $escolaId = $escola['id'];

    // B) Salvar o Orientador
    $sqlOrientador = "INSERT INTO orientadores (escola_id, nome, disciplina, email, cpf, whatsapp) VALUES (?, ?, ?, ?, ?, ?)";
    $stmtOrientador = $pdo->prepare($sqlOrientador);
    $stmtOrientador->execute([
        $escolaId,
        $dados['orientador']['disciplina'],
        $dados['orientador']['nome'],
        $dados['orientador']['email'],
        $dados['orientador']['cpf'],
        $dados['orientador']['whatsapp']
    ]);
    
    // Pega o ID que acabou de ser criado para o Orientador
    $orientadorId = $pdo->lastInsertId();

    // C) Salvar os Grupos e Alunos
    $sqlGrupo = "INSERT INTO grupos (orientador_id, categoria) VALUES (?, ?)";
    $stmtGrupo = $pdo->prepare($sqlGrupo);

    $sqlAluno = "INSERT INTO alunos (grupo_id, nome, cpf, nivel_ensino, serie_ano) VALUES (?, ?, ?, ?, ?)";
    $stmtAluno = $pdo->prepare($sqlAluno);

    foreach ($dados['grupos'] as $grupo) {
        // 1. Cria o Grupo
        $stmtGrupo->execute([$orientadorId, $grupo['categoria']]);
        $grupoId = $pdo->lastInsertId();

        // 2. Cria os Alunos deste Grupo
        foreach ($grupo['alunos'] as $aluno) {
            $stmtAluno->execute([
                $grupoId,
                $aluno['nome'],
                $aluno['cpf'],
                $aluno['nivel'],
                $aluno['serie']
            ]);
        }
    }

    // Se chegou até aqui, deu tudo certo! Confirma a gravação.
    $pdo->commit();

    echo json_encode(['sucesso' => true, 'protocolo' => $orientadorId]);

} catch (Exception $e) {
    // Se deu algum erro, desfaz tudo o que tentou gravar
    $pdo->rollBack();
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao salvar: ' . $e->getMessage()]);
}
?>
