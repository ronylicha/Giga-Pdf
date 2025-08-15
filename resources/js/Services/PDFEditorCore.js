/**
 * PDFEditorCore.js
 * Core service for PDF WYSIWYG Editor - 100% custom solution
 * Preserves original PDF formatting with absolute positioning
 */

export class PDFEditorCore {
    constructor() {
        this.elements = new Map();
        this.history = [];
        this.historyIndex = -1;
        this.maxHistorySize = 50;
        this.selectedElements = new Set();
        this.isDragging = false;
        this.isResizing = false;
        this.dragStartPos = { x: 0, y: 0 };
        this.elementStartPos = { x: 0, y: 0 };
        this.observers = [];
        this.clipboard = null;
        this.gridEnabled = false;
        this.gridSize = 10;
        this.multiSelectDrag = false;
        this.draggedElements = [];
    }

    /**
     * Initialize editor with container
     */
    init(container) {
        this.container = container;
        this.scanExistingElements();
        this.attachGlobalListeners();
        this.createOverlayCanvas();
    }

    /**
     * Scan and register all existing PDF elements
     */
    scanExistingElements() {
        const elements = this.container.querySelectorAll('.pdf-text, .pdf-image, .pdf-vector, .pdf-table');
        elements.forEach(el => {
            const id = el.dataset.elementId || this.generateId();
            el.dataset.elementId = id;
            
            // Add control icons to each element
            this.addControlIcons(el);
            
            this.elements.set(id, {
                id,
                element: el,
                type: this.getElementType(el),
                original: {
                    html: el.outerHTML,
                    styles: this.captureStyles(el),
                    position: this.getPosition(el),
                    dimensions: this.getDimensions(el)
                },
                modified: false
            });

            this.makeElementInteractive(el);
        });
    }

