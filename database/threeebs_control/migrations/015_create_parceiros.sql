USE threeebs_control;

CREATE TABLE IF NOT EXISTS parceiro_candidaturas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    convite_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    usuario_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    email VARCHAR(190) NOT NULL,
    nome VARCHAR(150) DEFAULT NULL,
    github_url VARCHAR(500) DEFAULT NULL,
    linkedin_url VARCHAR(500) DEFAULT NULL,
    portfolio_url VARCHAR(500) DEFAULT NULL,
    tecnologias TEXT DEFAULT NULL,
    experiencia TEXT DEFAULT NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin
        NOT NULL DEFAULT 'email_pendente',
    email_confirmado_em DATETIME(6) DEFAULT NULL,
    candidatura_enviada_em DATETIME(6) DEFAULT NULL,
    aprovado_em DATETIME(6) DEFAULT NULL,
    aprovado_por_usuario_uuid
        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    confirmacao_webhook_status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin
        NOT NULL DEFAULT 'pendente',
    confirmacao_webhook_tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    confirmacao_webhook_ultimo_erro VARCHAR(255) DEFAULT NULL,
    confirmacao_webhook_ultimo_envio_em DATETIME(6) DEFAULT NULL,
    aprovacao_webhook_status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin
        NOT NULL DEFAULT 'nao_aplicavel',
    aprovacao_webhook_tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    aprovacao_webhook_ultimo_erro VARCHAR(255) DEFAULT NULL,
    aprovacao_webhook_ultimo_envio_em DATETIME(6) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_parceiro_candidaturas_uuid (uuid),
    UNIQUE KEY uq_parceiro_candidaturas_email (email),
    UNIQUE KEY uq_parceiro_candidaturas_convite (convite_uuid),
    UNIQUE KEY uq_parceiro_candidaturas_usuario (usuario_uuid),
    KEY idx_parceiro_candidaturas_status_created (status, created_at),
    KEY idx_parceiro_candidaturas_aprovador (aprovado_por_usuario_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS parceiros (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    candidatura_id BIGINT UNSIGNED NOT NULL,
    usuario_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin
        NOT NULL DEFAULT 'ativo',
    aprovado_por_usuario_uuid
        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    aprovado_em DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_parceiros_uuid (uuid),
    UNIQUE KEY uq_parceiros_candidatura (candidatura_id),
    UNIQUE KEY uq_parceiros_usuario (usuario_uuid),
    KEY idx_parceiros_status_usuario (status, usuario_uuid),
    KEY idx_parceiros_aprovador (aprovado_por_usuario_uuid),
    CONSTRAINT fk_parceiros_candidatura
        FOREIGN KEY (candidatura_id) REFERENCES parceiro_candidaturas (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (migration, checksum)
VALUES ('015_create_parceiros.sql', SHA2('015_create_parceiros.sql', 256))
ON DUPLICATE KEY UPDATE migration = VALUES(migration);
