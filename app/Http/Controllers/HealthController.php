<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    /**
     * Health check endpoint for Ploi monitoring
     */
    public function check(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Database check
        try {
            DB::connection()->getPdo();
            $checks['database'] = [
                'status' => 'healthy',
                'message' => 'Database connection successful',
            ];
        } catch (\Exception $e) {
            $checks['database'] = [
                'status' => 'unhealthy',
                'message' => 'Database connection failed',
            ];
            $healthy = false;
        }

        // Redis check
        try {
            Redis::ping();
            $checks['redis'] = [
                'status' => 'healthy',
                'message' => 'Redis connection successful',
            ];
        } catch (\Exception $e) {
            $checks['redis'] = [
                'status' => 'unhealthy',
                'message' => 'Redis connection failed',
            ];
            $healthy = false;
        }

        // Storage check
        try {
            Storage::exists('test');
            $checks['storage'] = [
                'status' => 'healthy',
                'message' => 'Storage is accessible',
            ];
        } catch (\Exception $e) {
            $checks['storage'] = [
                'status' => 'unhealthy',
                'message' => 'Storage is not accessible',
            ];
            $healthy = false;
        }

        // Cache check
        try {
            Cache::remember('health_check', 60, function () {
                return true;
            });
            $checks['cache'] = [
                'status' => 'healthy',
                'message' => 'Cache is working',
            ];
        } catch (\Exception $e) {
            $checks['cache'] = [
                'status' => 'unhealthy',
                'message' => 'Cache is not working',
            ];
            $healthy = false;
        }

        // Queue check (check if any workers are running)
        try {
            $queueSize = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours(1))->count();

            $queueStatus = 'healthy';
            $queueMessage = "Queue size: $queueSize";

            if ($failedJobs > 10) {
                $queueStatus = 'warning';
                $queueMessage .= ", Failed jobs (last hour): $failedJobs";
            }

            $checks['queue'] = [
                'status' => $queueStatus,
                'message' => $queueMessage,
            ];
        } catch (\Exception $e) {
            $checks['queue'] = [
                'status' => 'unknown',
                'message' => 'Could not check queue status',
            ];
        }

        // LibreOffice check
        try {
            $libreofficeInstalled = shell_exec('which libreoffice') !== null;
            if ($libreofficeInstalled) {
                $checks['libreoffice'] = [
                    'status' => 'healthy',
                    'message' => 'LibreOffice is installed',
                ];
            } else {
                $checks['libreoffice'] = [
                    'status' => 'warning',
                    'message' => 'LibreOffice is not installed',
                ];
            }
        } catch (\Exception $e) {
            $checks['libreoffice'] = [
                'status' => 'unknown',
                'message' => 'Could not check LibreOffice',
            ];
        }

        // Disk space check
        try {
            $diskFreeSpace = disk_free_space('/');
            $diskTotalSpace = disk_total_space('/');
            $diskUsagePercent = round((($diskTotalSpace - $diskFreeSpace) / $diskTotalSpace) * 100, 2);

            $diskStatus = 'healthy';
            if ($diskUsagePercent > 90) {
                $diskStatus = 'critical';
                $healthy = false;
            } elseif ($diskUsagePercent > 80) {
                $diskStatus = 'warning';
            }

            $checks['disk'] = [
                'status' => $diskStatus,
                'message' => "Disk usage: {$diskUsagePercent}%",
            ];
        } catch (\Exception $e) {
            $checks['disk'] = [
                'status' => 'unknown',
                'message' => 'Could not check disk space',
            ];
        }

        $response = [
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'checks' => $checks,
        ];

        return response()->json($response, $healthy ? 200 : 503);
    }

    /**
     * Simple health check for uptime monitoring
     */
    public function ping(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
