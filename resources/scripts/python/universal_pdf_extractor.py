#!/usr/bin/env python3
"""
Universal PDF Extractor using PyMuPDF (fitz)
Complete extraction of all PDF components with maximum fidelity
"""

import sys
import json
import base64
import fitz  # PyMuPDF
import os
import traceback
from pathlib import Path
import re
import hashlib

def safe_json_dump(obj):
    """Safely dump object to JSON, handling special types"""
    def default(o):
        if isinstance(o, (fitz.Point, fitz.Rect, fitz.Matrix)):
            return str(o)
        elif isinstance(o, bytes):
            return base64.b64encode(o).decode('utf-8')
        else:
            return str(o)
    
    return json.dumps(obj, ensure_ascii=False, default=default)

def extract_pdf_components(pdf_path):
    """Extract ALL components from PDF with maximum detail"""
    try:
        # Open PDF with proper handling of CID fonts
        doc = fitz.open(pdf_path)
        
        # Set font substitution for better CID handling
        fitz.TOOLS.set_aa_level(0)  # Disable anti-aliasing for better text extraction
        
        result = {
            'success': True,
            'pages': len(doc),
            'metadata': extract_metadata(doc),
            'outline': extract_outline(doc),
            'embedded_files': extract_embedded_files(doc),
            'components': {
                'text': {},
                'images': {},
                'drawings': {},
                'tables': {},
                'forms': {},
                'annotations': {},
                'links': {},
                'backgrounds': {},
                'fonts': extract_fonts(doc),
                'layers': {}
            }
        }
        
        # Process each page
        for page_num, page in enumerate(doc, 1):
            # Get page dimensions and properties
            rect = page.rect
            rotation = page.rotation
            
            page_info = {
                'width': rect.width,
                'height': rect.height,
                'rotation': rotation,
                'mediabox': [rect.x0, rect.y0, rect.x1, rect.y1],
                'cropbox': list(page.cropbox),
                'bleedbox': list(page.bleedbox) if page.bleedbox else None,
                'trimbox': list(page.trimbox) if page.trimbox else None,
                'artbox': list(page.artbox) if page.artbox else None
            }
            
            # Extract all components for this page
            
            # 1. Text with complete formatting and positioning
            text_data = extract_text_complete(page)
            
            # Always try to extract text, even if it seems empty
            # Try multiple methods for CID fonts
            raw_text = page.get_text().strip()
            
            # If no text, try with text page
            if not raw_text:
                try:
                    tp = page.get_textpage()
                    raw_text = tp.extractText()
                except:
                    pass
            
            # If still no text, try HTML extraction and parse it
            if not raw_text:
                try:
                    html_text = page.get_text("html")
                    # Extract text from HTML
                    import re
                    raw_text = re.sub(r'<[^>]+>', '', html_text)
                except:
                    pass
            
            # Log for debugging (commented to avoid JSON corruption)
            # if raw_text:
            #     import sys
            #     print(f"Page {page_num} has {len(raw_text)} chars of raw text", file=sys.stderr)
            
            if text_data or raw_text:
                result['components']['text'][page_num] = {
                    'page_info': page_info,
                    'blocks': text_data['blocks'] if text_data else [],
                    'chars': text_data.get('chars', []) if text_data else [],
                    'raw_text': raw_text,
                    'text_page': extract_text_page_data(page)
                }
            
            # 2. Images with all metadata and positioning
            images = extract_images_complete(page, doc)
            if images:
                result['components']['images'][page_num] = images
            
            # 3. Vector graphics and drawings
            drawings = extract_drawings_complete(page)
            if drawings:
                result['components']['drawings'][page_num] = drawings
            
            # 4. Tables detection and extraction
            tables = extract_tables_advanced(page)
            if tables:
                result['components']['tables'][page_num] = tables
            
            # 5. Form fields and widgets
            forms = extract_forms_complete(page)
            if forms:
                result['components']['forms'][page_num] = forms
            
            # 6. Annotations (comments, highlights, etc.)
            annotations = extract_annotations_complete(page)
            if annotations:
                result['components']['annotations'][page_num] = annotations
            
            # 7. Links (internal and external)
            links = extract_links_complete(page)
            if links:
                result['components']['links'][page_num] = links
            
            # 8. Page background/watermark detection
            background = detect_background_elements(page)
            if background:
                result['components']['backgrounds'][page_num] = background
            
            # 9. OCR if needed (for scanned pages)
            if not text_data or len(text_data.get('blocks', [])) == 0:
                ocr_text = perform_ocr(page)
                if ocr_text:
                    result['components']['text'][page_num] = result['components']['text'].get(page_num, {})
                    result['components']['text'][page_num]['ocr'] = ocr_text
        
        doc.close()
        return result
        
    except Exception as e:
        return {
            'success': False,
            'error': str(e),
            'traceback': traceback.format_exc()
        }

