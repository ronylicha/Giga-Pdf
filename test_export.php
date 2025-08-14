<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Document;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

// Get document 4 (the original)
$document = Document::find(4);
if (!$document) {
    die("Document not found\n");
}

// Get HTML content by simulating conversion
$controller = app(App\Http\Controllers\DocumentController::class);

// Create a mock request
$request = new Illuminate\Http\Request();
$request->setMethod('POST');
$request->merge(['document_id' => 4]);

// Convert to HTML
$response = $controller->convertToHtml($request, $document);
$responseData = json_decode($response->getContent(), true);

if (!$responseData['success']) {
    die("Conversion failed: " . ($responseData['error'] ?? 'Unknown error') . "\n");
}

$html = $responseData['html'];

// Count vector images
preg_match_all('/<img[^>]*class=["\'][^"\']*pdf-vector[^"\']*["\'][^>]*>/i', $html, $vectorImages);
echo "Vector images found: " . count($vectorImages[0]) . "\n";

// Check for base64 images
preg_match_all('/src="data:image\/[^;]+;base64,[^"]+"/i', $html, $base64Images);
echo "Base64 images found: " . count($base64Images[0]) . "\n";

// Save HTML to file for inspection
file_put_contents('/tmp/test_export.html', $html);
echo "HTML saved to /tmp/test_export.html\n";

// Show first few vector images
if (count($vectorImages[0]) > 0) {
    echo "\nFirst vector image:\n";
    echo substr($vectorImages[0][0], 0, 200) . "...\n";
}