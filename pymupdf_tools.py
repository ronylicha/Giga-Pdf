#!/usr/bin/env python3
import sys
import fitz  # PyMuPDF
import json
from pathlib import Path

def extract_text_with_layout(pdf_path):
    """Extract text from PDF preserving layout"""
    try:
        doc = fitz.open(pdf_path)
        full_text = []
        
        for page_num, page in enumerate(doc, 1):
            # Extract text with layout preservation
            text = page.get_text("text")
            full_text.append(f"--- Page {page_num} ---\n{text}")
        
        doc.close()
        return '\n'.join(full_text)
    except Exception as e:
        return f"Error: {str(e)}"

def extract_images(pdf_path, output_dir):
    """Extract all images from PDF"""
    try:
        doc = fitz.open(pdf_path)
        output_dir = Path(output_dir)
        output_dir.mkdir(exist_ok=True)
        
        image_count = 0
        for page_num, page in enumerate(doc):
            image_list = page.get_images()
            
            for img_index, img in enumerate(image_list):
                xref = img[0]
                pix = fitz.Pixmap(doc, xref)
                
                if pix.n - pix.alpha < 4:  # GRAY or RGB
                    image_path = output_dir / f"page{page_num}_img{img_index}.png"
                    pix.save(str(image_path))
                    image_count += 1
                else:  # CMYK
                    pix1 = fitz.Pixmap(fitz.csRGB, pix)
                    image_path = output_dir / f"page{page_num}_img{img_index}.png"
                    pix1.save(str(image_path))
                    pix1 = None
                    image_count += 1
                pix = None
        
        doc.close()
        return f"Extracted {image_count} images"
    except Exception as e:
        return f"Error: {str(e)}"

def remove_text_keep_images(pdf_path, output_path):
    """Remove text from PDF while keeping images and backgrounds"""
    try:
        doc = fitz.open(pdf_path)
        
        for page in doc:
            # Get all text instances
            text_instances = page.get_text("dict")
            
            # Redact each text block
            for block in text_instances["blocks"]:
                if block["type"] == 0:  # Text block
                    for line in block["lines"]:
                        for span in line["spans"]:
                            # Create rectangle for text
                            rect = fitz.Rect(span["bbox"])
                            # Add redaction annotation
                            page.add_redact_annot(rect)
            
            # Apply redactions (removes text)
            page.apply_redactions()
        
        # Save the modified PDF
        doc.save(output_path)
        doc.close()
        return f"Text removed, saved to {output_path}"
    except Exception as e:
        return f"Error: {str(e)}"

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: pymupdf_tools.py <command> <pdf_file> [options]")
        print("Commands: extract_text, extract_images, remove_text")
        sys.exit(1)
    
    command = sys.argv[1]
    pdf_file = sys.argv[2] if len(sys.argv) > 2 else None
    
    if command == "extract_text" and pdf_file:
        print(extract_text_with_layout(pdf_file))
    elif command == "extract_images" and pdf_file:
        output_dir = sys.argv[3] if len(sys.argv) > 3 else "./extracted_images"
        print(extract_images(pdf_file, output_dir))
    elif command == "remove_text" and pdf_file:
        output_file = sys.argv[3] if len(sys.argv) > 3 else "output_no_text.pdf"
        print(remove_text_keep_images(pdf_file, output_file))
    else:
        print("Invalid command or missing arguments")