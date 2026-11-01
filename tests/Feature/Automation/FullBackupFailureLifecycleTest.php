<?php

use App\Enums\AutomationRunStatusEnum;
use App\Enums\AutomationTriggerTypeEnum;
use App\Jobs\Automation\CheckAutomationHealthJob;
use App\Jobs\Automation\RunFullBackupJob;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationAlertService;
use App\Services\Automation\AutomationPipelineRunner;
use App\Services\Automation\Backups\FullBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config()->set('automation.backups.disk', 'local');
    config()->set('automation.health.running_stale_after_minutes', 30);
    $this->alerts = Mockery::mock(AutomationAlertService::class);
    $this->alerts->allows('sendFailureAlertForRun');
    $this->alerts->allows('sendBackupAlertForRun');
    $this->alerts->allows('send');
    app()->instance(AutomationAlertService::class, $this->alerts);
});

function interruptedFullBackup(?string $executionId = null, array $attributes = []): AutomationRun
{
    $executionId ??= (string) Str::uuid();
    touch(fullBackupLockPath($executionId));

    return AutomationRun::query()->create(array_replace([
        'automation_key' => 'full_backup',
        'pipeline' => 'full_backup',
        'job_class' => RunFullBackupJob::class,
        'status' => AutomationRunStatusEnum::RUNNING,
        'trigger_type' => AutomationTriggerTypeEnum::SCHEDULED,
        'started_at' => now()->subHour(),
        'context' => [
            'full_backup_execution_id' => $executionId,
            'full_backup_guard_version' => 1,
        ],
    ], $attributes));
}

function fullBackupLockPath(string $executionId): string
{
    Storage::disk('local')->makeDirectory('backups/full');

    return Storage::disk('local')->path('backups/full/run-'.$executionId.'.lock');
}

it('correlates failed callbacks using the serialized execution identifier and never the latest run', function () {
    $job = new RunFullBackupJob;
    $restored = unserialize(serialize($job));
    $target = interruptedFullBackup($job->executionId);
    $other = interruptedFullBackup();

    expect($restored->executionId)->toBe($job->executionId);
    $restored->failed(new RuntimeException('Worker stopped'));
    $finishedAt = $target->fresh()->finished_at;
    $restored->failed(new RuntimeException('Repeated failure callback'));

    expect($target->fresh()->status)->toBe(AutomationRunStatusEnum::FAILED)
        ->and($target->fresh()->error_message)->toBe('Worker stopped')
        ->and($target->fresh()->finished_at->eq($finishedAt))->toBeTrue()
        ->and($other->fresh()->status)->toBe(AutomationRunStatusEnum::RUNNING);
    $this->alerts->shouldHaveReceived('sendFailureAlertForRun')->once();
    $this->alerts->shouldHaveReceived('sendBackupAlertForRun')->once();
});

it('reconciles only expired tracked full backups and preserves recent legacy and other pipeline runs', function () {
    $expired = interruptedFullBackup();
    $recent = interruptedFullBackup(attributes: ['started_at' => now()->subMinutes(5)]);
    $legacy = interruptedFullBackup(attributes: ['context' => []]);
    $other = interruptedFullBackup(attributes: ['automation_key' => 'user_backup', 'pipeline' => 'user_backup']);
    $completed = interruptedFullBackup(attributes: ['status' => AutomationRunStatusEnum::SUCCESS, 'finished_at' => now()]);

    $runner = app(AutomationPipelineRunner::class);
    expect($runner->reconcileExpiredFullBackups())->toBe(1)
        ->and($runner->reconcileExpiredFullBackups())->toBe(0)
        ->and($expired->fresh()->status)->toBe(AutomationRunStatusEnum::FAILED)
        ->and($expired->fresh()->finished_at)->not->toBeNull()
        ->and($recent->fresh()->status)->toBe(AutomationRunStatusEnum::RUNNING)
        ->and($legacy->fresh()->status)->toBe(AutomationRunStatusEnum::RUNNING)
        ->and($other->fresh()->status)->toBe(AutomationRunStatusEnum::RUNNING)
        ->and($completed->fresh()->status)->toBe(AutomationRunStatusEnum::SUCCESS);
});

it('keeps an active backup running beyond the expiration threshold even when failed is invoked', function () {
    $job = new RunFullBackupJob;
    $service = Mockery::mock(FullBackupService::class);
    $service->shouldReceive('run')->once()->andReturnUsing(function () use ($job): array {
        $this->travel(40)->minutes();
        $runner = app(AutomationPipelineRunner::class);
        expect($runner->reconcileExpiredFullBackups())->toBe(0);
        unserialize(serialize($job))->failed(new RuntimeException('Duplicate delivery failed'));
        expect(AutomationRun::query()->sole()->status)->toBe(AutomationRunStatusEnum::RUNNING);

        return ['table_count' => 1];
    });

    $job->handle(app(AutomationPipelineRunner::class), $service);
    expect(AutomationRun::query()->sole()->status)->toBe(AutomationRunStatusEnum::SUCCESS);
    $job->failed(new RuntimeException('Late callback'));
    expect(AutomationRun::query()->sole()->status)->toBe(AutomationRunStatusEnum::SUCCESS);
    $this->alerts->shouldNotHaveReceived('sendFailureAlertForRun');
});

