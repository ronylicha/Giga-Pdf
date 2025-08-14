<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantPermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:create 
                            {name : The name of the tenant}
                            {--domain= : The custom domain for the tenant}
                            {--admin-email= : Email for the admin user}
                            {--admin-password= : Password for the admin user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new tenant with an admin user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Creating new tenant...');

        // Récupérer les paramètres
        $name = $this->argument('name');
        $domain = $this->option('domain');

        // Demander les informations de l'admin si non fournies
        $adminEmail = $this->option('admin-email') ?: $this->ask('Admin email');
        $adminPassword = $this->option('admin-password') ?: $this->secret('Admin password (min 8 characters)');

        // Valider le mot de passe
        if (strlen($adminPassword) < 8) {
            $this->error('Password must be at least 8 characters long.');

            return 1;
        }

        // Vérifier si l'email existe déjà
        if (User::where('email', $adminEmail)->exists()) {
            $this->error("User with email {$adminEmail} already exists.");

            return 1;
        }

        try {
            // Générer un slug unique pour le tenant
            $baseSlug = Str::slug($name);
            $slug = $baseSlug;
            $counter = 1;

            // Vérifier l'unicité du slug
            while (Tenant::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter;
                $counter++;
            }

            // Créer le tenant avec les valeurs par défaut du plan gratuit
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug,
                'domain' => $domain,
                'subscription_plan' => 'free',
                'settings' => $this->getDefaultSettings(),
                'features' => $this->getDefaultFeatures(),
                'max_storage_gb' => 1,
                'max_users' => 5,
                'max_file_size_mb' => 50,
                'is_active' => true,
            ]);

            $this->info("✓ Tenant '{$name}' created successfully with ID: {$tenant->id}");

            // Créer les rôles et permissions pour ce tenant
            $permissionService = new TenantPermissionService();
            $permissionService->createTenantRolesAndPermissions($tenant);
            $this->info("✓ Roles and permissions created for tenant");

            // Créer l'utilisateur admin
            $adminUser = User::create([
                'name' => 'Admin',
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'tenant_id' => $tenant->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            // Assigner le rôle tenant-admin à l'utilisateur
            $permissionService->assignRoleToUser($adminUser, 'tenant-admin', $tenant->id);

            $this->info("✓ Admin user created: {$adminEmail}");

            // Afficher le résumé
            $this->newLine();
            $this->table(
                ['Property', 'Value'],
                [
                    ['Tenant ID', $tenant->id],
                    ['Tenant Name', $tenant->name],
                    ['Tenant Slug', $tenant->slug],
                    ['Domain', $tenant->domain ?: 'N/A'],
                    ['Plan', 'Gratuit (toutes fonctionnalités)'],
                    ['Max Storage', $tenant->max_storage_gb . ' GB'],
                    ['Max Users', $tenant->max_users],
                    ['Max File Size', $tenant->max_file_size_mb . ' MB'],
                    ['Admin Email', $adminUser->email],
                ]
            );

            $this->newLine();
            $this->info('Tenant created successfully! The admin user can now login.');

            return 0;

        } catch (\Exception $e) {
            $this->error('Failed to create tenant: ' . $e->getMessage());

            // Nettoyer en cas d'erreur
            if (isset($tenant)) {
                $tenant->delete();
            }

            return 1;
        }
    }

    /**
     * Get default settings
     */
    private function getDefaultSettings(): array
    {
        return [
            'theme' => 'light',
            'language' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'time_format' => 'H:i',
            'allow_registration' => true,
            'require_email_verification' => true,
            'require_2fa' => false,
            'session_lifetime' => 120,
            'password_expires_days' => null,
            'allowed_file_types' => [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff',
                'txt', 'rtf', 'odt', 'ods', 'odp',
            ],
        ];
    }

    /**
     * Get default features (toutes les fonctionnalités disponibles)
     */
    private function getDefaultFeatures(): array
    {
        return [
            'api_access',
            'custom_domain',
            'audit_logs',
            'digital_signatures',
            'ocr',
            'redaction',
            'collaboration',
            'advanced_editor',
            'batch_processing',
            'basic_editor',
            'basic_conversions',
            'advanced_conversions',
            'basic_sharing',
            'advanced_sharing',
            'email_support',
            'webhooks',
            'custom_integrations',
            'advanced_security',
            'sso',
            'white_label',
        ];
    }
}