def extract_metadata(doc):
    """Extract complete document metadata"""
    metadata = doc.metadata.copy() if doc.metadata else {}
    
    # Add additional document info
    metadata.update({
        'page_count': doc.page_count,
        'is_pdf': doc.is_pdf,
        'is_encrypted': doc.is_encrypted,
        'is_fast_webaccess': doc.is_fast_webaccess,
        'is_form_pdf': doc.is_form_pdf,
        'is_reflowable': doc.is_reflowable,
        'is_repaired': doc.is_repaired,
        'language': doc.language if hasattr(doc, 'language') else None,
        'needs_pass': doc.needs_pass,
        'permissions': doc.permissions,
        'pdf_version': doc.get_pdf_str() if hasattr(doc, 'get_pdf_str') else None
    })
    
    return metadata

def extract_outline(doc):
    """Extract document outline/bookmarks"""
    outline = []
    toc = doc.get_toc()
    
    for item in toc:
        outline.append({
            'level': item[0],
            'title': item[1],
            'page': item[2],
            'destination': item[3] if len(item) > 3 else None
        })
    
    return outline

def extract_embedded_files(doc):
    """Extract information about embedded files"""
    embedded = []
    
    try:
        embfile_names = doc.embfile_names()
        for name in embfile_names:
            info = doc.embfile_info(name)
            embedded.append({
                'name': name,
                'info': info,
                'size': info.get('size', 0) if info else 0
            })
    except:
        pass
    
    return embedded

def extract_fonts(doc):
    """Extract all fonts used in the document"""
    fonts = {}
    
    for page_num, page in enumerate(doc, 1):
        page_fonts = page.get_fonts()
        for font in page_fonts:
            font_name = font[3]  # Font name
            if font_name not in fonts:
                fonts[font_name] = {
                    'name': font_name,
                    'type': font[1],
                    'encoding': font[2],
                    'pages': []
                }
            fonts[font_name]['pages'].append(page_num)
    
    return fonts