    /**
     * Make element interactive (selectable, editable)
     */
    makeElementInteractive(element) {
        const type = this.getElementType(element);
        
        // Add drag handle to element
        this.addDragHandle(element);
        
        // Text elements
        if (type === 'text') {
            element.contentEditable = true;
            element.spellcheck = false;
            
            element.addEventListener('focus', (e) => {
                this.selectElement(element);
                this.saveState();
            });
            
            element.addEventListener('input', (e) => {
                this.markAsModified(element);
                this.notifyObservers('text-changed', element);
            });
            
            element.addEventListener('blur', (e) => {
                this.saveState();
            });
        }
        
        // All elements are draggable
        element.addEventListener('mousedown', (e) => {
            // Only start drag if not clicking on text content or drag handle is used
            if (e.target.classList.contains('drag-handle') || 
                (type !== 'text' || element.contentEditable === 'false')) {
                this.startDrag(e, element);
            }
        });
        
        // Images and vectors are resizable
        if (type === 'image' || type === 'vector') {
            this.addResizeHandles(element);
        }
        
        // Selection on click
        element.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!e.shiftKey) {
                this.clearSelection();
            }
            this.selectElement(element);
        });
        
        // Context menu
        element.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            this.showContextMenu(e, element);
        });
    }

    /**
     * Add drag handle to element
     */
    addDragHandle(element) {
        // Check if handle already exists
        if (element.dataset.hasHandle === 'true') return;
        
        // Don't add handle if element doesn't have absolute positioning
        const position = window.getComputedStyle(element).position;
        if (position !== 'absolute' && position !== 'fixed') return;
        
        // Create handle container that won't affect layout
        const handleContainer = document.createElement('div');
        handleContainer.className = 'drag-handle-container';
        handleContainer.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        `;
        
        const handle = document.createElement('div');
        handle.className = 'drag-handle';
        handle.innerHTML = `
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M8 6l4-4 4 4M8 18l4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M12 2v20M6 8l-4 4 4 4M18 8l4 4-4 4" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M2 12h20" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        `;
        
        handle.style.cssText = `
            position: absolute;
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            width: 28px;
            height: 28px;
            background: #007bff;
            color: white;
            border-radius: 6px;
            cursor: move;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1001;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
            transition: all 0.2s ease;
            border: 2px solid white;
            pointer-events: auto;
        `;
        
        // Show drag handle on hover
        handle.addEventListener('mouseenter', () => {
            handle.style.background = '#0056b3';
            handle.style.transform = 'translateX(-50%) scale(1.1)';
        });
        
        handle.addEventListener('mouseleave', () => {
            handle.style.background = '#007bff';
            handle.style.transform = 'translateX(-50%) scale(1)';
        });
        
        // Prevent text selection when dragging
        handle.addEventListener('mousedown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            this.startDrag(e, element);
        });
        
        handleContainer.appendChild(handle);
        element.appendChild(handleContainer);
        element.dataset.hasHandle = 'true';
    }
    
    /**
     * Start dragging element
     */
    startDrag(e, element) {
        if (e.target.classList.contains('resize-handle')) return;
        if (this.getElementType(element) === 'text' && 
            element.contentEditable === 'true' && 
            !e.target.classList.contains('drag-handle')) {
            return; // Don't drag when editing text unless using drag handle
        }
        
        e.preventDefault();
        this.isDragging = true;
        
        // Check if multiple elements are selected
        if (this.selectedElements.size > 1 && this.selectedElements.has(element)) {
            // Drag all selected elements
            this.multiSelectDrag = true;
            this.draggedElements = [];
            this.selectedElements.forEach(el => {
                const pos = this.getPosition(el);
                this.draggedElements.push({
                    element: el,
                    startPos: pos
                });
                el.style.cursor = 'grabbing';
                el.classList.add('dragging');
            });
        } else {
            // Single element drag
            this.multiSelectDrag = false;
            this.draggedElement = element;
            this.elementStartPos = this.getPosition(element);
            element.style.cursor = 'grabbing';
            element.style.zIndex = this.getMaxZIndex() + 1;
            element.classList.add('dragging');
        }
        
        this.dragStartPos = { x: e.clientX, y: e.clientY };
        
        // Add visual feedback
        this.addDragPreview();
        
        document.addEventListener('mousemove', this.handleDrag);
        document.addEventListener('mouseup', this.stopDrag);
    }

    /**
     * Handle dragging
     */
    handleDrag = (e) => {
        if (!this.isDragging) return;
        
        const dx = e.clientX - this.dragStartPos.x;
        const dy = e.clientY - this.dragStartPos.y;
        
        if (this.multiSelectDrag) {
            // Move all selected elements
            this.draggedElements.forEach(item => {
                const newLeft = item.startPos.x + dx;
                const newTop = item.startPos.y + dy;
                
                // Snap to grid if enabled
                const snappedPos = this.snapToGrid(newLeft, newTop);
                
                item.element.style.left = snappedPos.x + 'px';
                item.element.style.top = snappedPos.y + 'px';
                
                this.markAsModified(item.element);
            });
            
            this.notifyObservers('elements-moved', this.draggedElements);
        } else if (this.draggedElement) {
            // Single element drag
            const newLeft = this.elementStartPos.x + dx;
            const newTop = this.elementStartPos.y + dy;
            
            // Snap to grid if enabled
            const snappedPos = this.snapToGrid(newLeft, newTop);
            
            this.draggedElement.style.left = snappedPos.x + 'px';
            this.draggedElement.style.top = snappedPos.y + 'px';
            
            this.markAsModified(this.draggedElement);
            this.notifyObservers('element-moved', this.draggedElement);
        }
        
        // Show alignment guides
        this.showAlignmentGuides(e);
    }

    /**
     * Stop dragging
     */
    stopDrag = (e) => {
        if (!this.isDragging) return;
        
        this.isDragging = false;
        
        if (this.multiSelectDrag) {
            this.draggedElements.forEach(item => {
                item.element.style.cursor = '';
                item.element.classList.remove('dragging');
            });
            this.draggedElements = [];
            this.multiSelectDrag = false;
        } else if (this.draggedElement) {
            this.draggedElement.style.cursor = '';
            this.draggedElement.classList.remove('dragging');
            this.draggedElement = null;
        }
        
        this.saveState();
        this.removeDragPreview();
        this.hideAlignmentGuides();
        
        document.removeEventListener('mousemove', this.handleDrag);
        document.removeEventListener('mouseup', this.stopDrag);
    }

    /**
     * Add resize handles to element
     */
    addResizeHandles(element) {
        const handles = ['nw', 'ne', 'sw', 'se', 'n', 's', 'e', 'w'];
        
        handles.forEach(position => {
            const handle = document.createElement('div');
            handle.className = `resize-handle resize-${position}`;
            handle.dataset.position = position;
            handle.style.cssText = `
                position: absolute;
                width: 8px;
                height: 8px;
                background: #007bff;
                border: 1px solid #fff;
                border-radius: 50%;
                cursor: ${position}-resize;
                display: none;
                z-index: 1000;
            `;
            
            // Position handles
            switch(position) {
                case 'nw': handle.style.top = '-4px'; handle.style.left = '-4px'; break;
                case 'ne': handle.style.top = '-4px'; handle.style.right = '-4px'; break;
                case 'sw': handle.style.bottom = '-4px'; handle.style.left = '-4px'; break;
                case 'se': handle.style.bottom = '-4px'; handle.style.right = '-4px'; break;
                case 'n': handle.style.top = '-4px'; handle.style.left = '50%'; handle.style.transform = 'translateX(-50%)'; break;
                case 's': handle.style.bottom = '-4px'; handle.style.left = '50%'; handle.style.transform = 'translateX(-50%)'; break;
                case 'e': handle.style.right = '-4px'; handle.style.top = '50%'; handle.style.transform = 'translateY(-50%)'; break;
                case 'w': handle.style.left = '-4px'; handle.style.top = '50%'; handle.style.transform = 'translateY(-50%)'; break;
            }
            
            handle.addEventListener('mousedown', (e) => this.startResize(e, element, position));
            element.appendChild(handle);
        });
        
        // Show handles on hover/selection
        element.addEventListener('mouseenter', () => {
            this.showDragHandle(element);
            if (this.selectedElements.has(element)) {
                this.showResizeHandles(element);
            }
        });
        element.addEventListener('mouseleave', () => {
            if (!this.selectedElements.has(element)) {
                this.hideResizeHandles(element);
                this.hideDragHandle(element);
            }
        });
    }

    /**
     * Start resizing element
     */
    startResize(e, element, handle) {
        e.preventDefault();
        e.stopPropagation();
        
        this.isResizing = true;
        this.resizingElement = element;
        this.resizeHandle = handle;
        this.resizeStartPos = { x: e.clientX, y: e.clientY };
        this.elementStartDimensions = this.getDimensions(element);
        this.elementStartPos = this.getPosition(element);
        
        document.addEventListener('mousemove', this.handleResize);
        document.addEventListener('mouseup', this.stopResize);
    }

    /**
     * Handle resizing
     */
    handleResize = (e) => {
        if (!this.isResizing || !this.resizingElement) return;
        
        const dx = e.clientX - this.resizeStartPos.x;
        const dy = e.clientY - this.resizeStartPos.y;
        
        let newWidth = this.elementStartDimensions.width;
        let newHeight = this.elementStartDimensions.height;
        let newLeft = this.elementStartPos.x;
        let newTop = this.elementStartPos.y;
        
        // Calculate new dimensions based on handle position
        switch(this.resizeHandle) {
            case 'se':
                newWidth += dx;
                newHeight += dy;
                break;
            case 'sw':
                newWidth -= dx;
                newHeight += dy;
                newLeft += dx;
                break;
            case 'ne':
                newWidth += dx;
                newHeight -= dy;
                newTop += dy;
                break;
            case 'nw':
                newWidth -= dx;
                newHeight -= dy;
                newLeft += dx;
                newTop += dy;
                break;
            case 'n':
                newHeight -= dy;
                newTop += dy;
                break;
            case 's':
                newHeight += dy;
                break;
            case 'e':
                newWidth += dx;
                break;
            case 'w':
                newWidth -= dx;
                newLeft += dx;
                break;
        }
        
        // Apply minimum dimensions
        newWidth = Math.max(20, newWidth);
        newHeight = Math.max(20, newHeight);
        
        // Apply new dimensions
        this.resizingElement.style.width = newWidth + 'px';
        this.resizingElement.style.height = newHeight + 'px';
        this.resizingElement.style.left = newLeft + 'px';
        this.resizingElement.style.top = newTop + 'px';
        
        // For images, maintain aspect ratio if shift is held
        if (e.shiftKey && this.getElementType(this.resizingElement) === 'image') {
            const aspectRatio = this.elementStartDimensions.width / this.elementStartDimensions.height;
            if (this.resizeHandle.includes('e') || this.resizeHandle.includes('w')) {
                newHeight = newWidth / aspectRatio;
                this.resizingElement.style.height = newHeight + 'px';
            } else {
                newWidth = newHeight * aspectRatio;
                this.resizingElement.style.width = newWidth + 'px';
            }
        }
        
        this.markAsModified(this.resizingElement);
        this.notifyObservers('element-resized', this.resizingElement);
    }

    /**
     * Stop resizing
     */
    stopResize = (e) => {
        if (!this.isResizing) return;
        
        this.isResizing = false;
        this.saveState();
        this.resizingElement = null;
        this.resizeHandle = null;
        
        document.removeEventListener('mousemove', this.handleResize);
        document.removeEventListener('mouseup', this.stopResize);
    }

    /**
     * Add new element to editor
     */
    addElement(type, properties = {}) {
        const element = this.createElement(type, properties);
        const id = this.generateId();
        element.dataset.elementId = id;
        
        // Position at center of viewport if not specified
        if (!properties.position) {
            const containerRect = this.container.getBoundingClientRect();
            properties.position = {
                x: containerRect.width / 2 - 100,
                y: containerRect.height / 2 - 50
            };
        }
        
        element.style.position = 'absolute';
        element.style.left = properties.position.x + 'px';
        element.style.top = properties.position.y + 'px';
        
        // Add to container
        const pageContainer = this.container.querySelector('.pdf-page-container') || this.container;
        pageContainer.appendChild(element);
        
        // Register element
        this.elements.set(id, {
            id,
            element,
            type,
            original: null,
            modified: true,
            isNew: true
        });
        
        this.makeElementInteractive(element);
        this.selectElement(element);
        this.saveState();
        this.notifyObservers('element-added', element);
        
        return element;
    }

    /**
     * Create element based on type
     */
    createElement(type, properties) {
        let element;
        
        switch(type) {
            case 'text':
                element = document.createElement('div');
                element.className = 'pdf-text pdf-added';
                element.contentEditable = true;
                element.textContent = properties.text || 'New Text';
                element.style.cssText = `
                    font-size: ${properties.fontSize || 14}px;
                    color: ${properties.color || '#000'};
                    font-family: ${properties.fontFamily || 'Arial, sans-serif'};
                    padding: 4px;
                    min-width: 100px;
                    min-height: 30px;
                    z-index: 10;
                `;
                break;
                
            case 'image':
                element = document.createElement('div');
                element.className = 'pdf-image-container pdf-added';
                element.style.cssText = `
                    width: ${properties.width || 200}px;
                    height: ${properties.height || 200}px;
                    z-index: 5;
                `;
                
                const img = document.createElement('img');
                img.src = properties.src || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgZmlsbD0iI2VlZSIvPjx0ZXh0IHRleHQtYW5jaG9yPSJtaWRkbGUiIHg9IjEwMCIgeT0iMTAwIiBzdHlsZT0iZmlsbDojYWFhO2ZvbnQtd2VpZ2h0OmJvbGQ7Zm9udC1zaXplOjE5cHg7Zm9udC1mYW1pbHk6QXJpYWwsSGVsdmV0aWNhLHNhbnMtc2VyaWY7ZG9taW5hbnQtYmFzZWxpbmU6Y2VudHJhbCI+SW1hZ2U8L3RleHQ+PC9zdmc+';
                img.style.cssText = 'width: 100%; height: 100%; object-fit: contain;';
                element.appendChild(img);
                break;
                
            case 'shape':
                element = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                element.className = 'pdf-shape pdf-added';
                element.style.cssText = `
                    width: ${properties.width || 100}px;
                    height: ${properties.height || 100}px;
                    z-index: 8;
                `;
                
                const shape = this.createSVGShape(properties.shapeType || 'rectangle', properties);
                element.appendChild(shape);
                break;
                
            case 'form-field':
                element = this.createFormField(properties.fieldType || 'text', properties);
                break;
                
            case 'table':
                element = this.createTable(properties);
                break;
                
            default:
                element = document.createElement('div');
                element.className = 'pdf-element pdf-added';
        }
        
        return element;
    }

    /**
     * Create table element
     */
    createTable(properties) {
        const tableWrapper = document.createElement('div');
        tableWrapper.className = 'pdf-table-wrapper pdf-added';
        tableWrapper.style.cssText = `
            z-index: 10;
            background: white;
            border: 1px solid #ccc;
            padding: 0;
            overflow: visible;
        `;
        
        const table = document.createElement('table');
        table.className = 'pdf-table';
        table.style.cssText = `
            width: 100%;
            border-collapse: collapse;
            font-size: ${properties.fontSize || 12}px;
            font-family: ${properties.fontFamily || 'Arial, sans-serif'};
        `;
        
        const rows = properties.rows || 3;
        const cols = properties.cols || 3;
        const cellWidth = (properties.width || 400) / cols;
        const cellHeight = (properties.height || 150) / rows;
        
        // Create header if requested
        if (properties.hasHeader) {
            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');
            
            for (let c = 0; c < cols; c++) {
                const th = document.createElement('th');
                th.contentEditable = true;
                th.style.cssText = `
                    border: 1px solid #333;
                    padding: 8px;
                    background: #f0f0f0;
                    font-weight: bold;
                    text-align: ${properties.align || 'left'};
                    min-width: ${cellWidth}px;
                    height: ${cellHeight}px;
                `;
                th.textContent = `Header ${c + 1}`;
                headerRow.appendChild(th);
            }
            
            thead.appendChild(headerRow);
            table.appendChild(thead);
        }
        
        // Create body
        const tbody = document.createElement('tbody');
        const dataRows = properties.hasHeader ? rows - 1 : rows;
        
        for (let r = 0; r < dataRows; r++) {
            const row = document.createElement('tr');
            
            for (let c = 0; c < cols; c++) {
                const td = document.createElement('td');
                td.contentEditable = true;
                td.style.cssText = `
                    border: 1px solid #666;
                    padding: 6px;
                    text-align: ${properties.align || 'left'};
                    min-width: ${cellWidth}px;
                    height: ${cellHeight}px;
                    vertical-align: top;
                `;
                td.textContent = '';
                
                // Add cell editing handlers
                td.addEventListener('focus', (e) => {
                    e.stopPropagation();
                    this.editingCell = td;
                });
                
                td.addEventListener('blur', () => {
                    this.editingCell = null;
                    this.saveState();
                });
                
                td.addEventListener('keydown', (e) => {
                    if (e.key === 'Tab') {
                        e.preventDefault();
                        const cells = Array.from(table.querySelectorAll('td, th'));
                        const currentIndex = cells.indexOf(td);
                        const nextIndex = e.shiftKey ? currentIndex - 1 : currentIndex + 1;
                        
                        if (nextIndex >= 0 && nextIndex < cells.length) {
                            cells[nextIndex].focus();
                        }
                    }
                });
                
                row.appendChild(td);
            }
            
            tbody.appendChild(row);
        }
        
        table.appendChild(tbody);
        tableWrapper.appendChild(table);
        
        // Set dimensions
        tableWrapper.style.width = (properties.width || 400) + 'px';
        tableWrapper.style.height = 'auto';
        
        return tableWrapper;
    }
    
    /**
     * Add table with dialog
     */
    addTable() {
        // Create modal dialog for table configuration
        const dialog = document.createElement('div');
        dialog.className = 'pdf-table-dialog';
        dialog.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 10000;
            min-width: 300px;
        `;
        
        dialog.innerHTML = `
            <h3 style="margin: 0 0 15px 0;">Ajouter un tableau</h3>
            <div style="margin-bottom: 10px;">
                <label style="display: block; margin-bottom: 5px;">Nombre de lignes:</label>
                <input type="number" id="tableRows" value="3" min="1" max="50" style="width: 100%; padding: 5px;">
            </div>
            <div style="margin-bottom: 10px;">
                <label style="display: block; margin-bottom: 5px;">Nombre de colonnes:</label>
                <input type="number" id="tableCols" value="3" min="1" max="20" style="width: 100%; padding: 5px;">
            </div>
            <div style="margin-bottom: 10px;">
                <label style="display: inline-block; margin-right: 10px;">
                    <input type="checkbox" id="tableHeader" checked> Inclure un en-tête
                </label>
            </div>
            <div style="margin-bottom: 10px;">
                <label style="display: block; margin-bottom: 5px;">Largeur (px):</label>
                <input type="number" id="tableWidth" value="400" min="100" max="1000" style="width: 100%; padding: 5px;">
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 15px;">
                <button id="cancelTable" style="padding: 8px 16px; background: #666; color: white; border: none; border-radius: 4px; cursor: pointer;">Annuler</button>
                <button id="createTable" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Créer</button>
            </div>
        `;
        
        // Add backdrop
        const backdrop = document.createElement('div');
        backdrop.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
        `;
        
        document.body.appendChild(backdrop);
        document.body.appendChild(dialog);
        
        // Handle dialog actions
        const cleanup = () => {
            document.body.removeChild(dialog);
            document.body.removeChild(backdrop);
        };
        
        dialog.querySelector('#cancelTable').addEventListener('click', cleanup);
        backdrop.addEventListener('click', cleanup);
        
        dialog.querySelector('#createTable').addEventListener('click', () => {
            const rows = parseInt(dialog.querySelector('#tableRows').value);
            const cols = parseInt(dialog.querySelector('#tableCols').value);
            const hasHeader = dialog.querySelector('#tableHeader').checked;
            const width = parseInt(dialog.querySelector('#tableWidth').value);
            
            this.addElement('table', {
                rows,
                cols,
                hasHeader,
                width,
                height: rows * 40
            });
            
            cleanup();
        });
        
        // Focus first input
        dialog.querySelector('#tableRows').focus();
    }
    
    /**
     * Enable annotation mode
     */
    enableAnnotationMode() {
        this.mode = 'annotation';
        this.container.style.cursor = 'crosshair';
        
        // Add annotation click handler
        this.annotationHandler = (e) => {
            if (e.target === this.container || e.target.classList.contains('pdf-page-container')) {
                const rect = this.container.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                this.addAnnotation(x, y);
            }
        };
        
        this.container.addEventListener('click', this.annotationHandler);
        this.notifyObservers('mode-changed', 'annotation');
    }
    
    /**
     * Disable annotation mode
     */
    disableAnnotationMode() {
        this.mode = 'normal';
        this.container.style.cursor = 'default';
        
        if (this.annotationHandler) {
            this.container.removeEventListener('click', this.annotationHandler);
            this.annotationHandler = null;
        }
        
        this.notifyObservers('mode-changed', 'normal');
    }
    
    /**
     * Add annotation at position
     */
    addAnnotation(x, y, text = '') {
        const annotation = document.createElement('div');
        annotation.className = 'pdf-annotation pdf-added';
        annotation.dataset.annotationId = this.generateId();
        
        // Create annotation marker
        const marker = document.createElement('div');
        marker.className = 'annotation-marker';
        marker.innerHTML = `
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <path d="M9 9h6v6h-2.5l-1.5 3-1.5-3H9V9z"/>
                <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>
            </svg>
        `;
        
        // Create annotation bubble
        const bubble = document.createElement('div');
        bubble.className = 'annotation-bubble';
        bubble.contentEditable = true;
        bubble.innerHTML = `
            <div class="annotation-header">
                <span class="annotation-author">User</span>
                <span class="annotation-date">${new Date().toLocaleDateString()}</span>
                <button class="annotation-delete">×</button>
            </div>
            <div class="annotation-content" contenteditable="true">${text || 'Click to add comment...'}</div>
        `;
        
        annotation.appendChild(marker);
        annotation.appendChild(bubble);
        
        // Position annotation
        annotation.style.cssText = `
            position: absolute;
            left: ${x}px;
            top: ${y}px;
            z-index: 100;
        `;
        
        // Add to container
        const pageContainer = this.container.querySelector('.pdf-page-container') || this.container;
        pageContainer.appendChild(annotation);
        
        // Setup event handlers
        marker.addEventListener('click', () => {
            bubble.classList.toggle('visible');
        });
        
        bubble.querySelector('.annotation-delete').addEventListener('click', () => {
            annotation.remove();
            this.saveState();
        });
        
        bubble.querySelector('.annotation-content').addEventListener('blur', () => {
            this.saveState();
        });
        
        // Register annotation
        const id = annotation.dataset.annotationId;
        this.elements.set(id, {
            id,
            element: annotation,
            type: 'annotation',
            original: null,
            modified: true,
            isNew: true
        });
        
        this.makeElementInteractive(annotation);
        this.saveState();
        this.notifyObservers('annotation-added', annotation);
        
        return annotation;
    }
    
    /**
     * Enable highlight mode
     */
    enableHighlightMode(color = '#ffff00') {
        this.mode = 'highlight';
        this.highlightColor = color;
        this.container.style.cursor = 'text';
        
        // Add selection handler
        this.highlightHandler = () => {
            const selection = window.getSelection();
            if (selection.rangeCount > 0 && !selection.isCollapsed) {
                const range = selection.getRangeAt(0);
                
                // Check if selection is within our container
                if (this.container.contains(range.commonAncestorContainer)) {
                    this.highlightSelection(range, this.highlightColor);
                    selection.removeAllRanges();
                }
            }
        };
        
        document.addEventListener('mouseup', this.highlightHandler);
        this.notifyObservers('mode-changed', 'highlight');
    }
    
    /**
     * Disable highlight mode
     */
    disableHighlightMode() {
        this.mode = 'normal';
        this.container.style.cursor = 'default';
        
        if (this.highlightHandler) {
            document.removeEventListener('mouseup', this.highlightHandler);
            this.highlightHandler = null;
        }
        
        this.notifyObservers('mode-changed', 'normal');
    }
    
    /**
     * Highlight text selection
     */
    highlightSelection(range, color) {
        // Create highlight wrapper
        const highlight = document.createElement('span');
        highlight.className = 'pdf-highlight pdf-added';
        highlight.style.backgroundColor = color;
        highlight.style.opacity = '0.4';
        highlight.dataset.highlightId = this.generateId();
        highlight.dataset.color = color;
        
        try {
            // Wrap the selected content
            range.surroundContents(highlight);
        } catch (e) {
            // If surroundContents fails (e.g., partial selection across elements),
            // extract and wrap the contents
            const contents = range.extractContents();
            highlight.appendChild(contents);
            range.insertNode(highlight);
        }
        
        // Add context menu for highlight
        highlight.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            this.showHighlightMenu(e, highlight);
        });
        
        // Register highlight
        const id = highlight.dataset.highlightId;
        this.elements.set(id, {
            id,
            element: highlight,
            type: 'highlight',
            original: null,
            modified: true,
            isNew: true
        });
        
        this.saveState();
        this.notifyObservers('highlight-added', highlight);
        
        return highlight;
    }
    
    /**
     * Show highlight context menu
     */
    showHighlightMenu(event, highlight) {
        // Remove existing menu if any
        const existingMenu = document.querySelector('.highlight-menu');
        if (existingMenu) {
            existingMenu.remove();
        }
        
        // Create context menu
        const menu = document.createElement('div');
        menu.className = 'highlight-menu';
        menu.style.cssText = `
            position: fixed;
            left: ${event.clientX}px;
            top: ${event.clientY}px;
            background: white;
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 5px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 10000;
        `;
        
        // Color options
        const colors = [
            { name: 'Yellow', value: '#ffff00' },
            { name: 'Green', value: '#00ff00' },
            { name: 'Blue', value: '#00ffff' },
            { name: 'Pink', value: '#ff00ff' },
            { name: 'Orange', value: '#ffa500' }
        ];
        
        colors.forEach(color => {
            const item = document.createElement('div');
            item.style.cssText = `
                padding: 5px 15px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
            `;
            item.innerHTML = `
                <span style="width: 16px; height: 16px; background: ${color.value}; border: 1px solid #666; opacity: 0.4;"></span>
                <span>${color.name}</span>
            `;
            item.addEventListener('click', () => {
                highlight.style.backgroundColor = color.value;
                highlight.dataset.color = color.value;
                menu.remove();
                this.saveState();
            });
            menu.appendChild(item);
        });
        
        // Separator
        const separator = document.createElement('div');
        separator.style.cssText = 'border-top: 1px solid #ddd; margin: 5px 0;';
        menu.appendChild(separator);
        
        // Remove option
        const removeItem = document.createElement('div');
        removeItem.style.cssText = `
            padding: 5px 15px;
            cursor: pointer;
            color: #dc3545;
        `;
        removeItem.textContent = 'Remove highlight';
        removeItem.addEventListener('click', () => {
            const parent = highlight.parentNode;
            while (highlight.firstChild) {
                parent.insertBefore(highlight.firstChild, highlight);
            }
            highlight.remove();
            menu.remove();
            this.saveState();
        });
        menu.appendChild(removeItem);
        
        document.body.appendChild(menu);
        
        // Remove menu on click outside
        const removeMenu = (e) => {
            if (!menu.contains(e.target)) {
                menu.remove();
                document.removeEventListener('click', removeMenu);
            }
        };
        setTimeout(() => {
            document.addEventListener('click', removeMenu);
        }, 0);
    }
    
    /**
     * Create SVG shape
     */
    createSVGShape(shapeType, properties) {
        let shape;
        
        switch(shapeType) {
            case 'rectangle':
                shape = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                shape.setAttribute('width', '100%');
                shape.setAttribute('height', '100%');
                shape.setAttribute('fill', properties.fill || 'transparent');
                shape.setAttribute('stroke', properties.stroke || '#000');
                shape.setAttribute('stroke-width', properties.strokeWidth || 2);
                break;
                
            case 'circle':
                shape = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                shape.setAttribute('cx', '50%');
                shape.setAttribute('cy', '50%');
                shape.setAttribute('r', '45%');
                shape.setAttribute('fill', properties.fill || 'transparent');
                shape.setAttribute('stroke', properties.stroke || '#000');
                shape.setAttribute('stroke-width', properties.strokeWidth || 2);
                break;
                
            case 'line':
                shape = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                shape.setAttribute('x1', '0');
                shape.setAttribute('y1', '50%');
                shape.setAttribute('x2', '100%');
                shape.setAttribute('y2', '50%');
                shape.setAttribute('stroke', properties.stroke || '#000');
                shape.setAttribute('stroke-width', properties.strokeWidth || 2);
                break;
                
            case 'arrow':
                const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
                const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                line.setAttribute('x1', '10');
                line.setAttribute('y1', '50%');
                line.setAttribute('x2', 'calc(100% - 10px)');
                line.setAttribute('y2', '50%');
                line.setAttribute('stroke', properties.stroke || '#000');
                line.setAttribute('stroke-width', properties.strokeWidth || 2);
                
                const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
                arrow.setAttribute('points', 'calc(100% - 10px),50% calc(100% - 20px),calc(50% - 8px) calc(100% - 20px),calc(50% + 8px)');
                arrow.setAttribute('fill', properties.stroke || '#000');
                
                g.appendChild(line);
                g.appendChild(arrow);
                shape = g;
                break;
        }
        
        return shape;
    }

    /**
     * Create form field
     */
    createFormField(fieldType, properties) {
        const container = document.createElement('div');
        container.className = 'pdf-form-field pdf-added';
        container.style.cssText = `
            z-index: 15;
            padding: 4px;
        `;
        
        let field;
        
        switch(fieldType) {
            case 'text':
                field = document.createElement('input');
                field.type = 'text';
                field.placeholder = properties.placeholder || 'Enter text';
                field.style.cssText = `
                    width: ${properties.width || 200}px;
                    padding: 4px;
                    border: 1px solid #ccc;
                    font-size: 14px;
                `;
                break;
                
            case 'checkbox':
                field = document.createElement('input');
                field.type = 'checkbox';
                field.checked = properties.checked || false;
                break;
                
            case 'radio':
                field = document.createElement('input');
                field.type = 'radio';
                field.name = properties.name || 'radio-group';
                field.checked = properties.checked || false;
                break;
                
            case 'select':
                field = document.createElement('select');
                field.style.cssText = `
                    width: ${properties.width || 200}px;
                    padding: 4px;
                    border: 1px solid #ccc;
                    font-size: 14px;
                `;
                const options = properties.options || ['Option 1', 'Option 2'];
                options.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt;
                    option.textContent = opt;
                    field.appendChild(option);
                });
                break;
                
            case 'textarea':
                field = document.createElement('textarea');
                field.placeholder = properties.placeholder || 'Enter text';
                field.style.cssText = `
                    width: ${properties.width || 200}px;
                    height: ${properties.height || 100}px;
                    padding: 4px;
                    border: 1px solid #ccc;
                    font-size: 14px;
                    resize: both;
                `;
                break;
        }
        
        if (properties.label) {
            const label = document.createElement('label');
            label.textContent = properties.label;
            label.style.cssText = 'display: block; margin-bottom: 4px; font-size: 12px;';
            container.appendChild(label);
        }
        
        container.appendChild(field);
        return container;
    }

    /**
     * Delete selected elements
     */
    deleteSelectedElements() {
        this.selectedElements.forEach(element => {
            const id = element.dataset.elementId;
            if (id && this.elements.has(id)) {
                this.elements.delete(id);
            }
            element.remove();
        });
        
        this.clearSelection();
        this.saveState();
        this.notifyObservers('elements-deleted');
    }

    /**
     * Copy selected elements
     */
    copySelectedElements() {
        this.clipboard = [];
        this.selectedElements.forEach(element => {
            this.clipboard.push({
                html: element.outerHTML,
                styles: this.captureStyles(element),
                position: this.getPosition(element)
            });
        });
        this.notifyObservers('elements-copied', this.clipboard.length);
    }

    /**
     * Paste elements from clipboard
     */
    pasteElements() {
        if (!this.clipboard || this.clipboard.length === 0) return;
        
        this.clearSelection();
        
        this.clipboard.forEach((item, index) => {
            const temp = document.createElement('div');
            temp.innerHTML = item.html;
            const element = temp.firstChild;
            
            // Generate new ID
            const id = this.generateId();
            element.dataset.elementId = id;
            
            // Offset position to avoid exact overlap
            const offset = (index + 1) * 20;
            element.style.left = (item.position.x + offset) + 'px';
            element.style.top = (item.position.y + offset) + 'px';
            
            // Add to container
            const pageContainer = this.container.querySelector('.pdf-page-container') || this.container;
            pageContainer.appendChild(element);
            
            // Register and make interactive
            this.elements.set(id, {
                id,
                element,
                type: this.getElementType(element),
                original: null,
                modified: true,
                isNew: true
            });
            
            this.makeElementInteractive(element);
            this.selectElement(element);
        });
        
        this.saveState();
        this.notifyObservers('elements-pasted', this.clipboard.length);
    }

    /**
     * Undo last action
     */
    undo() {
        if (this.historyIndex > 0) {
            this.historyIndex--;
            this.restoreState(this.history[this.historyIndex]);
            this.notifyObservers('undo');
        }
    }

    /**
     * Redo last undone action
     */
    redo() {
        if (this.historyIndex < this.history.length - 1) {
            this.historyIndex++;
            this.restoreState(this.history[this.historyIndex]);
            this.notifyObservers('redo');
        }
    }

    /**
     * Save current state to history
     */
    saveState() {
        // Remove any states after current index
        this.history = this.history.slice(0, this.historyIndex + 1);
        
        // Create state snapshot
        const state = {
            html: this.container.innerHTML,
            elements: new Map(this.elements),
            timestamp: Date.now()
        };
        
        this.history.push(state);
        this.historyIndex++;
        
        // Limit history size
        if (this.history.length > this.maxHistorySize) {
            this.history.shift();
            this.historyIndex--;
        }
        
        this.notifyObservers('state-saved');
    }

    /**
     * Restore state from history
     */
    restoreState(state) {
        this.container.innerHTML = state.html;
        this.elements = new Map(state.elements);
        
        // Re-attach event listeners
        this.elements.forEach(item => {
            const element = this.container.querySelector(`[data-element-id="${item.id}"]`);
            if (element) {
                item.element = element;
                this.makeElementInteractive(element);
            }
        });
        
        this.clearSelection();
    }

    /**
     * Export current state as HTML
     */
    exportHTML() {
        // First, capture all styles from the ORIGINAL elements before cloning
        const styleMap = new Map();
        
        // Get all PDF elements from the original container
        // Include all images, vectors, lines, tables, annotations, highlights and added elements
        const originalElements = this.container.querySelectorAll('.pdf-text, .pdf-image, .pdf-vector, .pdf-line, .pdf-added, .pdf-table-wrapper, .pdf-annotation, .pdf-highlight, img, svg, div[data-line="true"]');
        originalElements.forEach(el => {
            const id = Math.random().toString(36).substr(2, 9);
            el.dataset.tempId = id;
            
            // Get the computed styles from the REAL element
            const computed = window.getComputedStyle(el);
            const rect = el.getBoundingClientRect();
            const containerRect = this.container.getBoundingClientRect();
            
            // Calculate absolute positions relative to container
            const relativeLeft = rect.left - containerRect.left;
            const relativeTop = rect.top - containerRect.top;
            
            // Store all important styles
            styleMap.set(id, {
                position: 'absolute',
                left: relativeLeft + 'px',
                top: relativeTop + 'px',
                width: rect.width + 'px',
                height: rect.height + 'px',
                fontSize: computed.fontSize,
                fontFamily: computed.fontFamily,
                fontWeight: computed.fontWeight,
                fontStyle: computed.fontStyle,
                color: computed.color,
                backgroundColor: computed.backgroundColor,
                textAlign: computed.textAlign,
                lineHeight: computed.lineHeight,
                letterSpacing: computed.letterSpacing,
                zIndex: computed.zIndex,
                opacity: computed.opacity,
                transform: computed.transform === 'none' ? '' : computed.transform,
                display: computed.display,
                whiteSpace: computed.whiteSpace,
                overflow: computed.overflow
            });
        });
        
        // Now clone the container
        const containerClone = this.container.cloneNode(true);
        
        // Remove all UI elements from the clone
        const uiElements = containerClone.querySelectorAll(
            '.resize-handle, .drag-handle, .drag-handle-container, .pdf-overlay-canvas, .editor-toolbar, .toolbar-btn, .dropdown-menu, .context-menu, .selection-box, .alignment-guide'
        );
        uiElements.forEach(el => el.remove());
        
        // Apply the captured styles to the cloned elements
        const clonedElements = containerClone.querySelectorAll('[data-temp-id]');
        clonedElements.forEach(el => {
            const id = el.dataset.tempId;
            const styles = styleMap.get(id);
            
            if (styles) {
                // Build inline style string
                let inlineStyle = '';
                for (const [prop, value] of Object.entries(styles)) {
                    if (value && value !== 'auto' && value !== 'normal' && value !== '') {
                        const cssProp = prop.replace(/([A-Z])/g, '-$1').toLowerCase();
                        inlineStyle += `${cssProp}: ${value} !important; `;
                    }
                }
                el.setAttribute('style', inlineStyle);
            }
            
            // Clean up attributes except data-line for line elements
            el.removeAttribute('data-temp-id');
            el.removeAttribute('data-element-id');
            el.removeAttribute('data-has-handle');
            el.removeAttribute('contenteditable');
            el.removeAttribute('spellcheck');
            el.classList.remove('selected', 'dragging');
            
            // Special handling for vector images and lines
            if (el.classList.contains('pdf-vector') || el.classList.contains('pdf-line')) {
                // Ensure these elements are exported correctly
                if (el.tagName === 'IMG' && el.src) {
                    // Preserve the image src
                    el.setAttribute('src', el.src);
                }
                if (el.dataset.line === 'true') {
                    // Keep the data-line attribute for line elements
                    el.setAttribute('data-line', 'true');
                }
            }
        });
        
        // Clean up temp IDs from original elements
        originalElements.forEach(el => {
            el.removeAttribute('data-temp-id');
        });
        
        // Get only PDF page containers
        const pageContainers = containerClone.querySelectorAll('.pdf-page-container');
        if (pageContainers.length > 0) {
            // Build complete HTML document with styles
            let html = `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body {
    margin: 0;
    padding: 0;
    background: #f5f5f5;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
#pdfContent {
    margin: 0;
    padding: 0;
}
.pdf-page-container {
    position: relative !important;
    margin: 0 auto;
    background: white;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: visible !important;
    page-break-inside: avoid;
}
.pdf-page-container:not(:last-child) {
    margin-bottom: 20px;
}
.pdf-page-container * {
    position: absolute !important;
}
.pdf-element {
    position: absolute !important;
    box-sizing: border-box;
}
.pdf-text {
    position: absolute !important;
    padding: 0 !important;
    margin: 0 !important;
    border: none !important;
    background: transparent !important;
    z-index: 10;
}
.pdf-image, img {
    position: absolute !important;
    z-index: 1;
    max-width: none !important;
    max-height: none !important;
}
.pdf-vector {
    position: absolute !important;
    z-index: 2;
}
.pdf-line {
    position: absolute !important;
    z-index: 3;
    pointer-events: none;
}
.pdf-added {
    position: absolute !important;
}
.pdf-table-wrapper {
    position: absolute !important;
    background: white;
    z-index: 10;
}
.pdf-table {
    border-collapse: collapse;
    width: 100%;
}
.pdf-table th,
.pdf-table td {
    border: 1px solid #666;
    padding: 6px;
    text-align: left;
}
.pdf-table th {
    background: #f0f0f0;
    font-weight: bold;
}
.pdf-annotation {
    position: absolute !important;
    z-index: 100;
}
.annotation-marker {
    width: 24px;
    height: 24px;
    color: #ff5722;
}
.annotation-bubble {
    position: absolute;
    left: 30px;
    top: -10px;
    min-width: 200px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    padding: 10px;
    display: none;
    z-index: 101;
}
.annotation-bubble.visible {
    display: block;
}
.pdf-highlight {
    display: inline;
    opacity: 0.4;
    position: relative;
}
@media print {
    body {
        background: white;
    }
    .pdf-page-container {
        box-shadow: none;
        margin: 0;
    }
    .pdf-page-container:not(:last-child) {
        page-break-after: always;
    }
}
</style>
</head>
<body>`;
            
            pageContainers.forEach((page, index) => {
                // Get original page from the real DOM to capture its actual dimensions
                const originalPage = this.container.querySelectorAll('.pdf-page-container')[index];
                if (originalPage) {
                    const originalStyle = window.getComputedStyle(originalPage);
                    const originalRect = originalPage.getBoundingClientRect();
                    
                    // Apply exact dimensions and styles to the cloned page
                    // Only add page break if there are multiple pages
                    const isLastPage = index === pageContainers.length - 1;
                    const pageBreakStyle = pageContainers.length > 1 && !isLastPage ? 'page-break-after: always !important;' : '';
                    
                    page.style.cssText = `
                        position: relative !important;
                        width: ${originalStyle.width} !important;
                        height: ${originalStyle.height} !important;
                        margin: 0 auto !important;
                        margin-bottom: ${isLastPage ? '0' : '20px'} !important;
                        background: white !important;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.1) !important;
                        ${pageBreakStyle}
                        page-break-inside: avoid !important;
                        overflow: visible !important;
                    `;
                    
                    // Set data attributes for dimensions
                    page.setAttribute('data-width', originalStyle.width);
                    page.setAttribute('data-height', originalStyle.height);
                }
                
                // Remove any remaining UI elements within the page
                const pageUIElements = page.querySelectorAll('.drag-handle, .drag-handle-container, .resize-handle');
                pageUIElements.forEach(el => el.remove());
                
                html += page.outerHTML;
            });
            
            html += '</div></body></html>';
            
            // Wrap pages in pdfContent div for server processing
            const finalHtml = html.replace('<body>', '<body><div id="pdfContent">');
            return finalHtml;
        }
        
        // Fallback: return cleaned container HTML with wrapper
        return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body { margin: 0; padding: 20px; background: #f5f5f5; }
.pdf-text { position: absolute; }
.pdf-image { position: absolute; }
.pdf-vector { position: absolute; }
</style>
</head>
<body>${containerClone.innerHTML}</body>
</html>`;
    }

    /**
     * Helper functions
     */
    
    generateId() {
        return 'element-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    }
    
    /**
     * Add control icons (move and delete) to an element
     */
    addControlIcons(element) {
        // Skip if controls already exist
        if (element.querySelector('.element-controls')) {
            return;
        }
        
        // Make element position relative if needed for controls
        if (window.getComputedStyle(element).position === 'static') {
            element.style.position = 'relative';
        }
        
        // Create controls container
        const controls = document.createElement('div');
        controls.className = 'element-controls';
        
        // Create move icon
        const moveIcon = document.createElement('div');
        moveIcon.className = 'control-move';
        moveIcon.title = 'Déplacer';
        moveIcon.addEventListener('mousedown', (e) => {
            e.stopPropagation();
            this.startDrag(e, element);
        });
        
        // Create delete icon
        const deleteIcon = document.createElement('div');
        deleteIcon.className = 'control-delete';
        deleteIcon.title = 'Supprimer';
        deleteIcon.addEventListener('click', (e) => {
            e.stopPropagation();
            this.deleteElement(element);
        });
        
        // Add icons to controls
        controls.appendChild(moveIcon);
        controls.appendChild(deleteIcon);
        
        // Add controls to element
        element.appendChild(controls);
    }
    
    /**
     * Delete an element
     */
    deleteElement(element) {
        const id = element.dataset.elementId;
        
        // Save to history before deleting
        this.saveToHistory();
        
        // Remove from DOM
        element.remove();
        
        // Remove from elements map
        this.elements.delete(id);
        
        // Remove from selection
        this.selectedElements.delete(element);
        
        // Notify observers
        this.notifyObservers({
            type: 'delete',
            element: element,
            id: id
        });
    }
    
    getElementType(element) {
        if (element.classList.contains('pdf-text')) return 'text';
        if (element.classList.contains('pdf-image') || element.classList.contains('pdf-image-container')) return 'image';
        if (element.classList.contains('pdf-vector')) return 'vector';
        if (element.classList.contains('pdf-shape')) return 'shape';
        if (element.classList.contains('pdf-table')) return 'table';
        if (element.classList.contains('pdf-form-field')) return 'form-field';
        return 'unknown';
    }
    
    getPosition(element) {
        return {
            x: parseFloat(element.style.left) || element.offsetLeft || 0,
            y: parseFloat(element.style.top) || element.offsetTop || 0
        };
    }
    
    getDimensions(element) {
        return {
            width: parseFloat(element.style.width) || element.offsetWidth || 0,
            height: parseFloat(element.style.height) || element.offsetHeight || 0
        };
    }
    
    captureStyles(element) {
        const computed = window.getComputedStyle(element);
        const styles = {};
        
        ['fontSize', 'fontFamily', 'color', 'backgroundColor', 'transform', 'opacity', 'zIndex'].forEach(prop => {
            styles[prop] = computed[prop];
        });
        
        return styles;
    }
    
    getMaxZIndex() {
        let max = 0;
        this.elements.forEach(item => {
            const z = parseInt(window.getComputedStyle(item.element).zIndex) || 0;
            if (z > max) max = z;
        });
        return max;
    }
    
    snapToGrid(x, y) {
        if (!this.gridEnabled) return { x, y };
        
        return {
            x: Math.round(x / this.gridSize) * this.gridSize,
            y: Math.round(y / this.gridSize) * this.gridSize
        };
    }
    
    /**
     * Add visual feedback for dragging
     */
    addDragPreview() {
        // Add a semi-transparent overlay to show drag state
        const overlay = document.createElement('div');
        overlay.id = 'drag-overlay';
        overlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;
            pointer-events: none;
            cursor: grabbing;
        `;
        document.body.appendChild(overlay);
        
        // Add visual feedback to dragged elements
        if (this.multiSelectDrag) {
            this.selectedElements.forEach(el => {
                el.style.opacity = '0.7';
                el.style.boxShadow = '0 5px 15px rgba(0,0,0,0.3)';
                el.style.transition = 'none';
            });
        } else if (this.draggedElement) {
            this.draggedElement.style.opacity = '0.7';
            this.draggedElement.style.boxShadow = '0 5px 15px rgba(0,0,0,0.3)';
            this.draggedElement.style.transition = 'none';
        }
    }
    
    /**
     * Remove drag preview
     */
    removeDragPreview() {
        const overlay = document.getElementById('drag-overlay');
        if (overlay) overlay.remove();
        
        // Remove visual feedback from dragged elements
        if (this.multiSelectDrag) {
            this.selectedElements.forEach(el => {
                el.style.opacity = '';
                el.style.boxShadow = '';
                el.style.transition = '';
            });
        } else if (this.draggedElement) {
            this.draggedElement.style.opacity = '';
            this.draggedElement.style.boxShadow = '';
            this.draggedElement.style.transition = '';
        }
    }
    
    /**
     * Show alignment guides when dragging
     */
    showAlignmentGuides(e) {
        // Remove existing guides
        this.hideAlignmentGuides();
        
        if (!this.draggedElement && !this.multiSelectDrag) return;
        
        const element = this.multiSelectDrag ? this.draggedElements[0].element : this.draggedElement;
        const rect = element.getBoundingClientRect();
        const containerRect = this.container.getBoundingClientRect();
        
        // Get positions relative to container
        const relX = rect.left - containerRect.left;
        const relY = rect.top - containerRect.top;
        const centerX = relX + rect.width / 2;
        const centerY = relY + rect.height / 2;
        
        const guides = [];
        const threshold = 5; // Snap threshold in pixels
        
        // Check alignment with other elements
        this.elements.forEach(item => {
            if (this.multiSelectDrag && this.selectedElements.has(item.element)) return;
            if (item.element === this.draggedElement) return;
            
            const otherRect = item.element.getBoundingClientRect();
            const otherRelX = otherRect.left - containerRect.left;
            const otherRelY = otherRect.top - containerRect.top;
            const otherCenterX = otherRelX + otherRect.width / 2;
            const otherCenterY = otherRelY + otherRect.height / 2;
            
            // Vertical alignment guides
            if (Math.abs(relX - otherRelX) < threshold) {
                guides.push({ type: 'vertical', position: otherRelX, from: Math.min(relY, otherRelY), to: Math.max(relY + rect.height, otherRelY + otherRect.height) });
            }
            if (Math.abs(centerX - otherCenterX) < threshold) {
                guides.push({ type: 'vertical', position: otherCenterX, from: Math.min(relY, otherRelY), to: Math.max(relY + rect.height, otherRelY + otherRect.height) });
            }
            if (Math.abs(relX + rect.width - (otherRelX + otherRect.width)) < threshold) {
                guides.push({ type: 'vertical', position: otherRelX + otherRect.width, from: Math.min(relY, otherRelY), to: Math.max(relY + rect.height, otherRelY + otherRect.height) });
            }
            
            // Horizontal alignment guides
            if (Math.abs(relY - otherRelY) < threshold) {
                guides.push({ type: 'horizontal', position: otherRelY, from: Math.min(relX, otherRelX), to: Math.max(relX + rect.width, otherRelX + otherRect.width) });
            }
            if (Math.abs(centerY - otherCenterY) < threshold) {
                guides.push({ type: 'horizontal', position: otherCenterY, from: Math.min(relX, otherRelX), to: Math.max(relX + rect.width, otherRelX + otherRect.width) });
            }
            if (Math.abs(relY + rect.height - (otherRelY + otherRect.height)) < threshold) {
                guides.push({ type: 'horizontal', position: otherRelY + otherRect.height, from: Math.min(relX, otherRelX), to: Math.max(relX + rect.width, otherRelX + otherRect.width) });
            }
        });
        
        // Draw guides
        guides.forEach(guide => {
            const line = document.createElement('div');
            line.className = 'alignment-guide';
            line.style.cssText = `
                position: absolute;
                background: #007bff;
                opacity: 0.5;
                z-index: 10000;
                pointer-events: none;
            `;
            
            if (guide.type === 'vertical') {
                line.style.left = guide.position + 'px';
                line.style.top = guide.from + 'px';
                line.style.width = '1px';
                line.style.height = (guide.to - guide.from) + 'px';
            } else {
                line.style.left = guide.from + 'px';
                line.style.top = guide.position + 'px';
                line.style.width = (guide.to - guide.from) + 'px';
                line.style.height = '1px';
            }
            
            this.container.appendChild(line);
        });
    }
    
    /**
     * Hide alignment guides
     */
    hideAlignmentGuides() {
        // Remove any visible guides
        const guides = document.querySelectorAll('.alignment-guide');
        guides.forEach(g => g.remove());
    }
    
    /**
     * Find nearby elements for snapping
     */
    findNearbyElements(x, y, threshold = 20) {
        const nearby = [];
        this.elements.forEach(item => {
            if (item.element === this.draggedElement) return;
            
            const pos = this.getPosition(item.element);
            const dim = this.getDimensions(item.element);
            
            // Check proximity
            if (Math.abs(pos.x - x) < threshold || 
                Math.abs(pos.x + dim.width - x) < threshold ||
                Math.abs(pos.y - y) < threshold ||
                Math.abs(pos.y + dim.height - y) < threshold) {
                nearby.push(item);
            }
        });
        return nearby;
    }
    
    /**
     * Enable/disable grid
     */
    setGridEnabled(enabled, size = 10) {
        this.gridEnabled = enabled;
        this.gridSize = size;
        this.notifyObservers('grid-changed', { enabled, size });
    }
    
    selectElement(element) {
        element.classList.add('selected');
        this.selectedElements.add(element);
        this.showResizeHandles(element);
        this.showDragHandle(element);
        this.notifyObservers('element-selected', element);
    }
    
    clearSelection() {
        this.selectedElements.forEach(el => {
            el.classList.remove('selected');
            this.hideResizeHandles(el);
            this.hideDragHandle(el);
        });
        this.selectedElements.clear();
        this.notifyObservers('selection-cleared');
    }
    
    showResizeHandles(element) {
        const handles = element.querySelectorAll('.resize-handle');
        handles.forEach(h => h.style.display = 'block');
    }
    
    hideResizeHandles(element) {
        const handles = element.querySelectorAll('.resize-handle');
        handles.forEach(h => h.style.display = 'none');
    }
    
    showDragHandle(element) {
        const handle = element.querySelector('.drag-handle');
        if (handle) {
            handle.style.display = 'flex';
            // Update handle position if element has moved
            const container = element.querySelector('.drag-handle-container');
            if (container) {
                container.style.width = '100%';
                container.style.height = '100%';
            }
        }
    }
    
    hideDragHandle(element) {
        const handle = element.querySelector('.drag-handle');
        if (handle) {
            handle.style.display = 'none';
        }
    }
    
    markAsModified(element) {
        const id = element.dataset.elementId;
        if (id && this.elements.has(id)) {
            const item = this.elements.get(id);
            item.modified = true;
        }
    }
    
    showContextMenu(e, element) {
        // This will be implemented by the Vue component
        this.notifyObservers('context-menu', { event: e, element });
    }
    
    attachGlobalListeners() {
        // Selection box variables
        let isSelecting = false;
        let selectionBox = null;
        let selectionStart = { x: 0, y: 0 };
        
        // Click on empty space to deselect
        this.container.addEventListener('click', (e) => {
            if (e.target === this.container || e.target.classList.contains('pdf-page-container')) {
                this.clearSelection();
            }
        });
        
        // Start selection box on mousedown
        this.container.addEventListener('mousedown', (e) => {
            // Only start selection if clicking on empty space
            if (e.target === this.container || e.target.classList.contains('pdf-page-container')) {
                isSelecting = true;
                selectionStart = { x: e.clientX, y: e.clientY };
                
                // Create selection box
                selectionBox = document.createElement('div');
                selectionBox.className = 'selection-box';
                selectionBox.style.left = e.clientX + 'px';
                selectionBox.style.top = e.clientY + 'px';
                selectionBox.style.width = '0px';
                selectionBox.style.height = '0px';
                document.body.appendChild(selectionBox);
                
                e.preventDefault();
            }
        });
        
        // Update selection box on mousemove
        document.addEventListener('mousemove', (e) => {
            if (!isSelecting || !selectionBox) return;
            
            const currentX = e.clientX;
            const currentY = e.clientY;
            
            const left = Math.min(currentX, selectionStart.x);
            const top = Math.min(currentY, selectionStart.y);
            const width = Math.abs(currentX - selectionStart.x);
            const height = Math.abs(currentY - selectionStart.y);
            
            selectionBox.style.left = left + 'px';
            selectionBox.style.top = top + 'px';
            selectionBox.style.width = width + 'px';
            selectionBox.style.height = height + 'px';
            
            // Select elements within the box
            this.selectElementsInBox(left, top, width, height);
        });
        
        // End selection box on mouseup
        document.addEventListener('mouseup', (e) => {
            if (isSelecting) {
                isSelecting = false;
                if (selectionBox) {
                    selectionBox.remove();
                    selectionBox = null;
                }
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            
            // Arrow key movement
            if (this.selectedElements.size > 0 && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                e.preventDefault();
                const step = e.shiftKey ? 10 : 1; // Shift for larger steps
                let dx = 0, dy = 0;
                
                switch(e.key) {
                    case 'ArrowUp': dy = -step; break;
                    case 'ArrowDown': dy = step; break;
                    case 'ArrowLeft': dx = -step; break;
                    case 'ArrowRight': dx = step; break;
                }
                
                this.selectedElements.forEach(element => {
                    const pos = this.getPosition(element);
                    const newPos = this.snapToGrid(pos.x + dx, pos.y + dy);
                    element.style.left = newPos.x + 'px';
                    element.style.top = newPos.y + 'px';
                    this.markAsModified(element);
                });
                
                this.saveState();
                this.notifyObservers('elements-moved', Array.from(this.selectedElements));
            }
            
            // Delete
            if (e.key === 'Delete' && this.selectedElements.size > 0) {
                e.preventDefault();
                this.deleteSelectedElements();
            }
            
            // Ctrl+C
            if ((e.ctrlKey || e.metaKey) && e.key === 'c' && this.selectedElements.size > 0) {
                e.preventDefault();
                this.copySelectedElements();
            }
            
            // Ctrl+V
            if ((e.ctrlKey || e.metaKey) && e.key === 'v' && this.clipboard) {
                e.preventDefault();
                this.pasteElements();
            }
            
            // Ctrl+Z
            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                this.undo();
            }
            
            // Ctrl+Y or Ctrl+Shift+Z
            if (((e.ctrlKey || e.metaKey) && e.key === 'y') || 
                ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'z')) {
                e.preventDefault();
                this.redo();
            }
            
            // Ctrl+A
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                this.selectAll();
            }
        });
    }
    
    selectAll() {
        this.clearSelection();
        this.elements.forEach(item => {
            this.selectElement(item.element);
        });
    }
    
    /**
     * Select elements within a box
     */
    selectElementsInBox(boxLeft, boxTop, boxWidth, boxHeight) {
        const boxRight = boxLeft + boxWidth;
        const boxBottom = boxTop + boxHeight;
        
        this.clearSelection();
        
        this.elements.forEach(item => {
            const rect = item.element.getBoundingClientRect();
            
            // Check if element is within selection box
            if (rect.left >= boxLeft && rect.right <= boxRight &&
                rect.top >= boxTop && rect.bottom <= boxBottom) {
                this.selectElement(item.element);
            }
        });
    }
    
    createOverlayCanvas() {
        // Create canvas for drawing annotations
        const canvas = document.createElement('canvas');
        canvas.className = 'pdf-overlay-canvas';
        canvas.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 100;
        `;
        
        const pageContainer = this.container.querySelector('.pdf-page-container');
        if (pageContainer) {
            canvas.width = pageContainer.offsetWidth;
            canvas.height = pageContainer.offsetHeight;
            pageContainer.appendChild(canvas);
        }
        
        this.overlayCanvas = canvas;
        this.overlayContext = canvas.getContext('2d');
    }
    
    /**
     * Observer pattern for Vue reactivity
     */
    subscribe(callback) {
        this.observers.push(callback);
    }
    
    unsubscribe(callback) {
        this.observers = this.observers.filter(obs => obs !== callback);
    }
    
    notifyObservers(event, data) {
        this.observers.forEach(callback => callback(event, data));
    }
}

export default PDFEditorCore;