it('does not execute a completed serialized backup job twice', function () {
    $job = new RunFullBackupJob;
    $service = Mockery::mock(FullBackupService::class);
    $service->shouldReceive('run')->once()->andReturn(['table_count' => 1]);
    $runner = app(AutomationPipelineRunner::class);
    $job->handle($runner, $service);
    unserialize(serialize($job))->handle($runner, $service);
    expect(AutomationRun::query()->count())->toBe(1)
        ->and(AutomationRun::query()->sole()->status)->toBe(AutomationRunStatusEnum::SUCCESS);
});

it('keeps the original handled error when the queue also calls failed', function () {
    $job = new RunFullBackupJob;
    $service = Mockery::mock(FullBackupService::class);
    $service->shouldReceive('run')->once()->andThrow(new RuntimeException('Original backup error'));
    $job->handle(app(AutomationPipelineRunner::class), $service);
    $job->failed(new RuntimeException('Queue failure'));
    expect(AutomationRun::query()->sole()->error_message)->toBe('Original backup error');
    $this->alerts->shouldHaveReceived('sendFailureAlertForRun')->once();
});

it('uses the existing health check to reconcile an interrupted full backup', function () {
    $run = interruptedFullBackup();
    config()->set('automation.pipelines', []);
    app(CheckAutomationHealthJob::class)->handle($this->alerts);
    expect($run->fresh()->status)->toBe(AutomationRunStatusEnum::FAILED);
});

it('detects worker death through the operating system lock without relying on a time lease', function () {
    $run = interruptedFullBackup();
    $path = fullBackupLockPath($run->context['full_backup_execution_id']);
    $process = proc_open([PHP_BINARY, '-r', '$lock = fopen($argv[1], "c"); flock($lock, LOCK_EX); fwrite(STDOUT, "locked\\n"); fgets(STDIN);', $path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    try {
        stream_set_timeout($pipes[1], 5);
        expect(fgets($pipes[1]))->toBe("locked\n");
        expect(app(AutomationPipelineRunner::class)->reconcileExpiredFullBackups())->toBe(0)
            ->and($run->fresh()->status)->toBe(AutomationRunStatusEnum::RUNNING);
        proc_terminate($process, 9);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        $process = null;
        expect(app(AutomationPipelineRunner::class)->reconcileExpiredFullBackups())->toBe(1)
            ->and($run->fresh()->status)->toBe(AutomationRunStatusEnum::FAILED);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }
});

it('does not create unrelated runs when a job fails before the pipeline starts', function () {
    (new RunFullBackupJob)->failed(new RuntimeException('Not started'));
    expect(AutomationRun::query()->count())->toBe(0);
});

it('does not interpret a missing lock file as evidence that a worker has stopped', function () {
    $run = interruptedFullBackup();
    $path = fullBackupLockPath($run->context['full_backup_execution_id']);
    unlink($path);
    expect(app(AutomationPipelineRunner::class)->reconcileExpiredFullBackups())->toBe(0)
        ->and($run->fresh()->status)->toBe(AutomationRunStatusEnum::RUNNING)
        ->and(is_file($path))->toBeFalse();
});

it('does not infer worker death when the execution lock cannot be inspected', function () {
    $run = interruptedFullBackup();
    config()->set('filesystems.disks.uninspectable.driver', 's3');
    config()->set('automation.backups.disk', 'uninspectable');
    expect(fn () => app(AutomationPipelineRunner::class)->reconcileExpiredFullBackups())
        ->toThrow(RuntimeException::class, 'shared local filesystem');
    expect($run->fresh()->status)->toBe(AutomationRunStatusEnum::RUNNING);
});

it('recovers a crash between creating the execution and marking it running', function () {
    $pending = interruptedFullBackup(attributes: [
        'status' => AutomationRunStatusEnum::PENDING,
        'started_at' => null,
    ]);
    $pending->forceFill(['created_at' => now()->subHour()])->save();
    $recent = interruptedFullBackup(attributes: [
        'status' => AutomationRunStatusEnum::PENDING,
        'started_at' => null,
    ]);
    expect(app(AutomationPipelineRunner::class)->reconcileExpiredFullBackups())->toBe(1)
        ->and($pending->fresh()->status)->toBe(AutomationRunStatusEnum::FAILED)
        ->and($recent->fresh()->status)->toBe(AutomationRunStatusEnum::PENDING);
});
