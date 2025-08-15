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
    """Convert HTML to PDF preserving layout and images with individual positioning."""
    try:
        # Read HTML content
        with open(html_path, 'r', encoding='utf-8') as f:
            html_content = f.read()
        
        soup = BeautifulSoup(html_content, 'html.parser')
        
        # Remove page break markers before conversion
        # These are visual markers only for the editor, not for PDF export
        for marker in soup.find_all('div', {'class': 'pdf-page-break-marker'}):
            marker.decompose()
        
        # Remove page markers that are only for visual display
        for marker in soup.find_all('div', {'class': 'page-marker'}):
            marker.decompose()
        
        # Remove any toolbar or instruction elements
        for element in soup.find_all(['div', 'button'], {'class': ['toolbar', 'instructions', 'delete-btn', 'no-print']}):
            element.decompose()
        
        # Get page dimensions
        page_width, page_height = extract_page_dimensions(html_content)
        
        # Create new PDF document
        doc = fitz.open()
        
        # Find all page containers
        page_containers = soup.find_all('div', {'class': 'pdf-page-container'})
        
        if not page_containers:
            # Also look for alternative page containers
            page_containers = soup.find_all('div', {'id': re.compile(r'page-container|pdf-page')})
            
        if not page_containers:
            # If no page containers, treat whole body as one page
            page_containers = [soup.body] if soup.body else [soup]
        
        for page_div in page_containers:
            # Create a new page with the extracted dimensions
            page = doc.new_page(width=page_width, height=page_height)
            
            # Process all elements in order (images first as background, then text)
            # This ensures proper layering in the PDF
            
            # First pass: Extract and position all images individually
            # Look for images with various classes and tags
            images = page_div.find_all('img')
            for img in images:
                src = img.get('src', '')
                style = img.get('style', '')
                
                # Try to get position from style attribute
                x, y = 0, 0
                img_width, img_height = 100, 100
                
                # Check for absolute positioning in style
                if 'position' in style and 'absolute' in style:
                    # Extract position values (support px and %)
                    left_match = re.search(r'left:\s*(\d+\.?\d*)(px|%)', style)
                    top_match = re.search(r'top:\s*(\d+\.?\d*)(px|%)', style)
                    width_match = re.search(r'width:\s*(\d+\.?\d*)(px|%)', style)
                    height_match = re.search(r'height:\s*(\d+\.?\d*)(px|%)', style)
                    
                    if left_match:
                        value = float(left_match.group(1))
                        unit = left_match.group(2)
                        x = (value * page_width / 100) if unit == '%' else (value * 72 / 96)
                    
                    if top_match:
                        value = float(top_match.group(1))
                        unit = top_match.group(2)
                        y = (value * page_height / 100) if unit == '%' else (value * 72 / 96)
                    
                    if width_match:
                        value = float(width_match.group(1))
                        unit = width_match.group(2)
                        img_width = (value * page_width / 100) if unit == '%' else (value * 72 / 96)
                    
                    if height_match:
                        value = float(height_match.group(1))
                        unit = height_match.group(2)
                        img_height = (value * page_height / 100) if unit == '%' else (value * 72 / 96)
                else:
                    # Try to get from data attributes or calculate from parent
                    if img.get('data-x'):
                        x = float(img.get('data-x', 0)) * 72 / 96
                    if img.get('data-y'):
                        y = float(img.get('data-y', 0)) * 72 / 96
                    if img.get('width'):
                        img_width = float(img.get('width', 100)) * 72 / 96
                    if img.get('height'):
                        img_height = float(img.get('height', 100)) * 72 / 96
                
                # Create rectangle for image placement
                rect = fitz.Rect(x, y, x + img_width, y + img_height)
                
                # Handle different image sources
                if src.startswith('data:'):
                    try:
                        # Extract MIME type and base64 data
                        header, data = src.split(',', 1)
                        mime_match = re.search(r'data:([^;]+)', header)
                        mime_type = mime_match.group(1) if mime_match else 'image/png'
                        
                        # Determine file extension
                        ext = '.png'
                        if 'jpeg' in mime_type or 'jpg' in mime_type:
                            ext = '.jpg'
                        elif 'gif' in mime_type:
                            ext = '.gif'
                        elif 'bmp' in mime_type:
                            ext = '.bmp'
                        
                        img_data = base64.b64decode(data)
                        
                        # Save to temp file with proper extension
                        with tempfile.NamedTemporaryFile(suffix=ext, delete=False) as tmp:
                            tmp.write(img_data)
                            tmp_path = tmp.name
                        
                        # Insert image at exact position
                        page.insert_image(rect, filename=tmp_path)
                        
                        # Clean up temp file
                        os.unlink(tmp_path)
                    except Exception as e:
                        print(f"Error inserting data URI image: {e}", file=sys.stderr)
                
                elif src and (src.startswith('http://') or src.startswith('https://')):
                    # Handle remote images (skip for now, could download if needed)
                    print(f"Skipping remote image: {src}", file=sys.stderr)
                
                elif src and os.path.exists(src):
                    # Local file path
                    try:
                        page.insert_image(rect, filename=src)
                    except Exception as e:
                        print(f"Error inserting local image {src}: {e}", file=sys.stderr)
            
            # Second pass: Extract and position text elements
            text_elements = page_div.find_all(class_=['pdf-text', 'pdf-element', 'draggable-element'])
            
            # Also find any element with contenteditable or position:absolute
            for elem in page_div.find_all(True):
                if elem.name in ['img', 'script', 'style']:
                    continue
                
                style = elem.get('style', '')
                contenteditable = elem.get('contenteditable')
                
                # Check if this is a text element
                if (contenteditable == 'true' or 
                    'position:absolute' in style or 
                    'pdf-text' in elem.get('class', [])):
                    
                    text = elem.text.strip()
                    if text:
                        # Get position from style
                        left_match = re.search(r'left:\s*(\d+\.?\d*)(px|%)', style)
                        top_match = re.search(r'top:\s*(\d+\.?\d*)(px|%)', style)
                        
                        if left_match and top_match:
                            left_value = float(left_match.group(1))
                            left_unit = left_match.group(2)
                            top_value = float(top_match.group(1))
                            top_unit = top_match.group(2)
                            
                            x = (left_value * page_width / 100) if left_unit == '%' else (left_value * 72 / 96)
                            y = (top_value * page_height / 100) if top_unit == '%' else (top_value * 72 / 96)
                            
                            # Get font properties
                            font_size = 11
                            font_match = re.search(r'font-size:\s*(\d+\.?\d*)(px|pt)', style)
                            if font_match:
                                size_value = float(font_match.group(1))
                                size_unit = font_match.group(2)
                                font_size = size_value if size_unit == 'pt' else (size_value * 72 / 96)
                            
                            # Get font color
                            color = (0, 0, 0)  # Default black
                            color_match = re.search(r'color:\s*rgb\((\d+),\s*(\d+),\s*(\d+)\)', style)
                            if color_match:
                                color = (
                                    int(color_match.group(1)) / 255,
                                    int(color_match.group(2)) / 255,
                                    int(color_match.group(3)) / 255
                                )
                            
                            # Insert text at exact position
                            try:
                                page.insert_text(
                                    (x, y + font_size),  # Adjust y for baseline
                                    text,
                                    fontsize=font_size,
                                    color=color
                                )
                            except Exception as e:
                                print(f"Error inserting text: {e}", file=sys.stderr)
        
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