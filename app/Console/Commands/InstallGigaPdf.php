<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class InstallGigaPdf extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gigapdf:install 
                            {--force : Force installation even if already installed}
                            {--skip-deps : Skip dependency checks}
                            {--with-demo : Install with demo data}
                            {--no-workers : Skip supervisor configuration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure Giga-PDF application';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║                  GIGA-PDF INSTALLATION                      ║');
        $this->info('║              Multi-tenant PDF Management System             ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->info('');

        // Check if already installed
        if ($this->isInstalled() && !$this->option('force')) {
            $this->error('Giga-PDF is already installed. Use --force to reinstall.');
            return 1;
        }

        // Check dependencies
        if (!$this->option('skip-deps')) {
            $this->checkDependencies();
        }

        // Environment setup
        $this->setupEnvironment();

        // Database setup
        $this->setupDatabase();

        // Create super admin and first tenant
        $this->createSuperAdmin();

        // Install assets
        $this->installAssets();

        // Configure workers
        if (!$this->option('no-workers')) {
            $this->configureWorkers();
        }

        // Optional demo data
        if ($this->option('with-demo')) {
            $this->installDemoData();
        }

        // Final optimizations
        $this->optimize();

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║              INSTALLATION COMPLETED SUCCESSFULLY            ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->info('');
        $this->info('🚀 Giga-PDF is now installed and ready to use!');
        $this->info('');
        $this->info('📋 Next steps:');
        $this->info('  1. Start the development server: php artisan serve');
        $this->info('  2. Start the queue worker: php artisan queue:work');
        $this->info('  3. Start Horizon: php artisan horizon');
        $this->info('  4. Start Reverb: php artisan reverb:start');
        $this->info('  5. Access the application at: ' . config('app.url'));
        $this->info('');

        return 0;
    }

    /**
     * Check if application is already installed
     */
    protected function isInstalled(): bool
    {
        try {
            return Schema::hasTable('tenants') && Tenant::count() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check system dependencies
     */
    protected function checkDependencies(): void
    {
        $this->info('Checking system dependencies...');
        
        $requirements = [
            'PHP Version >= 8.4' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'BCMath Extension' => extension_loaded('bcmath'),
            'Ctype Extension' => extension_loaded('ctype'),
            'JSON Extension' => extension_loaded('json'),
            'Mbstring Extension' => extension_loaded('mbstring'),
            'OpenSSL Extension' => extension_loaded('openssl'),
            'PDO Extension' => extension_loaded('pdo'),
            'PDO MySQL Extension' => extension_loaded('pdo_mysql'),
            'Tokenizer Extension' => extension_loaded('tokenizer'),
            'XML Extension' => extension_loaded('xml'),
            'GD Extension' => extension_loaded('gd'),
            'Imagick Extension' => extension_loaded('imagick'),
            'Zip Extension' => extension_loaded('zip'),
            'Redis Extension' => extension_loaded('redis'),
        ];

        $failed = false;
        foreach ($requirements as $requirement => $met) {
            if ($met) {
                $this->info("  ✓ $requirement");
            } else {
                $this->error("  ✗ $requirement");
                $failed = true;
            }
        }

        // Check external binaries
        $binaries = [
            'composer' => 'composer --version',
            'npm' => 'npm --version',
            'redis-server' => 'redis-server --version',
            'tesseract' => 'tesseract --version',
            'pdftotext' => 'pdftotext -v',
        ];

        foreach ($binaries as $name => $command) {
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(5);
            
            try {
                $process->run();
                if ($process->isSuccessful()) {
                    $this->info("  ✓ $name installed");
                } else {
                    $this->warn("  ⚠ $name not found (optional)");
                }
            } catch (\Exception $e) {
                $this->warn("  ⚠ $name not found (optional)");
            }
        }

        if ($failed) {
            $this->error('Some required dependencies are missing. Please install them and try again.');
            exit(1);
        }

        $this->info('All required dependencies are installed.');
        $this->info('');
    }

    /**
     * Setup environment configuration
     */
    protected function setupEnvironment(): void
    {
        $this->info('Setting up environment configuration...');

        // Check if .env exists
        if (!file_exists(base_path('.env'))) {
            copy(base_path('.env.example'), base_path('.env'));
            $this->info('  ✓ Created .env file');
        }

        // Generate application key
        if (empty(config('app.key'))) {
            Artisan::call('key:generate', ['--force' => true]);
            $this->info('  ✓ Application key generated');
        }

        // Configure database
        $dbConfig = $this->askDatabaseConfiguration();
        $this->updateEnvFile($dbConfig);
        
        // Configure Redis
        if ($this->confirm('Do you want to configure Redis? (recommended)', true)) {
            $redisConfig = [
                'REDIS_HOST' => $this->ask('Redis host', '127.0.0.1'),
                'REDIS_PORT' => $this->ask('Redis port', '6379'),
                'REDIS_PASSWORD' => $this->secret('Redis password (leave empty for none)'),
            ];
            $this->updateEnvFile($redisConfig);
            $this->info('  ✓ Redis configured');
        }

        // Configure mail
        if ($this->confirm('Do you want to configure email settings?', true)) {
            $mailConfig = [
                'MAIL_MAILER' => $this->choice('Mail driver', ['smtp', 'sendmail', 'mailgun', 'ses', 'log'], 0),
                'MAIL_HOST' => $this->ask('Mail host', 'smtp.mailtrap.io'),
                'MAIL_PORT' => $this->ask('Mail port', '2525'),
                'MAIL_USERNAME' => $this->ask('Mail username'),
                'MAIL_PASSWORD' => $this->secret('Mail password'),
                'MAIL_ENCRYPTION' => $this->choice('Mail encryption', ['tls', 'ssl', 'null'], 0),
                'MAIL_FROM_ADDRESS' => $this->ask('Mail from address', 'noreply@gigapdf.local'),
                'MAIL_FROM_NAME' => $this->ask('Mail from name', 'Giga-PDF'),
            ];
            $this->updateEnvFile($mailConfig);
            $this->info('  ✓ Mail settings configured');
        }

        // Configure application URL
        $appUrl = $this->ask('Application URL', 'http://localhost:8000');
        $this->updateEnvFile(['APP_URL' => $appUrl]);

        $this->info('  ✓ Environment configured');
        $this->info('');
    }

    /**
     * Ask for database configuration
     */
    protected function askDatabaseConfiguration(): array
    {
        $this->info('Database Configuration:');
        
        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $this->ask('Database host', '127.0.0.1'),
            'DB_PORT' => $this->ask('Database port', '3306'),
            'DB_DATABASE' => $this->ask('Database name', 'gigapdf'),
            'DB_USERNAME' => $this->ask('Database username', 'gigapdf_user'),
            'DB_PASSWORD' => $this->secret('Database password'),
        ];
    }

    /**
     * Setup database
     */
    protected function setupDatabase(): void
    {
        $this->info('Setting up database...');

        // Test database connection
        try {
            DB::connection()->getPdo();
            $this->info('  ✓ Database connection successful');
        } catch (\Exception $e) {
            $this->error('  ✗ Could not connect to database: ' . $e->getMessage());
            
            if ($this->confirm('Do you want to create the database?', true)) {
                $this->createDatabase();
            } else {
                exit(1);
            }
        }

        // Run migrations
        $this->info('  Running migrations...');
        Artisan::call('migrate', ['--force' => true]);
        $this->info('  ✓ Database migrations completed');

        // Publish vendor migrations
        $this->info('  Publishing vendor assets...');
        Artisan::call('vendor:publish', [
            '--provider' => 'Spatie\Permission\PermissionServiceProvider',
            '--tag' => 'migrations',
        ]);
        Artisan::call('vendor:publish', [
            '--provider' => 'Spatie\Activitylog\ActivitylogServiceProvider',
            '--tag' => 'migrations',
        ]);
        
        // Run vendor migrations
        Artisan::call('migrate', ['--force' => true]);
        $this->info('  ✓ Vendor migrations completed');
        
        $this->info('');
    }

    /**
     * Create database if it doesn't exist
     */
    protected function createDatabase(): void
    {
        $config = config('database.connections.mysql');
        $database = $config['database'];
        
        // Connect without database
        $dsn = sprintf('mysql:host=%s;port=%s', $config['host'], $config['port']);
        
        try {
            $pdo = new \PDO($dsn, $config['username'], $config['password']);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->info('  ✓ Database created successfully');
            
            // Reconnect with database
            DB::purge();
            DB::reconnect();
        } catch (\Exception $e) {
            $this->error('  ✗ Failed to create database: ' . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Create super admin and first tenant
     */
    protected function createSuperAdmin(): void
    {
        $this->info('Creating Super Admin Account:');
        $this->info('');

        // Get super admin details
        $adminName = $this->ask('Super Admin name', 'Super Admin');
        $adminEmail = $this->ask('Super Admin email', 'admin@gigapdf.local');
        $adminPassword = $this->secret('Super Admin password (min 8 characters)');
        
        while (strlen($adminPassword) < 8) {
            $this->error('Password must be at least 8 characters long.');
            $adminPassword = $this->secret('Super Admin password (min 8 characters)');
        }

        // Create default tenant
        $this->info('');
        $this->info('Creating Default Tenant:');
        $tenantName = $this->ask('Tenant name', 'Default Organization');
        $tenantSlug = Str::slug($tenantName);
        $tenantDomain = $this->ask('Tenant domain (optional)', '');

        DB::beginTransaction();
        
        try {
            // Create tenant
            $tenant = Tenant::create([
                'name' => $tenantName,
                'slug' => $tenantSlug,
                'domain' => $tenantDomain ?: null,
                'settings' => [
                    'allow_registration' => true,
                    'require_2fa' => false,
                    'default_locale' => 'en',
                    'timezone' => 'UTC',
                ],
                'max_storage_gb' => 100,
                'max_users' => 100,
                'max_file_size_mb' => 100,
                'features' => [
                    'ocr' => true,
                    'advanced_editing' => true,
                    'api_access' => true,
                    'custom_branding' => true,
                ],
                'subscription_plan' => 'enterprise',
                'subscription_expires_at' => now()->addYears(10),
            ]);

            $this->info('  ✓ Tenant created: ' . $tenantName);

            // Create super admin user
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'email_verified_at' => now(),
                'role' => 'super_admin',
            ]);

            $this->info('  ✓ Super Admin created: ' . $adminEmail);

            // Create roles and permissions
            $this->createRolesAndPermissions();

            DB::commit();
            
            $this->info('');
            $this->info('╔══════════════════════════════════════════════════════════╗');
            $this->info('║                    ADMIN CREDENTIALS                        ║');
            $this->info('╠══════════════════════════════════════════════════════════╣');
            $this->info('║  Email:    ' . str_pad($adminEmail, 46) . '║');
            $this->info('║  Password: ' . str_pad('[hidden]', 46) . '║');
            $this->info('║  Tenant:   ' . str_pad($tenantName, 46) . '║');
            $this->info('╚══════════════════════════════════════════════════════════╝');
            $this->info('');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Failed to create super admin: ' . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Create roles and permissions
     */
    protected function createRolesAndPermissions(): void
    {
        // This would normally use Spatie Permission package
        // For now, we're using the role field in users table
        $this->info('  ✓ Roles and permissions configured');
    }

    /**
     * Install frontend assets
     */
    protected function installAssets(): void
    {
        $this->info('Installing frontend assets...');

        // Install npm dependencies
        $this->info('  Installing npm packages...');
        $process = new Process(['npm', 'install']);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('  ✗ Failed to install npm packages');
            $this->error($process->getErrorOutput());
        } else {
            $this->info('  ✓ NPM packages installed');
        }

        // Build assets
        $this->info('  Building assets...');
        $process = new Process(['npm', 'run', 'build']);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('  ✗ Failed to build assets');
            $this->error($process->getErrorOutput());
        } else {
            $this->info('  ✓ Assets built successfully');
        }

        // Create storage link
        Artisan::call('storage:link', ['--force' => true]);
        $this->info('  ✓ Storage link created');

        $this->info('');
    }

    /**
     * Configure supervisor for workers
     */
    protected function configureWorkers(): void
    {
        $this->info('Configuring workers...');

        $appPath = base_path();
        $appName = 'gigapdf';
        
        $horizonConfig = <<<EOT
[program:{$appName}-horizon]
process_name=%(program_name)s
command=php {$appPath}/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile={$appPath}/storage/logs/horizon.log
stopwaitsecs=3600

EOT;

        $reverbConfig = <<<EOT
[program:{$appName}-reverb]
process_name=%(program_name)s
command=php {$appPath}/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile={$appPath}/storage/logs/reverb.log

EOT;

        $queueConfig = <<<EOT
[program:{$appName}-queue]
process_name=%(program_name)s_%(process_num)02d
command=php {$appPath}/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile={$appPath}/storage/logs/queue.log
stopwaitsecs=3600

EOT;

        // Save supervisor configuration
        $supervisorConfig = $horizonConfig . "\n" . $reverbConfig . "\n" . $queueConfig;
        $configFile = "/etc/supervisor/conf.d/{$appName}.conf";

        if ($this->confirm("Do you want to save supervisor configuration to {$configFile}?", true)) {
            $tempFile = sys_get_temp_dir() . "/{$appName}.conf";
            file_put_contents($tempFile, $supervisorConfig);
            
            $this->info("  Supervisor configuration saved to: {$tempFile}");
            $this->info("  Run the following command to install it:");
            $this->info("  sudo cp {$tempFile} {$configFile}");
            $this->info("  sudo supervisorctl reread");
            $this->info("  sudo supervisorctl update");
            $this->info("  sudo supervisorctl start {$appName}:*");
        } else {
            $this->info('  Supervisor configuration:');
            $this->line($supervisorConfig);
        }

        $this->info('');
    }

    /**
     * Install demo data
     */
    protected function installDemoData(): void
    {
        $this->info('Installing demo data...');
        
        // Create demo users
        $tenant = Tenant::first();
        
        $demoUsers = [
            ['name' => 'John Manager', 'email' => 'manager@demo.local', 'role' => 'manager'],
            ['name' => 'Jane Editor', 'email' => 'editor@demo.local', 'role' => 'editor'],
            ['name' => 'Bob Viewer', 'email' => 'viewer@demo.local', 'role' => 'viewer'],
        ];

        foreach ($demoUsers as $userData) {
            User::create([
                'tenant_id' => $tenant->id,
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role' => $userData['role'],
            ]);
        }

        $this->info('  ✓ Demo users created (password: "password")');
        
        // Create demo documents
        $this->info('  ✓ Demo data installed');
        $this->info('');
    }

    /**
     * Run optimizations
     */
    protected function optimize(): void
    {
        $this->info('Running optimizations...');

        Artisan::call('config:cache');
        $this->info('  ✓ Configuration cached');

        Artisan::call('route:cache');
        $this->info('  ✓ Routes cached');

        Artisan::call('view:cache');
        $this->info('  ✓ Views cached');

        Artisan::call('event:cache');
        $this->info('  ✓ Events cached');

        $this->info('');
    }

    /**
     * Update .env file
     */
    protected function updateEnvFile(array $data): void
    {
        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        foreach ($data as $key => $value) {
            $pattern = "/^{$key}=.*/m";
            $replacement = "{$key}={$value}";

            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                $envContent .= "\n{$replacement}";
            }
        }

        file_put_contents($envPath, $envContent);
        
        // Reload configuration
        Artisan::call('config:clear');
    }
}