def extract_text_complete(page):
    """Extract text with EXACT positioning for pixel-perfect HTML rendering"""
    try:
        # Get page dimensions for accurate positioning
        page_rect = page.rect
        page_width = page_rect.width
        page_height = page_rect.height
        
        # IMPORTANT: For CID fonts and Identity-H encoding, we need special handling
        # First try without flags for better CID font support
        text_dict = page.get_text("dict")
        
        # Check if we got actual text content
        has_text = False
        for block in text_dict.get("blocks", []):
            if block.get("type") == 0:
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        if span.get("text", "").strip():
                            has_text = True
                            break
        
        # If no text found, try with different flags
        if not has_text:
            # Try with minimal flags for CID fonts
            text_dict = page.get_text("dict", flags=0)
            
            # Still no text? Try rawdict
            if not has_text:
                text_dict = page.get_text("rawdict")
        
        blocks = []
        chars = []
        
        for block in text_dict.get("blocks", []):
            if block.get("type") == 0:  # Text block
                block_bbox = block.get("bbox", [0, 0, 0, 0])
                block_data = {
                    'type': 'text',
                    'bbox': block_bbox,
                    # Add percentage positions for HTML rendering
                    'x_percent': (block_bbox[0] / page_width) * 100 if page_width > 0 else 0,
                    'y_percent': (block_bbox[1] / page_height) * 100 if page_height > 0 else 0,
                    'width_percent': ((block_bbox[2] - block_bbox[0]) / page_width) * 100 if page_width > 0 else 0,
                    'height_percent': ((block_bbox[3] - block_bbox[1]) / page_height) * 100 if page_height > 0 else 0,
                    'lines': []
                }
                
                for line in block.get("lines", []):
                    line_bbox = line.get("bbox", [0, 0, 0, 0])
                    line_data = {
                        'bbox': line_bbox,
                        'spans': [],
                        'dir': line.get("dir"),
                        'wmode': line.get("wmode", 0)
                    }
                    
                    for span in line.get("spans", []):
                        # Extract complete span information
                        text = span.get("text", "")
                        
                        # Handle CID fonts and special encodings
                        if not text or text.isspace():
                            # Try to extract characters individually
                            chars = span.get("chars", [])
                            if chars:
                                text = ''.join(c.get("c", "") for c in chars)
                        
                        # Get comprehensive font information
                        font_name = span.get("font", "sans-serif")
                        font_size = span.get("size", 12)
                        font_flags = span.get("flags", 0)
                        
                        # Parse font family properly
                        # Remove font subtype suffixes and map to web fonts
                        clean_font = font_name.split('+')[-1].split('-')[0].split(',')[0] if font_name else "sans-serif"
                        
                        # Map common PDF fonts to web fonts
                        font_map = {
                            'Helvetica': 'Helvetica, Arial, sans-serif',
                            'Arial': 'Arial, Helvetica, sans-serif', 
                            'Times': '"Times New Roman", Times, serif',
                            'TimesNewRoman': '"Times New Roman", Times, serif',
                            'Courier': '"Courier New", Courier, monospace',
                            'Georgia': 'Georgia, serif',
                            'Verdana': 'Verdana, Geneva, sans-serif',
                            'Calibri': 'Calibri, Candara, Segoe, sans-serif',
                            'Cambria': 'Cambria, Georgia, serif',
                            'Roboto': 'Roboto, "Helvetica Neue", sans-serif',
                            'OpenSans': '"Open Sans", sans-serif',
                            'Lato': 'Lato, sans-serif',
                            'Montserrat': 'Montserrat, sans-serif'
                        }
                        
                        # Get the mapped font or use a fallback
                        web_font = font_map.get(clean_font, 'system-ui, -apple-system, sans-serif')
                        
                        # Determine font weight from flags
                        is_bold = bool(font_flags & 2**4)
                        font_weight = 700 if is_bold else 400
                        
                        span_data = {
                            'text': text,
                            'bbox': span.get("bbox"),
                            'font': font_name,
                            'font_family': web_font,
                            'size': font_size,
                            'weight': font_weight,
                            'flags': font_flags,
                            'color': "#{:06x}".format(span.get("color", 0)),
                            'origin': span.get("origin"),
                            'ascender': span.get("ascender"),
                            'descender': span.get("descender")
                        }
                        
                        # Determine text style from flags
                        span_data['bold'] = is_bold
                        span_data['italic'] = bool(font_flags & 2**1)
                        span_data['monospace'] = bool(font_flags & 2**3)
                        span_data['serif'] = bool(font_flags & 2**0)
                        span_data['superscript'] = bool(font_flags & 2**5)
                        span_data['subscript'] = bool(font_flags & 2**6)
                        span_data['underline'] = bool(font_flags & 2**3)
                        span_data['strikethrough'] = bool(font_flags & 2**7)
                        
                        line_data['spans'].append(span_data)
                        
                        # Extract individual characters for precise positioning
                        for char in span.get("chars", []):
                            chars.append({
                                'char': char.get("c"),
                                'bbox': char.get("bbox"),
                                'origin': char.get("origin"),
                                'font': span.get("font"),
                                'size': span.get("size"),
                                'color': span_data['color']
                            })
                    
                    block_data['lines'].append(line_data)
                
                blocks.append(block_data)
            
            elif block.get("type") == 1:  # Image block
                blocks.append({
                    'type': 'image',
                    'bbox': block.get("bbox"),
                    'ext': block.get("ext"),
                    'width': block.get("width"),
                    'height': block.get("height"),
                    'colorspace': block.get("colorspace"),
                    'bpc': block.get("bpc"),
                    'xres': block.get("xres"),
                    'yres': block.get("yres")
                })
        
        # If no blocks found, try alternative extraction methods
        if not blocks or all(not block.get('lines') for block in blocks if block['type'] == 'text'):
            # Try rawdict format for more detailed extraction
            raw_dict = page.get_text("rawdict", flags=flags)
            blocks = extract_from_rawdict(raw_dict)
            
            # If still no text, try simpler methods
            if not blocks:
                blocks = extract_text_fallback(page)
        
        return {
            'blocks': blocks,
            'chars': chars,
            'width': text_dict.get("width"),
            'height': text_dict.get("height")
        }
    
    except Exception as e:
        return None

