#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os

def pdf_to_html_hybrid(pdf_path, img_dir):
    """Convert PDF - Extract graphical elements as background, text as editable"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Minimal styles for tight selection
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
            pointer-events: none;
            user-select: none;
            z-index: 1;
        }
        
        .text-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2;
        }
        
        .pdf-text {
            position: absolute;
            background: transparent;
            padding: 0;
            margin: 0;
            border: none;
            cursor: text;
            line-height: 1;
            z-index: 3;
            display: inline-block;
        }
        
        .pdf-text:hover {
            outline: 1px dotted rgba(0,123,255,0.3);
            outline-offset: 1px;
        }
        
        .pdf-text:focus {
            background: rgba(255,255,204,0.2);
            outline: 1px solid #007bff;
            outline-offset: 0;
        }
    </style>
    """)
    
    for page_num, page in enumerate(doc):
        # Get page dimensions
        rect = page.rect
        width = rect.width
        height = rect.height
        
        # Create a copy of the page for background
        temp_doc = fitz.open()
        temp_doc.insert_pdf(doc, from_page=page_num, to_page=page_num)
        temp_page = temp_doc[0]
        
        # Get all text instances to remove them
        text_instances = page.get_text("dict")
        
        # Remove only text, keep all graphical elements
        for block in text_instances["blocks"]:
            if block["type"] == 0:  # Text block
                for line in block["lines"]:
                    for span in line["spans"]:
                        bbox = fitz.Rect(span["bbox"])
                        # Very tight redaction to preserve nearby graphics
                        temp_page.add_redact_annot(bbox)
        
        # Apply redactions (removes text only)
        temp_page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
        
        # Render at high quality (2x for balance between quality and size)
        mat = fitz.Matrix(2, 2)
        pix = temp_page.get_pixmap(matrix=mat, alpha=False)
        
        # Save background image
        img_filename = f"page_{page_num + 1}_bg.png"
        img_path = os.path.join(img_dir, img_filename)
        pix.save(img_path)
        
        # Calculate scaled dimensions
        scaled_width = width * 2
        scaled_height = height * 2
        
        # Start page HTML
        page_html = f"""
        <div class="pdf-page-container" style="width: {width}px; height: {height}px;">
            <!-- Background with all graphical elements -->
            <img src="{img_filename}" 
                 class="pdf-page-background" 
                 style="width: {scaled_width}px; 
                        height: {scaled_height}px; 
                        transform: scale(0.5); 
                        transform-origin: top left;" />
            
            <!-- Text layer -->
            <div class="text-layer">
        """
        html_parts.append(page_html)
        
        # Extract text with very precise positioning
        blocks = page.get_text("dict")
        
        for block in blocks["blocks"]:
            if block["type"] == 0:  # Text block
                for line in block["lines"]:
                    for span in line["spans"]:
                        text = span["text"].strip()
                        if text:
                            # Get exact position
                            bbox = span["bbox"]
                            x = bbox[0]
                            y = bbox[1]
                            width_span = bbox[2] - bbox[0]
                            height_span = bbox[3] - bbox[1]
                            
                            font_size = span["size"]
                            color = span["color"]
                            font_name = span.get("font", "")
                            flags = span.get("flags", 0)
                            
                            # Convert color to hex
                            color_hex = f"#{color:06x}"
                            
                            # Check font styles
                            font_weight = "bold" if (flags & 2**4) or "bold" in font_name.lower() else "normal"
                            font_style = "italic" if (flags & 2**1) or "italic" in font_name.lower() else "normal"
                            
                            # Escape HTML
                            text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                            text = text.replace('"', "&quot;").replace("'", "&#39;")
                            
                            # Create minimal text element
                            text_html = f"""<span contenteditable="true" class="pdf-text" style="left:{x}px;top:{y}px;font-size:{font_size}px;color:{color_hex};font-weight:{font_weight};font-style:{font_style};font-family:Arial,sans-serif;">{text}</span>"""
                            html_parts.append(text_html)
        
        # Close containers
        html_parts.append("""
            </div>
        </div>
        """)
        
        # Clean up
        temp_doc.close()
    
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
        html = pdf_to_html_hybrid(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)