<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

class MonitorQueueHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:queue-health 
                            {--queue= : Check specific queue}
                            {--alert : Send alerts for unhealthy queues}
                            {--restart : Restart workers if unhealthy}
                            {--clear-failed : Clear failed jobs older than 7 days}
                            {--format=table : Output format (table, json, csv)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor queue health and performance';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Monitoring queue health...');
        
        // Get queue statistics
        $queues = $this->getQueueStatistics();
        $failedJobs = $this->getFailedJobs();
        $horizonStatus = $this->checkHorizonStatus();
        
        // Display queue statistics
        $this->displayQueueStatistics($queues, $failedJobs, $horizonStatus);
        
        // Check for issues
        $issues = $this->detectIssues($queues, $failedJobs, $horizonStatus);
        
        if (!empty($issues)) {
            $this->displayIssues($issues);
            
            // Send alerts if requested
            if ($this->option('alert')) {
                $this->sendAlerts($issues);
            }
            
            // Restart workers if requested and needed
            if ($this->option('restart') && $this->shouldRestartWorkers($issues)) {
                $this->restartWorkers();
            }
        } else {
            $this->info('✅ All queues are healthy!');
        }
        
        // Clear old failed jobs if requested
        if ($this->option('clear-failed')) {
            $this->clearOldFailedJobs();
        }
        
        return Command::SUCCESS;
    }

    /**
     * Get queue statistics
     */
    private function getQueueStatistics(): array
    {
        $queueName = $this->option('queue');
        $connection = config('queue.default');
        
        $stats = [];
        
        // Get queue names
        $queues = $queueName 
            ? [$queueName] 
            : ['high', 'default', 'low', 'notifications'];
        
        foreach ($queues as $queue) {
            $pendingJobs = $this->getQueueSize($connection, $queue);
            $processingJobs = $this->getProcessingJobs($queue);
            $processedToday = $this->getProcessedToday($queue);
            $avgProcessingTime = $this->getAverageProcessingTime($queue);
            $failureRate = $this->getFailureRate($queue);
            
            $stats[] = [
                'queue' => $queue,
                'pending' => $pendingJobs,
                'processing' => $processingJobs,
                'processed_today' => $processedToday,
                'avg_time' => $avgProcessingTime,
                'failure_rate' => $failureRate,
                'status' => $this->getQueueStatus($pendingJobs, $processingJobs, $failureRate),
            ];
        }
        
        return $stats;
    }

    /**
     * Get queue size
     */
    private function getQueueSize(string $connection, string $queue): int
    {
        if ($connection === 'redis') {
            try {
                $redis = app('redis.connection');
                return $redis->llen("queues:{$queue}");
            } catch (\Exception $e) {
                return 0;
            }
        } elseif ($connection === 'database') {
            return DB::table('jobs')
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->count();
        }
        
        return 0;
    }

    /**
     * Get processing jobs count
     */
    private function getProcessingJobs(string $queue): int
    {
        $connection = config('queue.default');
        
        if ($connection === 'database') {
            return DB::table('jobs')
                ->where('queue', $queue)
                ->whereNotNull('reserved_at')
                ->count();
        }
        
        // For Redis, check Horizon metrics if available
        try {
            if (class_exists(WorkloadRepository::class)) {
                $workload = app(WorkloadRepository::class);
                $data = $workload->get();
                return $data[$queue]['length'] ?? 0;
            }
        } catch (\Exception $e) {
            // Horizon not available
        }
        
        return 0;
    }

    /**
     * Get jobs processed today
     */
    private function getProcessedToday(string $queue): int
    {
        $cacheKey = "queue_processed_{$queue}_" . now()->format('Y-m-d');
        return Cache::get($cacheKey, 0);
    }

    /**
     * Get average processing time
     */
    private function getAverageProcessingTime(string $queue): string
    {
        // Try to get from Horizon metrics
        try {
            if (class_exists(MetricsRepository::class)) {
                $metrics = app(MetricsRepository::class);
                $jobMetrics = $metrics->jobsProcessedPerMinute();
                
                if (isset($jobMetrics[$queue])) {
                    $avgTime = array_sum($jobMetrics[$queue]) / count($jobMetrics[$queue]);
                    return round($avgTime, 2) . 's';
                }
            }
        } catch (\Exception $e) {
            // Horizon not available
        }
        
        // Fallback to cache-based calculation
        $cacheKey = "queue_avg_time_{$queue}";
        $avgTime = Cache::get($cacheKey, 0);
        
        return $avgTime > 0 ? round($avgTime, 2) . 's' : 'N/A';
    }

    /**
     * Get failure rate
     */
    private function getFailureRate(string $queue): float
    {
        $processedToday = $this->getProcessedToday($queue);
        $failedToday = DB::table('failed_jobs')
            ->where('queue', $queue)
            ->whereDate('failed_at', today())
            ->count();
        
        if ($processedToday === 0) {
            return 0;
        }
        
        return round(($failedToday / ($processedToday + $failedToday)) * 100, 2);
    }

    /**
     * Get queue status
     */
    private function getQueueStatus(int $pending, int $processing, float $failureRate): string
    {
        if ($failureRate > 10) {
            return '<fg=red>Critical</>';
        } elseif ($pending > 1000) {
            return '<fg=red>Backlogged</>';
        } elseif ($pending > 500) {
            return '<fg=yellow>High Load</>';
        } elseif ($failureRate > 5) {
            return '<fg=yellow>Warning</>';
        } elseif ($processing === 0 && $pending > 0) {
            return '<fg=yellow>Stalled</>';
        } else {
            return '<fg=green>Healthy</>';
        }
    }

    /**
     * Get failed jobs
     */
    private function getFailedJobs(): array
    {
        $failedJobs = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as count'), DB::raw('MAX(failed_at) as last_failure'))
            ->groupBy('queue')
            ->get();
        
        return $failedJobs->map(function ($job) {
            return [
                'queue' => $job->queue,
                'count' => $job->count,
                'last_failure' => $job->last_failure,
            ];
        })->toArray();
    }

    /**
     * Check Horizon status
     */
    private function checkHorizonStatus(): array
    {
        $status = [
            'installed' => class_exists(\Laravel\Horizon\Horizon::class),
            'running' => false,
            'paused' => false,
            'supervisors' => 0,
        ];
        
        if ($status['installed']) {
            try {
                $masters = app(MasterSupervisorRepository::class)->all();
                $status['running'] = !empty($masters);
                $status['supervisors'] = count($masters);
                $status['paused'] = \Laravel\Horizon\Horizon::$paused ?? false;
            } catch (\Exception $e) {
                // Horizon not properly configured
            }
        }
        
        return $status;
    }

    /**
     * Display queue statistics
     */
    private function displayQueueStatistics(array $queues, array $failedJobs, array $horizonStatus): void
    {
        // Display Horizon status
        $this->info('Horizon Status:');
        if ($horizonStatus['installed']) {
            if ($horizonStatus['running']) {
                $this->line('  Status: <fg=green>Running</>');
                $this->line('  Supervisors: ' . $horizonStatus['supervisors']);
                if ($horizonStatus['paused']) {
                    $this->warn('  ⚠️  Horizon is PAUSED');
                }
            } else {
                $this->error('  Status: Not Running');
            }
        } else {
            $this->warn('  Horizon not installed (using basic queue workers)');
        }
        
        $this->newLine();
        
        // Display queue statistics
        $this->info('Queue Statistics:');
        
        switch ($this->option('format')) {
            case 'json':
                $this->line(json_encode([
                    'queues' => $queues,
                    'failed_jobs' => $failedJobs,
                    'horizon' => $horizonStatus,
                ], JSON_PRETTY_PRINT));
                break;
                
            case 'csv':
                $this->outputCsv($queues);
                break;
                
            default:
                $this->table(
                    ['Queue', 'Pending', 'Processing', 'Processed Today', 'Avg Time', 'Failure Rate', 'Status'],
                    array_map(function ($queue) {
                        return [
                            $queue['queue'],
                            $queue['pending'],
                            $queue['processing'],
                            $queue['processed_today'],
                            $queue['avg_time'],
                            $queue['failure_rate'] . '%',
                            $queue['status'],
                        ];
                    }, $queues)
                );
        }
        
        // Display failed jobs
        if (!empty($failedJobs)) {
            $this->newLine();
            $this->warn('Failed Jobs:');
            $this->table(
                ['Queue', 'Count', 'Last Failure'],
                array_map(function ($job) {
                    return [
                        $job['queue'],
                        $job['count'],
                        $job['last_failure'],
                    ];
                }, $failedJobs)
            );
        }
    }

    /**
     * Detect issues
     */
    private function detectIssues(array $queues, array $failedJobs, array $horizonStatus): array
    {
        $issues = [];
        
        // Check Horizon status
        if ($horizonStatus['installed'] && !$horizonStatus['running']) {
            $issues[] = [
                'type' => 'horizon_down',
                'severity' => 'critical',
                'message' => 'Horizon is not running',
            ];
        }
        
        if ($horizonStatus['paused']) {
            $issues[] = [
                'type' => 'horizon_paused',
                'severity' => 'warning',
                'message' => 'Horizon is paused',
            ];
        }
        
        // Check queue issues
        foreach ($queues as $queue) {
            if ($queue['pending'] > 1000) {
                $issues[] = [
                    'type' => 'queue_backlog',
                    'severity' => 'critical',
                    'message' => "Queue '{$queue['queue']}' has {$queue['pending']} pending jobs",
                ];
            } elseif ($queue['pending'] > 500) {
                $issues[] = [
                    'type' => 'queue_high_load',
                    'severity' => 'warning',
                    'message' => "Queue '{$queue['queue']}' has high load ({$queue['pending']} pending)",
                ];
            }
            
            if ($queue['processing'] === 0 && $queue['pending'] > 0) {
                $issues[] = [
                    'type' => 'queue_stalled',
                    'severity' => 'critical',
                    'message' => "Queue '{$queue['queue']}' appears to be stalled",
                ];
            }
            
            if ($queue['failure_rate'] > 10) {
                $issues[] = [
                    'type' => 'high_failure_rate',
                    'severity' => 'critical',
                    'message' => "Queue '{$queue['queue']}' has high failure rate ({$queue['failure_rate']}%)",
                ];
            } elseif ($queue['failure_rate'] > 5) {
                $issues[] = [
                    'type' => 'elevated_failure_rate',
                    'severity' => 'warning',
                    'message' => "Queue '{$queue['queue']}' has elevated failure rate ({$queue['failure_rate']}%)",
                ];
            }
        }
        
        // Check failed jobs
        $totalFailed = array_sum(array_column($failedJobs, 'count'));
        if ($totalFailed > 100) {
            $issues[] = [
                'type' => 'many_failed_jobs',
                'severity' => 'warning',
                'message' => "There are {$totalFailed} failed jobs",
            ];
        }
        
        return $issues;
    }

    /**
     * Display issues
     */
    private function displayIssues(array $issues): void
    {
        $this->newLine();
        $this->error('🚨 Issues Detected:');
        
        foreach ($issues as $issue) {
            $icon = $issue['severity'] === 'critical' ? '❌' : '⚠️';
            $color = $issue['severity'] === 'critical' ? 'red' : 'yellow';
            
            $this->line("  {$icon} <fg={$color}>{$issue['message']}</>");
        }
    }

    /**
     * Should restart workers
     */
    private function shouldRestartWorkers(array $issues): bool
    {
        $criticalTypes = ['horizon_down', 'queue_stalled'];
        
        foreach ($issues as $issue) {
            if (in_array($issue['type'], $criticalTypes)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Restart workers
     */
    private function restartWorkers(): void
    {
        $this->info('Restarting queue workers...');
        
        // Restart Horizon if installed
        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            Artisan::call('horizon:terminate');
            $this->line('  Horizon terminated. It will restart automatically via supervisor.');
        } else {
            // Restart regular queue workers
            Artisan::call('queue:restart');
            $this->line('  Queue restart signal sent.');
        }
        
        // Clear cache to reset metrics
        Cache::forget('queue:restart');
        
        $this->info('✅ Workers restarted successfully.');
    }

    /**
     * Clear old failed jobs
     */
    private function clearOldFailedJobs(): void
    {
        $cutoff = now()->subDays(7);
        
        $deleted = DB::table('failed_jobs')
            ->where('failed_at', '<', $cutoff)
            ->delete();
        
        if ($deleted > 0) {
            $this->info("✅ Cleared {$deleted} failed jobs older than 7 days.");
        } else {
            $this->info('No old failed jobs to clear.');
        }
    }

    /**
     * Send alerts
     */
    private function sendAlerts(array $issues): void
    {
        // Log alerts
        foreach ($issues as $issue) {
            activity()
                ->withProperties($issue)
                ->log('Queue health alert: ' . $issue['message']);
        }
        
        // TODO: Send email/Slack notifications
        // Mail::to(config('mail.admin'))->send(new QueueHealthAlert($issues));
        
        $this->info('Alerts logged for ' . count($issues) . ' issues.');
    }

    /**
     * Output data as CSV
     */
    private function outputCsv(array $data): void
    {
        $this->line('Queue,Pending,Processing,ProcessedToday,AvgTime,FailureRate,Status');
        
        foreach ($data as $row) {
            $this->line(implode(',', [
                $row['queue'],
                $row['pending'],
                $row['processing'],
                $row['processed_today'],
                $row['avg_time'],
                $row['failure_rate'] . '%',
                strip_tags($row['status']),
            ]));
        }
    }
}