def extract_from_rawdict(raw_dict):
    """Extract text from rawdict format"""
    blocks = []
    
    try:
        for block in raw_dict.get("blocks", []):
            if block.get("type") == 0:  # Text block
                block_data = {
                    'type': 'text',
                    'bbox': block.get("bbox"),
                    'lines': []
                }
                
                for line in block.get("lines", []):
                    line_data = {
                        'bbox': line.get("bbox"),
                        'spans': [],
                        'dir': line.get("dir"),
                        'wmode': line.get("wmode", 0)
                    }
                    
                    for span in line.get("spans", []):
                        text = span.get("text", "")
                        # Skip empty spans but keep spaces
                        if text or text == " ":
                            span_data = {
                                'text': text,
                                'bbox': span.get("bbox"),
                                'font': span.get("font", "Arial"),
                                'size': span.get("size", 12),
                                'flags': span.get("flags", 0),
                                'color': "#{:06x}".format(span.get("color", 0)),
                                'origin': span.get("origin")
                            }
                            
                            # Extract style flags
                            flags = span.get("flags", 0)
                            span_data['bold'] = bool(flags & 2**4)
                            span_data['italic'] = bool(flags & 2**1)
                            
                            line_data['spans'].append(span_data)
                    
                    if line_data['spans']:  # Only add lines with content
                        block_data['lines'].append(line_data)
                
                if block_data['lines']:  # Only add blocks with content
                    blocks.append(block_data)
    except:
        pass
    
    return blocks

def extract_text_fallback(page):
    """Fallback text extraction using multiple methods"""
    blocks = []
    
    try:
        # Method 1: Get text as blocks
        text_blocks = page.get_text("blocks")
        for b in text_blocks:
            if len(b) >= 7:  # Text block has at least 7 elements
                x0, y0, x1, y1, text, block_no, block_type = b[:7]
                if block_type == 0 and text.strip():  # Text block with content
                    blocks.append({
                        'type': 'text',
                        'bbox': [x0, y0, x1, y1],
                        'lines': [{
                            'bbox': [x0, y0, x1, y1],
                            'spans': [{
                                'text': text,
                                'bbox': [x0, y0, x1, y1],
                                'font': 'Unknown',
                                'size': 12,
                                'color': '#000000',
                                'bold': False,
                                'italic': False
                            }],
                            'dir': [1, 0],
                            'wmode': 0
                        }]
                    })
        
        # Method 2: If still no blocks, get words
        if not blocks:
            words = page.get_text("words")
            current_line = None
            current_block = {'type': 'text', 'bbox': None, 'lines': []}
            
            for w in words:
                if len(w) >= 5:
                    x0, y0, x1, y1, word = w[:5]
                    
                    # Group words into lines based on y-position
                    if current_line is None or abs(y0 - current_line['y']) > 2:
                        if current_line and current_line['spans']:
                            current_block['lines'].append(current_line['line_data'])
                        current_line = {
                            'y': y0,
                            'line_data': {
                                'bbox': [x0, y0, x1, y1],
                                'spans': [],
                                'dir': [1, 0],
                                'wmode': 0
                            }
                        }
                    
                    # Add word to current line
                    current_line['line_data']['spans'].append({
                        'text': word + ' ',
                        'bbox': [x0, y0, x1, y1],
                        'font': 'Unknown',
                        'size': abs(y1 - y0),
                        'color': '#000000',
                        'bold': False,
                        'italic': False
                    })
                    
                    # Update line bbox
                    if current_line['line_data']['spans']:
                        line_bbox = current_line['line_data']['bbox']
                        line_bbox[0] = min(line_bbox[0], x0)
                        line_bbox[2] = max(line_bbox[2], x1)
            
            # Add last line
            if current_line and current_line['spans']:
                current_block['lines'].append(current_line['line_data'])
            
            if current_block['lines']:
                # Calculate block bbox from all lines
                all_coords = []
                for line in current_block['lines']:
                    all_coords.extend([line['bbox'][0], line['bbox'][1], line['bbox'][2], line['bbox'][3]])
                if all_coords:
                    current_block['bbox'] = [
                        min(all_coords[::4]),  # min x
                        min(all_coords[1::4]), # min y  
                        max(all_coords[2::4]), # max x
                        max(all_coords[3::4])  # max y
                    ]
                    blocks.append(current_block)
    except Exception as e:
        pass
    
    return blocks

def extract_text_page_data(page):
    """Extract text page structure for text reflow"""
    try:
        # Extract text with different methods for comparison
        text_formats = {
            'text': page.get_text(),
            'html': page.get_text("html"),
            'xml': page.get_text("xml"),
            'xhtml': page.get_text("xhtml"),
            'blocks': page.get_text("blocks"),
            'words': page.get_text("words")
        }
        
        return text_formats
    except:
        return {}

