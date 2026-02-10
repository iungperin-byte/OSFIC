-- 1. Tabela de Escolas (A base da hierarquia)
CREATE TABLE escolas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sigla VARCHAR(20) NOT NULL,
    nome_completo VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserindo as escolas participantes
INSERT INTO escolas (sigla, nome_completo) VALUES 
('EMEFJK', 'Escola Municipal de Ensino Fundamental Juscelino Kubitschek'),
('CEGAF', 'Centro de Excelência Governador Augusto Franco'),
('EMPLAB', 'Escola Municipal Professor Luiz Antônio Barreto'),
('CEMG', 'Centro de Excelência Miguel das Graças');

-- 2. Tabela de Orientadores
-- O Orientador é vinculado à Escola. Ele se cadastra uma única vez.
CREATE TABLE orientadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    escola_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    disciplina VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    cpf VARCHAR(14) NOT NULL,
    whatsapp VARCHAR(20) NOT NULL, -- Campo WhatsApp incluído
    data_cadastro DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (escola_id) REFERENCES escolas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tabela de Grupos
-- O Grupo vincula o Orientador a uma Categoria.
-- Como o orientador_id está aqui, um mesmo orientador pode criar vários grupos
-- de categorias diferentes (ponte, catapulta, etc).
CREATE TABLE grupos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    orientador_id INT NOT NULL,
    categoria ENUM('ponte', 'catapulta', 'carrinho') NOT NULL,
    FOREIGN KEY (orientador_id) REFERENCES orientadores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Tabela de Alunos
-- Os alunos são vinculados ao Grupo específico.
CREATE TABLE alunos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grupo_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    cpf VARCHAR(14) NOT NULL,
    nivel_ensino VARCHAR(50) NOT NULL,
    serie_ano VARCHAR(20) NOT NULL,
    FOREIGN KEY (grupo_id) REFERENCES grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
