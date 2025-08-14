<?php
// Test PyMuPDF extraction directly
$pdfPath = '/var/www/html/giga-pdf/storage/app/private/test_modified.pdf';
$scriptPath = '/var/www/html/giga-pdf/resources/scripts/python/universal_pdf_extractor.py';

echo "Testing PyMuPDF extraction...\n";
echo "PDF: $pdfPath\n";
echo "Script: $scriptPath\n\n";

// Test 1: Run extraction
$command = sprintf(
    'python3 %s extract %s 2>&1',
    escapeshellarg($scriptPath),
    escapeshellarg($pdfPath)
);

echo "Command: $command\n";
$output = shell_exec($command);

if (!$output) {
    die("No output from PyMuPDF\n");
}

// Parse JSON
$result = json_decode($output, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON Error: " . json_last_error_msg() . "\n";
    echo "Raw output (first 1000 chars):\n";
    echo substr($output, 0, 1000) . "\n";
    die();
}

// Analyze results
echo "Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
if (!$result['success']) {
    echo "Error: " . $result['error'] . "\n";
    die();
}

echo "Pages: " . $result['pages'] . "\n";
echo "\nComponents found:\n";

// Text
$textCount = 0;
if (isset($result['components']['text'])) {
    foreach ($result['components']['text'] as $pageNum => $pageData) {
        if (isset($pageData['blocks'])) {
            $blockCount = count($pageData['blocks']);
            echo "  Page $pageNum: $blockCount text blocks\n";
            $textCount += $blockCount;
            
            // Show sample text
            if ($blockCount > 0 && isset($pageData['blocks'][0]['lines'][0]['spans'][0]['text'])) {
                echo "    Sample: \"" . substr($pageData['blocks'][0]['lines'][0]['spans'][0]['text'], 0, 50) . "...\"\n";
            }
        }
    }
}
echo "Total text blocks: $textCount\n";

// Images
$imageCount = 0;
if (isset($result['components']['images'])) {
    foreach ($result['components']['images'] as $pageNum => $images) {
        $count = count($images);
        echo "  Page $pageNum: $count images\n";
        $imageCount += $count;
    }
}
echo "Total images: $imageCount\n";

// Drawings
$drawingCount = 0;
if (isset($result['components']['drawings'])) {
    foreach ($result['components']['drawings'] as $pageNum => $drawings) {
        $count = count($drawings);
        echo "  Page $pageNum: $count drawings\n";
        $drawingCount += $count;
    }
}
echo "Total drawings: $drawingCount\n";

// Save to file for inspection
file_put_contents('/tmp/pymupdf_result.json', json_encode($result, JSON_PRETTY_PRINT));
echo "\nFull result saved to /tmp/pymupdf_result.json\n";