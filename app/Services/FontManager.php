<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use TCPDF_FONTS;

class FontManager
{
    protected $tcpdfFontsPath;
    protected $customFontsPath;
    protected $fontUrls = [
        'arial' => [
            'regular' => 'https://github.com/matomo-org/travis-scripts/raw/master/fonts/Arial.ttf',
            'bold' => 'https://github.com/matomo-org/travis-scripts/raw/master/fonts/Arial-Bold.ttf',
            'italic' => 'https://github.com/matomo-org/travis-scripts/raw/master/fonts/Arial-Italic.ttf',
            'bolditalic' => 'https://github.com/matomo-org/travis-scripts/raw/master/fonts/Arial-Bold-Italic.ttf',
        ],
        'times' => [
            'regular' => 'https://github.com/matomo-org/travis-scripts/raw/master/fonts/Times-New-Roman.ttf',
            'bold' => 'https://github.com/matomo-org/travis-scripts/raw/master/fonts/Times-New-Roman-Bold.ttf',
            'italic' => 'https://github.com/matomo-org/travis-scripts/raw/master/fonts/Times-New-Roman-Italic.ttf',
            'bolditalic' => 'https://github.com/matomo-org/travis-scripts/raw/master/fonts/Times-New-Roman-Bold-Italic.ttf',
        ],
        'calibri' => [
            'regular' => 'https://github.com/jtreminio/calibri-font/raw/master/calibri-regular.ttf',
            'bold' => 'https://github.com/jtreminio/calibri-font/raw/master/calibri-bold.ttf',
            'italic' => 'https://github.com/jtreminio/calibri-font/raw/master/calibri-italic.ttf',
            'bolditalic' => 'https://github.com/jtreminio/calibri-font/raw/master/calibri-bold-italic.ttf',
        ],
    ];

    protected $fontAliases = [
        'arial' => ['Arial', 'arial', 'ARIAL'],
        'times' => ['Times', 'Times New Roman', 'TimesNewRoman', 'times', 'TIMES'],
        'helvetica' => ['Helvetica', 'helvetica', 'HELVETICA'],
        'courier' => ['Courier', 'Courier New', 'CourierNew', 'courier', 'COURIER'],
        'calibri' => ['Calibri', 'calibri', 'CALIBRI'],
        'dejavusans' => ['DejaVu Sans', 'DejaVuSans', 'dejavusans'],
        'freesans' => ['FreeSans', 'freesans'],
    ];

    public function __construct()
    {
        $this->tcpdfFontsPath = base_path('vendor/tecnickcom/tcpdf/fonts');
        $this->customFontsPath = storage_path('app/fonts/tcpdf');

        // Créer le répertoire des polices personnalisées s'il n'existe pas
        if (! file_exists($this->customFontsPath)) {
            mkdir($this->customFontsPath, 0755, true);
        }
    }

    /**
     * Get the appropriate font name for TCPDF
     */
    public function getFontName($requestedFont, $style = '')
    {
        // Normaliser le nom de la police
        $normalizedFont = strtolower(trim($requestedFont));

        // Chercher dans les alias
        foreach ($this->fontAliases as $tcpdfFont => $aliases) {
            foreach ($aliases as $alias) {
                if (strtolower($alias) === $normalizedFont) {
                    return $this->ensureFontExists($tcpdfFont, $style);
                }
            }
        }

        // Si pas trouvé dans les alias, essayer de télécharger
        if ($this->downloadFont($normalizedFont)) {
            return $normalizedFont;
        }

        // Fallback sur une police par défaut
        return $this->getDefaultFont();
    }

