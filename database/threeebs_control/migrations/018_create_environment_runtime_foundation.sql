USE threeebs_control;

CREATE TABLE IF NOT EXISTS ambiente_runtimes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ambiente_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
        DEFAULT 'runtime.static',
    execucao_habilitada TINYINT(1) NOT NULL DEFAULT 0,
    mount_target VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
        DEFAULT '/var/www/project',
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
        DEFAULT 'planejado',
    configuracao JSON DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_ambiente_runtimes_uuid (uuid),
    UNIQUE KEY uq_ambiente_runtimes_ambiente (ambiente_id),
    KEY idx_ambiente_runtimes_tipo_status (tipo, status),
    CONSTRAINT fk_ambiente_runtimes_ambiente
        FOREIGN KEY (ambiente_id) REFERENCES ambientes (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_ambiente_runtimes_tipo
        CHECK (tipo IN ('runtime.static', 'runtime.php')),
    CONSTRAINT chk_ambiente_runtimes_mount
        CHECK (mount_target = '/var/www/project'),
    CONSTRAINT chk_ambiente_runtimes_execucao
        CHECK (execucao_habilitada = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ambiente_variaveis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ambiente_id BIGINT UNSIGNED NOT NULL,
    chave VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    valor_texto TEXT DEFAULT NULL,
    secreto TINYINT(1) NOT NULL DEFAULT 0,
    credential_ref VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_ambiente_variaveis_uuid (uuid),
    UNIQUE KEY uq_ambiente_variaveis_chave (ambiente_id, chave),
    KEY idx_ambiente_variaveis_secreto (ambiente_id, secreto),
    CONSTRAINT fk_ambiente_variaveis_ambiente
        FOREIGN KEY (ambiente_id) REFERENCES ambientes (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_ambiente_variaveis_chave
        CHECK (chave REGEXP '^[A-Z][A-Z0-9_]{0,99}$'),
    CONSTRAINT chk_ambiente_variaveis_fonte
        CHECK (
            (secreto = 0 AND valor_texto IS NOT NULL AND credential_ref IS NULL)
            OR
            (secreto = 1 AND valor_texto IS NULL AND credential_ref IS NOT NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO ambiente_runtimes
    (uuid, ambiente_id, tipo, execucao_habilitada, mount_target, status, configuracao)
SELECT
    UUID(),
    a.id,
    'runtime.static',
    0,
    '/var/www/project',
    'planejado',
    JSON_OBJECT('document_root', '/var/www/project')
FROM ambientes a
LEFT JOIN ambiente_runtimes ar ON ar.ambiente_id = a.id
WHERE ar.id IS NULL;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('018_create_environment_runtime_foundation.sql');
