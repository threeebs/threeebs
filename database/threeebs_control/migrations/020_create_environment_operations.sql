USE threeebs_control;

CREATE TABLE IF NOT EXISTS ambiente_operacoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ambiente_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pendente',
    solicitado_por_usuario_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload JSON DEFAULT NULL,
    resultado JSON DEFAULT NULL,
    erro_codigo VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    worker_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    solicitado_em DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    iniciado_em DATETIME(6) DEFAULT NULL,
    concluido_em DATETIME(6) DEFAULT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    ativa TINYINT GENERATED ALWAYS AS (
        CASE WHEN status IN ('pendente', 'executando') THEN 1 ELSE NULL END
    ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ambiente_operacoes_uuid (uuid),
    UNIQUE KEY uq_ambiente_operacoes_ativa (ambiente_id, tipo, ativa),
    KEY idx_ambiente_operacoes_fila (status, id),
    KEY idx_ambiente_operacoes_ambiente (ambiente_id, solicitado_em),
    CONSTRAINT fk_ambiente_operacoes_ambiente
        FOREIGN KEY (ambiente_id) REFERENCES ambientes (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_ambiente_operacoes_tipo
        CHECK (tipo IN (
            'database.provision',
            'runtime.php.activate',
            'database.migrations.apply'
        )),
    CONSTRAINT chk_ambiente_operacoes_status
        CHECK (status IN ('pendente', 'executando', 'concluida', 'falhou'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('020_create_environment_operations.sql');
