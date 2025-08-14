#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os
import base64

def pdf_to_html_individual_elements(pdf_path, img_dir):
    """Convert PDF to HTML extracting ONLY individual elements - NO full background"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Start with styles
    html_parts.append("""
    <style>
        .pdf-page-container {
            position: relative;
            margin: 0 auto;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .pdf-image-element {
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
        
        # STEP 1: Extract ALL images from the page
        try:
            # Method 1: Get images with their positions
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
                    
                    # Save image once
                    img_filename = f"page_{page_num + 1}_img_{img_index}.png"
                    img_path = os.path.join(img_dir, img_filename)
                    pix.save(img_path)
                    
                    # Add image at each position it appears
                    for rect_idx, img_rect in enumerate(img_rects):
                        x = img_rect.x0
                        y = img_rect.y0
                        w = img_rect.width
                        h = img_rect.height
                        
                        img_html = f"""
                        <img src="{img_filename}" 
                             class="pdf-image-element" 
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
                    print(f"<!-- Error extracting image {img_index}: {e} -->", file=sys.stderr)
            
            # Method 2: Also check for inline images or image masks
            # Sometimes images are embedded differently
            resources = page.get_images()
            xobjects = page.get_xobjects()
            
            for xobj_name in xobjects:
                xobj = xobjects[xobj_name]
                try:
                    if xobj.get("Subtype") == "/Image":
                        # This is an image we might have missed
                        # Extract it if not already processed
                        pass
                except:
                    pass
                    
        except Exception as e:
            print(f"<!-- Error processing images: {e} -->", file=sys.stderr)
        
        # STEP 2: Extract vector graphics and shapes
        try:
            drawings = page.get_drawings()
            
            for draw_idx, drawing in enumerate(drawings):
                items = drawing.get("items", [])
                
                # Check if this is a filled rectangle (might be a background)
                for item in items:
                    if len(item) > 1 and item[0] == "re":  # Rectangle
                        rect = item[1]
                        fill = drawing.get("fill")
                        stroke = drawing.get("stroke")
                        
                        if fill:  # Has fill color
                            # Extract color
                            if isinstance(fill, (list, tuple)) and len(fill) >= 3:
                                fill_color = f"rgb({int(fill[0]*255)}, {int(fill[1]*255)}, {int(fill[2]*255)})"
                            else:
                                fill_color = "#f0f0f0"
                            
                            # Add as a div element
                            shape_html = f"""
                            <div class="pdf-shape"
                                 style="left: {rect.x0}px;
                                        top: {rect.y0}px;
                                        width: {rect.width}px;
                                        height: {rect.height}px;
                                        background-color: {fill_color};
                                        position: absolute;
                                        z-index: 0;">
                            </div>
                            """
                            html_parts.append(shape_html)
                        
                        if stroke:  # Has stroke/border
                            if isinstance(stroke, (list, tuple)) and len(stroke) >= 3:
                                stroke_color = f"rgb({int(stroke[0]*255)}, {int(stroke[1]*255)}, {int(stroke[2]*255)})"
                            else:
                                stroke_color = "#000000"
                            
                            stroke_width = drawing.get("width", 1)
                            
                            # Add border as separate element
                            border_html = f"""
                            <div class="pdf-shape"
                                 style="left: {rect.x0}px;
                                        top: {rect.y0}px;
                                        width: {rect.width}px;
                                        height: {rect.height}px;
                                        border: {stroke_width}px solid {stroke_color};
                                        box-sizing: border-box;
                                        position: absolute;
                                        z-index: 0;">
                            </div>
                            """
                            html_parts.append(border_html)
                            
        except Exception as e:
            print(f"<!-- Error processing drawings: {e} -->", file=sys.stderr)
        
        # STEP 3: Try to extract any remaining visual elements via rendering specific areas
        # This catches logos, watermarks, etc. that might not be standard images
        try:
            # Look for areas with visual content but no text
            # Get all non-text areas
            text_blocks = page.get_text("dict")["blocks"]
            
            # Create a mask of text areas
            text_areas = []
            for block in text_blocks:
                if block.get("type") == 0:  # Text block
                    bbox = block.get("bbox", [0, 0, 0, 0])
                    text_areas.append(fitz.Rect(bbox))
            
            # Check if there are significant non-text areas that might contain images
            # For now, we'll try to detect common image areas
            # This is a heuristic approach
            
        except Exception as e:
            print(f"<!-- Error checking for additional visual elements: {e} -->", file=sys.stderr)
        
        # STEP 4: Extract all text as editable elements
        blocks = page.get_text("dict")
        
        for block in blocks["blocks"]:
            if block.get("type") == 0:  # Text block
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        text = span.get("text", "").strip()
                        if text:
                            # Get position and style with safe defaults
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
        
        # STEP 5: Extract any background color of the page itself
        # Check if page has a non-white background
        try:
            # Try to detect page background color
            # This is done by checking page properties
            # For now, we assume white background unless specified
            pass
        except:
            pass
        
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
        html = pdf_to_html_individual_elements(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)