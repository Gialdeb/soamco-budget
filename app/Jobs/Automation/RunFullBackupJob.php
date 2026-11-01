<?php

namespace App\Jobs\Automation;

use App\Enums\AutomationTriggerTypeEnum;
use App\Services\Automation\AutomationPipelineRunner;
use App\Services\Automation\Backups\FullBackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RunFullBackupJob implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 600;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public ?string $executionId = null;

    public function __construct(
        public AutomationTriggerTypeEnum $triggerType = AutomationTriggerTypeEnum::SCHEDULED,
    ) {
        $this->executionId = (string) Str::uuid();
    }

    public function handle(
        AutomationPipelineRunner $runner,
        FullBackupService $fullBackupService,
    ): void {
        $this->executionId ??= (string) Str::uuid();
        $backupDisk = (string) config('automation.backups.disk', 'local');
        $disk = Storage::disk($backupDisk);

        $runner->run(
            automationKey: 'full_backup',
            pipeline: 'full_backup',
            triggerType: $this->triggerType,
            callback: function () use ($fullBackupService): array {
                $result = $fullBackupService->run();

                return [
                    'status' => 'success',
                    'processed_count' => (int) ($result['table_count'] ?? 0),
                    'success_count' => 1,
                    'warning_count' => 0,
                    'error_count' => 0,
                    'result' => $result,
                ];
            },
            jobClass: self::class,
            context: [
                'environment' => app()->environment(),
                'backup_disk' => $backupDisk,
                'backup_root' => $disk->path(''),
            ],
            attempt: 1,
            fullBackupExecutionId: $this->executionId,
        );
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->executionId !== null) {
            app(AutomationPipelineRunner::class)->failFullBackup($this->executionId, $exception);
        }
    }
}
