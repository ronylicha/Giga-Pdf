#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os

def pdf_to_perfect_html(pdf_path, img_dir):
    """Convert PDF to pixel-perfect HTML with background only (no text)"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    for page_num, page in enumerate(doc):
        # Get page dimensions
        rect = page.rect
        width = rect.width
        height = rect.height
        
        # Method 1: Use redaction to remove all text before rendering
        # Get all text instances
        text_instances = page.get_text("dict")
        
        # Create a copy of the page to work with
        temp_doc = fitz.open()
        temp_doc.insert_pdf(doc, from_page=page_num, to_page=page_num)
        temp_page = temp_doc[0]
        
        # Apply redaction to remove all text
        for block in text_instances["blocks"]:
            if block["type"] == 0:  # Text block
                for line in block["lines"]:
                    for span in line["spans"]:
                        # Get the bounding box of the text
                        bbox = fitz.Rect(span["bbox"])
                        # Add slightly larger redaction area to ensure complete removal
                        bbox.x0 -= 1
                        bbox.y0 -= 1
                        bbox.x1 += 1
                        bbox.y1 += 1
                        # Add redaction annotation
                        temp_page.add_redact_annot(bbox)
        
        # Apply all redactions (this removes the text and fills with white)
        # Use fill color matching the page background
        temp_page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
        
        # Now render the page without text
        mat = fitz.Matrix(3, 3)  # 3x zoom for excellent quality
        pix = temp_page.get_pixmap(matrix=mat, alpha=False, colorspace="rgb")
        
        # Save image to file
        img_filename = f"page_{page_num + 1}_bg.png"
        img_path = os.path.join(img_dir, img_filename)
        pix.save(img_path)
        
        # Calculate scaled dimensions
        scaled_width = width * 3
        scaled_height = height * 3
        
        # Start page HTML
        page_html = f"""
        <div class="pdf-page-container" style="position: relative; margin: 20px auto; width: {width}px; height: {height}px; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden;">
            <!-- PDF background without text (logos, images, borders only) -->
            <img src="{img_filename}" class="pdf-page-background" style="position: absolute; top: 0; left: 0; width: {scaled_width}px; height: {scaled_height}px; transform: scale(0.333333); transform-origin: top left; pointer-events: none; user-select: none; z-index: 1;" />
            
            <!-- Editable text layer -->
            <div class="text-layer" style="position: absolute; top: 0; left: 0; width: {width}px; height: {height}px; z-index: 2;">
        """
        html_parts.append(page_html)
        
        # Extract text blocks with exact positioning from ORIGINAL page
        blocks = page.get_text("dict")
        
        for block in blocks["blocks"]:
            if block["type"] == 0:  # Text block
                for line in block["lines"]:
                    for span in line["spans"]:
                        text = span["text"].strip()
                        if text:
                            # Get exact position and style
                            x = span["bbox"][0]
                            y = span["bbox"][1]
                            font_size = span["size"]
                            font_name = span["font"]
                            color = span["color"]
                            
                            # Convert color to hex
                            color_hex = f"#{color:06x}"
                            
                            # Check for bold/italic
                            font_weight = "bold" if "bold" in font_name.lower() else "normal"
                            font_style = "italic" if "italic" in font_name.lower() else "normal"
                            
                            # Escape HTML special characters in text
                            text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace('"', "&quot;").replace("'", "&#39;")
                            
                            # Create editable text overlay (now visible since background has no text)
                            text_html = f"""
                                <div contenteditable="true" 
                                     style="position: absolute; 
                                            left: {x}px; 
                                            top: {y}px; 
                                            font-size: {font_size}px; 
                                            color: {color_hex}; 
                                            font-weight: {font_weight}; 
                                            font-style: {font_style};
                                            font-family: Arial, sans-serif;
                                            background: transparent;
                                            padding: 0 2px;
                                            border: 1px solid transparent;
                                            cursor: text;
                                            white-space: nowrap;
                                            line-height: 1.2;
                                            z-index: 3;"
                                     onmouseover="this.style.background='rgba(255,255,0,0.2)'; this.style.border='1px solid #007bff'"
                                     onmouseout="this.style.background='transparent'; this.style.border='1px solid transparent'"
                                     onfocus="this.style.background='rgba(255,255,204,0.5)'; this.style.border='2px solid #007bff'; this.style.outline='none'"
                                     onblur="this.style.background='transparent'; this.style.border='1px solid transparent'">
                                    {text}
                                </div>
                            """
                            html_parts.append(text_html)
        
        closing_html = """
            </div>
        </div>
        """
        html_parts.append(closing_html)
        
        # Clean up temp doc
        temp_doc.close()
    
    doc.close()
    return "".join(html_parts)

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("<div>Error: Usage: script.py pdf_file image_dir</div>")
        sys.exit(1)
    
    pdf_file = sys.argv[1]
    img_dir = sys.argv[2]
    
    try:
        html = pdf_to_perfect_html(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)