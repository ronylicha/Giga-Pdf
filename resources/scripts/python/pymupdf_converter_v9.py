#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os
from pdf2image import convert_from_path
from PIL import Image, ImageChops
import tempfile
import io

def pdf_to_html_perfect_extraction(pdf_path, img_dir):
    """Perfect extraction - renders graphics perfectly, text precisely positioned"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Ultra-precise styles with tight text selection
    html_parts.append("""
    <style>
        * {
            box-sizing: border-box;
        }
        
        .pdf-page-container {
            position: relative;
            margin: 20px auto;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .pdf-graphic-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }
        
        .pdf-graphic {
            position: absolute;
            z-index: 1;
        }
        
        .pdf-text {
            position: absolute;
            background: transparent;
            padding: 0 !important;
            margin: 0 !important;
            border: none !important;
            cursor: text;
            line-height: 1 !important;
            z-index: 2;
            display: inline-block;
            width: auto !important;
            height: auto !important;
            box-sizing: content-box !important;
        }
        
        .pdf-text:hover {
            background: rgba(255,255,0,0.1) !important;
            outline: none !important;
        }
        
        .pdf-text:focus {
            background: rgba(255,255,204,0.2) !important;
            outline: 1px solid rgba(0,123,255,0.5) !important;
            outline-offset: 0 !important;
        }
    </style>
    """)
    
    # Convert PDF to images using pdf2image for perfect graphic capture
    try:
        # Use pdf2image to get high quality page images
        pages_pil = convert_from_path(pdf_path, dpi=150)
    except:
        # Fallback to PyMuPDF rendering if pdf2image fails
        pages_pil = []
        for page_num in range(len(doc)):
            page = doc[page_num]
            mat = fitz.Matrix(150/72, 150/72)  # 150 DPI
            pix = page.get_pixmap(matrix=mat, alpha=False)
            img_data = pix.tobytes("png")
            img = Image.open(io.BytesIO(img_data))
            pages_pil.append(img)
            pix = None
    
    for page_num, page in enumerate(doc):
        # Get page dimensions
        rect = page.rect
        width = rect.width
        height = rect.height
        
        # Start page container
        page_html = f"""
        <div class="pdf-page-container" style="width: {width}px; height: {height}px;">
            <div class="pdf-graphic-layer">
        """
        html_parts.append(page_html)
        
        # METHOD 1: Extract background without text using masking
        if page_num < len(pages_pil):
            # Get the full page image
            full_page_img = pages_pil[page_num]
            
            # Create a version without text by masking text areas
            temp_doc = fitz.open()
            temp_doc.insert_pdf(doc, from_page=page_num, to_page=page_num)
            temp_page = temp_doc[0]
            
            # Get text blocks
            text_blocks = page.get_text("dict")["blocks"]
            
            # Apply white rectangles over text areas
            for block in text_blocks:
                if block["type"] == 0:  # Text block
                    bbox = fitz.Rect(block["bbox"])
                    # Draw white rectangle over text
                    temp_page.draw_rect(bbox, color=(1, 1, 1), fill=(1, 1, 1))
            
            # Render the masked page
            mat = fitz.Matrix(150/72, 150/72)  # 150 DPI
            pix = temp_page.get_pixmap(matrix=mat, alpha=False)
            
            # Save as background
            bg_filename = f"page_{page_num + 1}_graphics.png"
            bg_path = os.path.join(img_dir, bg_filename)
            pix.save(bg_path)
            
            # Add background image
            scale = 72/150  # Scale from 150 DPI back to 72 DPI
            img_width = full_page_img.width * scale
            img_height = full_page_img.height * scale
            
            bg_html = f"""
            <img src="{bg_filename}" 
                 class="pdf-graphic" 
                 style="left: 0; 
                        top: 0; 
                        width: {img_width}px; 
                        height: {img_height}px;
                        position: absolute;
                        z-index: 0;" />
            """
            html_parts.append(bg_html)
            
            temp_doc.close()
            pix = None
        
        # METHOD 2: Extract individual images that might be overlaid
        image_list = page.get_images(full=True)
        
        for img_index, img in enumerate(image_list):
            try:
                xref = img[0]
                
                # Skip small images (likely decorative elements already in background)
                img_rects = page.get_image_rects(xref)
                for rect_idx, img_rect in enumerate(img_rects):
                    if img_rect.width > 30 and img_rect.height > 30:
                        # Extract larger images as separate elements
                        pix = fitz.Pixmap(doc, xref)
                        
                        if pix.n - pix.alpha >= 4:  # CMYK
                            pix = fitz.Pixmap(fitz.csRGB, pix)
                        
                        img_filename = f"page_{page_num + 1}_img_{img_index}_{rect_idx}.png"
                        img_path = os.path.join(img_dir, img_filename)
                        pix.save(img_path)
                        
                        # Add as overlay image (might be interactive)
                        img_html = f"""
                        <img src="{img_filename}" 
                             class="pdf-graphic" 
                             style="left: {img_rect.x0}px; 
                                    top: {img_rect.y0}px; 
                                    width: {img_rect.width}px; 
                                    height: {img_rect.height}px;
                                    z-index: 1;" />
                        """
                        html_parts.append(img_html)
                        
                        pix = None
                        
            except Exception as e:
                print(f"<!-- Error extracting image {img_index}: {e} -->", file=sys.stderr)
        
        # Close graphic layer
        html_parts.append("</div>")
        
        # METHOD 3: Extract text with ultra-precise positioning
        blocks = page.get_text("dict")
        
        for block in blocks["blocks"]:
            if block["type"] == 0:  # Text block
                for line in block["lines"]:
                    for span in line["spans"]:
                        text = span["text"].strip()
                        if text:
                            # Get EXACT bounding box
                            bbox = span["bbox"]
                            x = bbox[0]
                            y = bbox[1]
                            # Calculate exact width and height
                            text_width = bbox[2] - bbox[0]
                            text_height = bbox[3] - bbox[1]
                            
                            font_size = span["size"]
                            color = span["color"]
                            font_name = span.get("font", "")
                            flags = span.get("flags", 0)
                            
                            color_hex = f"#{color:06x}"
                            font_weight = "bold" if (flags & 2**4) or "bold" in font_name.lower() else "normal"
                            font_style = "italic" if (flags & 2**1) or "italic" in font_name.lower() else "normal"
                            
                            # Escape HTML
                            text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                            text = text.replace('"', "&quot;").replace("'", "&#39;")
                            
                            # Create text element with EXACT dimensions
                            text_html = f"""<span contenteditable="true" class="pdf-text" style="position:absolute;left:{x}px;top:{y}px;font-size:{font_size}px;color:{color_hex};font-weight:{font_weight};font-style:{font_style};font-family:Arial,sans-serif;display:inline-block">{text}</span>"""
                            html_parts.append(text_html)
        
        html_parts.append("</div>")
    
    doc.close()
    return "".join(html_parts)

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("<div>Error: Usage: script.py pdf_file image_dir</div>")
        sys.exit(1)
    
    pdf_file = sys.argv[1]
    img_dir = sys.argv[2]
    
    os.makedirs(img_dir, exist_ok=True)
    
    try:
        html = pdf_to_html_perfect_extraction(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        import traceback
        traceback.print_exc(file=sys.stderr)
        sys.exit(1)