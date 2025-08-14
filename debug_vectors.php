<?php
// Script pour déboguer les images vectorielles

$htmlFile = '/var/www/html/giga-pdf/storage/app/documents/1/8nSnRQYZ0cc4xK4sNiLT8oSnrfbOLe7neeaHPFQ6_converted.html';

// Lire le contenu du fichier HTML s'il existe
if (file_exists($htmlFile)) {
    $html = file_get_contents($htmlFile);
    
    // Chercher toutes les images avec la classe pdf-vector
    preg_match_all('/<img[^>]*class=["\'][^"\']*pdf-vector[^"\']*["\'][^>]*>/i', $html, $vectorImages);
    
    echo "Images vectorielles trouvées: " . count($vectorImages[0]) . "\n\n";
    
    foreach ($vectorImages[0] as $i => $img) {
        echo "Vector " . ($i + 1) . ":\n";
        
        // Extraire le src
        if (preg_match('/src="([^"]+)"/', $img, $srcMatch)) {
            $src = $srcMatch[1];
            
            if (strpos($src, 'data:') === 0) {
                // C'est une data URL
                $dataUrlParts = explode(',', $src, 2);
                $header = $dataUrlParts[0];
                echo "  Type: Data URL\n";
                echo "  Header: " . $header . "\n";
                echo "  Data length: " . strlen($dataUrlParts[1] ?? '') . " chars\n";
            } else {
                echo "  Type: File URL\n";
                echo "  URL: " . $src . "\n";
            }
        } else {
            echo "  No src found!\n";
        }
        
        // Extraire le style
        if (preg_match('/style="([^"]+)"/', $img, $styleMatch)) {
            echo "  Style: " . substr($styleMatch[1], 0, 150) . "...\n";
        }
        
        echo "\n";
    }
} else {
    echo "HTML file not found. Looking for any HTML files...\n";
    $files = glob('/var/www/html/giga-pdf/storage/app/documents/1/*.html');
    foreach ($files as $file) {
        echo "Found: " . basename($file) . "\n";
    }
}

// Also check if there are any vector PNG files in the documents folder
echo "\n=== Checking for vector PNG files ===\n";
$vectorPngs = glob('/var/www/html/giga-pdf/storage/app/documents/*/p*_vec*.png');
echo "Vector PNG files found: " . count($vectorPngs) . "\n";
foreach ($vectorPngs as $png) {
    echo "  - " . basename($png) . " (size: " . filesize($png) . " bytes)\n";
}