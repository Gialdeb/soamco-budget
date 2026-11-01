<?php

namespace App\Services\Automation\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use JsonException;
use RuntimeException;
use ZipArchive;

class FullBackupService
{
    public const ARCHIVE_PREFIX = 'full-backup-';

    public function run(): array
    {
        $this->ensureZipArchiveAvailable();

        $startedAt = microtime(true);
        $disk = Storage::disk(config('automation.backups.disk', 'local'));
        $timestamp = now()->format('Ymd_His');
        $relativePath = $this->directory().'/'.self::ARCHIVE_PREFIX.$timestamp.'.zip';

        $disk->makeDirectory(dirname($relativePath));

        $absolutePath = $disk->path($relativePath);

        $temporaryDirectory = sys_get_temp_dir().'/full-backup-'.bin2hex(random_bytes(16));
        if (! mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException('Unable to create backup temporary directory.');
        }
        $partialPath = $absolutePath.'.'.bin2hex(random_bytes(8)).'.partial';
        $zip = new ZipArchive;
        $opened = false;

        try {
            if (is_file($absolutePath)) {
                throw new RuntimeException('The full backup archive already exists.');
            }
            $status = $zip->open($partialPath, ZipArchive::CREATE | ZipArchive::EXCL);
            if ($status !== true) {
                throw new RuntimeException('Unable to create full backup archive.');
            }
            $opened = true;
            $database = $this->exportDatabase($temporaryDirectory);

            $manifest = [
                'type' => 'full_backup',
                'artifact_classification' => 'application_snapshot_archive',
                'restore_capability' => [
                    'is_automated_restore_available' => false,
                    'is_end_to_end_restorable' => false,
                    'level' => 'manual_rebuild_required',
                    'notes' => [
                        'The archive contains JSON table exports and selected application files.',
                        'No automated restore command is implemented for full environment rebuild.',
                        'Use this archive as an application snapshot for manual recovery or future restore tooling.',
                    ],
                ],
                'environment' => app()->environment(),
                'app_url' => config('app.url'),
                'created_at' => now()->toIso8601String(),
                'database' => [
                    'table_count' => count($database),
                    'tables' => array_keys($database),
                    'format' => 'json-per-table',
                ],
                'included_directories' => [
                    'local_disk_root' => $disk->path(''),
                    'public_disk_root' => Storage::disk('public')->path(''),
                ],
                'included_assets' => [
                    'public/apple-touch-icon.png',
                    'public/favicon.svg',
                ],
            ];

            if (! $zip->addFromString('manifest.json', $this->encode($manifest))) {
                throw new RuntimeException('Unable to add backup manifest.');
            }

            foreach ($database as $table => $path) {
                if (! $zip->addFile($path, "database/{$table}.json")) {
                    throw new RuntimeException("Unable to add backup table: {$table}.");
                }
            }

            $this->addDiskDirectory($zip, 'local', '', 'storage/local', [
                $this->directory(),
                trim((string) config('automation.backups.user.directory', 'backups/users'), '/'),
            ]);
            $this->addDiskDirectory($zip, 'public', '', 'storage/public');
            $this->addPublicAsset($zip, 'apple-touch-icon.png', 'assets/apple-touch-icon.png');
            $this->addPublicAsset($zip, 'favicon.svg', 'assets/favicon.svg');
            $opened = false;
            $closed = $zip->close();
            if (! $closed) {
                throw new RuntimeException('Unable to finalize full backup archive.');
            }
            if (! rename($partialPath, $absolutePath)) {
                throw new RuntimeException('Unable to publish full backup archive.');
            }
        } finally {
            try {
                if ($opened) {
                    $zip->unchangeAll();
                    $zip->close();
                }
            } finally {
                try {
                    if (! File::deleteDirectory($temporaryDirectory)) {
                        throw new RuntimeException('Unable to remove backup temporary directory.');
                    }
                } finally {
                    if (is_file($partialPath) && ! unlink($partialPath)) {
                        throw new RuntimeException('Unable to remove incomplete backup archive.');
                    }
                }
            }
        }

        $sizeBytes = is_file($absolutePath) ? filesize($absolutePath) ?: 0 : 0;
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'summary' => __('automation.backups.full_generated'),
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'size_bytes' => $sizeBytes,
            'size_human' => Number::fileSize($sizeBytes),
            'duration_ms' => $durationMs,
            'duration_human' => $this->formatDuration($durationMs),
            'table_count' => count($database),
            'restore_capability' => 'manual_rebuild_required',
        ];
    }

    public function directory(): string
    {
        return trim((string) config('automation.backups.full.directory', 'backups/full'), '/');
    }

    /**
     * @return array<string, string>
     */
    protected function exportDatabase(string $directory): array
    {
        $tables = [];

        foreach ($this->tableNames() as $index => $table) {
            $path = $directory.'/'.$index.'.json';
            $stream = fopen($path, 'xb');
            if ($stream === false) {
                throw new RuntimeException("Unable to create export for table: {$table}.");
            }

            try {
                $this->write($stream, '[');
                $first = true;
                $columns = Schema::getColumnListing($table);
                DB::table($table)->orderBy($columns[0])->chunk(500, function ($rows) use ($stream, &$first): void {
                    foreach ($rows as $row) {
                        $json = str_replace("\n", "\n    ", $this->encode((array) $row));
                        $this->write($stream, ($first ? "\n" : ",\n").'    '.$json);
                        $first = false;
                    }
                });
                $this->write($stream, $first ? ']' : "\n]");
                if (! fflush($stream)) {
                    throw new RuntimeException("Unable to flush export for table: {$table}.");
                }
            } finally {
                fclose($stream);
            }

            $tables[$table] = $path;
        }

        return $tables;
    }

    /** @param resource $stream */
    protected function write($stream, string $contents): void
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write backup table export.');
            }
            $offset += $written;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function tableNames(): array
    {
        $driver = DB::getDriverName();
        $pgsqlTableQuery = implode(' ', [
            'select tablename',
            'from pg_tables',
            "where schemaname = 'public'",
            'order by tablename',
        ]);
        $sqliteTableQuery = implode(' ', [
            'select name',
            'from sqlite_master',
            "where type = 'table'",
            "and name not like 'sqlite_%'",
            'order by name',
        ]);
        $mysqlTableQuery = implode(' ', [
            'select table_name',
            'from information_schema.tables',
            'where table_schema = database()',
            'order by table_name',
        ]);

        return match ($driver) {
            // noinspection SqlNoDataSourceInspection
            'pgsql' => collect(DB::select($pgsqlTableQuery))
                ->pluck('tablename')
                ->all(),
            // noinspection SqlNoDataSourceInspection
            'sqlite' => collect(DB::select($sqliteTableQuery))
                ->pluck('name')
                ->all(),
            // noinspection SqlNoDataSourceInspection
            default => collect(DB::select($mysqlTableQuery))
                ->pluck('table_name')
                ->all(),
        };
    }

    /**
     * @param  array<int, string>  $excludedPrefixes
     */
    protected function addDiskDirectory(
        ZipArchive $zip,
        string $diskName,
        string $directory,
        string $zipPrefix,
        array $excludedPrefixes = [],
    ): void {
        $disk = Storage::disk($diskName);
        $files = $disk->allFiles($directory);

        foreach ($files as $file) {
            foreach ($excludedPrefixes as $excludedPrefix) {
                if ($excludedPrefix !== '' && str_starts_with($file, $excludedPrefix)) {
                    continue 2;
                }
            }

            $filePath = $disk->path($file);

            if (! is_file($filePath) || ! is_readable($filePath)) {
                continue;
            }

            if (! $zip->addFile($filePath, $zipPrefix.'/'.ltrim($file, '/'))) {
                throw new RuntimeException('Unable to add backup file: '.$file);
            }
        }
    }

    protected function addPublicAsset(ZipArchive $zip, string $source, string $target): void
    {
        $path = public_path($source);

        if (is_file($path) && is_readable($path)) {
            if (! $zip->addFile($path, $target)) {
                throw new RuntimeException('Unable to add backup asset: '.$source);
            }
        }
    }

    protected function formatDuration(int $durationMs): string
    {
        return number_format($durationMs / 1000, 2).'s';
    }

    protected function ensureZipArchiveAvailable(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'The PHP zip extension is required for backups. Install/enable ext-zip so ZipArchive is available.',
            );
        }
    }

    /**
     * @throws JsonException
     */
    protected function encode(mixed $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
