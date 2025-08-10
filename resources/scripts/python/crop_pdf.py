#!/usr/bin/env python3
import fitz  # PyMuPDF
import sys
import os

def crop_pdf(input_path, output_path):
    """
    Crops each page of a PDF to remove white space while preserving content.
    Detects the actual content boundaries and crops accordingly.
    """
    try:
        doc = fitz.open(input_path)
        new_doc = fitz.open()  # Create a new empty PDF

        for page_num in range(len(doc)):
            page = doc[page_num]
            
            # Get all content elements to find actual boundaries
            content_rect = None
            
            # Method 1: Get text content boundaries
            text_dict = page.get_text("dict")
            if text_dict and "blocks" in text_dict:
                blocks = text_dict["blocks"]
                if blocks:
                    # Find the bounding box of all content
                    min_x = float('inf')
                    min_y = float('inf')
                    max_x = float('-inf')
                    max_y = float('-inf')
                    
                    for block in blocks:
                        if "bbox" in block:
                            bbox = block["bbox"]
                            min_x = min(min_x, bbox[0])
                            min_y = min(min_y, bbox[1])
                            max_x = max(max_x, bbox[2])
                            max_y = max(max_y, bbox[3])
                    
                    if min_x != float('inf'):
                        content_rect = fitz.Rect(min_x, min_y, max_x, max_y)
            
            # Method 2: Get image boundaries if present
            image_list = page.get_images()
            if image_list and not content_rect:
                for img in image_list:
                    try:
                        # Get image position on page
                        xref = img[0]
                        img_rect = page.get_image_bbox(xref)
                        if img_rect:
                            if not content_rect:
                                content_rect = img_rect
                            else:
                                content_rect = content_rect | img_rect  # Union of rectangles
                    except:
                        pass
            
            # Method 3: Get drawings/graphics boundaries
            try:
                drawings = page.get_drawings()
                if drawings:
                    for drawing in drawings:
                        if 'rect' in drawing:
                            draw_rect = drawing['rect']
                            if not content_rect:
                                content_rect = draw_rect
                            else:
                                content_rect = content_rect | draw_rect
            except:
                pass
            
            # If no content found, use the page's cropbox or mediabox
            if not content_rect or content_rect.is_empty or content_rect.is_infinite:
                if not page.cropbox.is_empty:
                    content_rect = page.cropbox
                else:
                    content_rect = page.mediabox
            
            # Add a small margin (2-3 points) to avoid cutting content
            margin = 3
            content_rect.x0 -= margin
            content_rect.y0 -= margin
            content_rect.x1 += margin
            content_rect.y1 += margin
            
            # Ensure we don't exceed page boundaries
            page_rect = page.mediabox
            content_rect.x0 = max(content_rect.x0, page_rect.x0)
            content_rect.y0 = max(content_rect.y0, page_rect.y0)
            content_rect.x1 = min(content_rect.x1, page_rect.x1)
            content_rect.y1 = min(content_rect.y1, page_rect.y1)
            
            # Create a new page with the cropped dimensions
            new_page = new_doc.new_page(
                width=content_rect.width,
                height=content_rect.height
            )
            
            # Copy the content, adjusting for the crop
            new_page.show_pdf_page(
                new_page.rect,  # Target: full new page
                doc,            # Source document
                page_num,       # Source page number
                clip=content_rect  # Source area to copy
            )

        # Save the cropped document
        new_doc.save(output_path, garbage=4, deflate=True, clean=True)
        new_doc.close()
        doc.close()
        
        return True
        
    except Exception as e:
        sys.stderr.write(f"Error during PDF cropping: {e}\n")
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.stderr.write("Usage: python crop_pdf.py <input_pdf> <output_pdf>\n")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_file = sys.argv[2]
    
    if not os.path.exists(input_file):
        sys.stderr.write(f"Error: Input file not found at {input_file}\n")
        sys.exit(1)
        
    crop_pdf(input_file, output_file)
    print(f"Successfully cropped '{input_file}' to '{output_file}'")
    sys.exit(0)