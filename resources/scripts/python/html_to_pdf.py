#!/usr/bin/env python3
import sys
import os
import re
import base64
from pathlib import Path
import fitz  # PyMuPDF
from bs4 import BeautifulSoup
import tempfile

def extract_page_dimensions(html):
    """Extract page dimensions from HTML meta tags or style."""
    soup = BeautifulSoup(html, 'html.parser')
    
    # Try to get from meta tags
    width_meta = soup.find('meta', {'name': 'page-width'})
    height_meta = soup.find('meta', {'name': 'page-height'})
    
    if width_meta and height_meta:
        width = float(width_meta.get('content', 595))  # Default A4 width in points
        height = float(height_meta.get('content', 842))  # Default A4 height in points
        # Convert pixels to points (72 points = 1 inch, assuming 96 DPI for pixels)
        width = width * 72 / 96
        height = height * 72 / 96
        return width, height
    
    # Try to get from first page container
    page_container = soup.find('div', {'class': 'pdf-page-container'})
    if page_container and page_container.get('style'):
        style = page_container['style']
        width_match = re.search(r'width:\s*(\d+\.?\d*)px', style)
        height_match = re.search(r'height:\s*(\d+\.?\d*)px', style)
        if width_match and height_match:
            width = float(width_match.group(1)) * 72 / 96
            height = float(height_match.group(1)) * 72 / 96
            return width, height
    
    # Default A4 size in points
    return 595, 842

def html_to_pdf_with_content(html_path, output_path, original_pdf_path=None):
    """Convert HTML to PDF preserving layout and images."""
    try:
        # Read HTML content
        with open(html_path, 'r', encoding='utf-8') as f:
            html_content = f.read()
        
        soup = BeautifulSoup(html_content, 'html.parser')
        
        # Get page dimensions
        page_width, page_height = extract_page_dimensions(html_content)
        
        # Create new PDF document
        doc = fitz.open()
        
        # Find all page containers
        page_containers = soup.find_all('div', {'class': 'pdf-page-container'})
        
        if not page_containers:
            # If no page containers, treat whole body as one page
            page_containers = [soup.body] if soup.body else [soup]
        
        for page_div in page_containers:
            # Create a new page with the extracted dimensions
            page = doc.new_page(width=page_width, height=page_height)
            
            # Extract text elements
            text_elements = page_div.find_all(class_=['pdf-text', 'pdf-element'])
            for elem in text_elements:
                if elem.text.strip():
                    # Get position from style
                    style = elem.get('style', '')
                    left_match = re.search(r'left:\s*(\d+\.?\d*)px', style)
                    top_match = re.search(r'top:\s*(\d+\.?\d*)px', style)
                    
                    if left_match and top_match:
                        x = float(left_match.group(1)) * 72 / 96
                        y = float(top_match.group(1)) * 72 / 96
                        
                        # Get font size if available
                        font_size = 11
                        font_match = re.search(r'font-size:\s*(\d+\.?\d*)px', style)
                        if font_match:
                            font_size = float(font_match.group(1)) * 72 / 96
                        
                        # Insert text at position
                        try:
                            page.insert_text(
                                (x, y + font_size),  # Adjust y for baseline
                                elem.text.strip(),
                                fontsize=font_size
                            )
                        except:
                            pass
            
            # Handle images
            images = page_div.find_all('img')
            for img in images:
                src = img.get('src', '')
                style = img.get('style', '')
                
                # Get position and size
                left_match = re.search(r'left:\s*(\d+\.?\d*)px', style)
                top_match = re.search(r'top:\s*(\d+\.?\d*)px', style)
                width_match = re.search(r'width:\s*(\d+\.?\d*)px', style)
                height_match = re.search(r'height:\s*(\d+\.?\d*)px', style)
                
                if left_match and top_match:
                    x = float(left_match.group(1)) * 72 / 96
                    y = float(top_match.group(1)) * 72 / 96
                    
                    img_width = float(width_match.group(1)) * 72 / 96 if width_match else 100
                    img_height = float(height_match.group(1)) * 72 / 96 if height_match else 100
                    
                    rect = fitz.Rect(x, y, x + img_width, y + img_height)
                    
                    # Handle data URIs
                    if src.startswith('data:'):
                        try:
                            # Extract base64 data
                            header, data = src.split(',', 1)
                            img_data = base64.b64decode(data)
                            
                            # Save to temp file
                            with tempfile.NamedTemporaryFile(suffix='.png', delete=False) as tmp:
                                tmp.write(img_data)
                                tmp_path = tmp.name
                            
                            # Insert image
                            page.insert_image(rect, filename=tmp_path)
                            
                            # Clean up
                            os.unlink(tmp_path)
                        except Exception as e:
                            print(f"Error inserting image: {e}", file=sys.stderr)
                    elif os.path.exists(src):
                        # Local file
                        try:
                            page.insert_image(rect, filename=src)
                        except:
                            pass
        
        # If we have an original PDF and no pages were created, copy from original
        if len(doc) == 0 and original_pdf_path and os.path.exists(original_pdf_path):
            orig_doc = fitz.open(original_pdf_path)
            for orig_page in orig_doc:
                new_page = doc.new_page(width=orig_page.rect.width, height=orig_page.rect.height)
                new_page.show_pdf_page(new_page.rect, orig_doc, orig_page.number)
            orig_doc.close()
        
        # Save the PDF
        doc.save(output_path, garbage=4, deflate=True, clean=True)
        doc.close()
        
        return True
        
    except Exception as e:
        print(f"Error converting HTML to PDF: {e}", file=sys.stderr)
        return False

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python html_to_pdf.py <html_file> <output_pdf> [original_pdf]", file=sys.stderr)
        sys.exit(1)
    
    html_file = sys.argv[1]
    output_pdf = sys.argv[2]
    original_pdf = sys.argv[3] if len(sys.argv) > 3 else None
    
    if not os.path.exists(html_file):
        print(f"Error: HTML file not found at {html_file}", file=sys.stderr)
        sys.exit(1)
    
    success = html_to_pdf_with_content(html_file, output_pdf, original_pdf)
    
    if success:
        print(f"Successfully converted '{html_file}' to '{output_pdf}'")
        sys.exit(0)
    else:
        sys.exit(1)