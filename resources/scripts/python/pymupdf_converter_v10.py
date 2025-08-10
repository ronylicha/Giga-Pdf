#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os

def pdf_to_html_optimized(pdf_path, img_dir):
    """Optimized extraction - render page without text, add text with minimal hover"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Inline all styles directly - ultra minimal hover
    for page_num, page in enumerate(doc):
        # Get page dimensions
        rect = page.rect
        width = rect.width
        height = rect.height
        
        # Create a copy for background (without text)
        temp_doc = fitz.open()
        temp_doc.insert_pdf(doc, from_page=page_num, to_page=page_num)
        temp_page = temp_doc[0]
        
        # Remove all text
        text_instances = page.get_text("dict")
        for block in text_instances["blocks"]:
            if block["type"] == 0:  # Text block
                for line in block["lines"]:
                    for span in line["spans"]:
                        bbox = fitz.Rect(span["bbox"])
                        temp_page.add_redact_annot(bbox)
        
        # Apply redactions
        temp_page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
        
        # Render at 2x for quality
        mat = fitz.Matrix(2, 2)
        pix = temp_page.get_pixmap(matrix=mat, alpha=False)
        
        # Save background
        bg_filename = f"page_{page_num + 1}_bg.png"
        bg_path = os.path.join(img_dir, bg_filename)
        pix.save(bg_path)
        
        # Start page HTML with inline styles
        page_html = f"""
        <div style="position:relative;margin:20px auto;width:{width}px;height:{height}px;background:white;box-shadow:0 2px 10px rgba(0,0,0,0.1);overflow:hidden">
            <img src="{bg_filename}" style="position:absolute;top:0;left:0;width:{width*2}px;height:{height*2}px;transform:scale(0.5);transform-origin:top left;pointer-events:none;user-select:none;z-index:1" />
        """
        html_parts.append(page_html)
        
        # Add text with minimal hover area
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
                            
                            # Inline text with minimal hover styles
                            text_html = f"""<span contenteditable="true" onmouseover="this.style.backgroundColor='rgba(255,255,0,0.05)'" onmouseout="this.style.backgroundColor='transparent'" onfocus="this.style.outline='1px solid rgba(0,123,255,0.3)';this.style.backgroundColor='rgba(255,255,204,0.1)'" onblur="this.style.outline='none';this.style.backgroundColor='transparent'" style="position:absolute;left:{x}px;top:{y}px;font-size:{font_size}px;color:{color_hex};font-weight:{font_weight};font-style:{font_style};font-family:Arial,sans-serif;background:transparent;padding:0;margin:0;border:none;cursor:text;line-height:1;z-index:3;display:inline-block">{text}</span>"""
                            html_parts.append(text_html)
        
        html_parts.append("</div>")
        
        # Clean up
        temp_doc.close()
        pix = None
    
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
        html = pdf_to_html_optimized(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)