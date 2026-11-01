<?php

namespace App\Jobs\Automation;

use App\DTO\Automation\AutomationAlertData;
use App\Enums\AutomationRunStatusEnum;
use App\Enums\AutomationTriggerTypeEnum;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationAlertService;
use App\Services\Automation\AutomationPipelineRunner;
use App\Services\Automation\AutomationRunFreshness;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CheckAutomationHealthJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function handle(
        AutomationAlertService $alertService,
        ?AutomationPipelineRunner $runner = null,
    ): void {
        if ($runner) {
            $runner->run(
                automationKey: 'automation_health_check',
                pipeline: 'automation_health_check',
                triggerType: AutomationTriggerTypeEnum::SYSTEM,
                callback: function () use ($alertService): array {
                    $result = $this->runChecks($alertService);

                    return [
                        'status' => 'success',
                        'processed_count' => $result['checked_pipelines'],
                        'success_count' => $result['healthy_checks'],
                        'warning_count' => $result['alerts_emitted'],
                        'error_count' => 0,
                        'result' => $result,
                    ];
                },
                jobClass: self::class,
                attempt: 1,
            );

            return;
        }

        $this->runChecks($alertService);
    }

    /**
     * @return array{
     *     summary: string,
     *     checked_pipelines: int,
     *     healthy_checks: int,
     *     alerts_emitted: int
     * }
     */
    protected function runChecks(AutomationAlertService $alertService): array
    {
        app(AutomationPipelineRunner::class)->reconcileExpiredFullBackups();

        $pipelines = config('automation.pipelines', []);
        $staleRunningMinutes = (int) config('automation.health.running_stale_after_minutes', 30);
        $checkedPipelines = 0;
        $alertsEmitted = 0;

        foreach ($pipelines as $pipelineKey => $pipelineConfig) {
            if (! ($pipelineConfig['enabled'] ?? false)) {
                continue;
            }

            $checkedPipelines++;
            $alertsEmitted += $this->checkMissingOrStaleRun($pipelineKey, $pipelineConfig, $alertService);
            $alertsEmitted += $this->checkRunningTooLong($pipelineKey, $staleRunningMinutes, $alertService);
            $alertsEmitted += $this->checkLatestFailure($pipelineKey, $pipelineConfig, $alertService);
        }

        return [
            'summary' => 'Automation health check completed.',
            'checked_pipelines' => $checkedPipelines,
            'healthy_checks' => max($checkedPipelines - $alertsEmitted, 0),
            'alerts_emitted' => $alertsEmitted,
        ];
    }

    protected function checkMissingOrStaleRun(
        string $pipelineKey,
        array $pipelineConfig,
        AutomationAlertService $alertService,
    ): int {
        $maxExpectedIntervalMinutes = (int) ($pipelineConfig['max_expected_interval_minutes'] ?? 0);

        if ($maxExpectedIntervalMinutes <= 0) {
            return 0;
        }

        $latestRun = AutomationRun::query()
            ->where('automation_key', $pipelineKey)
            ->latest('created_at')
            ->first();

        if (! $latestRun) {
            if ($this->shouldDeferMissingRunAlert($pipelineKey, $maxExpectedIntervalMinutes)) {
                return 0;
            }

            $alertService->send(new AutomationAlertData(
                type: 'missing_run',
                pipeline: $pipelineKey,
                title: 'Automation pipeline has never run',
                message: 'No execution has been recorded yet for this pipeline.',
                context: [
                    'environment' => app()->environment(),
                    'timestamp' => now()->toDateTimeString(),
                    'status' => 'missing_run',
                    'max_expected_interval_minutes' => $maxExpectedIntervalMinutes,
                ],
            ));

            return 1;
        }

        Cache::forget($this->missingRunCacheKey($pipelineKey));

        $freshness = $this->freshness();
        $effectiveThresholdMinutes = $freshness->effectiveStaleThresholdMinutes($pipelineConfig);
        $lastActivityAt = $freshness->lastActivityAt($latestRun);

        if ($freshness->isStale($latestRun, $pipelineConfig)) {
            $alertService->send(new AutomationAlertData(
                type: 'stale_run',
                pipeline: $pipelineKey,
                title: 'Automation pipeline is stale',
                message: 'The latest execution is older than the expected interval.',
                context: [
                    'environment' => app()->environment(),
                    'timestamp' => now()->toDateTimeString(),
                    'status' => 'stale_run',
                    'last_run_at' => $lastActivityAt?->toDateTimeString(),
                    'last_run_created_at' => $latestRun->created_at?->toDateTimeString(),
                    'last_run_finished_at' => $latestRun->finished_at?->toDateTimeString(),
                    'max_expected_interval_minutes' => $maxExpectedIntervalMinutes,
                    'effective_threshold_minutes' => $effectiveThresholdMinutes,
                    'stale_grace_minutes' => max($effectiveThresholdMinutes - $maxExpectedIntervalMinutes, 0),
                    'last_status' => $latestRun->status?->value,
                    'run_uuid' => $latestRun->uuid,
                ],
            ));

            return 1;
        }

        return 0;
    }

    protected function checkRunningTooLong(
        string $pipelineKey,
        int $staleRunningMinutes,
        AutomationAlertService $alertService,
    ): int {
        $stuckRun = AutomationRun::query()
            ->where('automation_key', $pipelineKey)
            ->where('status', AutomationRunStatusEnum::RUNNING)
            ->whereNotNull('started_at')
            ->where('started_at', '<=', now()->subMinutes($staleRunningMinutes))
            ->latest('started_at')
            ->first();

        if (! $stuckRun) {
            return 0;
        }

        $alertService->send(new AutomationAlertData(
            type: 'running_too_long',
            pipeline: $pipelineKey,
            title: 'Automation pipeline appears stuck',
            message: 'A run is still marked as running beyond the configured threshold.',
            context: [
                'environment' => app()->environment(),
                'timestamp' => now()->toDateTimeString(),
                'status' => 'running_too_long',
                'started_at' => $stuckRun->started_at?->toDateTimeString(),
                'threshold_minutes' => $staleRunningMinutes,
                'run_uuid' => $stuckRun->uuid,
            ],
        ));

        return 1;
    }

    protected function checkLatestFailure(
        string $pipelineKey,
        array $pipelineConfig,
        AutomationAlertService $alertService,
    ): int {
        if (! ($pipelineConfig['alert_on_failure'] ?? false)) {
            return 0;
        }

        $latestRun = AutomationRun::query()
            ->where('automation_key', $pipelineKey)
            ->latest('created_at')
            ->first();

        if (! $latestRun) {
            return 0;
        }

        if ($latestRun->status !== AutomationRunStatusEnum::FAILED) {
            return 0;
        }

        $alertService->send(new AutomationAlertData(
            type: 'failed_run',
            pipeline: $pipelineKey,
            title: 'Automation pipeline failed',
            message: $latestRun->error_message ?: __('automation.errors.latest_run_failed_without_message'),
            context: [
                'environment' => is_array($latestRun->context) ? ($latestRun->context['environment'] ?? app()->environment()) : app()->environment(),
                'status' => $latestRun->status?->value,
                'run_uuid' => $latestRun->uuid,
                'exception_class' => $latestRun->exception_class,
                'finished_at' => $latestRun->finished_at?->toDateTimeString(),
            ],
        ));

        return 1;
    }

    protected function shouldDeferMissingRunAlert(
        string $pipelineKey,
        int $maxExpectedIntervalMinutes,
    ): bool {
        if (
            app()->environment('local')
            && (bool) config('automation.health.skip_missing_run_alert_in_local', true)
        ) {
            return true;
        }

        $graceMinutes = $this->missingRunGraceMinutes($maxExpectedIntervalMinutes);

        if ($graceMinutes <= 0) {
            return false;
        }

        $cacheKey = $this->missingRunCacheKey($pipelineKey);
        $firstObservedAt = Cache::get($cacheKey);

        if (! is_string($firstObservedAt)) {
            Cache::forever($cacheKey, now()->toIso8601String());

            return true;
        }

        return now()->diffInMinutes(Carbon::parse($firstObservedAt)) < $graceMinutes;
    }

    protected function missingRunGraceMinutes(int $maxExpectedIntervalMinutes): int
    {
        $multiplier = (int) config('automation.health.missing_run_grace_multiplier', 1);

        if ($maxExpectedIntervalMinutes <= 0 || $multiplier <= 0) {
            return 0;
        }

        return $maxExpectedIntervalMinutes * $multiplier;
    }

    protected function missingRunCacheKey(string $pipelineKey): string
    {
        return "automation:health:missing-run-first-observed:{$pipelineKey}";
    }

    protected function freshness(): AutomationRunFreshness
    {
        return app(AutomationRunFreshness::class);
    }
}
