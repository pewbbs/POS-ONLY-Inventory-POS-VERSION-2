<?php

namespace App\Services;

use App\Jobs\CheckLowStock;
use App\Jobs\MonitoringQueueProbe;
use App\Models\MonitoringEvent;
use App\Models\SystemHeartbeat;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MonitoringService
{
    /** The recorder must never replace the application's original failure. */
    public function recordException(Throwable $error, ?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }
        try {
            MonitoringEvent::create([
                'tenant_id' => $tenantId, 'kind' => 'application_error', 'status' => 'failed',
                'summary' => 'An application request failed.', 'error_class' => $this->safeClass($error::class),
                'error_code' => $this->safeErrorCode($error),
                'created_at' => $this->storageNow(), 'updated_at' => $this->storageNow(),
            ]);
        } catch (Throwable) {
            // Recording must remain safe during a database outage.
        }
    }

    public function recordJobFailure(string $jobClass, int $tenantId): void
    {
        $summary = match ($jobClass) {
            CheckLowStock::class => 'A stock replenishment background job failed.',
            MonitoringQueueProbe::class => 'A diagnostic queue probe failed.',
            default => null,
        };
        if ($summary === null) {
            return;
        }
        try {
            MonitoringEvent::create([
                'tenant_id' => $tenantId, 'kind' => 'queue_failure', 'status' => 'failed',
                'summary' => $summary, 'error_class' => $this->safeClass($jobClass),
                'created_at' => $this->storageNow(), 'updated_at' => $this->storageNow(),
            ]);
        } catch (Throwable) {
            // Queue failure callbacks must not depend on monitoring availability.
        }
    }

    public function heartbeat(string $component, ?string $connection = null, ?string $queue = null): void
    {
        if (! in_array($component, ['worker', 'scheduler'], true) || ($component === 'worker' && ($connection === null || $queue === null))) {
            return;
        }
        $queues = $component === 'worker' ? array_values(array_unique(array_filter(array_map('trim', explode(',', $queue)), fn (string $name): bool => $name !== ''))) : [null];
        foreach ($queues as $listenedQueue) {
            try {
                $scope = $this->heartbeatScope($component, $component === 'worker' ? $connection : null, $listenedQueue);
                $timestamp = $this->storageNow();
                $heartbeat = SystemHeartbeat::firstOrCreate(['scope_key' => $scope], [
                    'component' => $component, 'connection' => $component === 'worker' ? $this->safeLabel($connection) : null,
                    'queue' => $listenedQueue === null ? null : $this->safeLabel($listenedQueue), 'last_seen_at' => $timestamp,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
                if (! $heartbeat->wasRecentlyCreated) {
                    SystemHeartbeat::whereKey($heartbeat->id)
                        ->where('last_seen_at', '<=', $timestamp->subSeconds($this->setting('heartbeat_write_interval_seconds', 15)))
                        ->update(['last_seen_at' => $timestamp, 'updated_at' => $timestamp]);
                }
            } catch (Throwable) {
                // Infrastructure pulses must not interrupt workers or scheduling.
            }
        }
    }

    public function systemReport(): array
    {
        $queue = $this->queueConfiguration();
        $checks = [$this->databaseCheck(), $this->cacheCheck(), $this->storageCheck(), $this->logCheck(), $this->heartbeatCheck('worker', $queue), $this->heartbeatCheck('scheduler', $queue)];

        return ['status' => $this->overallStatus($checks), 'checks' => $checks, 'checked_at' => $this->storageNow()->toIso8601String()];
    }

    public function snapshot(User $user): array
    {
        $actor = $this->authorize($user);
        $report = $this->systemReport();
        $events = $this->tenantEvents($actor->tenant_id);
        $incidents = (clone $events)->whereIn('kind', ['application_error', 'queue_failure']);
        $probes = (clone $events)->where('kind', 'queue_probe');
        $counts = [
            'open_incidents' => (clone $incidents)->whereNull('acknowledged_at')->count(),
            'acknowledged_incidents' => (clone $incidents)->whereNotNull('acknowledged_at')->count(),
            'pending_probes' => (clone $probes)->where('status', 'pending')->count(),
            'failed_probes' => (clone $probes)->where('status', 'failed')->count(),
            'passed_probes' => (clone $probes)->where('status', 'passed')->count(),
        ];
        $limit = $this->setting('history_limit', 50, 50);
        $latestProbe = $this->latestProbe($actor->tenant_id);
        $stalePending = (clone $probes)->where('status', 'pending')->where('created_at', '<', $this->storageNow()->subSeconds($this->setting('pending_probe_stale_seconds', 300)))->exists();
        $probeNeedsReview = ($latestProbe && $latestProbe->status === 'failed') || $stalePending;
        if ($report['status'] === 'healthy' && ($counts['open_incidents'] > 0 || $probeNeedsReview)) {
            $report['status'] = 'warning';
        }

        return $report + [
            'queue' => $this->publicQueueConfiguration() + ['cooldown_remaining_seconds' => $this->cooldown($latestProbe)],
            'counts' => $counts,
            'incidents' => (clone $incidents)->latest('id')->limit($limit)->get()->map(fn (MonitoringEvent $event): array => $this->serializeEvent($event))->all(),
            'probes' => (clone $probes)->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest('id')->limit($limit)->get()->map(fn (MonitoringEvent $event): array => $this->serializeEvent($event))->all(),
            'integrations' => $this->integrationChecks(), 'history_limit' => $limit, 'retention_days' => $this->setting('retention_days', 30, 365),
        ];
    }

    public function probe(User $user): array
    {
        [$event, $reused] = DB::transaction(function () use ($user): array {
            Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $actor = $this->authorize($user, true);
            $pending = MonitoringEvent::where('tenant_id', $actor->tenant_id)->where('kind', 'queue_probe')->where('status', 'pending')->lockForUpdate()->first();
            if ($pending) {
                return [$pending, true];
            }
            if ($this->queueConfiguration()['mode'] === 'disabled') {
                throw ValidationException::withMessages(['probe' => 'Configure an enabled queue connection before running a diagnostic probe.']);
            }
            $cooldown = $this->cooldown($this->latestProbe($actor->tenant_id));
            if ($cooldown > 0) {
                throw new HttpResponseException(response()->json(['message' => 'Wait before starting another diagnostic queue probe.', 'retry_after' => $cooldown], 429, ['Retry-After' => (string) $cooldown, 'Cache-Control' => 'no-store, private']));
            }

            return [MonitoringEvent::create([
                'tenant_id' => $actor->tenant_id, 'kind' => 'queue_probe', 'status' => 'pending',
                'summary' => 'A diagnostic queue probe is waiting to run.', 'pending_scope' => 'tenant:'.$actor->tenant_id.':queue_probe',
                'created_at' => $this->storageNow(), 'updated_at' => $this->storageNow(),
            ]), false];
        }, 5);
        if (! $reused) {
            try {
                Bus::dispatch(new MonitoringQueueProbe($event->tenant_id, $event->id));
            } catch (Throwable) {
                $this->failProbe($event->tenant_id, $event->id);
            }
        }

        return ['probe' => $this->serializeEvent($event->refresh()), 'reused' => $reused, 'cooldown_remaining_seconds' => $this->cooldown($event)];
    }

    public function completeProbe(int $tenantId, int $eventId): void
    {
        MonitoringEvent::where('tenant_id', $tenantId)->whereKey($eventId)->where('kind', 'queue_probe')->where('status', 'pending')->update([
            'status' => 'passed', 'summary' => 'The diagnostic queue probe ran successfully.', 'completed_at' => $this->storageNow(), 'pending_scope' => null, 'updated_at' => $this->storageNow(),
        ]);
    }

    public function failProbe(int $tenantId, int $eventId): void
    {
        try {
            $updated = MonitoringEvent::where('tenant_id', $tenantId)->whereKey($eventId)->where('kind', 'queue_probe')->where('status', 'pending')->update([
                'status' => 'failed', 'summary' => 'The diagnostic queue probe could not run.', 'completed_at' => $this->storageNow(), 'pending_scope' => null, 'updated_at' => $this->storageNow(),
            ]);
            if ($updated > 0) {
                $this->recordJobFailure(MonitoringQueueProbe::class, $tenantId);
            }
        } catch (Throwable) {
            // A failed diagnostic must not interrupt the queue failure handler.
        }
    }

    public function acknowledge(User $user, int $eventId): array
    {
        return DB::transaction(function () use ($user, $eventId): array {
            Tenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $actor = $this->authorize($user, true);
            $event = MonitoringEvent::where('tenant_id', $actor->tenant_id)->whereIn('kind', ['application_error', 'queue_failure'])->lockForUpdate()->findOrFail($eventId);
            if ($event->acknowledged_at === null) {
                $event->update(['acknowledged_at' => $this->storageNow()]);
            }

            return ['incident' => $this->serializeEvent($event)];
        }, 5);
    }

    /** Returns expired handled events removed; outstanding diagnostics remain visible. */
    public function prune(): int
    {
        return MonitoringEvent::where('created_at', '<', $this->storageNow()->subDays($this->setting('retention_days', 30, 365)))
            ->where(fn (Builder $query): Builder => $query->where('kind', '!=', 'queue_probe')->orWhere('status', '!=', 'pending'))->delete();
    }

    public function overallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array('critical', $statuses, true)) {
            return 'critical';
        }

        return count(array_intersect($statuses, ['warning', 'unverified'])) > 0 ? 'warning' : 'healthy';
    }

    private function authorize(User $user, bool $lock = false): User
    {
        $query = User::where('tenant_id', $user->tenant_id)->whereKey($user->id);
        $actor = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($actor && $actor->is_active && $actor->role === 'admin', 403, 'Monitoring is available to active workspace administrators.');

        return $actor;
    }

    private function tenantEvents(int $tenantId): Builder
    {
        return MonitoringEvent::where('tenant_id', $tenantId)->where(function (Builder $query): void {
            $query->where('created_at', '>=', $this->storageNow()->subDays($this->setting('retention_days', 30, 365)))
                ->orWhere(fn (Builder $pending): Builder => $pending->where('kind', 'queue_probe')->where('status', 'pending'));
        });
    }

    private function latestProbe(int $tenantId): ?MonitoringEvent
    {
        return $this->tenantEvents($tenantId)->where('kind', 'queue_probe')->latest('id')->first();
    }

    private function cooldown(?MonitoringEvent $event): int
    {
        return $event ? max(0, $this->setting('probe_cooldown_seconds', 60) - $this->age($event->getRawOriginal('created_at'))) : 0;
    }

    private function serializeEvent(MonitoringEvent $event): array
    {
        $result = [
            'id' => $event->id, 'kind' => $event->kind, 'status' => $event->status, 'summary' => $event->summary,
            'error_class' => $event->error_class, 'error_code' => $event->error_code,
            'created_at' => $this->timestamp($event->getRawOriginal('created_at')),
            'completed_at' => $this->timestamp($event->getRawOriginal('completed_at')),
            'acknowledged_at' => $this->timestamp($event->getRawOriginal('acknowledged_at')),
        ];
        if ($event->kind === 'queue_probe') {
            $result['stale'] = $event->status === 'pending' && $this->age($event->getRawOriginal('created_at')) > $this->setting('pending_probe_stale_seconds', 300);
        }

        return $result;
    }

    private function queueConfiguration(): array
    {
        $connection = (string) config('queue.default', '');
        $driver = (string) config('queue.connections.'.$connection.'.driver', '');
        $queue = (string) config('queue.connections.'.$connection.'.queue', 'default');
        $mode = match ($driver) {
            '', 'null' => 'disabled', 'sync' => 'inline', default => 'background'
        };

        return compact('connection', 'driver', 'queue', 'mode');
    }

    private function publicQueueConfiguration(): array
    {
        $configuration = $this->queueConfiguration();

        return ['connection' => $this->safeLabel($configuration['connection']), 'driver' => $this->safeLabel($configuration['driver']), 'queue' => $this->safeLabel($configuration['queue']), 'mode' => $configuration['mode']];
    }

    private function databaseCheck(): array
    {
        try {
            DB::select('SELECT 1');

            return $this->check('database', 'Database', 'healthy', 'The application database answered a live connection check.');
        } catch (Throwable) {
            return $this->check('database', 'Database', 'critical', 'The application database did not answer the connection check.', 'Ask the system operator to check the database connection.');
        }
    }

    private function cacheCheck(): array
    {
        $key = 'monitoring:probe:'.Str::uuid();
        $value = (string) Str::uuid();
        try {
            if (! Cache::put($key, $value, 30) || Cache::get($key) !== $value || ! Cache::forget($key)) {
                return $this->check('cache', 'Cache', 'critical', 'The cache could not complete a temporary write, read, and delete check.', 'Ask the system operator to check the configured cache store.');
            }

            return $this->check('cache', 'Cache', 'healthy', 'The cache completed a temporary write, read, and delete check.');
        } catch (Throwable) {
            return $this->check('cache', 'Cache', 'critical', 'The cache could not complete its connection check.', 'Ask the system operator to check the configured cache store.');
        } finally {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // Temporary keys also expire after 30 seconds.
            }
        }
    }

    private function storageCheck(): array
    {
        $path = 'monitoring/.probe-'.Str::uuid();
        $value = 'POSONLY diagnostic';
        $disk = null;
        try {
            $diskName = (string) config('monitoring.storage_disk', 'local');
            if (config('filesystems.disks.'.$diskName.'.driver') !== 'local') {
                return $this->check('private_storage', 'Private storage', 'unverified', 'A private local storage disk is not configured for this check.', 'Ask the system operator to check private local storage configuration.');
            }
            $disk = Storage::disk($diskName);
            if (! $disk->put($path, $value) || $disk->get($path) !== $value || ! $disk->delete($path)) {
                return $this->check('private_storage', 'Private storage', 'critical', 'Private storage could not complete a temporary write, read, and delete check.', 'Ask the system operator to check private storage access.');
            }

            return $this->check('private_storage', 'Private storage', 'healthy', 'Private storage completed a temporary write, read, and delete check.');
        } catch (Throwable) {
            return $this->check('private_storage', 'Private storage', 'critical', 'Private storage could not complete its access check.', 'Ask the system operator to check private storage access.');
        } finally {
            try {
                $disk?->delete($path);
            } catch (Throwable) {
                // The temporary diagnostic contains no workspace data.
            }
        }
    }

    private function logCheck(): array
    {
        try {
            if (File::isDirectory(storage_path('logs')) && File::isWritable(storage_path('logs'))) {
                return $this->check('log_storage', 'Application log directory', 'healthy', 'The application log directory is writable.');
            }
        } catch (Throwable) {
            // Only an access result is displayed, never a filesystem error.
        }

        return $this->check('log_storage', 'Application log directory', 'critical', 'The application log directory is unavailable or not writable.', 'Ask the system operator to check application log directory permissions.');
    }

    private function heartbeatCheck(string $component, array $queue): array
    {
        $label = $component === 'worker' ? 'Queue worker' : 'Scheduler';
        if ($component === 'worker' && $queue['mode'] === 'inline') {
            return $this->check('worker', $label, 'unverified', 'Jobs run inside the application request. A background worker has not been verified.', 'Use a background queue and run its worker when background processing is needed.');
        }
        if ($component === 'worker' && $queue['mode'] === 'disabled') {
            return $this->check('worker', $label, 'warning', 'The configured queue is disabled.', 'Ask the system operator to configure an enabled queue connection.');
        }
        if ($component === 'worker' && ! in_array($queue['driver'], ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            return $this->check('worker', $label, 'unverified', 'The configured queue driver does not provide a verified dedicated worker heartbeat.', 'Use a supported background queue when dedicated worker verification is needed.');
        }
        $threshold = $this->setting($component === 'worker' ? 'worker_stale_seconds' : 'scheduler_stale_seconds', $component === 'worker' ? 90 : 180);
        try {
            $scope = $this->heartbeatScope($component, $component === 'worker' ? $queue['connection'] : null, $component === 'worker' ? $queue['queue'] : null);
            $heartbeat = SystemHeartbeat::where('scope_key', $scope)->first();
            if (! $heartbeat) {
                return $this->check($component, $label, 'warning', 'No '.$component.' heartbeat has been recorded.', 'Ask the system operator to run the '.($component === 'worker' ? 'worker for the configured queue.' : 'scheduled tasks.'), null, null, $threshold);
            }
            $raw = $heartbeat->getRawOriginal('last_seen_at');
            $seen = CarbonImmutable::parse($raw, config('app.timezone', 'UTC'));
            $age = $this->age($raw);
            if ($seen->greaterThan($this->storageNow()->addSeconds(5))) {
                return $this->check($component, $label, 'warning', 'The '.$component.' heartbeat is ahead of the application clock.', 'Ask the system operator to check system clock synchronization.', $this->timestamp($raw), null, $threshold);
            }
            if ($age > $threshold) {
                return $this->check($component, $label, 'warning', 'The '.$component.' heartbeat is stale.', 'Ask the system operator to check the '.($component === 'worker' ? 'configured queue worker.' : 'scheduled task runner.'), $this->timestamp($raw), $age, $threshold);
            }

            return $this->check($component, $label, 'healthy', 'A recent '.$component.' heartbeat was recorded.', null, $this->timestamp($raw), $age, $threshold);
        } catch (Throwable) {
            return $this->check($component, $label, 'warning', 'The '.$component.' heartbeat could not be checked.', 'Ask the system operator to check monitoring storage.', null, null, $threshold);
        }
    }

    private function integrationChecks(): array
    {
        $local = app()->environment(['local', 'testing']);

        return array_map(function (string $key) use ($local): array {
            if ($key === 'billing' && config('billing.payment_provider') === 'paymongo') {
                $configuration = app(PayMongoClient::class)->configuration();

                return ['key' => $key, 'label' => 'Subscription billing', 'status' => $configuration['checkout_enabled'] ? $configuration['mode'] : 'disabled',
                    'summary' => $configuration['checkout_enabled']
                        ? 'PayMongo '.$configuration['mode'].' checkout is configured. Provider availability is checked when a payment is requested or rechecked; health checks make no provider requests.'
                        : $configuration['reason']];
            }
            $enabled = $local && (bool) config($key.'.simulations_enabled', false);

            return ['key' => $key, 'label' => $key === 'commerce' ? 'Commerce channels' : 'Subscription billing', 'status' => $enabled ? 'simulation' : 'disabled', 'summary' => $enabled ? 'Local simulation is enabled. No external provider is connected.' : 'Local simulation is disabled. No external provider is connected.'];
        }, ['commerce', 'billing']);
    }

    private function check(string $key, string $label, string $status, string $summary, ?string $nextStep = null, ?string $lastSeen = null, ?int $age = null, ?int $staleAfter = null): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'summary' => $summary, 'next_step' => $nextStep, 'last_seen_at' => $lastSeen, 'age_seconds' => $age, 'stale_after_seconds' => $staleAfter];
    }

    private function heartbeatScope(string $component, ?string $connection, ?string $queue): string
    {
        return hash('sha256', json_encode([$component, $connection, $queue], JSON_THROW_ON_ERROR));
    }

    private function safeClass(string $class): string
    {
        $basename = class_basename($class);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,119}$/D', $basename) === 1 ? $basename : 'Exception';
    }

    private function safeErrorCode(Throwable $error): ?int
    {
        $code = $error->getCode();

        return is_int($code) && $code >= -2147483648 && $code <= 2147483647 ? $code : null;
    }

    private function safeLabel(string $label): string
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $label) === 1 ? $label : 'configured';
    }

    private function setting(string $name, int $default, int $maximum = 86400): int
    {
        return max(1, min($maximum, (int) config('monitoring.'.$name, $default)));
    }

    private function storageNow(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone', 'UTC'));
    }

    private function age(string $timestamp): int
    {
        return max(0, (int) CarbonImmutable::parse($timestamp, config('app.timezone', 'UTC'))->diffInSeconds($this->storageNow()));
    }

    private function timestamp(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp, config('app.timezone', 'UTC'))->toIso8601String();
    }
}