def extract_images_complete(page, doc):
    """Extract all images with complete metadata"""
    images = []
    
    try:
        # Get list of all images on the page
        image_list = page.get_images(full=True)
        
        for img_index, img_info in enumerate(image_list):
            xref = img_info[0]
            smask = img_info[1]
            width = img_info[2]
            height = img_info[3]
            bpc = img_info[4]  # bits per component
            colorspace = img_info[5]
            alt_colorspace = img_info[6]
            name = img_info[7]
            
            # Get image position(s) on page
            rects = page.get_image_rects(xref)
            
            # Extract image data
            try:
                # Get the pixmap
                pix = fitz.Pixmap(doc, xref)
                
                # Convert CMYK to RGB if necessary
                if pix.colorspace and pix.colorspace.n == 4:  # CMYK
                    pix = fitz.Pixmap(fitz.csRGB, pix)
                
                # Remove alpha channel if present
                if pix.alpha:
                    pix = fitz.Pixmap(pix, 0)
                
                # Get image as PNG
                img_data = pix.tobytes("png")
                img_base64 = base64.b64encode(img_data).decode('utf-8')
                
                # Calculate image hash for deduplication
                img_hash = hashlib.md5(img_data).hexdigest()
                
                # Get additional image properties
                image_info = {
                    'index': img_index,
                    'xref': xref,
                    'smask': smask,
                    'name': name,
                    'width': width,
                    'height': height,
                    'bpc': bpc,
                    'colorspace': colorspace,
                    'alt_colorspace': alt_colorspace,
                    'data': img_base64,
                    'hash': img_hash,
                    'type': 'image/png',
                    'positions': []
                }
                
                # Add all positions where this image appears
                if rects:
                    for rect in rects:
                        image_info['positions'].append({
                            'x': rect.x0,
                            'y': rect.y0,
                            'width': rect.width,
                            'height': rect.height,
                            'transform': page.get_image_bbox(name) if name else None
                        })
                else:
                    # If no rects found, try to get position from page resources
                    # Default position if we can't find the exact location
                    image_info['positions'].append({
                        'x': 0,
                        'y': 0,
                        'width': width,
                        'height': height,
                        'transform': None
                    })
                
                images.append(image_info)
                
                pix = None
            except Exception as e:
                # If we can't extract the image, still record its presence
                images.append({
                    'index': img_index,
                    'xref': xref,
                    'error': str(e),
                    'positions': [{'x': r.x0, 'y': r.y0, 'width': r.width, 'height': r.height} for r in rects]
                })
    
    except Exception as e:
        pass
    
    return images

