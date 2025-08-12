<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Inertia\Inertia;

class PDFToolsController extends Controller
{
    public function merge()
    {
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('user_id', auth()->id())
            ->where('mime_type', 'application/pdf')
            ->orderBy('original_name')
            ->get(['id', 'original_name', 'size', 'mime_type']);

        return Inertia::render('Tools/Merge', [
            'title' => 'Fusionner PDF',
            'documents' => $documents,
        ]);
    }

    public function split()
    {
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('user_id', auth()->id())
            ->where('mime_type', 'application/pdf')
            ->orderBy('original_name')
            ->get(['id', 'original_name', 'size', 'mime_type']);

        return Inertia::render('Tools/Split', [
            'title' => 'Diviser PDF',
            'documents' => $documents,
        ]);
    }

    public function rotate()
    {
        return Inertia::render('Tools/Rotate', [
            'title' => 'Rotation PDF',
        ]);
    }

    public function compress()
    {
        return Inertia::render('Tools/Compress', [
            'title' => 'Compresser PDF',
        ]);
    }

    public function watermark()
    {
        return Inertia::render('Tools/Watermark', [
            'title' => 'Filigrane PDF',
        ]);
    }

    public function encrypt()
    {
        return Inertia::render('Tools/Encrypt', [
            'title' => 'Chiffrer PDF',
        ]);
    }

    public function decrypt()
    {
        $documents = Document::where('tenant_id', auth()->user()->tenant_id)
            ->where('user_id', auth()->id())
            ->where('mime_type', 'application/pdf')
            ->orderBy('original_name')
            ->get(['id', 'original_name', 'size', 'mime_type', 'metadata']);

        return Inertia::render('Tools/Decrypt', [
            'title' => 'Déverrouiller PDF',
            'documents' => $documents,
        ]);
    }

    public function ocr()
    {
        return Inertia::render('Tools/OCR', [
            'title' => 'OCR (Reconnaissance de texte)',
        ]);
    }

    public function extract()
    {
        return Inertia::render('Tools/Extract', [
            'title' => 'Extraire Pages PDF',
        ]);
    }
}
