#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os

def pdf_to_html_smart_extraction(pdf_path, img_dir):
    """Smart extraction - render only non-text areas as images"""
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Ultra-minimal styles for precise selection
    html_parts.append("""
    <style>
        .pdf-page-container {
            position: relative;
            margin: 0 auto;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .pdf-graphic {
            position: absolute;
            z-index: 1;
        }
        
        .pdf-text {
            position: absolute;
            background: transparent;
            padding: 0;
            margin: 0;
            border: none;
            cursor: text;
            line-height: 1;
            z-index: 2;
        }
        
        .pdf-text:hover {
            outline: 1px dotted rgba(0,123,255,0.2);
        }
        
        .pdf-text:focus {
            outline: 1px solid #007bff;
            background: rgba(255,255,204,0.15);
        }
    </style>
    """)
    
    for page_num, page in enumerate(doc):
        rect = page.rect
        width = rect.width
        height = rect.height
        
        # Start page container
        page_html = f"""
        <div class="pdf-page-container" style="width: {width}px; height: {height}px;">
        """
        html_parts.append(page_html)
        
        # Get text blocks to know where NOT to render
        text_blocks = page.get_text("dict")["blocks"]
        text_areas = []
        
        for block in text_blocks:
            if block["type"] == 0:  # Text block
                # Expand text area slightly to avoid cutting edges
                bbox = block["bbox"]
                expanded_rect = fitz.Rect(bbox[0]-2, bbox[1]-2, bbox[2]+2, bbox[3]+2)
                text_areas.append(expanded_rect)
        
        # Extract images first
        image_list = page.get_images(full=True)
        
        for img_index, img in enumerate(image_list):
            try:
                xref = img[0]
                pix = fitz.Pixmap(doc, xref)
                
                if pix.n - pix.alpha >= 4:  # CMYK
                    pix = fitz.Pixmap(fitz.csRGB, pix)
                
                # Get image positions
                img_rects = page.get_image_rects(xref)
                
                # Save image
                img_filename = f"page_{page_num + 1}_img_{img_index}.png"
                img_path = os.path.join(img_dir, img_filename)
                pix.save(img_path)
                
                for rect_idx, img_rect in enumerate(img_rects):
                    # Check if this image overlaps with text
                    overlaps_text = any(img_rect.intersects(text_rect) for text_rect in text_areas)
                    
                    # Add image
                    img_html = f"""
                    <img src="{img_filename}" 
                         class="pdf-graphic" 
                         style="left: {img_rect.x0}px; 
                                top: {img_rect.y0}px; 
                                width: {img_rect.width}px; 
                                height: {img_rect.height}px;" />
                    """
                    html_parts.append(img_html)
                
                pix = None
                
            except Exception as e:
                print(f"<!-- Error with image {img_index}: {e} -->", file=sys.stderr)
        
        # Now find and render non-text, non-image areas that have content
        # These are typically backgrounds, borders, shapes
        
        # Merge overlapping text areas to create larger exclusion zones
        merged_text_areas = []
        for rect in text_areas:
            merged = False
            for i, merged_rect in enumerate(merged_text_areas):
                if rect.intersects(merged_rect):
                    # Merge the rectangles
                    merged_text_areas[i] = merged_rect | rect
                    merged = True
                    break
            if not merged:
                merged_text_areas.append(rect)
        
        # Find areas that might contain graphics
        # Divide page into strips and check for content
        strip_height = 100  # Check in 100px strips
        
        for y in range(0, int(height), strip_height):
            strip_rect = fitz.Rect(0, y, width, min(y + strip_height, height))
            
            # Check if this strip has any text
            has_text = any(strip_rect.intersects(text_rect) for text_rect in merged_text_areas)
            
            if not has_text:
                # This strip might have graphics - render it
                # But first check if it's not just white space
                temp_doc = fitz.open()
                temp_doc.insert_pdf(doc, from_page=page_num, to_page=page_num)
                temp_page = temp_doc[0]
                
                # Get pixmap of this strip
                mat = fitz.Matrix(1, 1)
                pix = temp_page.get_pixmap(matrix=mat, clip=strip_rect, alpha=False)
                
                # Check if it has content (not all white)
                # Sample a few pixels to see if they're all white
                samples = pix.samples
                has_content = False
                
                # Quick check - if the samples vary, there's content
                if len(set(samples[:100])) > 2:  # More than just white
                    has_content = True
                
                if has_content:
                    # Save this strip as an image
                    strip_filename = f"page_{page_num + 1}_strip_{int(y)}.png"
                    strip_path = os.path.join(img_dir, strip_filename)
                    pix.save(strip_path)
                    
                    # Add as graphic element
                    strip_html = f"""
                    <img src="{strip_filename}" 
                         class="pdf-graphic" 
                         style="left: 0px; 
                                top: {y}px; 
                                width: {width}px; 
                                height: {strip_rect.height}px;" />
                    """
                    html_parts.append(strip_html)
                
                pix = None
                temp_doc.close()
        
        # Extract vector graphics/drawings
        try:
            drawings = page.get_drawings()
            
            for draw_idx, drawing in enumerate(drawings):
                rect_item = drawing.get("rect")
                if rect_item:
                    fill = drawing.get("fill")
                    stroke = drawing.get("stroke")
                    
                    if fill or stroke:
                        # Check if this overlaps with text
                        overlaps_text = any(rect_item.intersects(text_rect) for text_rect in text_areas)
                        
                        if not overlaps_text:
                            # Render this shape
                            styles = []
                            
                            if fill and isinstance(fill, (list, tuple)) and len(fill) >= 3:
                                fill_color = f"rgb({int(fill[0]*255)},{int(fill[1]*255)},{int(fill[2]*255)})"
                                styles.append(f"background:{fill_color}")
                            
                            if stroke and isinstance(stroke, (list, tuple)) and len(stroke) >= 3:
                                stroke_color = f"rgb({int(stroke[0]*255)},{int(stroke[1]*255)},{int(stroke[2]*255)})"
                                stroke_width = drawing.get("width", 1)
                                styles.append(f"border:{stroke_width}px solid {stroke_color}")
                            
                            if styles:
                                shape_html = f"""
                                <div class="pdf-graphic" 
                                     style="left:{rect_item.x0}px;
                                            top:{rect_item.y0}px;
                                            width:{rect_item.width}px;
                                            height:{rect_item.height}px;
                                            {';'.join(styles)}"></div>
                                """
                                html_parts.append(shape_html)
        except:
            pass
        
        # Finally, add text elements
        for block in text_blocks:
            if block["type"] == 0:  # Text block
                for line in block["lines"]:
                    for span in line["spans"]:
                        text = span["text"].strip()
                        if text:
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
                            
                            text_html = f"""<span contenteditable="true" class="pdf-text" style="left:{x}px;top:{y}px;font-size:{font_size}px;color:{color_hex};font-weight:{font_weight};font-style:{font_style};font-family:Arial,sans-serif">{text}</span>"""
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
        html = pdf_to_html_smart_extraction(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)