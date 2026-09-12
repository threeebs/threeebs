USE threeebs_control;

CREATE TABLE IF NOT EXISTS projeto_storage_quotas (
    projeto_id BIGINT UNSIGNED NOT NULL,
    armazenamento_bytes_max BIGINT UNSIGNED NOT NULL DEFAULT 104857600,
    arquivos_max INT UNSIGNED NOT NULL DEFAULT 1000,
    pastas_max INT UNSIGNED NOT NULL DEFAULT 250,
    arquivo_bytes_max BIGINT UNSIGNED NOT NULL DEFAULT 1048576,
    armazenamento_bytes_usados BIGINT UNSIGNED NOT NULL DEFAULT 0,
    arquivos_usados INT UNSIGNED NOT NULL DEFAULT 0,
    pastas_usadas INT UNSIGNED NOT NULL DEFAULT 0,
    armazenamento_bytes_reservados BIGINT UNSIGNED NOT NULL DEFAULT 0,
    arquivos_reservados INT UNSIGNED NOT NULL DEFAULT 0,
    pastas_reservadas INT UNSIGNED NOT NULL DEFAULT 0,
    reconciliado_em DATETIME(6) DEFAULT NULL,
    versao BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (projeto_id),
    CONSTRAINT fk_projeto_storage_quotas_projeto
        FOREIGN KEY (projeto_id) REFERENCES projetos (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS projeto_arquivos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ambiente_id BIGINT UNSIGNED NOT NULL,
    caminho VARCHAR(500) COLLATE utf8mb4_bin NOT NULL,
    tipo VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tamanho_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    hash_conteudo CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    versao BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_projeto_arquivos_ambiente_caminho (ambiente_id, caminho),
    KEY idx_projeto_arquivos_ambiente_tipo (ambiente_id, tipo),
    CONSTRAINT fk_projeto_arquivos_ambiente
        FOREIGN KEY (ambiente_id) REFERENCES ambientes (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_projeto_arquivos_tipo CHECK (tipo IN ('arquivo', 'pasta'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS projeto_storage_reservas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    projeto_id BIGINT UNSIGNED NOT NULL,
    ambiente_id BIGINT UNSIGNED NOT NULL,
    operacao VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    caminho VARCHAR(500) COLLATE utf8mb4_bin NOT NULL,
    armazenamento_bytes_delta BIGINT NOT NULL DEFAULT 0,
    arquivos_delta INT NOT NULL DEFAULT 0,
    pastas_delta INT NOT NULL DEFAULT 0,
    armazenamento_bytes_reservados BIGINT UNSIGNED NOT NULL DEFAULT 0,
    arquivos_reservados INT UNSIGNED NOT NULL DEFAULT 0,
    pastas_reservadas INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pendente',
    expira_em DATETIME(6) NOT NULL,
    finalizada_em DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_projeto_storage_reservas_uuid (uuid),
    KEY idx_projeto_storage_reservas_projeto_status_expira
        (projeto_id, status, expira_em),
    CONSTRAINT fk_projeto_storage_reservas_projeto
        FOREIGN KEY (projeto_id) REFERENCES projetos (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_projeto_storage_reservas_ambiente
        FOREIGN KEY (ambiente_id) REFERENCES ambientes (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_projeto_storage_reservas_status
        CHECK (status IN ('pendente', 'confirmada', 'cancelada', 'expirada'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO projeto_storage_quotas (projeto_id)
SELECT id FROM projetos;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('016_create_project_storage.sql');