    /**
     * Ensure font exists in TCPDF
     */
    protected function ensureFontExists($fontName, $style = '')
    {
        // Vérifier si la police existe déjà dans TCPDF
        $fontFile = $this->tcpdfFontsPath . '/' . $fontName . '.php';
        if (file_exists($fontFile)) {
            return $fontName;
        }

        // Vérifier dans les polices personnalisées
        $customFontFile = $this->customFontsPath . '/' . $fontName . '.php';
        if (file_exists($customFontFile)) {
            return $fontName;
        }

        // Essayer de télécharger et installer la police
        if ($this->downloadAndInstallFont($fontName, $style)) {
            return $fontName;
        }

        // Retourner une police par défaut
        return $this->getDefaultFont();
    }

    /**
     * Download and install font for TCPDF
     */
    protected function downloadAndInstallFont($fontName, $style = '')
    {
        if (! isset($this->fontUrls[$fontName])) {
            return false;
        }

        try {
            $urls = $this->fontUrls[$fontName];
            $styleKey = $this->getStyleKey($style);

            if (! isset($urls[$styleKey])) {
                $styleKey = 'regular';
            }

            $url = $urls[$styleKey];
            $tempFile = tempnam(sys_get_temp_dir(), 'font_') . '.ttf';

            // Télécharger la police
            $response = Http::timeout(30)->get($url);

            if ($response->successful()) {
                file_put_contents($tempFile, $response->body());

                // Convertir et installer la police pour TCPDF
                $tcpdfFonts = new TCPDF_FONTS();
                $fontname = $tcpdfFonts->addTTFfont($tempFile, 'TrueTypeUnicode', '', 32, $this->customFontsPath . '/');

                // Nettoyer le fichier temporaire
                unlink($tempFile);

                Log::info("Font installed successfully: {$fontname}");

                return true;
            }
        } catch (Exception $e) {
            Log::error("Failed to download/install font {$fontName}: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Download font from URL
     */
    protected function downloadFont($fontName)
    {
        // Chercher des sources de polices en ligne
        $searchUrls = [
            "https://www.googleapis.com/webfonts/v1/webfonts?key=YOUR_API_KEY&family={$fontName}",
            // Ajouter d'autres sources si nécessaire
        ];

        // Pour l'instant, retourner false si la police n'est pas dans notre liste
        return false;
    }

    /**
     * Get style key from style string
     */
    protected function getStyleKey($style)
    {
        $style = strtolower($style);

        if (strpos($style, 'b') !== false && strpos($style, 'i') !== false) {
            return 'bolditalic';
        } elseif (strpos($style, 'b') !== false) {
            return 'bold';
        } elseif (strpos($style, 'i') !== false) {
            return 'italic';
        }

        return 'regular';
    }

    /**
     * Get default font that exists in TCPDF
     */
    protected function getDefaultFont()
    {
        // Utiliser helvetica comme police par défaut (intégrée dans TCPDF)
        return 'helvetica';
    }

    /**
     * Install common fonts
     */
    public function installCommonFonts()
    {
        $fonts = ['arial', 'times', 'calibri'];
        $installed = [];

        foreach ($fonts as $font) {
            if ($this->downloadAndInstallFont($font, '')) {
                $installed[] = $font;

                // Installer aussi les variantes
                $this->downloadAndInstallFont($font, 'B');
                $this->downloadAndInstallFont($font, 'I');
                $this->downloadAndInstallFont($font, 'BI');
            }
        }

        return $installed;
    }

    /**
     * List available fonts
     */
    public function getAvailableFonts()
    {
        $fonts = [];

        // Polices TCPDF natives
        $tcpdfFonts = ['helvetica', 'times', 'courier', 'dejavusans', 'freesans'];
        foreach ($tcpdfFonts as $font) {
            $fontFile = $this->tcpdfFontsPath . '/' . $font . '.php';
            if (file_exists($fontFile)) {
                $fonts[] = $font;
            }
        }

        // Polices personnalisées
        if (is_dir($this->customFontsPath)) {
            $files = scandir($this->customFontsPath);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $fontName = pathinfo($file, PATHINFO_FILENAME);
                    if (! in_array($fontName, $fonts)) {
                        $fonts[] = $fontName;
                    }
                }
            }
        }

        return $fonts;
    }
}
