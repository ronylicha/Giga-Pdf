/**
 * PDF Editor Image Fix
 * Fixes image display issues in the HTML editor
 */

(function() {
    'use strict';

    // Fix CSS for existing images
    function fixImageStyles() {
        // Add global CSS fixes
        const style = document.createElement('style');
        style.textContent = `
            /* Fix for image containers */
            .pdf-image-container {
                position: absolute !important;
                overflow: visible !important;
                background-color: rgba(255, 255, 255, 0.1);
                border: 1px dashed rgba(0, 123, 255, 0.3);
                cursor: move;
                z-index: 100 !important;
            }
            
            .pdf-image-container.active {
                border: 2px solid #007bff;
                box-shadow: 0 0 10px rgba(0, 123, 255, 0.3);
            }
            
            /* Fix for images */
            .pdf-image, 
            .pdf-image-container img {
                display: block !important;
                width: 100% !important;
                height: 100% !important;
                object-fit: contain !important;
                visibility: visible !important;
                opacity: 1 !important;
                max-width: none !important;
                max-height: none !important;
                pointer-events: auto !important;
            }
            
            /* Fix for vector images */
            .pdf-vector {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                pointer-events: auto !important;
            }
            
            /* Image controls */
            .pdf-image-controls {
                position: absolute;
                top: -35px;
                right: 0;
                display: none;
                background: white;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 4px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                z-index: 1000;
            }
            
            .pdf-image-container:hover .pdf-image-controls,
            .pdf-image-container.active .pdf-image-controls {
                display: flex !important;
                gap: 4px;
            }
            
            .pdf-image-controls button {
                padding: 4px 8px;
                border: 1px solid #ddd;
                background: white;
                cursor: pointer;
                border-radius: 3px;
                font-size: 12px;
            }
            
            .pdf-image-controls button:hover {
                background: #f0f0f0;
            }
            
            /* Resize handles */
            .resize-handle {
                position: absolute;
                width: 10px;
                height: 10px;
                background: #007bff;
                border: 1px solid white;
                border-radius: 50%;
                cursor: pointer;
                z-index: 1001;
                display: none;
            }
            
            .pdf-image-container:hover .resize-handle,
            .pdf-image-container.active .resize-handle {
                display: block !important;
            }
            
            .resize-handle.nw { top: -5px; left: -5px; cursor: nw-resize; }
            .resize-handle.ne { top: -5px; right: -5px; cursor: ne-resize; }
            .resize-handle.sw { bottom: -5px; left: -5px; cursor: sw-resize; }
            .resize-handle.se { bottom: -5px; right: -5px; cursor: se-resize; }
        `;
        document.head.appendChild(style);
    }

    // Enhanced image insertion function
    function insertImage(imageData, pageNumber = 1, x = 50, y = 50) {
        const pageContainer = document.querySelector(`.pdf-page-container[data-page-number="${pageNumber}"]`) 
                           || document.querySelector('.pdf-page-container');
        
        if (!pageContainer) {
            console.error('Page container not found');
            return null;
        }

        // Create image container
        const container = document.createElement('div');
        container.className = 'pdf-image-container';
        container.style.cssText = `
            position: absolute;
            left: ${x}px;
            top: ${y}px;
            width: 200px;
            height: auto;
            z-index: 100;
        `;

        // Create image element
        const img = document.createElement('img');
        img.className = 'pdf-image';
        img.src = imageData;
        
        // Handle image load
        img.onload = function() {
            const aspectRatio = img.naturalHeight / img.naturalWidth;
            const containerWidth = 200;
            const containerHeight = containerWidth * aspectRatio;
            container.style.height = containerHeight + 'px';
            
            console.log('Image loaded:', {
                naturalWidth: img.naturalWidth,
                naturalHeight: img.naturalHeight,
                containerWidth: containerWidth,
                containerHeight: containerHeight
            });
        };
        
        img.onerror = function() {
            console.error('Failed to load image');
            container.style.backgroundColor = 'rgba(255, 0, 0, 0.1)';
        };

        // Add controls
        const controls = document.createElement('div');
        controls.className = 'pdf-image-controls';
        controls.innerHTML = `
            <button onclick="replaceImage(this)">Remplacer</button>
            <button onclick="deleteImage(this)">Supprimer</button>
        `;

        // Add resize handles
        const handles = ['nw', 'ne', 'sw', 'se'];
        handles.forEach(position => {
            const handle = document.createElement('div');
            handle.className = `resize-handle ${position}`;
            container.appendChild(handle);
        });

        // Assemble container
        container.appendChild(img);
        container.appendChild(controls);
        pageContainer.appendChild(container);

        // Make draggable
        makeDraggable(container);
        
        // Make resizable
        makeResizable(container);

        return container;
    }

    // Make element draggable
    function makeDraggable(element) {
        let isDragging = false;
        let startX, startY, initialLeft, initialTop;

        element.addEventListener('mousedown', function(e) {
            if (e.target.classList.contains('resize-handle') || 
                e.target.tagName === 'BUTTON') {
                return;
            }
            
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            initialLeft = element.offsetLeft;
            initialTop = element.offsetTop;
            
            element.classList.add('active');
            e.preventDefault();
        });

        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            
            element.style.left = (initialLeft + dx) + 'px';
            element.style.top = (initialTop + dy) + 'px';
        });

        document.addEventListener('mouseup', function() {
            if (isDragging) {
                isDragging = false;
                element.classList.remove('active');
            }
        });
    }

    // Make element resizable
    function makeResizable(element) {
        const handles = element.querySelectorAll('.resize-handle');
        
        handles.forEach(handle => {
            let isResizing = false;
            let startX, startY, startWidth, startHeight, startLeft, startTop;

            handle.addEventListener('mousedown', function(e) {
                isResizing = true;
                startX = e.clientX;
                startY = e.clientY;
                startWidth = element.offsetWidth;
                startHeight = element.offsetHeight;
                startLeft = element.offsetLeft;
                startTop = element.offsetTop;
                
                element.classList.add('active');
                e.preventDefault();
                e.stopPropagation();
            });

            document.addEventListener('mousemove', function(e) {
                if (!isResizing) return;

                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                const position = handle.className.split(' ')[1];

                switch(position) {
                    case 'se':
                        element.style.width = (startWidth + dx) + 'px';
                        element.style.height = (startHeight + dy) + 'px';
                        break;
                    case 'sw':
                        element.style.width = (startWidth - dx) + 'px';
                        element.style.height = (startHeight + dy) + 'px';
                        element.style.left = (startLeft + dx) + 'px';
                        break;
                    case 'ne':
                        element.style.width = (startWidth + dx) + 'px';
                        element.style.height = (startHeight - dy) + 'px';
                        element.style.top = (startTop + dy) + 'px';
                        break;
                    case 'nw':
                        element.style.width = (startWidth - dx) + 'px';
                        element.style.height = (startHeight - dy) + 'px';
                        element.style.left = (startLeft + dx) + 'px';
                        element.style.top = (startTop + dy) + 'px';
                        break;
                }
            });

            document.addEventListener('mouseup', function() {
                if (isResizing) {
                    isResizing = false;
                    element.classList.remove('active');
                }
            });
        });
    }

    // Fix existing images on page
    function fixExistingImages() {
        // Fix all images with pdf-image or pdf-vector class
        const images = document.querySelectorAll('.pdf-image, .pdf-vector, img[src^="data:image"]');
        
        images.forEach((img, index) => {
            // Ensure image is visible
            img.style.display = 'block';
            img.style.visibility = 'visible';
            img.style.opacity = '1';
            
            // If image is in a container, fix container too
            const container = img.closest('.pdf-image-container');
            if (container) {
                container.style.overflow = 'visible';
                container.style.zIndex = '100';
                
                // Add draggable and resizable if not already present
                if (!container.querySelector('.resize-handle')) {
                    const handles = ['nw', 'ne', 'sw', 'se'];
                    handles.forEach(position => {
                        const handle = document.createElement('div');
                        handle.className = `resize-handle ${position}`;
                        container.appendChild(handle);
                    });
                    
                    makeDraggable(container);
                    makeResizable(container);
                }
                
                // Don't add controls if they already exist
                // The HTML already includes .pdf-image-controls with buttons
            }
            
            console.log(`Fixed image ${index + 1}:`, {
                src: img.src.substring(0, 50) + '...',
                width: img.offsetWidth,
                height: img.offsetHeight,
                display: window.getComputedStyle(img).display,
                visibility: window.getComputedStyle(img).visibility
            });
        });
    }

    // Delete image function
    window.deleteImage = function(button) {
        const container = button.closest('.pdf-image-container');
        if (container && confirm('Supprimer cette image ?')) {
            container.remove();
        }
    };

    // Replace image function
    window.replaceImage = function(button) {
        const container = button.closest('.pdf-image-container');
        if (!container) return;
        
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const img = container.querySelector('img');
                    if (img) {
                        img.src = event.target.result;
                    }
                };
                reader.readAsDataURL(file);
            }
        };
        input.click();
    };

    // Debug function to check all images
    window.debugImages = function() {
        const images = document.querySelectorAll('img');
        console.log('Total images found:', images.length);
        
        images.forEach((img, index) => {
            const rect = img.getBoundingClientRect();
            const computed = window.getComputedStyle(img);
            
            console.log(`Image ${index + 1}:`, {
                src: img.src.substring(0, 50) + '...',
                classList: img.className,
                dimensions: `${img.offsetWidth}x${img.offsetHeight}`,
                boundingRect: `${rect.width}x${rect.height}`,
                position: `(${rect.left}, ${rect.top})`,
                display: computed.display,
                visibility: computed.visibility,
                opacity: computed.opacity,
                zIndex: computed.zIndex,
                naturalSize: `${img.naturalWidth}x${img.naturalHeight}`,
                complete: img.complete,
                parent: img.parentElement?.className
            });
            
            // Highlight image for debugging
            img.style.border = '2px solid red';
            setTimeout(() => {
                img.style.border = '';
            }, 3000);
        });
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            fixImageStyles();
            setTimeout(fixExistingImages, 100);
        });
    } else {
        fixImageStyles();
        setTimeout(fixExistingImages, 100);
    }

    // Re-fix images after dynamic content loads
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                setTimeout(fixExistingImages, 100);
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Export functions for global use
    window.pdfEditorFix = {
        insertImage: insertImage,
        fixExistingImages: fixExistingImages,
        debugImages: window.debugImages
    };

    console.log('PDF Editor Fix loaded. Use window.debugImages() to check images.');
})();