def extract_drawings_complete(page):
    """Extract ONLY graphical elements (lines, borders, backgrounds) - NO TEXT"""
    drawings = []
    
    try:
        # Get page dimensions for relative positioning
        page_rect = page.rect
        page_width = page_rect.width
        page_height = page_rect.height
        
        # Get all drawings on the page
        paths = page.get_drawings()
        
        for path_index, path in enumerate(paths):
            # Skip if this looks like text (very small height, typical text dimensions)
            rect = path.get('rect')
            if rect:
                height = rect.y1 - rect.y0
                width = rect.x1 - rect.x0
                # Skip tiny elements that are likely text decorations
                if height < 2 and width > 20:  # Likely underline or text decoration
                    continue
                if height < 15 and width < 200 and height > 0:  # Might be text
                    # Check if it's actually a line or border
                    if path.get('fill') and not path.get('stroke'):
                        continue  # Skip filled tiny rectangles (likely text)
            
            drawing = {
                'index': path_index,
                'type': 'drawing',
                'rect': [rect.x0, rect.y0, rect.x1, rect.y1] if rect else None,
                'x_percent': (rect.x0 / page_width * 100) if rect else 0,
                'y_percent': (rect.y0 / page_height * 100) if rect else 0,
                'width_percent': ((rect.x1 - rect.x0) / page_width * 100) if rect else 0,
                'height_percent': ((rect.y1 - rect.y0) / page_height * 100) if rect else 0,
                'fill': get_color_hex(path.get('fill')) if path.get('fill') else None,
                'color': get_color_hex(path.get('color')) if path.get('color') else None,
                'stroke': get_color_hex(path.get('stroke')) if path.get('stroke') else None,
                'width': path.get('width', 1),
                'lineCap': path.get('lineCap'),
                'lineJoin': path.get('lineJoin'),
                'dashes': path.get('dashes'),
                'opacity': path.get('opacity', 1),
                'blend_mode': path.get('blend_mode'),
                'items': []
            }
            
            # Process each item in the path
            for item in path.get('items', []):
                item_type = item[0]
                
                if item_type == 'l':  # Line
                    drawing['items'].append({
                        'type': 'line',
                        'from': {'x': item[1].x, 'y': item[1].y} if hasattr(item[1], 'x') else {'x': item[1][0], 'y': item[1][1]},
                        'to': {'x': item[2].x, 'y': item[2].y} if hasattr(item[2], 'x') else {'x': item[2][0], 'y': item[2][1]}
                    })
                    
                elif item_type == 're':  # Rectangle
                    if hasattr(item[1], 'x'):
                        drawing['items'].append({
                            'type': 'rect',
                            'x': item[1].x,
                            'y': item[1].y,
                            'width': item[2].x - item[1].x,
                            'height': item[2].y - item[1].y
                        })
                    else:
                        drawing['items'].append({
                            'type': 'rect',
                            'x': item[1][0],
                            'y': item[1][1],
                            'width': item[2][0] - item[1][0],
                            'height': item[2][1] - item[1][1]
                        })
                    
                elif item_type == 'qu':  # Quad (4 points)
                    points = []
                    for i in range(1, 5):
                        if hasattr(item[i], 'x'):
                            points.append({'x': item[i].x, 'y': item[i].y})
                        else:
                            points.append({'x': item[i][0], 'y': item[i][1]})
                    drawing['items'].append({
                        'type': 'quad',
                        'points': points
                    })
                    
                elif item_type == 'c':  # Curve (Bezier)
                    points = []
                    for i in range(1, len(item)):
                        if hasattr(item[i], 'x'):
                            points.append({'x': item[i].x, 'y': item[i].y})
                        else:
                            points.append({'x': item[i][0], 'y': item[i][1]})
                    drawing['items'].append({
                        'type': 'curve',
                        'points': points
                    })
            
            if drawing['items']:
                drawings.append(drawing)
    
    except Exception as e:
        pass
    
    return drawings

def extract_tables_advanced(page):
    """Advanced table detection and extraction"""
    tables = []
    
    try:
        # Method 1: Use text positioning to detect tables
        text_dict = page.get_text("dict")
        
        # Group text by vertical position
        rows_by_y = {}
        for block in text_dict.get("blocks", []):
            if block.get("type") == 0:  # Text block
                for line in block.get("lines", []):
                    y = round(line["bbox"][1], 1)  # Round to nearest 0.1
                    if y not in rows_by_y:
                        rows_by_y[y] = []
                    
                    for span in line.get("spans", []):
                        rows_by_y[y].append({
                            'x': span["bbox"][0],
                            'text': span["text"],
                            'bbox': span["bbox"],
                            'font': span.get("font"),
                            'size': span.get("size")
                        })
        
        # Sort rows by Y position
        sorted_rows = sorted(rows_by_y.items())
        
        # Detect table structures
        potential_table = []
        prev_y = None
        
        for y, cells in sorted_rows:
            # Check if this could be a table row (multiple cells)
            if len(cells) > 1:
                # Sort cells by X position
                cells.sort(key=lambda c: c['x'])
                
                # Check if row spacing is consistent (table-like)
                if prev_y is None or abs(y - prev_y) < 30:  # Max 30 units between rows
                    potential_table.append({
                        'y': y,
                        'cells': cells
                    })
                    prev_y = y
                else:
                    # Save current table if we have one
                    if len(potential_table) > 1:
                        tables.append(process_table_data(potential_table))
                    potential_table = [{'y': y, 'cells': cells}]
                    prev_y = y
            else:
                # Single cell - might be end of table
                if len(potential_table) > 1:
                    tables.append(process_table_data(potential_table))
                    potential_table = []
                    prev_y = None
        
        # Don't forget the last table
        if len(potential_table) > 1:
            tables.append(process_table_data(potential_table))
    
    except Exception as e:
        pass
    
    return tables

