#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os

def pdf_to_html_no_background(pdf_path, img_dir):
    """
    Extracts individual elements (text, images, vector drawings) from a PDF
    without creating a full-page background image.
    Focuses on a 1-by-1 element extraction approach, preserving fonts and
    rendering vector graphics as text-free images.
    """
    doc = fitz.open(pdf_path)
    html_parts = []
    
    # Start HTML with styles for absolute positioning of elements
    html_parts.append("""<style>
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
</style>""")
    
    for page_num, page in enumerate(doc):
        page_width, page_height = page.rect.width, page.rect.height
        html_parts.append(f'<div class="pdf-page-container" style="width:{page_width}px;height:{page_height}px;" data-page-number="{page_num + 1}">')

        # --- STAGE 1: Data Extraction ---
        # Extract all text, image, and drawing information from the original page first.
        
        all_text_spans = []
        try:
            text_blocks = page.get_text("dict", flags=fitz.TEXT_PRESERVE_LIGATURES | fitz.TEXT_PRESERVE_WHITESPACE)["blocks"]
            for block in text_blocks:
                if block["type"] == 0:
                    for line in block["lines"]:
                        all_text_spans.extend(line["spans"])
        except Exception as e:
            sys.stderr.write(f"Error pre-extracting text on page {page_num + 1}: {e}\n")

        raster_images = []
        try:
            raster_images = page.get_images(full=True)
        except Exception as e:
            sys.stderr.write(f"Error pre-extracting raster images on page {page_num + 1}: {e}\n")
            
        vector_drawings = []
        try:
            vector_drawings = page.get_drawings()
        except Exception as e:
            sys.stderr.write(f"Error pre-extracting vector drawings on page {page_num + 1}: {e}\n")

        # --- STAGE 2: HTML Assembly ---

        # Add editable text elements to HTML, preserving fonts
        for span in all_text_spans:
            text = span["text"]
            if text.strip():
                bbox = fitz.Rect(span["bbox"])
                font_name = span.get("font", "sans-serif")
                # Clean up font name for CSS
                font_name = font_name.split("+")[-1].replace("-", " ")
                
                escaped_text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
                
                style = (
                    f'left:{bbox.x0}px;top:{bbox.y0}px;font-size:{span["size"]:.2f}px;'
                    f'color:#{span["color"]:06x};font-family:"{font_name}";'
                    f'font-weight:{"bold" if "bold" in font_name.lower() or (span["flags"] & 2**4) else "normal"};'
                    f'font-style:{"italic" if "italic" in font_name.lower() or (span["flags"] & 2**1) else "normal"};'
                )
                html_parts.append(f'<span contenteditable="true" class="pdf-text" style="{style}">{escaped_text}</span>')

        # Add raster images to HTML
        for img_info in raster_images:
            xref = img_info[0]
            if xref == 0: continue
            
            img_rects = page.get_image_rects(xref, transform=True)
            img_filename = f"p{page_num + 1}_img{xref}.png"
            img_path = os.path.join(img_dir, img_filename)
            
            if not os.path.exists(img_path):
                pix = fitz.Pixmap(doc, xref)
                if pix.n - pix.alpha >= 4:
                    pix = fitz.Pixmap(fitz.csRGB, pix)
                pix.save(img_path)
                pix = None

            for r in img_rects:
                if not r.is_empty:
                    html_parts.append(f'<img src="{img_filename}" class="pdf-element" style="left:{r.x0}px;top:{r.y0}px;width:{r.width}px;height:{r.height}px;z-index:1" alt="Image" />')

        # Render vector drawings as images, but erase overlapping text from the render
        for i, drawing in enumerate(vector_drawings):
            drawing_rect = fitz.Rect(drawing.get("rect"))
            if drawing_rect.is_empty or drawing_rect.width < 3 or drawing_rect.height < 3:
                continue

            # Render the graphic's area from the original page
            pix = page.get_pixmap(clip=drawing_rect, dpi=200, alpha=True)

            # Erase any text that falls within this graphic's area
            for span in all_text_spans:
                text_rect = fitz.Rect(span["bbox"])
                intersection = text_rect & drawing_rect
                if not intersection.is_empty:
                    # Convert intersection rect to pixmap coordinates and clear it
                    relative_intersection = intersection - drawing_rect.top_left
                    pix.clear_with(irect=relative_intersection.irect)

            # Save the pixmap only if it's not blank
            if len(set(pix.samples)) > 2: # More than just transparent and one other color
                img_filename = f"p{page_num + 1}_vec{i}.png"
                img_path = os.path.join(img_dir, img_filename)
                pix.save(img_path)
                
                html_parts.append(f'<img src="{img_filename}" class="pdf-element" style="left:{drawing_rect.x0}px;top:{drawing_rect.y0}px;width:{drawing_rect.width}px;height:{drawing_rect.height}px;z-index:2" alt="Vector Graphic" />')
            
            pix = None

        html_parts.append('</div>')
    
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
        html = pdf_to_html_no_background(pdf_file, img_dir)
        print(html)
    except Exception as e:
        print(f"<div>Error: {str(e)}</div>")
        sys.exit(1)