<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CertificateController extends Controller
{
    /**
     * Display certificates management page
     */
    public function index()
    {
        $certificates = Certificate::where('tenant_id', auth()->user()->tenant_id)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Admin/Certificates/Index', [
            'certificates' => $certificates,
        ]);
    }

    /**
     * Store a new certificate
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:self_signed,imported',
            'certificate_file' => 'required_if:type,imported|file|mimes:crt,pem,p12',
            'private_key_file' => 'required_if:type,imported|file',
            'password' => 'required|string',
            'is_default' => 'boolean',
            // For self-signed generation
            'key_size' => 'required_if:type,self_signed|in:1024,2048,4096',
            'common_name' => 'required_if:type,self_signed|string|max:255',
            'organization' => 'nullable|string|max:255',
            'organizational_unit' => 'nullable|string|max:255',
            'country' => 'nullable|string|size:2',
            'state' => 'nullable|string|max:255',
            'locality' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'validity_years' => 'required_if:type,self_signed|integer|min:1|max:10',
        ]);

        try {
            $tenantId = auth()->user()->tenant_id;

            // Reset default if needed
            if ($request->is_default) {
                Certificate::where('tenant_id', $tenantId)
                    ->update(['is_default' => false]);
            }

            if ($request->type === 'self_signed') {
                $certificate = $this->generateSelfSignedCertificate($request);
            } else {
                $certificate = $this->importCertificate($request);
            }

            return redirect()->route('admin.certificates.index')
                ->with('success', 'Certificat créé avec succès');
        } catch (Exception $e) {
            return back()->withErrors(['error' => 'Erreur lors de la création du certificat : ' . $e->getMessage()]);
        }
    }

    /**
     * Generate self-signed certificate
     */
    private function generateSelfSignedCertificate(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        $certDir = storage_path("app/private/certificates/tenant_{$tenantId}");

        if (! file_exists($certDir)) {
            mkdir($certDir, 0755, true);
        }

        $certId = Str::uuid();
        $privateKeyPath = "$certDir/{$certId}_private.key";
        $certificatePath = "$certDir/{$certId}_cert.pem";

        // Configuration for certificate
        $dn = [
            "countryName" => $request->country ?? 'FR',
            "stateOrProvinceName" => $request->state ?? 'France',
            "localityName" => $request->locality ?? 'Paris',
            "organizationName" => $request->organization ?? 'Giga-PDF',
            "organizationalUnitName" => $request->organizational_unit ?? 'IT',
            "commonName" => $request->common_name,
            "emailAddress" => $request->email ?? 'admin@giga-pdf.local',
        ];

        // Generate private key
        $config = [
            "private_key_bits" => (int)$request->key_size,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($config);

        // Generate certificate signing request
        $csr = openssl_csr_new($dn, $privateKey, $config);

        // Generate self-signed certificate
        $validityDays = $request->validity_years * 365;
        $certificate = openssl_csr_sign($csr, null, $privateKey, $validityDays);

        // Export private key
        openssl_pkey_export($privateKey, $privateKeyPEM, $request->password);
        file_put_contents($privateKeyPath, $privateKeyPEM);

        // Export certificate
        openssl_x509_export($certificate, $certificatePEM);
        file_put_contents($certificatePath, $certificatePEM);

        // Get certificate info
        $certInfo = openssl_x509_parse($certificate);

        // Create database record
        return Certificate::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'description' => $request->description,
            'type' => 'self_signed',
            'key_size' => $request->key_size,
            'common_name' => $request->common_name,
            'organization' => $request->organization,
            'organizational_unit' => $request->organizational_unit,
            'country' => $request->country,
            'state' => $request->state,
            'locality' => $request->locality,
            'email' => $request->email,
            'certificate_path' => $certificatePath,
            'private_key_path' => $privateKeyPath,
            'password' => $request->password,
            'is_default' => $request->is_default ?? false,
            'is_active' => true,
            'valid_from' => now(),
            'valid_to' => now()->addYears($request->validity_years),
            'serial_number' => $certInfo['serialNumber'] ?? null,
            'fingerprint' => openssl_x509_fingerprint($certificate),
        ]);
    }

    /**
     * Import existing certificate
     */
    private function importCertificate(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        $certDir = storage_path("app/private/certificates/tenant_{$tenantId}");

        if (! file_exists($certDir)) {
            mkdir($certDir, 0755, true);
        }

        $certId = Str::uuid();

        // Store certificate file
        $certificatePath = $request->file('certificate_file')->storeAs(
            "private/certificates/tenant_{$tenantId}",
            "{$certId}_cert." . $request->file('certificate_file')->getClientOriginalExtension()
        );

        // Store private key file if provided
        $privateKeyPath = null;
        if ($request->hasFile('private_key_file')) {
            $privateKeyPath = $request->file('private_key_file')->storeAs(
                "private/certificates/tenant_{$tenantId}",
                "{$certId}_private.key"
            );
        }

        // Read certificate info
        $certContent = Storage::get($certificatePath);
        $certificate = openssl_x509_read($certContent);
        $certInfo = openssl_x509_parse($certificate);

        return Certificate::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'description' => $request->description,
            'type' => 'imported',
            'common_name' => $certInfo['subject']['CN'] ?? 'Unknown',
            'organization' => $certInfo['subject']['O'] ?? null,
            'organizational_unit' => $certInfo['subject']['OU'] ?? null,
            'country' => $certInfo['subject']['C'] ?? null,
            'state' => $certInfo['subject']['ST'] ?? null,
            'locality' => $certInfo['subject']['L'] ?? null,
            'email' => $certInfo['subject']['emailAddress'] ?? null,
            'certificate_path' => Storage::path($certificatePath),
            'private_key_path' => $privateKeyPath ? Storage::path($privateKeyPath) : null,
            'password' => $request->password,
            'is_default' => $request->is_default ?? false,
            'is_active' => true,
            'valid_from' => date('Y-m-d H:i:s', $certInfo['validFrom_time_t']),
            'valid_to' => date('Y-m-d H:i:s', $certInfo['validTo_time_t']),
            'serial_number' => $certInfo['serialNumber'] ?? null,
            'fingerprint' => openssl_x509_fingerprint($certificate),
        ]);
    }

    /**
     * Set certificate as default
     */
    public function setDefault(Certificate $certificate)
    {
        $this->authorize('update', $certificate);

        Certificate::where('tenant_id', $certificate->tenant_id)
            ->update(['is_default' => false]);

        $certificate->update(['is_default' => true]);

        return redirect()->route('admin.certificates.index')
            ->with('success', 'Certificat défini par défaut');
    }

    /**
     * Toggle certificate status
     */
    public function toggle(Certificate $certificate)
    {
        $this->authorize('update', $certificate);

        $certificate->update(['is_active' => ! $certificate->is_active]);

        return redirect()->route('admin.certificates.index')
            ->with('success', 'Statut du certificat mis à jour');
    }

    /**
     * Delete certificate
     */
    public function destroy(Certificate $certificate)
    {
        $this->authorize('delete', $certificate);

        // Delete files
        if ($certificate->certificate_path && file_exists($certificate->certificate_path)) {
            unlink($certificate->certificate_path);
        }
        if ($certificate->private_key_path && file_exists($certificate->private_key_path)) {
            unlink($certificate->private_key_path);
        }

        $certificate->delete();

        return redirect()->route('admin.certificates.index')
            ->with('success', 'Certificat supprimé');
    }
}
