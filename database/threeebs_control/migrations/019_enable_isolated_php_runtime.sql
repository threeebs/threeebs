USE threeebs_control;

ALTER TABLE ambiente_runtimes
    DROP CHECK chk_ambiente_runtimes_execucao,
    ADD CONSTRAINT chk_ambiente_runtimes_execucao
        CHECK (execucao_habilitada IN (0, 1));

INSERT IGNORE INTO schema_migrations (migration)
VALUES ('019_enable_isolated_php_runtime.sql');
