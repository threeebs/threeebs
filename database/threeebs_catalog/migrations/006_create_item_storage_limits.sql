USE threeebs_catalog;

CREATE TABLE IF NOT EXISTS item_limites_storage (
    item_id BIGINT UNSIGNED NOT NULL,
    armazenamento_bytes_max BIGINT UNSIGNED NOT NULL,
    arquivos_max INT UNSIGNED NOT NULL,
    pastas_max INT UNSIGNED NOT NULL,
    arquivo_bytes_max BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (item_id),
    CONSTRAINT fk_item_limites_storage_item
        FOREIGN KEY (item_id) REFERENCES itens (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT chk_item_limites_storage_armazenamento
        CHECK (armazenamento_bytes_max > 0),
    CONSTRAINT chk_item_limites_storage_arquivos
        CHECK (arquivos_max > 0),
    CONSTRAINT chk_item_limites_storage_arquivo
        CHECK (arquivo_bytes_max > 0
            AND arquivo_bytes_max <= armazenamento_bytes_max)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('006_create_item_storage_limits.sql');
