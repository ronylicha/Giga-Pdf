<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
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
                            {--plan=basic : The subscription plan (basic, professional, enterprise)}
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
        $plan = $this->option('plan');
        
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
            // Créer le tenant
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'domain' => $domain,
                'subscription_plan' => $plan,
                'settings' => $this->getDefaultSettings($plan),
                'features' => $this->getFeaturesByPlan($plan),
                'max_storage_gb' => $this->getStorageByPlan($plan),
                'max_users' => $this->getUsersByPlan($plan),
                'max_file_size_mb' => $this->getFileSizeByPlan($plan),
                'is_active' => true,
            ]);
            
            $this->info("✓ Tenant '{$name}' created successfully with ID: {$tenant->id}");
            
            // Créer l'utilisateur admin
            $adminUser = User::create([
                'name' => 'Admin',
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'tenant_id' => $tenant->id,
                'role_id' => 1, // Admin role
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            
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
                    ['Plan', $tenant->subscription_plan],
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
     * Get default settings based on plan
     */
    private function getDefaultSettings(string $plan): array
    {
        return [
            'theme' => 'light',
            'language' => 'fr',
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'time_format' => 'H:i',
            'allow_registration' => false,
            'require_email_verification' => true,
            'require_2fa' => $plan === 'enterprise',
            'session_lifetime' => $plan === 'enterprise' ? 60 : 120,
            'password_expires_days' => $plan === 'enterprise' ? 90 : null,
            'allowed_file_types' => [
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff',
                'txt', 'rtf', 'odt', 'ods', 'odp'
            ],
        ];
    }
    
    /**
     * Get features based on plan
     */
    private function getFeaturesByPlan(string $plan): array
    {
        return match($plan) {
            'enterprise' => [
                'unlimited_users',
                'unlimited_storage',
                'api_access',
                'white_label',
                'priority_support',
                'advanced_security',
                'custom_domain',
                'sso',
                'audit_logs',
                'digital_signatures',
                'ocr',
                'redaction',
                'collaboration',
                'advanced_editor',
                'batch_processing',
                'webhooks',
                'custom_integrations',
                'dedicated_support',
                'sla_guarantee'
            ],
            'professional' => [
                'api_access',
                'priority_support',
                'custom_domain',
                'audit_logs',
                'digital_signatures',
                'ocr',
                'redaction',
                'collaboration',
                'advanced_editor',
                'batch_processing',
                'email_support'
            ],
            'basic' => [
                'basic_editor',
                'basic_conversions',
                'basic_sharing',
                'email_support',
                'standard_security'
            ],
            default => ['basic_conversions']
        };
    }
    
    /**
     * Get storage limit based on plan
     */
    private function getStorageByPlan(string $plan): int
    {
        return match($plan) {
            'enterprise' => 1000, // 1TB
            'professional' => 100, // 100GB
            'basic' => 10, // 10GB
            default => 5
        };
    }
    
    /**
     * Get user limit based on plan
     */
    private function getUsersByPlan(string $plan): int
    {
        return match($plan) {
            'enterprise' => 999999, // Unlimited
            'professional' => 50,
            'basic' => 5,
            default => 3
        };
    }
    
    /**
     * Get file size limit based on plan
     */
    private function getFileSizeByPlan(string $plan): int
    {
        return match($plan) {
            'enterprise' => 500, // 500MB
            'professional' => 200, // 200MB
            'basic' => 100, // 100MB
            default => 50
        };
    }
}