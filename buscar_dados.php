<?php
header('Content-Type: application/json');

// CONFIGURAÇÕES DO BANCO (Preencha igual ao salvar_inscricao.php)
$host = 'localhost';
$db   = 'joao4312_osfic';
$user = 'joao4312_osfic';
$pass = '81@c?W8B^VGm';
$charset = 'utf8mb4';


$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    echo json_encode(['erro' => true, 'msg' => 'Erro conexão']); exit;
}

$cpf = $_GET['cpf'] ?? '';
// Limpa o CPF para garantir busca correta
// (Assumindo que no banco está salvo com pontuação ou sem, aqui vamos buscar string exata do input)
// Se no banco você salvou com pontuação, mantenha.

if (!$cpf) { echo json_encode([]); exit; }

// 1. Busca Orientadores com esse CPF
$sql = "SELECT o.*, e.sigla as escola_sigla, e.nome_completo as escola_nome 
        FROM orientadores o 
        JOIN escolas e ON o.escola_id = e.id 
        WHERE o.cpf = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$cpf]);
$orientadores = $stmt->fetchAll();

$resultado = [];

foreach ($orientadores as $ori) {
    // 2. Para cada cadastro, busca os Grupos
    $sqlG = "SELECT * FROM grupos WHERE orientador_id = ?";
    $stmtG = $pdo->prepare($sqlG);
    $stmtG->execute([$ori['id']]);
    $gruposDB = $stmtG->fetchAll();
    
    $gruposFormatados = [];
    
    foreach ($gruposDB as $g) {
        // 3. Para cada Grupo, busca os Alunos
        $sqlA = "SELECT * FROM alunos WHERE grupo_id = ?";
        $stmtA = $pdo->prepare($sqlA);
        $stmtA->execute([$g['id']]);
        $alunosDB = $stmtA->fetchAll();
        
        $gruposFormatados[] = [
            'id' => $g['id'], // ID real do banco
            'categoria' => $g['categoria'],
            'alunos' => array_map(function($a) {
                return [
                    'nome' => $a['nome'],
                    'cpf' => $a['cpf'],
                    'nivel' => $a['nivel_ensino'],
                    'serie' => $a['serie_ano']
                ];
            }, $alunosDB)
        ];
    }

    $resultado[] = [
        'id_orientador' => $ori['id'],
        'escola_sigla' => $ori['escola_sigla'],
        'escola_nome' => $ori['escola_nome'],
        'nome' => $ori['nome'],
        'email' => $ori['email'],
        'disciplina' => $ori['disciplina'],
        'whatsapp' => $ori['whatsapp'],
        'grupos' => $gruposFormatados
    ];
}

echo json_encode($resultado);
?>
