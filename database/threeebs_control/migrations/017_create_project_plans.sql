USE threeebs_control;

CREATE TABLE IF NOT EXISTS projeto_planos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    projeto_id BIGINT UNSIGNED NOT NULL,
    catalog_item_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    catalog_item_codigo VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    catalog_item_nome VARCHAR(180) NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ativo',
    armazenamento_bytes_max BIGINT UNSIGNED NOT NULL,
    arquivos_max INT UNSIGNED NOT NULL,
    pastas_max INT UNSIGNED NOT NULL,
    arquivo_bytes_max BIGINT UNSIGNED NOT NULL,
    atribuido_por_usuario_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    vigencia_inicio DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    vigencia_fim DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_projeto_planos_uuid (uuid),
    KEY idx_projeto_planos_projeto_status (projeto_id, status, vigencia_inicio),
    KEY idx_projeto_planos_catalog_item (catalog_item_uuid),
    CONSTRAINT fk_projeto_planos_projeto
        FOREIGN KEY (projeto_id) REFERENCES projetos (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_projeto_planos_status
        CHECK (status IN ('ativo', 'encerrado')),
    CONSTRAINT chk_projeto_planos_vigencia
        CHECK (vigencia_fim IS NULL OR vigencia_fim >= vigencia_inicio),
    CONSTRAINT chk_projeto_planos_limites
        CHECK (armazenamento_bytes_max > 0
            AND arquivos_max > 0
            AND arquivo_bytes_max > 0
            AND arquivo_bytes_max <= armazenamento_bytes_max)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('017_create_project_plans.sql');
