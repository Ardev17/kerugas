<?php

/**
 * Cópias de segurança (Outubro 2026) — botão "Fazer backup" do Super Admin
 * geral (painel → Cópias de segurança).
 */
return [

    // Pasta onde ficam as cópias. Em produção aponte para um VOLUME próprio
    // (idealmente noutro disco), nunca para a pasta do código.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Quantas cópias concluídas se guardam; as mais antigas são apagadas
    // quando uma nova termina. 0 = nunca apagar sozinho.
    'keep' => (int) env('BACKUP_KEEP', 7),

    // Caminho do `mysqldump` (ou `mariadb-dump`). No Laragon (Windows) é algo
    // como C:\laragon\bin\mysql\mysql-8.0.30\bin\mysqldump.exe.
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

    // Servidor de onde se lê a cópia. Vazio = o mesmo da aplicação. Com muitos
    // dados, aponte para uma RÉPLICA de leitura para o backup não pesar no
    // servidor que atende as pessoas (ver docs/dimensionamento-producao.md).
    'db' => [
        'host' => env('BACKUP_DB_HOST'),
        'port' => env('BACKUP_DB_PORT'),
        'username' => env('BACKUP_DB_USERNAME'),
        'password' => env('BACKUP_DB_PASSWORD'),
    ],

    // Espaço livre mínimo (MB) na pasta das cópias para começar.
    'min_free_mb' => (int) env('BACKUP_MIN_FREE_MB', 500),

    // Também guarda as fotos (avatares e fotos do Vizinhos).
    'include_files' => (bool) env('BACKUP_INCLUDE_FILES', true),
    'files_dirs' => ['avatars', 'community'],

    // Segundos até o Super Admin deixar de poder usar o link de descarga.
    'download_link_minutes' => (int) env('BACKUP_DOWNLOAD_LINK_MINUTES', 10),

    // Descarga eficiente: prefixo de um `location ... { internal; alias <pasta dos backups>; }`
    // do nginx (ver infrastructure/nginx/nginx.conf). Vazio = o PHP envia o ficheiro.
    'accel_prefix' => env('BACKUP_ACCEL_PREFIX'),

    // Um backup "em curso" há mais do que isto (minutos) é dado como falhado
    // (o servidor reiniciou a meio) e deixa fazer outro.
    'stale_after_minutes' => (int) env('BACKUP_STALE_AFTER_MINUTES', 480),

    // Backup automático todas as noites (hora de Angola), além do botão.
    'schedule' => [
        'enabled' => (bool) env('BACKUP_SCHEDULE_ENABLED', false),
        'time' => env('BACKUP_SCHEDULE_TIME', '02:30'),
    ],

    // Restaurar pelo painel (Outubro 2026): pasta onde ficam os ficheiros
    // enviados até ao restauro (apagados no fim; sessões com mais de 24 h são
    // limpas sozinhas), tamanho de cada parte do envio e limite total.
    'restore_path' => env('BACKUP_RESTORE_PATH') ?: storage_path('app/restore'),
    'restore_chunk_kb' => (int) env('BACKUP_RESTORE_CHUNK_KB', 1024),
    'restore_max_upload_mb' => (int) env('BACKUP_RESTORE_MAX_UPLOAD_MB', 51200),
];
