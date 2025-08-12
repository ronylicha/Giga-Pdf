<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait LogsActivity
{
    /**
     * Log an activity
     */
    protected function logActivity(string $description, $subject = null, array $properties = [])
    {
        $data = [
            'log_name' => 'default',
            'description' => $description,
            'properties' => json_encode($properties),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Add causer information if user is authenticated
        if (Auth::check()) {
            $data['causer_type'] = 'App\Models\User';
            $data['causer_id'] = Auth::id();
        }

        // Add subject information if provided
        if ($subject) {
            $data['subject_type'] = get_class($subject);
            $data['subject_id'] = $subject->id;
        }

        // Check if we have Spatie Activity Log package
        if (class_exists('\Spatie\Activitylog\Models\Activity')) {
            try {
                activity($data['log_name'])
                    ->causedBy(Auth::user())
                    ->performedOn($subject)
                    ->withProperties($properties)
                    ->log($description);

                return;
            } catch (\Exception $e) {
                // Fall back to direct insert
            }
        }

        // Direct insert into activity_log table
        DB::table('activity_log')->insert($data);
    }

    /**
     * Log user login
     */
    protected function logLogin()
    {
        $this->logActivity('User logged in', Auth::user(), [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log user logout
     */
    protected function logLogout()
    {
        $this->logActivity('User logged out', Auth::user(), [
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Log document action
     */
    protected function logDocumentAction(string $action, $document, array $extra = [])
    {
        $properties = array_merge([
            'document_name' => $document->original_name ?? $document->name,
            'document_id' => $document->id,
        ], $extra);

        $this->logActivity("Document {$action}", $document, $properties);
    }

    /**
     * Log admin action
     */
    protected function logAdminAction(string $action, $target = null, array $properties = [])
    {
        $description = "Admin action: {$action}";
        $this->logActivity($description, $target, $properties);
    }
}
