<?php

use App\Services\Automation\Backups\FullBackupService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    config()->set('automation.backups.disk', 'local');
    Schema::create('backup_stream_rows', function (Blueprint $table): void {
        $table->string('reference')->primary();
        $table->text('value')->nullable();
        $table->integer('quantity');
    });
    Schema::create('backup_stream_empty', function (Blueprint $table): void {
        $table->string('reference');
    });
});

function streamingBackupFixture(): FullBackupService
{
    return new class extends FullBackupService
    {
        public ?string $temporaryDirectory = null;

        public bool $failAfterExport = false;

        public bool $removeExportBeforeClose = false;

        protected function tableNames(): array
        {
            return ['backup_stream_rows', 'backup_stream_empty'];
        }

        protected function exportDatabase(string $directory): array
        {
            $this->temporaryDirectory = $directory;

            return parent::exportDatabase($directory);
        }

        protected function addPublicAsset(ZipArchive $zip, string $source, string $target): void
        {
            if ($this->removeExportBeforeClose && is_file($this->temporaryDirectory.'/0.json')) {
                unlink($this->temporaryDirectory.'/0.json');
            }
            if ($this->failAfterExport) {
                throw new RuntimeException('File collection failed');
            }
        }
    };
}

it('streams multiple chunks with the existing JSON format including empty tables and tables without an id', function () {
    foreach (range(1, 1201) as $number) {
        DB::table('backup_stream_rows')->insert([
            'reference' => sprintf('%05d', $number),
            'value' => $number % 2 === 0 ? null : "È un valore / con\nuna nuova riga",
            'quantity' => $number,
        ]);
    }
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_starts_with($query->sql, 'select * from "backup_stream_rows"')) {
            $queries[] = $query->sql;
        }
    });
    $service = streamingBackupFixture();
    $result = $service->run();
    expect($queries)->toHaveCount(3);
    foreach ($queries as $query) {
        expect($query)->toContain('limit 500');
    }
    $archive = new ZipArchive;
    expect($archive->open($result['absolute_path']))->toBeTrue();
    try {
        $expected = DB::table('backup_stream_rows')->orderBy('reference')->get()->map(fn ($row): array => (array) $row)->all();
        expect($archive->getFromName('database/backup_stream_rows.json'))->toBe(json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            ->and($archive->getFromName('database/backup_stream_empty.json'))->toBe('[]');
        $manifest = json_decode($archive->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($manifest['database'])->toBe([
            'table_count' => 2,
            'tables' => ['backup_stream_rows', 'backup_stream_empty'],
            'format' => 'json-per-table',
        ]);
    } finally {
        $archive->close();
    }
    expect(is_dir($service->temporaryDirectory))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('backups/full'))->toBe([$result['path']])
        ->and(DB::table('backup_stream_rows')->count())->toBe(1201);
});

it('removes temporary exports and the incomplete archive after invalid JSON data', function () {
    DB::table('backup_stream_rows')->insert(['reference' => 'invalid', 'value' => "\xB1", 'quantity' => 1]);
    $service = streamingBackupFixture();
    expect(fn () => $service->run())->toThrow(JsonException::class);
    expect(is_dir($service->temporaryDirectory))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('backups/full'))->toBe([])
        ->and(DB::table('backup_stream_rows')->value('value'))->toBe("\xB1");
});

it('removes exported files and never publishes an archive when file collection fails', function () {
    $service = streamingBackupFixture();
    $service->failAfterExport = true;
    expect(fn () => $service->run())->toThrow(RuntimeException::class, 'File collection failed');
    expect(is_dir($service->temporaryDirectory))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('backups/full'))->toBe([]);
});

it('preserves an existing archive instead of overwriting it', function () {
    $this->freezeTime();
    $path = 'backups/full/full-backup-'.now()->format('Ymd_His').'.zip';
    Storage::disk('local')->put($path, 'existing archive');
    expect(fn () => streamingBackupFixture()->run())->toThrow(RuntimeException::class, 'already exists');
    expect(Storage::disk('local')->get($path))->toBe('existing archive')
        ->and(Storage::disk('local')->allFiles('backups/full'))->toBe([$path]);
});

it('cleans up and does not publish the archive when ZIP finalization fails', function () {
    $service = streamingBackupFixture();
    $service->removeExportBeforeClose = true;
    set_error_handler(function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        expect(fn () => $service->run())->toThrow(ErrorException::class);
    } finally {
        restore_error_handler();
    }
    expect(is_dir($service->temporaryDirectory))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('backups/full'))->toBe([]);
});