def process_table_data(table_rows):
    """Process raw table data into structured format"""
    if not table_rows:
        return None
    
    # Find column positions
    all_x_positions = set()
    for row in table_rows:
        for cell in row['cells']:
            all_x_positions.add(round(cell['x'], 1))
    
    col_positions = sorted(all_x_positions)
    
    # Build table structure
    table = {
        'row_count': len(table_rows),
        'col_count': len(col_positions),
        'column_positions': col_positions,
        'rows': []
    }
    
    for row_data in table_rows:
        row = []
        cells = row_data['cells']
        
        # Map cells to columns
        for col_x in col_positions:
            cell_text = ""
            for cell in cells:
                if abs(cell['x'] - col_x) < 5:  # Within 5 units
                    cell_text = cell['text']
                    break
            row.append(cell_text)
        
        table['rows'].append(row)
    
    # Detect header row (usually first row with different formatting)
    if table['rows']:
        table['has_header'] = True  # Assume first row is header
        table['header'] = table['rows'][0]
        table['data'] = table['rows'][1:] if len(table['rows']) > 1 else []
    
    return table

def extract_forms_complete(page):
    """Extract all form fields and widgets"""
    forms = []
    
    try:
        widgets = page.widgets()
        
        for widget in widgets:
            form_field = {
                'type': widget.field_type_string,
                'type_code': widget.field_type,
                'name': widget.field_name,
                'value': widget.field_value,
                'default_value': widget.field_default_value,
                'label': widget.field_label,
                'flags': widget.field_flags,
                'rect': [widget.rect.x0, widget.rect.y0, widget.rect.x1, widget.rect.y1],
                'border_style': widget.border_style,
                'border_width': widget.border_width,
                'border_color': widget.border_color,
                'fill_color': widget.fill_color,
                'text_color': widget.text_color,
                'text_font': widget.text_font,
                'text_fontsize': widget.text_fontsize,
                'text_maxlen': widget.text_maxlen,
                'button_caption': widget.button_caption,
                'is_signed': widget.is_signed
            }
            
            # Handle choice fields (dropdowns, lists)
            if widget.field_type in [5, 6]:  # Choice fields
                form_field['choices'] = widget.choice_values
                form_field['selected'] = widget.field_value
            
            # Handle checkbox/radio
            if widget.field_type in [2, 3]:  # Checkbox or Radio
                form_field['checked'] = widget.field_value == widget.on_state
                form_field['on_state'] = widget.on_state
                form_field['off_state'] = widget.off_state
            
            forms.append(form_field)
    
    except Exception as e:
        pass
    
    return forms

def extract_annotations_complete(page):
    """Extract all annotations including comments, highlights, etc."""
    annotations = []
    
    try:
        for annot in page.annots():
            annotation = {
                'type': annot.type[1],  # Type name
                'type_code': annot.type[0],  # Type code
                'content': annot.info.get("content", ""),
                'title': annot.info.get("title", ""),
                'subject': annot.info.get("subject", ""),
                'author': annot.info.get("name", ""),
                'rect': [annot.rect.x0, annot.rect.y0, annot.rect.x1, annot.rect.y1],
                'page': page.number,
                'flags': annot.flags,
                'colors': {
                    'stroke': annot.colors.get("stroke"),
                    'fill': annot.colors.get("fill")
                },
                'opacity': annot.opacity,
                'creation_date': annot.info.get("creationDate"),
                'modification_date': annot.info.get("modDate"),
                'popup': annot.popup,
                'is_open': annot.is_open
            }
            
            # Handle specific annotation types
            if annot.type[0] == 2:  # Highlight
                annotation['highlighted_text'] = page.get_textbox(annot.rect)
            elif annot.type[0] == 1:  # Text/Note
                annotation['icon'] = annot.info.get("icon")
            elif annot.type[0] == 3:  # Underline
                annotation['underlined_text'] = page.get_textbox(annot.rect)
            elif annot.type[0] == 4:  # Strikeout
                annotation['strikeout_text'] = page.get_textbox(annot.rect)
            
            annotations.append(annotation)
    
    except Exception as e:
        pass
    
    return annotations

def extract_links_complete(page):
    """Extract all links (internal and external)"""
    links = []
    
    try:
        for link in page.get_links():
            link_info = {
                'kind': link.get('kind'),  # 1=internal, 2=external, 3=launch, 4=named
                'from': [link['from'].x0, link['from'].y0, link['from'].x1, link['from'].y1] if 'from' in link else None,
                'page': link.get('page'),
                'to': link.get('to'),
                'file': link.get('file'),
                'uri': link.get('uri'),
                'xref': link.get('xref')
            }
            
            # Get link text if possible
            if 'from' in link:
                try:
                    link_info['text'] = page.get_textbox(link['from'])
                except:
                    link_info['text'] = ""
            
            links.append(link_info)
    
    except Exception as e:
        pass
    
    return links

