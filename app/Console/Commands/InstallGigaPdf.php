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
                            {--no-workers : Skip supervisor configuration}
                            {--fresh : Fresh install with database reset}
                            {--skip-npm : Skip npm packages installation}
                            {--clean-deps : Remove unused dependencies}
                            {--install-pymupdf : Install PyMuPDF for advanced PDF operations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install and configure Giga-PDF application with all dependencies';

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
        
        // Clean unused dependencies if requested
        if ($this->option('clean-deps')) {
            $this->cleanUnusedDependencies();
        }
        
        // Install PyMuPDF if requested or by default
        if ($this->option('install-pymupdf') || !$this->option('skip-deps')) {
            $this->installPyMuPDF();
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
            'PHP Version >= 8.2' => version_compare(PHP_VERSION, '8.2.0', '>='),
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
            'composer' => ['command' => 'composer --version', 'required' => true],
            'npm' => ['command' => 'npm --version', 'required' => true],
            'redis-server' => ['command' => 'redis-server --version', 'required' => false],
            'tesseract' => ['command' => 'tesseract --version', 'required' => false],
            'pdftotext' => ['command' => 'pdftotext -v', 'required' => false],
            'pdftohtml' => ['command' => 'pdftohtml -v', 'required' => false],
            'wkhtmltopdf' => ['command' => 'wkhtmltopdf --version', 'required' => false],
            'convert' => ['command' => 'convert --version', 'required' => false, 'name' => 'ImageMagick'],
            'pdftoppm' => ['command' => 'pdftoppm -v', 'required' => false],
            'ghostscript' => ['command' => 'gs --version', 'required' => false],
            'python3' => ['command' => 'python3 --version', 'required' => false],
            'pip3' => ['command' => 'pip3 --version', 'required' => false],
            'libreoffice' => ['command' => 'libreoffice --version', 'required' => false],
            'qpdf' => ['command' => 'qpdf --version', 'required' => false],
        ];

        $missingOptional = [];
        foreach ($binaries as $name => $config) {
            $process = Process::fromShellCommandline($config['command']);
            $process->setTimeout(5);
            
            try {
                $process->run();
                if ($process->isSuccessful()) {
                    $this->info("  ✓ $name installed");
                } else {
                    if ($config['required']) {
                        $this->error("  ✗ $name not found (REQUIRED)");
                        $failed = true;
                    } else {
                        $this->warn("  ⚠ $name not found (optional)");
                        $missingOptional[] = $name;
                    }
                }
            } catch (\Exception $e) {
                if ($config['required']) {
                    $this->error("  ✗ $name not found (REQUIRED)");
                    $failed = true;
                } else {
                    $this->warn("  ⚠ $name not found (optional)");
                    $missingOptional[] = $name;
                }
            }
        }

        if ($failed) {
            $this->error('Some required dependencies are missing. Please install them and try again.');
            exit(1);
        }

        $this->info('All required dependencies are installed.');
        
        // Offer to install missing optional dependencies
        if (!empty($missingOptional)) {
            $this->info('');
            $this->warn('Some optional dependencies are missing. These are needed for PDF features:');
            
            if (in_array('tesseract', $missingOptional)) {
                $this->installTesseract();
            }
            
            if (in_array('pdftotext', $missingOptional)) {
                $this->installPdftotext();
            }
            
            if (in_array('libreoffice', $missingOptional)) {
                $this->offerLibreOfficeInstallation();
            }
            
            if (in_array('redis-server', $missingOptional)) {
                $this->offerRedisInstallation();
            }
            
            if (in_array('wkhtmltopdf', $missingOptional)) {
                $this->installWkhtmltopdf();
            }
            
            if (in_array('pdftohtml', $missingOptional)) {
                $this->installPdfTools();
            }
            
            if (in_array('convert', $missingOptional) || in_array('ImageMagick', $missingOptional)) {
                $this->installImageMagick();
            }
            
            if (in_array('python3', $missingOptional) || in_array('pip3', $missingOptional)) {
                $this->installPythonTools();
            }
            
            if (in_array('qpdf', $missingOptional)) {
                $this->installQpdf();
            }
        }
        
        // Always check and install Python PDF libraries if Python is available
        if ($this->commandExists('python3') && $this->commandExists('pip3')) {
            $this->checkAndInstallPythonPdfLibraries();
        }
        
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
        $adminEmail = $this->ask('Super Admin email', 'admin@giga-pdf.local');
        $adminPassword = $this->secret('Super Admin password (min 8 characters)') ?: 'Admin@123456';
        
        while (strlen($adminPassword) < 8) {
            $this->error('Password must be at least 8 characters long.');
            $adminPassword = $this->secret('Super Admin password (min 8 characters)');
        }

        // Create default tenant
        $this->info('');
        $this->info('Creating Default Tenant:');
        $tenantName = $this->ask('Tenant name', 'Default Organization');
        $tenantSlug = Str::slug($tenantName);
        $tenantDomain = $this->ask('Tenant domain (optional)', 'giga-pdf.local');

        // First create the super admin (without transaction since tenant_id is null)
        $this->info('  Creating Super Admin...');
        
        // Check if super admin already exists
        $existingAdmin = User::where('email', $adminEmail)->first();
        if (!$existingAdmin) {
            // Create roles and permissions first
            $this->createRolesAndPermissions();
            
            // Create the super admin
            $superAdmin = User::create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'tenant_id' => null, // Super admin doesn't belong to any tenant
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            
            // Don't set team context for super-admin
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
            
            // Check if super-admin role exists, create if not
            $superAdminRole = \Spatie\Permission\Models\Role::where('name', 'super-admin')
                ->whereNull('team_id')
                ->first();
                
            if (!$superAdminRole) {
                $superAdminRole = \Spatie\Permission\Models\Role::create([
                    'name' => 'super-admin',
                    'guard_name' => 'web',
                    'team_id' => null
                ]);
            }
            
            // Assign super-admin role
            $superAdmin->assignRole('super-admin');
            
            $this->info('  ✓ Super Admin created successfully');
        } else {
            $this->warn('  Super admin with this email already exists.');
            $superAdmin = $existingAdmin;
        }
        
        // Now create the tenant in a transaction
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
                    'default_locale' => 'fr',
                    'timezone' => 'Europe/Paris',
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
        $this->info('  Creating roles and permissions...');
        
        // Create roles
        $roles = [
            'super-admin' => 'Super Administrator with full system access',
            'tenant-admin' => 'Tenant Administrator',
            'manager' => 'Manager with user management capabilities',
            'editor' => 'Editor with document editing capabilities', 
            'viewer' => 'Viewer with read-only access'
        ];
        
        foreach ($roles as $roleName => $description) {
            // Only create global roles (without tenant_id)
            if ($roleName === 'super-admin') {
                $role = \Spatie\Permission\Models\Role::firstOrCreate(
                    ['name' => $roleName, 'guard_name' => 'web', 'team_id' => null],
                    []
                );
            }
            // Skip tenant-specific roles here as they will be created per tenant
        }
        
        // Create permissions
        $permissions = [
            // User management
            'users.view',
            'users.create', 
            'users.edit',
            'users.delete',
            
            // Document management
            'documents.view',
            'documents.create',
            'documents.edit', 
            'documents.delete',
            'documents.share',
            
            // Conversion
            'conversions.create',
            'conversions.view',
            
            // Tenant management
            'tenant.manage',
            'tenant.settings',
            
            // System management
            'system.manage',
            'system.logs',
            'system.backup',
        ];
        
        foreach ($permissions as $permissionName) {
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'web']
            );
        }
        
        // Assign permissions to super admin role only
        $superAdminRole = \Spatie\Permission\Models\Role::where('name', 'super-admin')
            ->whereNull('team_id')
            ->first();
        
        if ($superAdminRole) {
            $superAdminRole->syncPermissions(\Spatie\Permission\Models\Permission::all());
        }
        
        // Tenant-specific roles will get their permissions when created per tenant
        
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

    /**
     * Install Tesseract OCR
     */
    protected function installTesseract(): void
    {
        $this->warn('');
        $this->warn('Tesseract OCR is required for text extraction from images and scanned PDFs.');
        
        if ($this->confirm('Do you want to install Tesseract OCR?', true)) {
            $os = $this->detectOS();
            
            $this->info('Installing Tesseract OCR...');
            
            $commands = [];
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $commands = [
                        'sudo apt-get update',
                        'sudo apt-get install -y tesseract-ocr',
                        'sudo apt-get install -y tesseract-ocr-fra tesseract-ocr-deu tesseract-ocr-spa', // Additional languages
                    ];
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $commands = [
                        'sudo yum install -y epel-release',
                        'sudo yum install -y tesseract',
                        'sudo yum install -y tesseract-langpack-fra tesseract-langpack-deu tesseract-langpack-spa',
                    ];
                    break;
                    
                case 'macos':
                    $commands = [
                        'brew install tesseract',
                        'brew install tesseract-lang', // All languages
                    ];
                    break;
                    
                default:
                    $this->error('Automatic installation not supported for your OS.');
                    $this->info('Please install Tesseract manually:');
                    $this->info('  Ubuntu/Debian: sudo apt-get install tesseract-ocr');
                    $this->info('  CentOS/RHEL: sudo yum install tesseract');
                    $this->info('  macOS: brew install tesseract');
                    $this->info('  Windows: Download from https://github.com/UB-Mannheim/tesseract/wiki');
                    return;
            }
            
            foreach ($commands as $command) {
                $this->info("Running: $command");
                $process = Process::fromShellCommandline($command);
                $process->setTimeout(300);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    $this->error('Failed to install Tesseract. Please install it manually.');
                    $this->error($process->getErrorOutput());
                    return;
                }
            }
            
            $this->info('  ✓ Tesseract OCR installed successfully');
        } else {
            $this->info('Skipping Tesseract installation.');
            $this->warn('Note: OCR features will not be available without Tesseract.');
        }
    }

    /**
     * Install pdftotext (part of poppler-utils)
     */
    protected function installPdftotext(): void
    {
        $this->warn('');
        $this->warn('pdftotext is required for extracting text from PDF files.');
        
        if ($this->confirm('Do you want to install pdftotext?', true)) {
            $os = $this->detectOS();
            
            $this->info('Installing pdftotext...');
            
            $commands = [];
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $commands = ['sudo apt-get update', 'sudo apt-get install -y poppler-utils'];
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $commands = ['sudo yum install -y poppler-utils'];
                    break;
                    
                case 'macos':
                    $commands = ['brew install poppler'];
                    break;
                    
                default:
                    $this->error('Automatic installation not supported for your OS.');
                    $this->info('Please install poppler-utils manually:');
                    $this->info('  Ubuntu/Debian: sudo apt-get install poppler-utils');
                    $this->info('  CentOS/RHEL: sudo yum install poppler-utils');
                    $this->info('  macOS: brew install poppler');
                    $this->info('  Windows: Download from https://poppler.freedesktop.org/');
                    return;
            }
            
            foreach ($commands as $command) {
                $this->info("Running: $command");
                $process = Process::fromShellCommandline($command);
                $process->setTimeout(300);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    $this->error('Failed to install pdftotext. Please install it manually.');
                    $this->error($process->getErrorOutput());
                    return;
                }
            }
            
            $this->info('  ✓ pdftotext installed successfully');
        } else {
            $this->info('Skipping pdftotext installation.');
            $this->warn('Note: PDF text extraction may be limited without pdftotext.');
        }
    }

    /**
     * Offer LibreOffice installation
     */
    protected function offerLibreOfficeInstallation(): void
    {
        $this->warn('');
        $this->warn('LibreOffice is required for converting Office documents (Word, Excel, PowerPoint) to PDF.');
        
        if ($this->confirm('Do you want instructions for installing LibreOffice?', true)) {
            $os = $this->detectOS();
            
            $this->info('');
            $this->info('LibreOffice Installation Instructions:');
            
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $this->info('  sudo apt-get update');
                    $this->info('  sudo apt-get install -y libreoffice');
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $this->info('  sudo yum install -y libreoffice');
                    break;
                    
                case 'macos':
                    $this->info('  brew install --cask libreoffice');
                    $this->info('  Or download from: https://www.libreoffice.org/download/');
                    break;
                    
                default:
                    $this->info('  Download from: https://www.libreoffice.org/download/');
            }
            
            $this->info('');
            $this->info('For headless operation (recommended for servers):');
            $this->info('  Start LibreOffice in headless mode:');
            $this->info('  libreoffice --headless --accept="socket,host=127.0.0.1,port=2002;urp;" --nofirststartwizard');
        }
    }

    /**
     * Offer Redis installation
     */
    protected function offerRedisInstallation(): void
    {
        $this->warn('');
        $this->warn('Redis is recommended for caching and queue management.');
        
        if ($this->confirm('Do you want instructions for installing Redis?', true)) {
            $os = $this->detectOS();
            
            $this->info('');
            $this->info('Redis Installation Instructions:');
            
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $this->info('  sudo apt-get update');
                    $this->info('  sudo apt-get install -y redis-server');
                    $this->info('  sudo systemctl enable redis-server');
                    $this->info('  sudo systemctl start redis-server');
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $this->info('  sudo yum install -y epel-release');
                    $this->info('  sudo yum install -y redis');
                    $this->info('  sudo systemctl enable redis');
                    $this->info('  sudo systemctl start redis');
                    break;
                    
                case 'macos':
                    $this->info('  brew install redis');
                    $this->info('  brew services start redis');
                    break;
                    
                default:
                    $this->info('  Download from: https://redis.io/download');
            }
            
            $this->info('');
            $this->info('Don\'t forget to install PHP Redis extension:');
            $this->info('  pecl install redis');
            $this->info('  Or: sudo apt-get install php-redis');
        }
    }

    /**
     * Install wkhtmltopdf
     */
    protected function installWkhtmltopdf(): void
    {
        $this->warn('');
        $this->warn('wkhtmltopdf is required for HTML to PDF conversion.');
        
        if ($this->confirm('Do you want to install wkhtmltopdf?', true)) {
            $os = $this->detectOS();
            
            $this->info('Installing wkhtmltopdf...');
            
            $commands = [];
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $commands = [
                        'sudo apt-get update',
                        'sudo apt-get install -y wkhtmltopdf wkhtmltoimage'
                    ];
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $commands = [
                        'sudo yum install -y epel-release',
                        'sudo yum install -y wkhtmltopdf'
                    ];
                    break;
                    
                case 'macos':
                    $commands = ['brew install --cask wkhtmltopdf'];
                    break;
                    
                default:
                    $this->error('Automatic installation not supported for your OS.');
                    $this->info('Please install wkhtmltopdf manually:');
                    $this->info('  Ubuntu/Debian: sudo apt-get install wkhtmltopdf');
                    $this->info('  CentOS/RHEL: sudo yum install wkhtmltopdf');
                    $this->info('  macOS: brew install --cask wkhtmltopdf');
                    $this->info('  Download: https://wkhtmltopdf.org/downloads.html');
                    return;
            }
            
            foreach ($commands as $command) {
                $this->info("Running: $command");
                $process = Process::fromShellCommandline($command);
                $process->setTimeout(300);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    $this->error('Failed to install wkhtmltopdf. Please install it manually.');
                    $this->error($process->getErrorOutput());
                    return;
                }
            }
            
            $this->info('  ✓ wkhtmltopdf installed successfully');
        } else {
            $this->info('Skipping wkhtmltopdf installation.');
            $this->warn('Note: HTML to PDF conversion will not work without wkhtmltopdf.');
        }
    }

    /**
     * Install PDF tools (pdftohtml, pdftoppm, etc.)
     */
    protected function installPdfTools(): void
    {
        $this->warn('');
        $this->warn('PDF tools are required for PDF manipulation and conversion.');
        
        if ($this->confirm('Do you want to install PDF tools?', true)) {
            $os = $this->detectOS();
            
            $this->info('Installing PDF tools...');
            
            $commands = [];
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $commands = [
                        'sudo apt-get update',
                        'sudo apt-get install -y poppler-utils',
                        'sudo apt-get install -y pdftk',
                        'sudo apt-get install -y qpdf',
                        'sudo apt-get install -y ghostscript'
                    ];
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $commands = [
                        'sudo yum install -y poppler-utils',
                        'sudo yum install -y pdftk',
                        'sudo yum install -y qpdf',
                        'sudo yum install -y ghostscript'
                    ];
                    break;
                    
                case 'macos':
                    $commands = [
                        'brew install poppler',
                        'brew install pdftk-java',
                        'brew install qpdf',
                        'brew install ghostscript'
                    ];
                    break;
                    
                default:
                    $this->error('Automatic installation not supported for your OS.');
                    $this->info('Please install PDF tools manually.');
                    return;
            }
            
            foreach ($commands as $command) {
                $this->info("Running: $command");
                $process = Process::fromShellCommandline($command);
                $process->setTimeout(300);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    $this->warn('Some PDF tools may not have installed correctly.');
                }
            }
            
            $this->info('  ✓ PDF tools installed successfully');
        } else {
            $this->info('Skipping PDF tools installation.');
            $this->warn('Note: Some PDF features may be limited.');
        }
    }

    /**
     * Install ImageMagick
     */
    protected function installImageMagick(): void
    {
        $this->warn('');
        $this->warn('ImageMagick is required for image processing and PDF to image conversion.');
        
        if ($this->confirm('Do you want to install ImageMagick?', true)) {
            $os = $this->detectOS();
            
            $this->info('Installing ImageMagick...');
            
            $commands = [];
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $commands = [
                        'sudo apt-get update',
                        'sudo apt-get install -y imagemagick',
                        'sudo apt-get install -y libmagickwand-dev'
                    ];
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $commands = [
                        'sudo yum install -y ImageMagick',
                        'sudo yum install -y ImageMagick-devel'
                    ];
                    break;
                    
                case 'macos':
                    $commands = ['brew install imagemagick'];
                    break;
                    
                default:
                    $this->error('Automatic installation not supported for your OS.');
                    $this->info('Please install ImageMagick manually:');
                    $this->info('  Ubuntu/Debian: sudo apt-get install imagemagick');
                    $this->info('  CentOS/RHEL: sudo yum install ImageMagick');
                    $this->info('  macOS: brew install imagemagick');
                    return;
            }
            
            foreach ($commands as $command) {
                $this->info("Running: $command");
                $process = Process::fromShellCommandline($command);
                $process->setTimeout(300);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    $this->error('Failed to install ImageMagick. Please install it manually.');
                    $this->error($process->getErrorOutput());
                    return;
                }
            }
            
            // Install PHP Imagick extension
            $this->info('Installing PHP Imagick extension...');
            $process = Process::fromShellCommandline('pecl install imagick');
            $process->setTimeout(300);
            $process->run();
            
            if (!$process->isSuccessful()) {
                $this->warn('PHP Imagick extension installation failed. You may need to install it manually.');
                $this->info('  Ubuntu/Debian: sudo apt-get install php-imagick');
                $this->info('  CentOS/RHEL: sudo yum install php-imagick');
            }
            
            $this->info('  ✓ ImageMagick installed successfully');
        } else {
            $this->info('Skipping ImageMagick installation.');
            $this->warn('Note: Image processing features will be limited.');
        }
    }

    /**
     * Install Python tools for table extraction
     */
    protected function installPythonTools(): void
    {
        $this->warn('');
        $this->warn('Python tools are required for advanced table extraction from PDFs.');
        
        if ($this->confirm('Do you want to install Python tools for table extraction?', true)) {
            $os = $this->detectOS();
            
            $this->info('Installing Python and pip...');
            
            $commands = [];
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $commands = [
                        'sudo apt-get update',
                        'sudo apt-get install -y python3 python3-pip python3-dev',
                        'sudo apt-get install -y default-jre'  // Required for tabula-py
                    ];
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $commands = [
                        'sudo yum install -y python3 python3-pip python3-devel',
                        'sudo yum install -y java-11-openjdk'
                    ];
                    break;
                    
                case 'macos':
                    $commands = [
                        'brew install python3',
                        'brew install openjdk'
                    ];
                    break;
                    
                default:
                    $this->error('Automatic installation not supported for your OS.');
                    return;
            }
            
            foreach ($commands as $command) {
                $this->info("Running: $command");
                $process = Process::fromShellCommandline($command);
                $process->setTimeout(300);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    $this->warn('Some Python dependencies may not have installed correctly.');
                }
            }
            
            // Install Python packages for PDF processing and table extraction
            $this->info('Installing Python packages for PDF processing...');
            $pythonPackages = [
                'PyMuPDF' => 'Advanced PDF manipulation and rendering',
                'beautifulsoup4' => 'HTML parsing for PDF conversion',
                'lxml' => 'XML/HTML processing library',
                'tabula-py' => 'Table extraction from PDFs',
                'pandas' => 'Data manipulation library',
                'pdfplumber' => 'PDF text and table extraction',
                'openpyxl' => 'Excel file support',
                'pytesseract' => 'OCR support for scanned PDFs',
                'opencv-python-headless' => 'Computer vision for table detection',
                'Pillow' => 'Image processing library',
                'reportlab' => 'PDF generation library'
            ];
            
            // Try to install all packages at once for efficiency
            $allPackages = implode(' ', array_keys($pythonPackages));
            $this->info('Installing all Python packages...');
            
            // Try with --break-system-packages for newer systems
            $process = Process::fromShellCommandline("pip3 install $allPackages --break-system-packages");
            $process->setTimeout(600);
            $process->run();
            
            if (!$process->isSuccessful()) {
                // Fallback to user installation
                $this->info('Trying user installation...');
                $process = Process::fromShellCommandline("pip3 install --user $allPackages");
                $process->setTimeout(600);
                $process->run();
            }
            
            if ($process->isSuccessful()) {
                $this->info('  ✓ All Python packages installed successfully');
                foreach ($pythonPackages as $package => $description) {
                    $this->info("    - $package: $description");
                }
            } else {
                $this->warn('Some Python packages may have failed to install.');
                $this->info('You can manually install them with:');
                $this->info("  pip3 install $allPackages --break-system-packages");
            }
            
            // Create Python extraction scripts
            $this->createPythonExtractionScripts();
            
            $this->info('  ✓ Python tools installed successfully');
        } else {
            $this->info('Skipping Python tools installation.');
            $this->warn('Note: Advanced table extraction will not be available.');
        }
    }

    /**
     * Check and install Python PDF libraries if missing
     */
    protected function checkAndInstallPythonPdfLibraries(): void
    {
        $this->info('Checking Python PDF libraries...');
        
        // Check if tabula-py is installed
        exec('python3 -c "import tabula" 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->warn('Python PDF libraries are not installed.');
            if ($this->confirm('Do you want to install them now for better PDF extraction?', true)) {
                $packages = 'PyMuPDF beautifulsoup4 lxml tabula-py pandas pdfplumber openpyxl pytesseract Pillow reportlab';
                
                $this->info('Installing Python PDF libraries...');
                $process = Process::fromShellCommandline("pip3 install $packages --break-system-packages");
                $process->setTimeout(600);
                $process->run();
                
                if ($process->isSuccessful()) {
                    $this->info('  ✓ Python PDF libraries installed successfully');
                } else {
                    $this->warn('Failed to install some packages. Try manually:');
                    $this->info("  pip3 install $packages --break-system-packages");
                }
            }
        } else {
            $this->info('  ✓ Python PDF libraries are already installed');
        }
    }
    
    /**
     * Install qpdf for high-quality PDF manipulation
     */
    protected function installQpdf(): void
    {
        $this->warn('');
        $this->warn('qpdf is highly recommended for high-quality PDF split/merge operations.');
        
        if ($this->confirm('Do you want to install qpdf?', true)) {
            $os = $this->detectOS();
            
            $this->info('Installing qpdf...');
            
            $commands = [];
            switch ($os) {
                case 'ubuntu':
                case 'debian':
                    $commands = [
                        'sudo apt-get update',
                        'sudo apt-get install -y qpdf'
                    ];
                    break;
                    
                case 'centos':
                case 'rhel':
                case 'fedora':
                    $commands = [
                        'sudo yum install -y qpdf'
                    ];
                    break;
                    
                case 'macos':
                    $commands = ['brew install qpdf'];
                    break;
                    
                default:
                    $this->error('Automatic installation not supported for your OS.');
                    $this->info('Please install qpdf manually:');
                    $this->info('  Ubuntu/Debian: sudo apt-get install qpdf');
                    $this->info('  CentOS/RHEL: sudo yum install qpdf');
                    $this->info('  macOS: brew install qpdf');
                    $this->info('  Download: https://github.com/qpdf/qpdf');
                    return;
            }
            
            foreach ($commands as $command) {
                $this->info("Running: $command");
                $process = Process::fromShellCommandline($command);
                $process->setTimeout(300);
                $process->run();
                
                if (!$process->isSuccessful()) {
                    $this->error('Failed to install qpdf. Please install it manually.');
                    $this->error($process->getErrorOutput());
                    return;
                }
            }
            
            $this->info('  ✓ qpdf installed successfully');
            $this->info('  qpdf provides lossless PDF manipulation for split, merge, and rotate operations.');
        } else {
            $this->info('Skipping qpdf installation.');
            $this->warn('Note: PDF split/merge will fall back to lower quality methods without qpdf.');
        }
    }
    
    /**
     * Create Python scripts for table extraction
     */
    protected function createPythonExtractionScripts(): void
    {
        $this->info('Creating Python extraction scripts...');
        
        // Create tabula extraction script
        $tabulaScript = <<<'PYTHON'
#!/usr/bin/env python3
import sys
import json
import tabula
from pathlib import Path

def extract_tables_to_html(pdf_path):
    """Extract tables from PDF and return HTML"""
    try:
        # Read all tables from PDF
        tables = tabula.read_pdf(
            pdf_path, 
            pages='all',
            multiple_tables=True,
            lattice=True  # Better border detection
        )
        
        html_output = []
        for i, table in enumerate(tables):
            # Convert each table to HTML
            html = table.to_html(
                index=False,
                table_id=f'table_{i}',
                classes='pdf-table border-collapse'
            )
            html_output.append(html)
        
        return '\n'.join(html_output)
        
    except Exception as e:
        return f"<p>Error: {str(e)}</p>"

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("<p>Usage: extract_tables.py <pdf_file></p>")
        sys.exit(1)
    
    pdf_file = sys.argv[1]
    html = extract_tables_to_html(pdf_file)
    print(html)
PYTHON;

        file_put_contents(base_path('extract_tables.py'), $tabulaScript);
        chmod(base_path('extract_tables.py'), 0755);
        
        // Create camelot extraction script
        $camelotScript = <<<'PYTHON'
#!/usr/bin/env python3
import sys
import camelot

def extract_with_camelot(pdf_path):
    """Use Camelot for more precise extraction"""
    try:
        # Detect and extract tables
        tables = camelot.read_pdf(pdf_path, pages='all', flavor='lattice')
        
        html_output = []
        for i, table in enumerate(tables):
            # Generate HTML with styles
            html = f"""
            <table class="extracted-table" style="border-collapse: collapse; width: 100%; margin: 20px 0;">
                <thead>
                    <tr>
                        {''.join([f'<th style="border: 1px solid #000; padding: 8px; background: #f0f0f0;">{cell}</th>' for cell in table.df.iloc[0]])}
                    </tr>
                </thead>
                <tbody>
            """
            
            for _, row in table.df.iloc[1:].iterrows():
                html += '<tr>'
                for cell in row:
                    html += f'<td style="border: 1px solid #000; padding: 8px;">{cell}</td>'
                html += '</tr>'
            
            html += """
                </tbody>
            </table>
            """
            html_output.append(html)
        
        return '\n'.join(html_output)
        
    except Exception as e:
        return f"<p>Camelot Error: {str(e)}</p>"

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("<p>Usage: extract_tables_camelot.py <pdf_file></p>")
        sys.exit(1)
    
    pdf_file = sys.argv[1]
    html = extract_with_camelot(pdf_file)
    print(html)
PYTHON;

        file_put_contents(base_path('extract_tables_camelot.py'), $camelotScript);
        chmod(base_path('extract_tables_camelot.py'), 0755);
        
        $this->info('  ✓ Python extraction scripts created');
    }

    /**
     * Install PyMuPDF specifically
     */
    protected function installPyMuPDF(): void
    {
        $this->info('');
        $this->info('Installing PyMuPDF for advanced PDF operations...');
        
        // Check if Python3 and pip3 are available
        if (!$this->commandExists('python3') || !$this->commandExists('pip3')) {
            $this->warn('Python3 or pip3 not found. Installing Python first...');
            $this->installPythonTools();
        }
        
        // Install PyMuPDF and related packages
        $this->info('Installing PyMuPDF and dependencies...');
        $packages = 'PyMuPDF beautifulsoup4 lxml Pillow reportlab';
        
        $process = Process::fromShellCommandline("pip3 install $packages --break-system-packages");
        $process->setTimeout(300);
        $process->run();
        
        if (!$process->isSuccessful()) {
            // Try user installation
            $process = Process::fromShellCommandline("pip3 install --user $packages");
            $process->setTimeout(300);
            $process->run();
        }
        
        if (!$process->isSuccessful()) {
            // Try with sudo for system-wide installation
            $this->info('Trying with sudo...');
            $process = Process::fromShellCommandline("sudo pip3 install $packages --break-system-packages");
            $process->setTimeout(300);
            $process->run();
        }
        
        if ($process->isSuccessful()) {
            $this->info('  ✓ PyMuPDF and BeautifulSoup4 installed successfully');
            
            // Create PyMuPDF extraction script
            $this->createPyMuPDFScript();
        } else {
            $this->warn('Failed to install PyMuPDF. You can install manually with:');
            $this->info("  sudo pip3 install $packages --break-system-packages");
        }
    }
    
    /**
     * Create PyMuPDF extraction script
     */
    protected function createPyMuPDFScript(): void
    {
        $script = <<<'PYTHON'
#!/usr/bin/env python3
import sys
import fitz  # PyMuPDF
import json
from pathlib import Path

def extract_text_with_layout(pdf_path):
    """Extract text from PDF preserving layout"""
    try:
        doc = fitz.open(pdf_path)
        full_text = []
        
        for page_num, page in enumerate(doc, 1):
            # Extract text with layout preservation
            text = page.get_text("text")
            full_text.append(f"--- Page {page_num} ---\n{text}")
        
        doc.close()
        return '\n'.join(full_text)
    except Exception as e:
        return f"Error: {str(e)}"

def extract_images(pdf_path, output_dir):
    """Extract all images from PDF"""
    try:
        doc = fitz.open(pdf_path)
        output_dir = Path(output_dir)
        output_dir.mkdir(exist_ok=True)
        
        image_count = 0
        for page_num, page in enumerate(doc):
            image_list = page.get_images()
            
            for img_index, img in enumerate(image_list):
                xref = img[0]
                pix = fitz.Pixmap(doc, xref)
                
                if pix.n - pix.alpha < 4:  # GRAY or RGB
                    image_path = output_dir / f"page{page_num}_img{img_index}.png"
                    pix.save(str(image_path))
                    image_count += 1
                else:  # CMYK
                    pix1 = fitz.Pixmap(fitz.csRGB, pix)
                    image_path = output_dir / f"page{page_num}_img{img_index}.png"
                    pix1.save(str(image_path))
                    pix1 = None
                    image_count += 1
                pix = None
        
        doc.close()
        return f"Extracted {image_count} images"
    except Exception as e:
        return f"Error: {str(e)}"

def remove_text_keep_images(pdf_path, output_path):
    """Remove text from PDF while keeping images and backgrounds"""
    try:
        doc = fitz.open(pdf_path)
        
        for page in doc:
            # Get all text instances
            text_instances = page.get_text("dict")
            
            # Redact each text block
            for block in text_instances["blocks"]:
                if block["type"] == 0:  # Text block
                    for line in block["lines"]:
                        for span in line["spans"]:
                            # Create rectangle for text
                            rect = fitz.Rect(span["bbox"])
                            # Add redaction annotation
                            page.add_redact_annot(rect)
            
            # Apply redactions (removes text)
            page.apply_redactions()
        
        # Save the modified PDF
        doc.save(output_path)
        doc.close()
        return f"Text removed, saved to {output_path}"
    except Exception as e:
        return f"Error: {str(e)}"

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: pymupdf_tools.py <command> <pdf_file> [options]")
        print("Commands: extract_text, extract_images, remove_text")
        sys.exit(1)
    
    command = sys.argv[1]
    pdf_file = sys.argv[2] if len(sys.argv) > 2 else None
    
    if command == "extract_text" and pdf_file:
        print(extract_text_with_layout(pdf_file))
    elif command == "extract_images" and pdf_file:
        output_dir = sys.argv[3] if len(sys.argv) > 3 else "./extracted_images"
        print(extract_images(pdf_file, output_dir))
    elif command == "remove_text" and pdf_file:
        output_file = sys.argv[3] if len(sys.argv) > 3 else "output_no_text.pdf"
        print(remove_text_keep_images(pdf_file, output_file))
    else:
        print("Invalid command or missing arguments")
PYTHON;

        file_put_contents(base_path('pymupdf_tools.py'), $script);
        chmod(base_path('pymupdf_tools.py'), 0755);
        
        $this->info('  ✓ PyMuPDF extraction script created');
    }
    
    /**
     * Clean unused dependencies
     */
    protected function cleanUnusedDependencies(): void
    {
        $this->info('');
        $this->info('Cleaning unused dependencies...');
        
        // Remove unused Composer packages
        $unusedPackages = [
            'laravel/sail',  // Not needed in production
            'laravel/pail',  // Development tool
        ];
        
        if ($this->confirm('Remove unused Composer packages?', true)) {
            foreach ($unusedPackages as $package) {
                $this->info("Removing $package...");
                $process = Process::fromShellCommandline("composer remove $package --no-interaction");
                $process->setTimeout(300);
                $process->run();
                
                if ($process->isSuccessful()) {
                    $this->info("  ✓ Removed $package");
                }
            }
            
            // Optimize autoloader
            $this->info('Optimizing Composer autoloader...');
            $process = Process::fromShellCommandline('composer dump-autoload --optimize');
            $process->setTimeout(300);
            $process->run();
            
            if ($process->isSuccessful()) {
                $this->info('  ✓ Composer autoloader optimized');
            }
        }
        
        // Clean npm packages
        if ($this->confirm('Clean and reinstall npm packages?', true)) {
            $this->info('Cleaning npm packages...');
            
            // Remove node_modules and package-lock
            $process = Process::fromShellCommandline('rm -rf node_modules package-lock.json');
            $process->run();
            
            // Reinstall with production flag
            $this->info('Reinstalling npm packages (production only)...');
            $process = Process::fromShellCommandline('npm install --production');
            $process->setTimeout(600);
            $process->run();
            
            if ($process->isSuccessful()) {
                $this->info('  ✓ NPM packages cleaned and reinstalled');
            }
            
            // Audit and fix vulnerabilities
            $this->info('Checking for vulnerabilities...');
            $process = Process::fromShellCommandline('npm audit fix');
            $process->setTimeout(300);
            $process->run();
            
            if ($process->isSuccessful()) {
                $this->info('  ✓ Vulnerabilities fixed');
            }
        }
        
        // Clear all caches
        $this->info('Clearing all caches...');
        $cacheCommands = [
            'config:clear',
            'cache:clear',
            'route:clear',
            'view:clear',
            'event:clear'
        ];
        
        foreach ($cacheCommands as $command) {
            Artisan::call($command);
            $this->info("  ✓ $command executed");
        }
        
        $this->info('  ✓ Cleanup completed');
    }
    
    /**
     * Check if a command exists
     */
    protected function commandExists(string $command): bool
    {
        $process = Process::fromShellCommandline("which $command");
        $process->run();
        return $process->isSuccessful();
    }
    
    /**
     * Detect operating system
     */
    protected function detectOS(): string
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return 'macos';
        }
        
        if (PHP_OS_FAMILY === 'Windows') {
            return 'windows';
        }
        
        // For Linux, try to detect distribution
        if (file_exists('/etc/os-release')) {
            $osRelease = parse_ini_file('/etc/os-release');
            $id = strtolower($osRelease['ID'] ?? '');
            
            if (in_array($id, ['ubuntu', 'debian'])) {
                return 'ubuntu';
            }
            
            if (in_array($id, ['centos', 'rhel', 'fedora', 'rocky', 'almalinux'])) {
                return 'centos';
            }
        }
        
        // Check for common commands
        if (shell_exec('which apt-get')) {
            return 'ubuntu';
        }
        
        if (shell_exec('which yum')) {
            return 'centos';
        }
        
        return 'unknown';
    }
}