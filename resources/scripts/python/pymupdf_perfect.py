#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import base64
import io
import os

def pdf_to_perfect_html(pdf_path, img_dir):
    """Convert PDF to pixel-perfect HTML with complete visual rendering"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    for page_num, page in enumerate(doc):
        # Get page dimensions
        rect = page.rect
        width = rect.width
        height = rect.height
        
        # Convert page to high-quality image for background (includes ALL visual elements)
        # Use higher resolution for better quality
        mat = fitz.Matrix(3, 3)  # 3x zoom for excellent quality
        pix = page.get_pixmap(matrix=mat, alpha=False, colorspace="rgb")
        
        # Save image to file
        img_filename = f"page_{page_num + 1}_bg.png"
        img_path = os.path.join(img_dir, img_filename)
        pix.save(img_path)
        
        # Calculate scaled dimensions
        scaled_width = width * 3
        scaled_height = height * 3
        
        # Use file path instead of base64
        # This will be replaced with proper URL later
        html_parts.append(f''''
        <div class="pdf-page-container" style="position: relative; margin: 20px auto; width: {width}px; height: {height}px; background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden;">
            <!-- Complete PDF render as background (includes logos, tables, all formatting) -->
            <img src="{img_filename}" class="pdf-page-background" style="position: absolute; top: 0; left: 0; width: {scaled_width}px; height: {scaled_height}px; transform: scale(0.333333); transform-origin: top left; pointer-events: none; user-select: none; z-index: 1;" />
            
            <!-- Transparent editable text overlay -->
            <div class="text-layer" style="position: absolute; top: 0; left: 0; width: {width}px; height: {height}px; z-index: 2;">
        '''')  
        
        # Extract text blocks with exact positioning
        blocks = page.get_text("dict")
        
        for block in blocks["blocks"]:
            if block["type"] == 0:  # Text block
                for line in block["lines"]:
                    for span in line["spans"]:
                        text = span["text"].strip()
                        if text:
                            # Get exact position and style (no scaling needed, we match the original size)
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
                            
                            # Create completely transparent editable text overlay
                            html_parts.append(f''''
                                <div contenteditable="true" 
                                     style="position: absolute; 
                                            left: {x}px; 
                                            top: {y}px; 
                                            font-size: {font_size}px; 
                                            color: transparent; 
                                            font-weight: {font_weight}; 
                                            font-style: {font_style};
                                            font-family: Arial, sans-serif;
                                            background: transparent;
                                            padding: 0 2px;
                                            border: 1px solid transparent;
                                            cursor: text;
                                            white-space: nowrap;
                                            z-index: 3;"
                                     onmouseover="this.style.background='rgba(255,255,0,0.3)'; this.style.border='1px solid #007bff'"
                                     onmouseout="this.style.background='transparent'; this.style.border='1px solid transparent'"
                                     onfocus="this.style.color='#000'; this.style.background='rgba(255,255,255,0.95)'"
                                     onblur="this.style.color='transparent'; this.style.background='transparent'">
                                    {text}
                                </div>
                            '''')  
        
        html_parts.append(''''
            </div>
        </div>
        '''')  
    
    doc.close()
    return "".join(html_parts)

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("<div>Error: Usage: script.py pdf_file image_dir</div>")
        sys.exit(1)
    
    pdf_file = sys.argv[1]
    img_dir = sys.argv[2]
    html = pdf_to_perfect_html(pdf_file, img_dir)
    print(html)
