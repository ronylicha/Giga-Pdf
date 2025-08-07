<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class LibreOfficeService
{
    protected $host;
    protected $port;
    protected $timeout;
    
    public function __construct()
    {
        $this->host = config('services.libreoffice.host', 'localhost');
        $this->port = config('services.libreoffice.port', 2004);
        $this->timeout = config('services.libreoffice.timeout', 120);
    }
    
    /**
     * Convert document to PDF using LibreOffice
     */
    public function convertToPDF(string $inputPath, string $outputPath): bool
    {
        if (!file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }
        
        $outputDir = dirname($outputPath);
        
        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        // Build LibreOffice command
        $command = $this->buildCommand($inputPath, $outputDir, 'pdf');
        
        // Execute conversion
        $output = [];
        $returnCode = 0;
        
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            Log::error('LibreOffice conversion failed', [
                'command' => $command,
                'output' => $output,
                'return_code' => $returnCode
            ]);
            
            throw new Exception('LibreOffice conversion failed: ' . implode("\n", $output));
        }
        
        // Find the generated file
        $generatedFile = $this->findGeneratedFile($outputDir, $inputPath, 'pdf');
        
        if (!$generatedFile) {
            throw new Exception('Converted file not found after LibreOffice conversion');
        }
        
        // Move to desired output path
        if ($generatedFile !== $outputPath) {
            if (!rename($generatedFile, $outputPath)) {
                throw new Exception('Failed to move converted file to destination');
            }
        }
        
        return true;
    }
    
    /**
     * Convert PDF to another format using LibreOffice
     */
    public function convertFromPDF(string $inputPath, string $outputPath, string $format): bool
    {
        if (!file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }
        
        $outputDir = dirname($outputPath);
        
        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        // Build LibreOffice command
        $command = $this->buildCommand($inputPath, $outputDir, $format);
        
        // Execute conversion
        $output = [];
        $returnCode = 0;
        
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            Log::error('LibreOffice conversion from PDF failed', [
                'command' => $command,
                'output' => $output,
                'return_code' => $returnCode
            ]);
            
            throw new Exception('LibreOffice conversion failed: ' . implode("\n", $output));
        }
        
        // Find the generated file
        $generatedFile = $this->findGeneratedFile($outputDir, $inputPath, $format);
        
        if (!$generatedFile) {
            throw new Exception('Converted file not found after LibreOffice conversion');
        }
        
        // Move to desired output path
        if ($generatedFile !== $outputPath) {
            if (!rename($generatedFile, $outputPath)) {
                throw new Exception('Failed to move converted file to destination');
            }
        }
        
        return true;
    }
    
    /**
     * Convert between office formats
     */
    public function convert(string $inputPath, string $outputPath, string $format): bool
    {
        if (!file_exists($inputPath)) {
            throw new Exception("Input file not found: {$inputPath}");
        }
        
        $outputDir = dirname($outputPath);
        
        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        // Build LibreOffice command
        $command = $this->buildCommand($inputPath, $outputDir, $format);
        
        // Execute conversion
        $output = [];
        $returnCode = 0;
        
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            Log::error('LibreOffice conversion failed', [
                'command' => $command,
                'output' => $output,
                'return_code' => $returnCode
            ]);
            
            throw new Exception('LibreOffice conversion failed: ' . implode("\n", $output));
        }
        
        // Find the generated file
        $generatedFile = $this->findGeneratedFile($outputDir, $inputPath, $format);
        
        if (!$generatedFile) {
            throw new Exception('Converted file not found after LibreOffice conversion');
        }
        
        // Move to desired output path
        if ($generatedFile !== $outputPath) {
            if (!rename($generatedFile, $outputPath)) {
                throw new Exception('Failed to move converted file to destination');
            }
        }
        
        return true;
    }
    
    /**
     * Build LibreOffice command
     */
    protected function buildCommand(string $inputPath, string $outputDir, string $format): string
    {
        // Use libreoffice in headless mode
        $libreOfficePath = $this->getLibreOfficePath();
        
        $command = sprintf(
            '%s --headless --convert-to %s --outdir %s %s',
            escapeshellcmd($libreOfficePath),
            escapeshellarg($format),
            escapeshellarg($outputDir),
            escapeshellarg($inputPath)
        );
        
        // Add timeout
        if ($this->timeout > 0) {
            $command = sprintf('timeout %d %s', $this->timeout, $command);
        }
        
        return $command;
    }
    
    /**
     * Get LibreOffice executable path
     */
    protected function getLibreOfficePath(): string
    {
        $paths = [
            '/usr/bin/libreoffice',
            '/usr/bin/soffice',
            '/opt/libreoffice/program/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
            'C:\Program Files\LibreOffice\program\soffice.exe',
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path) || is_executable($path)) {
                return $path;
            }
        }
        
        // Try to find using which command
        $output = shell_exec('which libreoffice 2>/dev/null');
        if ($output) {
            return trim($output);
        }
        
        $output = shell_exec('which soffice 2>/dev/null');
        if ($output) {
            return trim($output);
        }
        
        throw new Exception('LibreOffice not found. Please install LibreOffice.');
    }
    
    /**
     * Find generated file after conversion
     */
    protected function findGeneratedFile(string $outputDir, string $inputPath, string $format): ?string
    {
        $baseName = pathinfo($inputPath, PATHINFO_FILENAME);
        $extension = $this->getExtensionForFormat($format);
        
        // Expected file name
        $expectedFile = $outputDir . '/' . $baseName . '.' . $extension;
        
        if (file_exists($expectedFile)) {
            return $expectedFile;
        }
        
        // Search for file with similar name
        $pattern = $outputDir . '/' . $baseName . '*.' . $extension;
        $files = glob($pattern);
        
        if (!empty($files)) {
            return $files[0];
        }
        
        return null;
    }
    
    /**
     * Get file extension for format
     */
    protected function getExtensionForFormat(string $format): string
    {
        // Handle special LibreOffice format codes
        $map = [
            'pdf' => 'pdf',
            'docx' => 'docx',
            'doc' => 'doc',
            'xlsx' => 'xlsx',
            'xls' => 'xls',
            'pptx' => 'pptx',
            'ppt' => 'ppt',
            'odt' => 'odt',
            'ods' => 'ods',
            'odp' => 'odp',
            'rtf' => 'rtf',
            'txt' => 'txt',
            'html' => 'html',
            'csv' => 'csv',
        ];
        
        // Extract format name if it contains filters
        if (strpos($format, ':') !== false) {
            $parts = explode(':', $format);
            $format = $parts[0];
        }
        
        return $map[strtolower($format)] ?? strtolower($format);
    }
    
    /**
     * Check if LibreOffice is available
     */
    public function isAvailable(): bool
    {
        try {
            $path = $this->getLibreOfficePath();
            return !empty($path);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get LibreOffice version
     */
    public function getVersion(): ?string
    {
        try {
            $path = $this->getLibreOfficePath();
            $command = sprintf('%s --version 2>&1', escapeshellcmd($path));
            $output = shell_exec($command);
            
            if ($output && preg_match('/LibreOffice\s+([\d\.]+)/', $output, $matches)) {
                return $matches[1];
            }
            
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
}