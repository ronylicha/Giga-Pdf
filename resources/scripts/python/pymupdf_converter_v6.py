#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os
import base64

def pdf_to_html_elements_only(pdf_path, img_dir):
    """Convert PDF to HTML extracting ONLY individual elements - NO full background"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Start with styles
    html_parts.append("""
    <style>
        .pdf-page-container {
            position: relative;
            margin: 20px auto;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .pdf-element {
            position: absolute;
        }
        
        .pdf-image {
            position: absolute;
            z-index: 1;
        }
        
        .pdf-shape {
            position: absolute;
            z-index: 0;
        }
        
        .pdf-text {
            position: absolute;
            background: transparent;
            padding: 0;
            border: 1px solid transparent;
            cursor: text;
            white-space: nowrap;
            line-height: 1;
            z-index: 2;
            display: inline-block;
        }
        
        .pdf-text:hover {
            background: rgba(255,255,0,0.1);
            border: 1px dotted #007bff;
            outline: 1px dotted #007bff;
            outline-offset: -1px;
        }
        
        .pdf-text:focus {
            background: rgba(255,255,204,0.3);
            border: 1px solid #007bff;
            outline: none;
            padding: 0 1px;
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
        
        # Track what areas have content to find hidden images
        content_areas = []
        
        # STEP 1: Extract images by multiple methods
        
        # Method 1: Standard image extraction
        image_list = page.get_images(full=True)
        for img_index, img in enumerate(image_list):
            try:
                xref = img[0]
                
                # Get the pixmap for this image
                pix = fitz.Pixmap(doc, xref)
                
                # Convert to RGB if necessary
                if pix.n - pix.alpha >= 4:  # CMYK
                    pix_rgb = fitz.Pixmap(fitz.csRGB, pix)
                    pix = pix_rgb
                
                # Get all positions where this image appears
                img_rects = page.get_image_rects(xref)
                
                # Save image
                img_filename = f"page_{page_num + 1}_img_{img_index}.png"
                img_path = os.path.join(img_dir, img_filename)
                pix.save(img_path)
                
                # Add image at each position
                for rect_idx, img_rect in enumerate(img_rects):
                    x = img_rect.x0
                    y = img_rect.y0
                    w = img_rect.width
                    h = img_rect.height
                    
                    content_areas.append(img_rect)
                    
                    img_html = f"""
                    <img src="{img_filename}" 
                         class="pdf-image" 
                         style="left: {x}px; 
                                top: {y}px; 
                                width: {w}px; 
                                height: {h}px;"
                         alt="Image {img_index}" />
                    """
                    html_parts.append(img_html)
                
                pix = None
                
            except Exception as e:
                print(f"<!-- Error extracting image {img_index}: {e} -->", file=sys.stderr)
        
        # Method 2: Extract via XObjects (catches embedded images)
        try:
            xobjects = page.get_xobjects()
            for xobj_name in xobjects:
                xobj = xobjects[xobj_name]
                try:
                    if xobj.get("Subtype") == "/Image":
                        # Try to extract this as an image
                        pix = fitz.Pixmap(xobj)
                        if pix.width > 5 and pix.height > 5:  # Ignore tiny images
                            img_filename = f"page_{page_num + 1}_xobj_{xobj_name.replace('/', '_')}.png"
                            img_path = os.path.join(img_dir, img_filename)
                            pix.save(img_path)
                            # Note: Position would need to be determined from content stream
                        pix = None
                except:
                    pass
        except:
            pass
        
        # Skip Method 3 for now - it was causing issues
        # We'll rely on the standard image extraction methods
        
        # STEP 2: Extract vector graphics and shapes
        try:
            drawings = page.get_drawings()
            
            for draw_idx, drawing in enumerate(drawings):
                items = drawing.get("items", [])
                rect_item = drawing.get("rect")
                
                if rect_item:
                    fill = drawing.get("fill")
                    stroke = drawing.get("stroke")
                    opacity = drawing.get("opacity", 1.0)
                    
                    # Create shape element
                    shape_styles = []
                    
                    if fill:  # Has fill color
                        if isinstance(fill, (list, tuple)) and len(fill) >= 3:
                            fill_color = f"rgba({int(fill[0]*255)}, {int(fill[1]*255)}, {int(fill[2]*255)}, {opacity})"
                            shape_styles.append(f"background-color: {fill_color}")
                    
                    if stroke:  # Has stroke/border
                        if isinstance(stroke, (list, tuple)) and len(stroke) >= 3:
                            stroke_color = f"rgb({int(stroke[0]*255)}, {int(stroke[1]*255)}, {int(stroke[2]*255)})"
                            stroke_width = drawing.get("width", 1)
                            shape_styles.append(f"border: {stroke_width}px solid {stroke_color}")
                    
                    if shape_styles:
                        shape_html = f"""
                        <div class="pdf-shape"
                             style="left: {rect_item.x0}px;
                                    top: {rect_item.y0}px;
                                    width: {rect_item.width}px;
                                    height: {rect_item.height}px;
                                    {'; '.join(shape_styles)};">
                        </div>
                        """
                        html_parts.append(shape_html)
                        
        except Exception as e:
            print(f"<!-- Error processing drawings: {e} -->", file=sys.stderr)
        
        # STEP 3: Extract text as editable elements
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
                            
                            # Check font styles
                            font_weight = "bold" if (flags & 2**4) or (font_name and "bold" in font_name.lower()) else "normal"
                            font_style = "italic" if (flags & 2**1) or (font_name and "italic" in font_name.lower()) else "normal"
                            
                            # Escape HTML
                            text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                            text = text.replace('"', "&quot;").replace("'", "&#39;")
                            
                            # Create editable text element with tighter bounds
                            text_html = f"""
                            <span contenteditable="true" 
                                 class="pdf-text"
                                 style="left: {x}px; 
                                        top: {y}px; 
                                        font-size: {font_size}px; 
                                        color: {color_hex}; 
                                        font-weight: {font_weight}; 
                                        font-style: {font_style};
                                        font-family: Arial, sans-serif;
                                        max-width: fit-content;">
                                {text}
                            </span>
                            """
                            html_parts.append(text_html)
        
        # Close page container
        html_parts.append("</div>")
    
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
        html = pdf_to_html_elements_only(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)