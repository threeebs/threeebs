<?php

declare(strict_types=1);

require '/var/www/shared/bootstrap.php';

$statement = db('control')->query(
    "SELECT p.id project_id,p.uuid project_uuid,
            a.id environment_id,a.uuid environment_uuid,a.diretorio
     FROM projetos p
     JOIN ambientes a ON a.projeto_id=p.id AND a.status='ativo'
     WHERE p.status='ativo'
       AND a.id=(SELECT MIN(a2.id) FROM ambientes a2
                 WHERE a2.projeto_id=p.id AND a2.status='ativo')
     ORDER BY p.id"
);

$failures = 0;
foreach ($statement->fetchAll() as $row) {
    try {
        $access = [
            'project' => ['id' => $row['project_id'], 'uuid' => $row['project_uuid']],
            'environment' => ['id' => $row['environment_id'], 'uuid' => $row['environment_uuid']],
            'root' => safe_environment_path((string) $row['diretorio'], false),
        ];
        $usage = project_storage_service($access)->reconcile();
        echo sprintf(
            "PASS %s bytes=%d arquivos=%d pastas=%d\n",
            $row['project_uuid'],
            $usage['bytes'],
            $usage['files'],
            $usage['folders']
        );
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, sprintf("FAIL %s %s\n", $row['project_uuid'], $error->getMessage()));
    }
}

exit($failures === 0 ? 0 : 1);
