<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\PDFContentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReindexPdfSearch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pdf:reindex-search {--tenant= : Specific tenant ID to reindex} {--document= : Specific document ID to reindex}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reindex PDF content for search functionality';

    protected PDFContentService $contentService;

    public function __construct(PDFContentService $contentService)
    {
        parent::__construct();
        $this->contentService = $contentService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting PDF search reindexing...');

        $tenantId = $this->option('tenant');
        $documentId = $this->option('document');

        $query = Document::where('mime_type', 'application/pdf');

        if ($documentId) {
            $query->where('id', $documentId);
        } elseif ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $totalDocuments = $query->count();
        $this->info("Found {$totalDocuments} PDF document(s) to reindex.");

        if ($totalDocuments === 0) {
            $this->info('No documents to reindex.');

            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalDocuments);
        $bar->start();

        $successCount = 0;
        $errorCount = 0;

        $query->chunk(10, function ($documents) use ($bar, &$successCount, &$errorCount) {
            foreach ($documents as $document) {
                try {
                    if (Storage::exists($document->stored_name)) {
                        $content = $this->extractTextContent($document);

                        $document->update([
                            'search_content' => $content,
                            'metadata' => array_merge($document->metadata ?? [], [
                                'last_indexed' => now()->toIso8601String(),
                                'content_length' => strlen($content),
                            ]),
                        ]);

                        $successCount++;
                    } else {
                        $this->line("\nFile not found: {$document->stored_name}");
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $this->line("\nError indexing document {$document->id}: " . $e->getMessage());
                    $errorCount++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info("Reindexing completed!");
        $this->info("Successfully indexed: {$successCount} document(s)");

        if ($errorCount > 0) {
            $this->warn("Failed to index: {$errorCount} document(s)");
        }

        return Command::SUCCESS;
    }

    private function extractTextContent(Document $document): string
    {
        try {
            $pdfPath = Storage::path($document->stored_name);

            // Try using pdftotext first (fastest)
            $command = sprintf('pdftotext -layout %s - 2>/dev/null', escapeshellarg($pdfPath));
            $text = shell_exec($command);

            if (! empty($text)) {
                return $text;
            }

            // Fallback to service method
            return $this->contentService->extractTextFromPdf($pdfPath);

        } catch (\Exception $e) {
            $this->line("Could not extract text from document {$document->id}: " . $e->getMessage());

            return '';
        }
    }
}
