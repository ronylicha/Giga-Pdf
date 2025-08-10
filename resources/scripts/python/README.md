# Scripts Python pour Giga-PDF

Ce dossier contient tous les scripts Python utilisés par l'application Giga-PDF pour les conversions et manipulations de PDF.

## Scripts disponibles

### Conversion PDF vers HTML
- `pymupdf_converter_v11.py` - Version 11 (dernière version)
- `pymupdf_converter_v10.py` - Version 10 (stable)
- `pymupdf_converter_v9.py` - Version 9
- `pymupdf_converter_v8.py` - Version 8
- `pymupdf_converter_v7.py` - Version 7
- `pymupdf_converter_v6.py` - Version 6
- `pymupdf_converter_v5.py` - Version 5
- `pymupdf_converter_v4.py` - Version 4
- `pymupdf_converter_v3.py` - Version 3
- `pymupdf_converter_v2.py` - Version 2
- `pymupdf_perfect.py` - Version optimisée pour la perfection

### Conversion HTML vers PDF
- `html_to_pdf.py` - Convertit du HTML en PDF avec support des images

### Manipulation PDF
- `crop_pdf.py` - Découpe et ajuste les marges des PDF

## Configuration

Les scripts sont référencés dans le fichier de configuration `/config/pdf_converter.php`.

## Dépendances Python

Les scripts nécessitent les packages Python suivants :
- PyMuPDF (fitz)
- Pillow (PIL)
- beautifulsoup4
- wkhtmltopdf (pour html_to_pdf.py)

Installation :
```bash
pip3 install PyMuPDF Pillow beautifulsoup4
sudo apt-get install wkhtmltopdf
```

## Utilisation

Les scripts sont appelés automatiquement par l'application via les services PHP :
- `App\Services\PdfToHtmlService` - Pour les conversions PDF vers HTML
- `App\Services\HTMLPDFEditor` - Pour l'édition et conversion HTML vers PDF

## Permissions

Assurez-vous que les scripts ont les permissions d'exécution :
```bash
chmod +x /var/www/html/giga-pdf/resources/scripts/python/*.py
```

## Tests

Pour tester un script manuellement :
```bash
python3 /var/www/html/giga-pdf/resources/scripts/python/pymupdf_converter_v10.py input.pdf output.html
```