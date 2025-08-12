<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class TenantSeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:seed 
                            {tenant? : The ID or slug of the tenant to seed}
                            {--all : Seed all tenants}
                            {--class= : Specific seeder class to run}
                            {--force : Force run in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run seeders for specific tenant(s)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check if running in production
        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm('You are running seeders in production. Are you sure?')) {
                $this->info('Seeding cancelled.');

                return Command::SUCCESS;
            }
        }

        // Determine which tenants to seed
        $tenants = $this->getTenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');

            return Command::FAILURE;
        }

        $this->info('Starting tenant seeding...');
        $bar = $this->output->createProgressBar($tenants->count());
        $bar->start();

        $failed = [];
        $succeeded = 0;

        foreach ($tenants as $tenant) {
            $bar->setMessage("Seeding tenant: {$tenant->name}");

            try {
                $this->seedTenant($tenant);
                $succeeded++;
            } catch (\Exception $e) {
                $failed[] = [
                    'tenant' => $tenant->name,
                    'error' => $e->getMessage(),
                ];
                $this->error("\nFailed to seed tenant {$tenant->name}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Show results
        $this->info("✅ Successfully seeded {$succeeded} tenant(s)");

        if (! empty($failed)) {
            $this->error("❌ Failed to seed " . count($failed) . " tenant(s):");
            foreach ($failed as $failure) {
                $this->line("  - {$failure['tenant']}: {$failure['error']}");
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Get tenants to seed
     */
    private function getTenants()
    {
        if ($this->option('all')) {
            return Tenant::all();
        }

        $identifier = $this->argument('tenant');

        if (! $identifier) {
            // Interactive selection
            $tenants = Tenant::pluck('name', 'id')->toArray();
            $tenants['all'] = 'All Tenants';

            $choice = $this->choice('Which tenant would you like to seed?', $tenants);

            if ($choice === 'All Tenants') {
                return Tenant::all();
            }

            $tenantId = array_search($choice, $tenants);

            return Tenant::where('id', $tenantId)->get();
        }

        // Find by ID or slug
        return Tenant::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->get();
    }

    /**
     * Seed a specific tenant
     */
    private function seedTenant(Tenant $tenant): void
    {
        $this->line("\nSeeding tenant: {$tenant->name} (ID: {$tenant->id})");

        // Set the tenant context
        app()->instance('tenant', $tenant);
        config(['tenant.id' => $tenant->id]);

        // Run specific seeder class if provided
        if ($seederClass = $this->option('class')) {
            $this->runSeederClass($tenant, $seederClass);
        } else {
            // Run default tenant seeders
            $this->runDefaultSeeders($tenant);
        }

        // Clear tenant context
        app()->forgetInstance('tenant');
        config(['tenant.id' => null]);

        $this->line("  ✅ Tenant {$tenant->name} seeded successfully");
    }

    /**
     * Run specific seeder class
     */
    private function runSeederClass(Tenant $tenant, string $seederClass): void
    {
        $fullClass = "Database\\Seeders\\{$seederClass}";

        if (! class_exists($fullClass)) {
            throw new \Exception("Seeder class {$fullClass} not found");
        }

        $this->line("  Running seeder: {$seederClass}");
        $seeder = new $fullClass();
        $seeder->run();
    }

    /**
     * Run default seeders for tenant
     */
    private function runDefaultSeeders(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            // Create default roles if they don't exist
            $this->seedRoles($tenant);

            // Create default permissions
            $this->seedPermissions($tenant);

            // Create sample data based on subscription plan
            if ($tenant->subscription_plan === 'demo' || app()->environment('local')) {
                $this->seedSampleData($tenant);
            }

            // Create default settings
            $this->seedSettings($tenant);

            // Create default templates
            $this->seedTemplates($tenant);
        });
    }

    /**
     * Seed roles for tenant
     */
    private function seedRoles(Tenant $tenant): void
    {
        $this->line("  Creating default roles...");

        $roles = [
            'tenant-admin' => [
                'description' => 'Tenant Administrator',
                'permissions' => [
                    'manage-users', 'manage-documents', 'manage-settings',
                    'view-reports', 'manage-roles', 'manage-invitations',
                ],
            ],
            'manager' => [
                'description' => 'Manager',
                'permissions' => [
                    'manage-users', 'manage-documents', 'view-reports',
                ],
            ],
            'editor' => [
                'description' => 'Editor',
                'permissions' => [
                    'create-documents', 'edit-documents', 'delete-documents',
                    'convert-documents', 'share-documents',
                ],
            ],
            'viewer' => [
                'description' => 'Viewer',
                'permissions' => [
                    'view-documents', 'download-documents',
                ],
            ],
        ];

        foreach ($roles as $roleName => $roleData) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'team_id' => $tenant->id],
                ['guard_name' => 'web']
            );

            // Attach permissions
            foreach ($roleData['permissions'] as $permissionName) {
                $permission = Permission::firstOrCreate(
                    ['name' => $permissionName, 'guard_name' => 'web']
                );
                $role->givePermissionTo($permission);
            }

            $this->line("    Created role: {$roleName}");
        }
    }

    /**
     * Seed permissions for tenant
     */
    private function seedPermissions(Tenant $tenant): void
    {
        $this->line("  Creating default permissions...");

        $permissions = [
            // User management
            'view-users', 'create-users', 'edit-users', 'delete-users', 'manage-users',

            // Document management
            'view-documents', 'create-documents', 'edit-documents', 'delete-documents',
            'download-documents', 'share-documents', 'convert-documents', 'manage-documents',

            // Settings
            'view-settings', 'manage-settings',

            // Reports
            'view-reports', 'export-reports',

            // Roles & Permissions
            'view-roles', 'manage-roles',

            // Invitations
            'send-invitations', 'manage-invitations',

            // Billing (if applicable)
            'view-billing', 'manage-billing',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $this->line("    Created " . count($permissions) . " permissions");
    }

    /**
     * Seed sample data for tenant
     */
    private function seedSampleData(Tenant $tenant): void
    {
        $this->line("  Creating sample data...");

        // Create sample users
        $users = [
            [
                'name' => 'John Manager',
                'email' => "manager@{$tenant->slug}.test",
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
                'role' => 'manager',
            ],
            [
                'name' => 'Jane Editor',
                'email' => "editor@{$tenant->slug}.test",
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
                'role' => 'editor',
            ],
            [
                'name' => 'Bob Viewer',
                'email' => "viewer@{$tenant->slug}.test",
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
                'role' => 'viewer',
            ],
        ];

        foreach ($users as $userData) {
            $role = $userData['role'];
            unset($userData['role']);

            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                $userData
            );

            // Assign role with team context
            $user->assignRole($role);

            $this->line("    Created user: {$user->name} ({$role})");
        }
    }

    /**
     * Seed settings for tenant
     */
    private function seedSettings(Tenant $tenant): void
    {
        $this->line("  Creating default settings...");

        $settings = [
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i:s',
            'timezone' => 'UTC',
            'language' => 'en',
            'currency' => 'USD',
            'allow_registration' => false,
            'require_email_verification' => true,
            'default_document_privacy' => 'private',
            'auto_ocr' => false,
            'max_upload_size' => $tenant->max_file_size_mb,
            'allowed_file_types' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'png'],
            'watermark_enabled' => false,
            'watermark_text' => $tenant->name,
        ];

        $tenant->updateSettings($settings);
        $this->line("    Created " . count($settings) . " default settings");
    }

    /**
     * Seed templates for tenant
     */
    private function seedTemplates(Tenant $tenant): void
    {
        $this->line("  Creating default templates...");

        $prefix = 'tenant_' . $tenant->id . '_';
        $templatesTable = $prefix . 'templates';

        // Check if templates table exists
        if (! DB::getSchemaBuilder()->hasTable($templatesTable)) {
            $this->line("    Templates table does not exist, skipping...");

            return;
        }

        $templates = [
            [
                'name' => 'Welcome Email',
                'type' => 'email',
                'content' => 'Welcome to ' . $tenant->name . '! Your account has been created.',
                'variables' => json_encode(['user_name', 'tenant_name', 'login_url']),
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Share Notification',
                'type' => 'email',
                'content' => '{{sharer_name}} has shared a document with you: {{document_name}}',
                'variables' => json_encode(['sharer_name', 'document_name', 'share_url']),
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Invoice Template',
                'type' => 'document',
                'content' => '<h1>Invoice</h1><p>Invoice Number: {{invoice_number}}</p>',
                'variables' => json_encode(['invoice_number', 'date', 'amount', 'customer_name']),
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($templates as $template) {
            DB::table($templatesTable)->insertOrIgnore($template);
        }

        $this->line("    Created " . count($templates) . " default templates");
    }
}
