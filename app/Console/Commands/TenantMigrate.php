<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantMigrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:migrate 
                            {tenant? : The ID or slug of the tenant to migrate}
                            {--all : Migrate all tenants}
                            {--fresh : Drop all tables and re-run all migrations}
                            {--seed : Run seeders after migrating}
                            {--force : Force run in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations for specific tenant(s)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check if running in production
        if (app()->environment('production') && ! $this->option('force')) {
            if (! $this->confirm('You are running migrations in production. Are you sure?')) {
                $this->info('Migration cancelled.');

                return Command::SUCCESS;
            }
        }

        // Determine which tenants to migrate
        $tenants = $this->getTenants();

        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');

            return Command::FAILURE;
        }

        $this->info('Starting tenant migrations...');
        $bar = $this->output->createProgressBar($tenants->count());
        $bar->start();

        $failed = [];
        $succeeded = 0;

        foreach ($tenants as $tenant) {
            $bar->setMessage("Migrating tenant: {$tenant->name}");

            try {
                $this->migrateTenant($tenant);
                $succeeded++;
            } catch (\Exception $e) {
                $failed[] = [
                    'tenant' => $tenant->name,
                    'error' => $e->getMessage(),
                ];
                $this->error("\nFailed to migrate tenant {$tenant->name}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Show results
        $this->info("✅ Successfully migrated {$succeeded} tenant(s)");

        if (! empty($failed)) {
            $this->error("❌ Failed to migrate " . count($failed) . " tenant(s):");
            foreach ($failed as $failure) {
                $this->line("  - {$failure['tenant']}: {$failure['error']}");
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Get tenants to migrate
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

            $choice = $this->choice('Which tenant would you like to migrate?', $tenants);

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
     * Migrate a specific tenant
     */
    private function migrateTenant(Tenant $tenant): void
    {
        $this->line("\nMigrating tenant: {$tenant->name} (ID: {$tenant->id})");

        // Set the tenant context
        app()->instance('tenant', $tenant);
        config(['database.connections.tenant' => [
            'driver' => 'mysql',
            'host' => config('database.connections.mysql.host', '127.0.0.1'),
            'port' => config('database.connections.mysql.port', '3306'),
            'database' => config('database.connections.mysql.database', 'gigapdf'),
            'username' => config('database.connections.mysql.username', 'root'),
            'password' => config('database.connections.mysql.password', ''),
            'unix_socket' => config('database.connections.mysql.unix_socket', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => 'tenant_' . $tenant->id . '_',
            'strict' => true,
            'engine' => null,
        ]]);

        // Create tenant-specific tables if needed
        $this->createTenantTables($tenant);

        // Run migrations
        if ($this->option('fresh')) {
            // Drop all tenant tables
            $this->dropTenantTables($tenant);
            $this->line("  Dropped all tables for tenant {$tenant->name}");
        }

        // Run tenant-specific migrations
        $this->runTenantMigrations($tenant);

        // Run seeders if requested
        if ($this->option('seed')) {
            $this->line("  Running seeders for tenant {$tenant->name}...");
            Artisan::call('tenant:seed', [
                'tenant' => $tenant->id,
                '--force' => true,
            ]);
            $this->line("  Seeders completed");
        }

        // Clear tenant context
        app()->forgetInstance('tenant');

        $this->line("  ✅ Tenant {$tenant->name} migrated successfully");
    }

    /**
     * Create tenant-specific tables
     */
    private function createTenantTables(Tenant $tenant): void
    {
        $prefix = 'tenant_' . $tenant->id . '_';

        // List of tenant-specific tables to create
        $tables = [
            'settings' => function ($table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('type')->default('string');
                $table->timestamps();
            },
            'custom_fields' => function ($table) {
                $table->id();
                $table->string('entity_type');
                $table->string('field_name');
                $table->string('field_type');
                $table->json('field_options')->nullable();
                $table->boolean('is_required')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            },
            'templates' => function ($table) {
                $table->id();
                $table->string('name');
                $table->string('type');
                $table->text('content');
                $table->json('variables')->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            },
        ];

        foreach ($tables as $tableName => $schema) {
            $fullTableName = $prefix . $tableName;

            if (! Schema::hasTable($fullTableName)) {
                Schema::create($fullTableName, $schema);
                $this->line("  Created table: {$fullTableName}");
            }
        }
    }

    /**
     * Drop tenant-specific tables
     */
    private function dropTenantTables(Tenant $tenant): void
    {
        $prefix = 'tenant_' . $tenant->id . '_';

        // Get all tables with the tenant prefix
        $tables = DB::select('SHOW TABLES');
        $dbName = config('database.connections.mysql.database');

        foreach ($tables as $table) {
            $tableName = $table->{"Tables_in_{$dbName}"} ?? null;

            if ($tableName && str_starts_with($tableName, $prefix)) {
                Schema::dropIfExists($tableName);
            }
        }
    }

    /**
     * Run tenant-specific migrations
     */
    private function runTenantMigrations(Tenant $tenant): void
    {
        // Create tenant-specific migrations path if needed
        $tenantMigrationsPath = database_path('migrations/tenants');

        if (! file_exists($tenantMigrationsPath)) {
            mkdir($tenantMigrationsPath, 0755, true);
        }

        // Check if there are tenant-specific migrations
        $migrations = glob($tenantMigrationsPath . '/*.php');

        if (! empty($migrations)) {
            $this->line("  Running tenant-specific migrations...");

            // Run migrations with tenant prefix
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenants',
                '--database' => 'tenant',
                '--force' => true,
            ], $this->output);
        }

        // Update tenant migration status
        $tenant->update([
            'settings' => array_merge($tenant->settings ?? [], [
                'last_migration' => now()->toIso8601String(),
                'migration_version' => config('app.version', '1.0.0'),
            ]),
        ]);
    }
}
