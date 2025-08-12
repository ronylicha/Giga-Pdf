#!/usr/bin/env python3
"""
PDF Protection Removal Script
Attempts to remove PDF protection without password
"""

import sys
import os
try:
    import PyPDF2
except ImportError:
    try:
        from pypdf import PdfReader, PdfWriter
        PyPDF2 = None
    except ImportError:
        print("Error: PyPDF2 or pypdf not installed. Install with: pip3 install pypdf", file=sys.stderr)
        sys.exit(1)

def remove_protection(input_path, output_path):
    """
    Attempt to remove PDF protection without password
    """
    try:
        if PyPDF2:
            # Using PyPDF2
            with open(input_path, 'rb') as input_file:
                reader = PyPDF2.PdfFileReader(input_file)
                
                # Check if encrypted
                if reader.isEncrypted:
                    # Try to decrypt with empty password
                    if not reader.decrypt(''):
                        # Try to decrypt with common default passwords
                        common_passwords = ['', ' ', '1234', '0000', 'password']
                        decrypted = False
                        for pwd in common_passwords:
                            if reader.decrypt(pwd):
                                decrypted = True
                                break
                        
                        if not decrypted:
                            print("Error: Cannot decrypt PDF without correct password", file=sys.stderr)
                            return False
                
                # Create writer and copy pages
                writer = PyPDF2.PdfFileWriter()
                for page_num in range(reader.numPages):
                    page = reader.getPage(page_num)
                    writer.addPage(page)
                
                # Write to output
                with open(output_path, 'wb') as output_file:
                    writer.write(output_file)
                
                return True
        else:
            # Using pypdf (newer version)
            from pypdf import PdfReader, PdfWriter
            
            reader = PdfReader(input_path)
            
            # Check if encrypted
            if reader.is_encrypted:
                # Try to decrypt with empty password
                if not reader.decrypt(''):
                    # Try common passwords
                    common_passwords = ['', ' ', '1234', '0000', 'password']
                    decrypted = False
                    for pwd in common_passwords:
                        if reader.decrypt(pwd):
                            decrypted = True
                            break
                    
                    if not decrypted:
                        print("Error: Cannot decrypt PDF without correct password", file=sys.stderr)
                        return False
            
            # Create writer and copy pages
            writer = PdfWriter()
            for page in reader.pages:
                writer.add_page(page)
            
            # Write to output
            with open(output_path, 'wb') as output_file:
                writer.write(output_file)
            
            return True
            
    except Exception as e:
        print(f"Error: {str(e)}", file=sys.stderr)
        return False

def main():
    if len(sys.argv) != 3:
        print("Usage: python3 remove_pdf_protection.py input.pdf output.pdf", file=sys.stderr)
        sys.exit(1)
    
    input_path = sys.argv[1]
    output_path = sys.argv[2]
    
    if not os.path.exists(input_path):
        print(f"Error: Input file '{input_path}' does not exist", file=sys.stderr)
        sys.exit(1)
    
    if remove_protection(input_path, output_path):
        print("PDF protection removed successfully")
        sys.exit(0)
    else:
        sys.exit(1)

if __name__ == "__main__":
    main()