def detect_background_elements(page):
    """Detect background and watermark elements"""
    background = {
        'has_watermark': False,
        'has_background': False,
        'elements': []
    }
    
    try:
        # Check for large images that could be backgrounds
        images = page.get_images()
        page_rect = page.rect
        
        for img in images:
            rects = page.get_image_rects(img[0])
            for rect in rects:
                # If image covers most of the page, it's likely a background
                coverage = (rect.width * rect.height) / (page_rect.width * page_rect.height)
                if coverage > 0.8:
                    background['has_background'] = True
                    background['elements'].append({
                        'type': 'background_image',
                        'coverage': coverage,
                        'rect': [rect.x0, rect.y0, rect.x1, rect.y1]
                    })
        
        # Check for semi-transparent text (watermarks)
        text_dict = page.get_text("dict")
        for block in text_dict.get("blocks", []):
            if block.get("type") == 0:
                for line in block.get("lines", []):
                    for span in line.get("spans", []):
                        # Check if text has low opacity or is very large
                        if span.get("size", 0) > 50:  # Large text
                            background['has_watermark'] = True
                            background['elements'].append({
                                'type': 'watermark_text',
                                'text': span.get("text"),
                                'size': span.get("size"),
                                'bbox': span.get("bbox")
                            })
    
    except Exception as e:
        pass
    
    return background

def perform_ocr(page):
    """Perform OCR on page if needed"""
    ocr_result = None
    
    try:
        # Check if page has no text
        text = page.get_text()
        if not text.strip():
            # Page seems to be scanned - perform OCR
            # Note: This requires tesseract to be installed
            import subprocess
            import tempfile
            
            # Render page as image
            pix = page.get_pixmap(dpi=300)
            
            with tempfile.NamedTemporaryFile(suffix='.png', delete=False) as tmp:
                pix.save(tmp.name)
                
                # Run tesseract
                try:
                    result = subprocess.run(
                        ['tesseract', tmp.name, 'stdout', '--dpi', '300', 'hocr'],
                        capture_output=True,
                        text=True
                    )
                    
                    if result.returncode == 0:
                        ocr_result = {
                            'text': result.stdout,
                            'format': 'hocr'
                        }
                except:
                    # Tesseract not available
                    pass
                
                os.unlink(tmp.name)
            
            pix = None
    
    except:
        pass
    
    return ocr_result

def render_page_as_image(pdf_path, page_num, dpi=150):
    """Render a specific page as high-quality image"""
    try:
        doc = fitz.open(pdf_path)
        
        if page_num < 1 or page_num > doc.page_count:
            return {
                'success': False,
                'error': f'Page {page_num} out of range (1-{doc.page_count})'
            }
        
        page = doc[page_num - 1]
        
        # Render page at specified DPI
        mat = fitz.Matrix(dpi/72.0, dpi/72.0)
        pix = page.get_pixmap(matrix=mat, alpha=False)
        
        # Convert to PNG
        img_data = pix.tobytes("png")
        img_base64 = base64.b64encode(img_data).decode('utf-8')
        
        result = {
            'success': True,
            'page': page_num,
            'data': img_base64,
            'width': pix.width,
            'height': pix.height,
            'type': 'image/png',
            'dpi': dpi
        }
        
        pix = None
        doc.close()
        
        return result
        
    except Exception as e:
        return {
            'success': False,
            'error': str(e),
            'traceback': traceback.format_exc()
        }

def main():
    if len(sys.argv) < 3:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python universal_pdf_extractor.py <action> <pdf_path> [options]'
        }))
        sys.exit(1)
    
    action = sys.argv[1]
    pdf_path = sys.argv[2]
    
    if not os.path.exists(pdf_path):
        print(json.dumps({
            'success': False,
            'error': f'PDF file not found: {pdf_path}'
        }))
        sys.exit(1)
    
    try:
        if action == 'extract':
            result = extract_pdf_components(pdf_path)
        elif action == 'render':
            page_num = int(sys.argv[3]) if len(sys.argv) > 3 else 1
            dpi = int(sys.argv[4]) if len(sys.argv) > 4 else 150
            result = render_page_as_image(pdf_path, page_num, dpi)
        else:
            result = {
                'success': False,
                'error': f'Unknown action: {action}'
            }
        
        print(safe_json_dump(result))
        
    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': str(e),
            'traceback': traceback.format_exc()
        }))

if __name__ == "__main__":
    main()