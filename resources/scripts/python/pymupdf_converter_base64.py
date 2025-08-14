#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import base64
import io
import html

def pdf_to_html_base64(pdf_path):
    """
    Extracts individual elements (text, images, vector drawings) from a PDF
    and creates a self-contained HTML with all images embedded as base64.
    """
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Start HTML with styles for absolute positioning of elements
    html_parts.append("""<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body {
    margin: 0;
    padding: 0;
    background: #f5f5f5;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.pdf-page-container {
    position: relative;
    margin: 0 auto;
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}
.pdf-element {
    position: absolute;
    box-sizing: border-box;
}
.pdf-text {
    position: absolute;
    padding: 0 !important;
    margin: 0 !important;
    border: none !important;
    background: transparent !important;
    cursor: text;
    line-height: 1.1 !important;
    white-space: nowrap;
    z-index: 10;
}
.pdf-text:hover {
    background: rgba(0, 123, 255, 0.1) !important;
}
.pdf-text:focus {
    outline: 1px solid rgba(0, 123, 255, 0.4) !important;
    background: rgba(0, 123, 255, 0.05) !important;
}
.pdf-image {
    position: absolute;
    z-index: 1;
}
.pdf-vector {
    position: absolute;
    z-index: 2;
}
.pdf-line {
    position: absolute;
    z-index: 3;
    pointer-events: none;
}
</style>
</head>
<body>""")
    
    for page_num, page in enumerate(doc):
        page_width, page_height = page.rect.width, page.rect.height
        html_parts.append(f'<div class="pdf-page-container" style="width:{page_width}px;height:{page_height}px;" data-page-number="{page_num + 1}">')

        # --- Extract text spans ---
        all_text_spans = []
        try:
            text_dict = page.get_text("dict", flags=fitz.TEXT_PRESERVE_LIGATURES | fitz.TEXT_PRESERVE_WHITESPACE)
            for block in text_dict.get("blocks", []):
                if block["type"] == 0:  # text block
                    for line in block.get("lines", []):
                        for span in line.get("spans", []):
                            all_text_spans.append(span)
        except Exception as e:
            sys.stderr.write(f"Error extracting text on page {page_num + 1}: {e}\n")

        # --- Extract and convert raster images to base64 ---
        raster_images = []
        try:
            raster_images = page.get_images(full=True)
        except Exception as e:
            sys.stderr.write(f"Error extracting raster images on page {page_num + 1}: {e}\n")
            
        # --- Extract vector drawings and lines ---
        vector_drawings = []
        horizontal_lines = []
        
        # Method 1: Standard drawings extraction
        try:
            drawings = page.get_drawings()
            for drawing in drawings:
                if drawing.get("items"):
                    vector_drawings.append(drawing)
        except Exception as e:
            sys.stderr.write(f"Error extracting vector drawings on page {page_num + 1}: {e}\n")
        
        # Method 2: Extract paths (includes lines not detected as drawings)
        try:
            paths = page.get_cdrawings()
            for path in paths:
                # Check if it's a horizontal line
                items = path.get("items", [])
                for item in items:
                    if item[0] == "l":  # line
                        p1, p2 = item[1], item[2]
                        # Check if it's horizontal (y coordinates similar)
                        if abs(p1.y - p2.y) < 2:  # tolerance of 2 pixels
                            line_rect = fitz.Rect(min(p1.x, p2.x), min(p1.y, p2.y) - 1,
                                                max(p1.x, p2.x), max(p1.y, p2.y) + 1)
                            horizontal_lines.append({
                                "rect": line_rect,
                                "stroke": path.get("stroke", (0, 0, 0)),
                                "width": path.get("width", 1)
                            })
        except Exception as e:
            sys.stderr.write(f"Error extracting paths on page {page_num + 1}: {e}\n")
        
        # Method 3: Extract from page content stream (fallback for undetected lines)
        try:
            # Get the page's display list
            dl = page.get_displaylist()
            # Extract text and graphics
            text_page = dl.get_textpage()
            
            # Try to find horizontal rules in the content
            contents = page.read_contents()
            if contents and b"re" in contents:  # rectangle operator
                # Basic pattern matching for rectangles that might be lines
                import re
                # Pattern for thin rectangles (potential horizontal lines)
                rect_pattern = re.compile(rb'(\d+\.?\d*)\s+(\d+\.?\d*)\s+(\d+\.?\d*)\s+(\d+\.?\d*)\s+re')
                for match in rect_pattern.finditer(contents):
                    try:
                        x, y, w, h = map(float, match.groups())
                        # Check if it's a horizontal line (height < 3 pixels)
                        if h < 3 and w > 20:  # thin and wide enough
                            line_rect = fitz.Rect(x, y, x + w, y + h)
                            # Check if not already captured
                            is_duplicate = False
                            for existing in horizontal_lines:
                                if abs(existing["rect"].y0 - line_rect.y0) < 2:
                                    is_duplicate = True
                                    break
                            if not is_duplicate:
                                horizontal_lines.append({
                                    "rect": line_rect,
                                    "stroke": (0, 0, 0),
                                    "width": max(1, h)
                                })
                    except:
                        pass
        except Exception as e:
            sys.stderr.write(f"Error extracting from content stream on page {page_num + 1}: {e}\n")

        # --- Add text spans to HTML ---
        for span in all_text_spans:
            text = span.get("text", "").strip()
            if not text:
                continue
                
            bbox = span.get("bbox", [0, 0, 0, 0])
            font_size = span.get("size", 12)
            color = span.get("color", 0)
            color_hex = f"#{color:06x}" if isinstance(color, int) else "#000000"
            font_name = span.get("font", "Arial")
            
            escaped_text = html.escape(text)
            style = (f"left:{bbox[0]:.1f}px;top:{bbox[1]:.1f}px;"
                    f"font-size:{font_size:.1f}px;color:{color_hex};"
                    f"font-family:'{font_name}',sans-serif")
            html_parts.append(f'<span contenteditable="true" class="pdf-text" style="{style}">{escaped_text}</span>')

        # --- Add raster images as base64 ---
        for img_info in raster_images:
            xref = img_info[0]
            if xref == 0:
                continue
            
            try:
                # Extract the image
                pix = fitz.Pixmap(doc, xref)
                
                # Convert to PNG if necessary
                if pix.alpha:
                    pix = fitz.Pixmap(fitz.csRGB, pix)
                
                # Convert to base64
                img_buffer = io.BytesIO()
                pix.pil_save(img_buffer, format="PNG", optimize=True)
                img_buffer.seek(0)
                img_base64 = base64.b64encode(img_buffer.read()).decode('utf-8')
                
                # Get image rectangles on the page
                img_rects = page.get_image_rects(xref, transform=True)
                
                for r in img_rects:
                    if not r.is_empty:
                        html_parts.append(
                            f'<img src="data:image/png;base64,{img_base64}" '
                            f'class="pdf-image" '
                            f'style="left:{r.x0}px;top:{r.y0}px;width:{r.width}px;height:{r.height}px;" '
                            f'alt="Image" />'
                        )
                
                pix = None  # Free memory
                
            except Exception as e:
                sys.stderr.write(f"Error converting image {xref} to base64: {e}\n")

        # --- Render vector drawings as base64 images ---
        for i, drawing in enumerate(vector_drawings):
            try:
                # Get the bounding box of the drawing
                rect = drawing.get("rect")
                if not rect or rect.is_empty:
                    continue
                
                # Create a temporary page with just this drawing
                temp_doc = fitz.open()
                temp_page = temp_doc.new_page(width=page_width, height=page_height)
                
                # Render the drawing on the temporary page
                shape = temp_page.new_shape()
                for item in drawing.get("items", []):
                    if item[0] == "l":  # line
                        shape.draw_line(item[1], item[2])
                    elif item[0] == "re":  # rectangle
                        shape.draw_rect(item[1])
                    elif item[0] == "qu":  # quad
                        shape.draw_quad(item[1])
                    elif item[0] == "c":  # curve
                        shape.draw_bezier(item[1], item[2], item[3], item[4])
                
                # Get stroke and fill properties
                stroke = drawing.get("stroke")
                fill = drawing.get("fill")
                width = drawing.get("width", 1)
                
                if stroke:
                    stroke_color = stroke if isinstance(stroke, tuple) else (0, 0, 0)
                else:
                    stroke_color = None
                    
                if fill:
                    fill_color = fill if isinstance(fill, tuple) else (0, 0, 0)
                else:
                    fill_color = None
                
                shape.finish(
                    color=stroke_color,
                    fill=fill_color,
                    width=width
                )
                shape.commit()
                
                # Render the drawing area as an image
                mat = fitz.Matrix(2, 2)  # 2x scale for better quality
                clip = fitz.Rect(rect.x0, rect.y0, rect.x1, rect.y1)
                pix = temp_page.get_pixmap(matrix=mat, clip=clip)
                
                # Convert to base64
                img_buffer = io.BytesIO()
                pix.pil_save(img_buffer, format="PNG", optimize=True)
                img_buffer.seek(0)
                vec_base64 = base64.b64encode(img_buffer.read()).decode('utf-8')
                
                # Add to HTML with original position
                html_parts.append(
                    f'<img src="data:image/png;base64,{vec_base64}" '
                    f'class="pdf-vector" '
                    f'style="left:{rect.x0}px;top:{rect.y0}px;width:{rect.width}px;height:{rect.height}px;" '
                    f'alt="Vector drawing" />'
                )
                
                temp_doc.close()
                pix = None  # Free memory
                
            except Exception as e:
                sys.stderr.write(f"Error converting vector drawing {i} to base64: {e}\n")
        
        # --- Render horizontal lines as HTML elements ---
        for i, line in enumerate(horizontal_lines):
            try:
                rect = line["rect"]
                stroke = line.get("stroke", (0, 0, 0))
                width = line.get("width", 1)
                
                # Convert stroke color to hex
                if isinstance(stroke, tuple) and len(stroke) >= 3:
                    color_hex = f"#{int(stroke[0]*255):02x}{int(stroke[1]*255):02x}{int(stroke[2]*255):02x}"
                else:
                    color_hex = "#000000"
                
                # Add as a div element styled as a line
                html_parts.append(
                    f'<div class="pdf-vector pdf-line" '
                    f'style="position:absolute;left:{rect.x0}px;top:{rect.y0}px;'
                    f'width:{rect.width}px;height:{max(1, width)}px;'
                    f'background-color:{color_hex};" '
                    f'data-line="true"></div>'
                )
            except Exception as e:
                sys.stderr.write(f"Error adding horizontal line {i}: {e}\n")

        html_parts.append('</div>')  # Close page container

    html_parts.append('</body></html>')
    doc.close()
    
    return ''.join(html_parts)

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python pymupdf_converter_base64.py <pdf_path>", file=sys.stderr)
        sys.exit(1)
    
    pdf_path = sys.argv[1]
    
    try:
        html_output = pdf_to_html_base64(pdf_path)
        print(html_output)
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)