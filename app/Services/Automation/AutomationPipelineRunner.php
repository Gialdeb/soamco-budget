<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunStatusEnum;
use App\Enums\AutomationTriggerTypeEnum;
use App\Jobs\Automation\RunFullBackupJob;
use App\Models\AutomationRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AutomationPipelineRunner
{
    public function __construct(
        protected AutomationRunRecorder $recorder,
        protected AutomationAlertService $alertService,
    ) {}

    /**
     * @param  callable(): array{
     *     status?: 'success'|'warning',
     *     processed_count?: int,
     *     success_count?: int,
     *     warning_count?: int,
     *     error_count?: int,
     *     result?: array,
     *     message?: string|null
     * }  $callback
     */
    public function run(
        string $automationKey,
        string $pipeline,
        AutomationTriggerTypeEnum $triggerType,
        callable $callback,
        ?string $jobClass = null,
        array $context = [],
        int $attempt = 1,
        ?string $batchId = null,
        ?string $fullBackupExecutionId = null,
    ): AutomationRun {
        if ($fullBackupExecutionId === null) {
            return $this->executePipeline($automationKey, $pipeline, $triggerType, $callback, $jobClass, $context, $attempt, $batchId);
        }
        if ($automationKey !== 'full_backup' || $pipeline !== 'full_backup' || $jobClass !== RunFullBackupJob::class) {
            throw new RuntimeException('Full backup execution tracking is only available for full backups.');
        }

        $lock = $this->acquireFullBackupLock($fullBackupExecutionId, true);
        if ($lock === null) {
            throw new RuntimeException('This full backup execution is still active.');
        }
        try {
            $existingRun = $this->fullBackupRuns($fullBackupExecutionId)->first();
            if ($existingRun !== null) {
                return $existingRun;
            }

            $context['full_backup_execution_id'] = $fullBackupExecutionId;
            $context['full_backup_guard_version'] = 1;

            return $this->executePipeline($automationKey, $pipeline, $triggerType, $callback, $jobClass, $context, $attempt, $batchId);
        } finally {
            fclose($lock);
        }
    }

    protected function executePipeline(
        string $automationKey,
        string $pipeline,
        AutomationTriggerTypeEnum $triggerType,
        callable $callback,
        ?string $jobClass,
        array $context,
        int $attempt,
        ?string $batchId,
    ): AutomationRun {
        $context = array_merge([
            'environment' => app()->environment(),
        ], $context);

        $run = $this->recorder->start(
            automationKey: $automationKey,
            pipeline: $pipeline,
            triggerType: $triggerType,
            jobClass: $jobClass,
            context: $context,
            attempt: $attempt,
            batchId: $batchId,
        );

        $run = $this->recorder->markRunning($run);

        try {
            $payload = $callback();

            $status = $payload['status'] ?? 'success';
            $result = $payload['result'] ?? [];
            $processedCount = $payload['processed_count'] ?? 0;
            $successCount = $payload['success_count'] ?? 0;
            $warningCount = $payload['warning_count'] ?? 0;
            $errorCount = $payload['error_count'] ?? 0;
            $message = $payload['message'] ?? null;

            if ($status === 'warning') {
                return $this->recorder->markWarning(
                    $run,
                    result: $result,
                    processedCount: $processedCount,
                    successCount: $successCount,
                    warningCount: $warningCount,
                    errorCount: $errorCount,
                    message: $message,
                );
            }

            $completedRun = $this->recorder->markSuccess(
                $run,
                result: $result,
                processedCount: $processedCount,
                successCount: $successCount,
                warningCount: $warningCount,
                errorCount: $errorCount,
            );

            $this->alertService->sendBackupAlertForRun($completedRun);

            return $completedRun;
        } catch (Throwable $exception) {
            $failedRun = $this->recorder->markFailed($run, $exception);

            $this->alertService->sendFailureAlertForRun($failedRun);
            $this->alertService->sendBackupAlertForRun($failedRun);

            return $failedRun;
        }
    }

    public function failFullBackup(string $executionId, ?Throwable $exception, ?CarbonInterface $expiredBefore = null): ?AutomationRun
    {
        $lock = $this->acquireFullBackupLock($executionId);
        if ($lock === null) {
            return null;
        }

        try {
            $run = DB::transaction(function () use ($executionId, $exception, $expiredBefore): ?AutomationRun {
                $query = ($expiredBefore === null ? $this->fullBackupRuns() : $this->expiredFullBackupRuns($expiredBefore))
                    ->where('context->full_backup_execution_id', $executionId)
                    ->whereIn('status', [AutomationRunStatusEnum::PENDING, AutomationRunStatusEnum::RUNNING])
                    ->whereNull('finished_at');
                $run = $query->lockForUpdate()->first();
                if ($run === null) {
                    return null;
                }

                return $this->recorder->markFailed($run, $exception ?? new RuntimeException('The full backup worker stopped before completing the execution.'));
            });
            if ($run !== null) {
                $this->alertService->sendFailureAlertForRun($run);
                $this->alertService->sendBackupAlertForRun($run);
            }

            return $run;
        } finally {
            fclose($lock);
        }
    }

    public function reconcileExpiredFullBackups(): int
    {
        $expiredBefore = now()->subSeconds(max(RunFullBackupJob::TIMEOUT_SECONDS + 60, (int) config('automation.health.running_stale_after_minutes', 30) * 60));
        $reconciled = 0;
        $this->expiredFullBackupRuns($expiredBefore)
            ->chunkById(100, function ($runs) use ($expiredBefore, &$reconciled): void {
                foreach ($runs as $run) {
                    $executionId = $run->context['full_backup_execution_id'] ?? null;
                    if (is_string($executionId) && Str::isUuid($executionId)
                        && $this->failFullBackup($executionId, null, $expiredBefore) !== null) {
                        $reconciled++;
                    }
                }
            });

        return $reconciled;
    }

    protected function expiredFullBackupRuns(CarbonInterface $expiredBefore): Builder
    {
        return $this->fullBackupRuns()->whereNull('finished_at')
            ->where(function (Builder $query) use ($expiredBefore): void {
                $query->where(fn (Builder $running) => $running->where('status', AutomationRunStatusEnum::RUNNING)->where('started_at', '<=', $expiredBefore))
                    ->orWhere(fn (Builder $pending) => $pending->where('status', AutomationRunStatusEnum::PENDING)->where('created_at', '<=', $expiredBefore));
            });
    }

    protected function fullBackupRuns(?string $executionId = null): Builder
    {
        return AutomationRun::query()
            ->where('automation_key', 'full_backup')->where('pipeline', 'full_backup')
            ->where('job_class', RunFullBackupJob::class)
            ->where('context->full_backup_guard_version', 1)
            ->when($executionId !== null, fn (Builder $query) => $query->where('context->full_backup_execution_id', $executionId));
    }

    /**
     * Lock files must remain on the shared backup volume: deleting one can create
     * a second inode while an active worker still holds the original lock.
     * Missing locks cannot establish that an existing execution has stopped.
     *
     * @return resource|null
     */
    protected function acquireFullBackupLock(string $executionId, bool $create = false): mixed
    {
        if (! Str::isUuid($executionId)) {
            throw new RuntimeException('Invalid full backup execution identifier.');
        }
        $diskName = (string) config('automation.backups.disk', 'local');
        if (config('filesystems.disks.'.$diskName.'.driver') !== 'local') {
            throw new RuntimeException('Full backup execution locks require a shared local filesystem.');
        }
        $disk = Storage::disk($diskName);
        $directory = trim((string) config('automation.backups.full.directory', 'backups/full'), '/');
        if ($create && ! $disk->makeDirectory($directory)) {
            throw new RuntimeException('Unable to create the full backup lock directory.');
        }
        $path = $disk->path($directory.'/run-'.$executionId.'.lock');
        if (! $create && ! is_file($path)) {
            return null;
        }
        $lock = fopen($path, $create ? 'c' : 'r+');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the full backup execution lock.');
        }
        $wouldBlock = 0;
        if (! flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($lock);
            if ($wouldBlock) {
                return null;
            }
            throw new RuntimeException('Unable to acquire the full backup execution lock.');
        }

        return $lock;
    }
}
