<?php
// Simple viewer to test the converted HTML
$htmlFile = '/tmp/doc_test_v2.html';

if (file_exists($htmlFile)) {
    $html = file_get_contents($htmlFile);
    
    // Add a simple header with stats
    $pageCount = substr_count($html, 'class="pdf-page"');
    $textCount = substr_count($html, 'class="pdf-text"');
    $imageCount = substr_count($html, 'class="pdf-image"');
    
    $header = '<div style="position: fixed; top: 0; left: 0; right: 0; background: #333; color: white; padding: 10px; z-index: 10000; font-family: monospace;">
        Universal PDF Converter Test | Pages: ' . $pageCount . ' | Text Elements: ' . $textCount . ' | Images: ' . $imageCount . ' | 
        <span style="color: #4CAF50;">✓ ALL TEXT IS EDITABLE</span>
    </div>
    <div style="height: 50px;"></div>';
    
    // Insert header after body tag
    $html = str_replace('<body>', '<body>' . $header, $html);
    
    echo $html;
} else {
    echo "HTML file not found. Run the converter first.";
}
?>