#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os
import base64

def pdf_to_html_with_individual_elements(pdf_path, img_dir):
    """Convert PDF to HTML with individual images and text elements (not as background)"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Start HTML document
    html_parts.append("""
    <style>
        .pdf-page-container {
            position: relative;
            margin: 0 auto;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .pdf-image {
            position: absolute;
            pointer-events: auto;
            z-index: 1;
        }
        
        .pdf-text {
            position: absolute;
            background: transparent;
            padding: 0 2px;
            border: 1px solid transparent;
            cursor: text;
            white-space: nowrap;
            line-height: 1.2;
            z-index: 2;
        }
        
        .pdf-text:hover {
            background: rgba(255,255,0,0.2);
            border: 1px solid #007bff;
        }
        
        .pdf-text:focus {
            background: rgba(255,255,204,0.5);
            border: 2px solid #007bff;
            outline: none;
        }
        
        .pdf-table {
            position: absolute;
            border-collapse: collapse;
            z-index: 2;
        }
        
        .pdf-table td {
            border: 1px solid #ccc;
            padding: 2px 4px;
        }
    </style>
    """)
    
    for page_num, page in enumerate(doc):
        # Get page dimensions
        rect = page.rect
        width = rect.width
        height = rect.height
        
        # Start page container
        page_html = f"""
        <div class="pdf-page-container" id="page_{page_num + 1}" style="width: {width}px; height: {height}px;">
        """
        html_parts.append(page_html)
        
        # Extract and add images as individual elements
        image_list = page.get_images(full=True)
        
        for img_index, img in enumerate(image_list):
            try:
                # Get image data
                xref = img[0]
                pix = fitz.Pixmap(doc, xref)
                
                # Convert to RGB if necessary
                if pix.n - pix.alpha >= 4:  # CMYK
                    pix = fitz.Pixmap(fitz.csRGB, pix)
                
                # Get image position on page
                img_rects = page.get_image_rects(xref)
                
                if img_rects:
                    # Save image as file
                    img_filename = f"page_{page_num + 1}_img_{img_index}.png"
                    img_path = os.path.join(img_dir, img_filename)
                    pix.save(img_path)
                    
                    # Add each image position
                    for rect_idx, img_rect in enumerate(img_rects):
                        x = img_rect.x0
                        y = img_rect.y0
                        w = img_rect.width
                        h = img_rect.height
                        
                        # Add image as individual element
                        img_html = f"""
                        <img src="{img_filename}" 
                             class="pdf-image" 
                             data-original="true"
                             style="left: {x}px; 
                                    top: {y}px; 
                                    width: {w}px; 
                                    height: {h}px;"
                             alt="Image {img_index}" />
                        """
                        html_parts.append(img_html)
                
                pix = None
                
            except Exception as e:
                print(f"<!-- Error extracting image: {e} -->", file=sys.stderr)
        
        # Extract tables if present
        tables = extract_tables_from_page(page)
        for table in tables:
            html_parts.append(table)
        
        # Extract text elements
        blocks = page.get_text("dict")
        
        for block in blocks["blocks"]:
            if block["type"] == 0:  # Text block
                # Check if this text is part of a table (skip if so)
                block_rect = fitz.Rect(block["bbox"])
                is_in_table = False
                
                # Simple heuristic: if many text blocks are aligned, it might be a table
                # For now, we'll add all text as editable
                
                for line in block["lines"]:
                    for span in line["spans"]:
                        text = span.get("text", "").strip()
                        if text:
                            # Get exact position and style with safe defaults
                            bbox = span.get("bbox", [0, 0, 0, 0])
                            x = bbox[0] if len(bbox) > 0 else 0
                            y = bbox[1] if len(bbox) > 1 else 0
                            font_size = span.get("size", 12)
                            font_name = span.get("font", "Arial")
                            color = span.get("color", 0)
                            
                            # Convert color to hex (handle different color formats)
                            if isinstance(color, int):
                                color_hex = f"#{color:06x}"
                            elif isinstance(color, (list, tuple)) and len(color) >= 3:
                                color_hex = f"#{int(color[0]*255):02x}{int(color[1]*255):02x}{int(color[2]*255):02x}"
                            else:
                                color_hex = "#000000"
                            
                            # Check for bold/italic (handle None font_name)
                            if font_name:
                                font_weight = "bold" if "bold" in font_name.lower() else "normal"
                                font_style = "italic" if "italic" in font_name.lower() else "normal"
                            else:
                                font_weight = "normal"
                                font_style = "normal"
                            
                            # Escape HTML special characters
                            text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                            text = text.replace('"', "&quot;").replace("'", "&#39;")
                            
                            # Create editable text element
                            text_html = f"""
                            <div contenteditable="true" 
                                 class="pdf-text"
                                 data-original="true"
                                 style="left: {x}px; 
                                        top: {y}px; 
                                        font-size: {font_size}px; 
                                        color: {color_hex}; 
                                        font-weight: {font_weight}; 
                                        font-style: {font_style};
                                        font-family: Arial, sans-serif;">
                                {text}
                            </div>
                            """
                            html_parts.append(text_html)
        
        # Extract vector graphics as SVG (if any)
        drawings = page.get_drawings()
        if drawings:
            svg_html = render_drawings_as_svg(drawings, page_num)
            html_parts.append(svg_html)
        
        # Close page container
        html_parts.append("</div>")
    
    doc.close()
    return "".join(html_parts)

def extract_tables_from_page(page):
    """Extract tables from a PDF page"""
    tables = []
    
    # Use PyMuPDF's table detection (if available in newer versions)
    # For now, we'll use a simple heuristic based on line positions
    
    try:
        # Get all lines on the page
        paths = page.get_drawings()
        
        # Group horizontal and vertical lines
        h_lines = []
        v_lines = []
        
        for path in paths:
            items = path.get("items", [])
            for item in items:
                if len(item) > 2 and item[0] == "l":  # Line
                    p1, p2 = item[1], item[2]
                    if hasattr(p1, 'y') and hasattr(p2, 'y'):
                        if abs(p1.y - p2.y) < 1:  # Horizontal line
                            h_lines.append((min(p1.x, p2.x), p1.y, max(p1.x, p2.x)))
                        elif abs(p1.x - p2.x) < 1:  # Vertical line
                            v_lines.append((p1.x, min(p1.y, p2.y), max(p1.y, p2.y)))
    except Exception as e:
        # If drawing extraction fails, just return empty tables
        pass
    
    # If we have a grid of lines, we might have a table
    # This is a simplified approach - a full implementation would be more complex
    
    return tables

def render_drawings_as_svg(drawings, page_num):
    """Render vector drawings as SVG"""
    svg_parts = []
    
    try:
        for drawing in drawings:
            items = drawing.get("items", [])
            # Skip if it's just a rectangle (might be a border)
            if len(items) == 1 and items[0][0] == "re":
                continue
            
            # Convert drawing to SVG path
            path_data = []
            for item in items:
                if len(item) < 1:
                    continue
                cmd = item[0]
                if cmd == "l" and len(item) >= 3:  # Line
                    p1, p2 = item[1], item[2]
                    if hasattr(p1, 'x') and hasattr(p1, 'y'):
                        path_data.append(f"M {p1.x} {p1.y} L {p2.x} {p2.y}")
                elif cmd == "c" and len(item) >= 4:  # Curve
                    p1, p2, p3 = item[1], item[2], item[3]
                    if hasattr(p1, 'x') and hasattr(p1, 'y'):
                        path_data.append(f"C {p1.x} {p1.y}, {p2.x} {p2.y}, {p3.x} {p3.y}")
            
            if path_data:
                color = drawing.get("color", [0, 0, 0])
                if isinstance(color, (list, tuple)) and len(color) >= 3:
                    color_hex = f"#{int(color[0]*255):02x}{int(color[1]*255):02x}{int(color[2]*255):02x}"
                else:
                    color_hex = "#000000"
                
                svg_html = f"""
                <svg style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none;">
                    <path d="{' '.join(path_data)}" 
                          stroke="{color_hex}" 
                          fill="none" 
                          stroke-width="{drawing.get('width', 1)}" />
                </svg>
                """
                svg_parts.append(svg_html)
    except Exception as e:
        # If drawing extraction fails, just skip
        pass
    
    return "".join(svg_parts)

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("<div>Error: Usage: script.py pdf_file image_dir</div>")
        sys.exit(1)
    
    pdf_file = sys.argv[1]
    img_dir = sys.argv[2]
    
    try:
        html = pdf_to_html_with_individual_elements(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)