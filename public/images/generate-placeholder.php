<?php
// Script pour générer une image placeholder pour les PDF
$width = 300;
$height = 400;

// Créer une nouvelle image
$image = imagecreatetruecolor($width, $height);

// Couleurs
$background = imagecolorallocate($image, 245, 245, 245); // Gris clair
$borderColor = imagecolorallocate($image, 200, 200, 200); // Gris
$iconColor = imagecolorallocate($image, 150, 150, 150); // Gris foncé
$textColor = imagecolorallocate($image, 100, 100, 100); // Gris très foncé

// Remplir le fond
imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);

// Dessiner une bordure
imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);

// Dessiner une icône de document simplifiée
$docWidth = 100;
$docHeight = 130;
$docX = ($width - $docWidth) / 2;
$docY = ($height - $docHeight) / 2 - 20;

// Corps du document
imagefilledrectangle($image, $docX, $docY, $docX + $docWidth, $docY + $docHeight, imagecolorallocate($image, 255, 255, 255));
imagerectangle($image, $docX, $docY, $docX + $docWidth, $docY + $docHeight, $iconColor);

// Coin plié du document
$foldSize = 20;
$points = [
    $docX + $docWidth - $foldSize, $docY,
    $docX + $docWidth, $docY + $foldSize,
    $docX + $docWidth - $foldSize, $docY + $foldSize,
];
imagefilledpolygon($image, $points, 3, $background);
imagepolygon($image, $points, 3, $iconColor);

// Lignes de texte simulées dans le document
$lineY = $docY + 30;
for ($i = 0; $i < 5; $i++) {
    $lineWidth = rand(60, 80);
    imagefilledrectangle($image, $docX + 10, $lineY, $docX + 10 + $lineWidth, $lineY + 2, $iconColor);
    $lineY += 15;
}

// Texte "PDF"
$fontSize = 5; // Font built-in size
$text = "PDF";
$textWidth = imagefontwidth($fontSize) * strlen($text);
$textX = ($width - $textWidth) / 2;
$textY = $docY + $docHeight + 20;
imagestring($image, $fontSize, $textX, $textY, $text, $textColor);

// Sauvegarder l'image
imagepng($image, '/var/www/html/giga-pdf/public/images/pdf-placeholder.png');
imagedestroy($image);

echo "Placeholder image created successfully!\n";