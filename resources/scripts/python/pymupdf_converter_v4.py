#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os
import base64

def pdf_to_html_complete(pdf_path, img_dir):
    """Convert PDF to HTML with COMPLETE visual preservation (background + individual elements)"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Start HTML document with styles
    html_parts.append("""
    <style>
        .pdf-page-container {
            position: relative;
            margin: 20px auto;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .pdf-page-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
            user-select: none;
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
            z-index: 3;
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
        
        .text-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2;
        }
    </style>
    """)
    
    for page_num, page in enumerate(doc):
        # Get page dimensions
        rect = page.rect
        width = rect.width
        height = rect.height
        
        # STEP 1: Render the COMPLETE page as background (with text removed)
        # This captures ALL visual elements including backgrounds, borders, logos, etc.
        
        # Create a copy of the page for background
        temp_doc = fitz.open()
        temp_doc.insert_pdf(doc, from_page=page_num, to_page=page_num)
        temp_page = temp_doc[0]
        
        # Get all text instances to remove them from background
        text_instances = page.get_text("dict")
        
        # Remove text from the background version
        for block in text_instances["blocks"]:
            if block.get("type") == 0:  # Text block
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        bbox = span.get("bbox")
                        if bbox and len(bbox) >= 4:
                            # Create rect for redaction
                            redact_rect = fitz.Rect(bbox)
                            # Slightly expand to ensure complete removal
                            redact_rect.x0 -= 0.5
                            redact_rect.y0 -= 0.5
                            redact_rect.x1 += 0.5
                            redact_rect.y1 += 0.5
                            temp_page.add_redact_annot(redact_rect, fill=(1, 1, 1))  # White fill
        
        # Apply redactions to remove text
        temp_page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
        
        # Render the page without text as high-quality image
        mat = fitz.Matrix(2, 2)  # 2x zoom for good quality
        pix = temp_page.get_pixmap(matrix=mat, alpha=False)
        
        # Save background image
        bg_filename = f"page_{page_num + 1}_complete_bg.png"
        bg_path = os.path.join(img_dir, bg_filename)
        pix.save(bg_path)
        
        # Clean up
        temp_doc.close()
        pix = None
        
        # Start page container
        page_html = f"""
        <div class="pdf-page-container" id="page_{page_num + 1}" style="width: {width}px; height: {height}px;">
            <!-- Complete page background (all visual elements except text) -->
            <img src="{bg_filename}" 
                 class="pdf-page-background" 
                 alt="Page background" />
            
            <!-- Text layer -->
            <div class="text-layer">
        """
        html_parts.append(page_html)
        
        # STEP 2: Extract individual images that might need to be editable/moveable
        # This is in addition to the background
        try:
            image_list = page.get_images(full=True)
            
            for img_index, img in enumerate(image_list):
                try:
                    xref = img[0]
                    
                    # Get image position(s) on page
                    img_rects = page.get_image_rects(xref)
                    
                    # Only extract large/important images as separate elements
                    # Small decorative elements are already in the background
                    for rect_idx, img_rect in enumerate(img_rects):
                        # Only extract if image is significant size (> 50x50 pixels)
                        if img_rect.width > 50 and img_rect.height > 50:
                            # Extract the image
                            pix = fitz.Pixmap(doc, xref)
                            
                            # Convert to RGB if necessary
                            if pix.n - pix.alpha >= 4:  # CMYK
                                pix = fitz.Pixmap(fitz.csRGB, pix)
                            
                            # Save as separate file
                            img_filename = f"page_{page_num + 1}_img_{img_index}_{rect_idx}.png"
                            img_path = os.path.join(img_dir, img_filename)
                            pix.save(img_path)
                            
                            # Add as editable/moveable element
                            x = img_rect.x0
                            y = img_rect.y0
                            w = img_rect.width
                            h = img_rect.height
                            
                            img_html = f"""
                            <img src="{img_filename}" 
                                 class="pdf-image" 
                                 data-original="false"
                                 data-editable="true"
                                 style="left: {x}px; 
                                        top: {y}px; 
                                        width: {w}px; 
                                        height: {h}px;
                                        opacity: 0;"
                                 alt="Image {img_index}" />
                            """
                            # Note: opacity:0 because image is already in background
                            # but we keep it for potential editing
                            
                            html_parts.append(img_html)
                            
                            pix = None
                            
                except Exception as e:
                    print(f"<!-- Error extracting image {img_index}: {e} -->", file=sys.stderr)
        except Exception as e:
            print(f"<!-- Error processing images: {e} -->", file=sys.stderr)
        
        # STEP 3: Extract all text as editable elements
        blocks = page.get_text("dict")
        
        for block in blocks["blocks"]:
            if block.get("type") == 0:  # Text block
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        text = span.get("text", "").strip()
                        if text:
                            # Get position and style
                            bbox = span.get("bbox", [0, 0, 0, 0])
                            x = bbox[0] if len(bbox) > 0 else 0
                            y = bbox[1] if len(bbox) > 1 else 0
                            
                            font_size = span.get("size", 12)
                            font_name = span.get("font", "Arial")
                            color = span.get("color", 0)
                            flags = span.get("flags", 0)
                            
                            # Convert color to hex
                            if isinstance(color, int):
                                color_hex = f"#{color:06x}"
                            else:
                                color_hex = "#000000"
                            
                            # Check font styles from flags
                            font_weight = "bold" if (flags & 2**4) or (font_name and "bold" in font_name.lower()) else "normal"
                            font_style = "italic" if (flags & 2**1) or (font_name and "italic" in font_name.lower()) else "normal"
                            
                            # Escape HTML
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
        
        # Close page container
        html_parts.append("""
            </div>
        </div>
        """)
    
    doc.close()
    return "".join(html_parts)

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("<div>Error: Usage: script.py pdf_file image_dir</div>")
        sys.exit(1)
    
    pdf_file = sys.argv[1]
    img_dir = sys.argv[2]
    
    # Ensure image directory exists
    os.makedirs(img_dir, exist_ok=True)
    
    try:
        html = pdf_to_html_complete(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)