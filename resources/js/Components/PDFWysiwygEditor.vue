<template>
    <div class="pdf-wysiwyg-editor">
        <!-- Top Toolbar -->
        <div class="editor-toolbar">
            <!-- File Actions -->
            <div class="toolbar-group">
                <button @click="exportPDF" class="toolbar-btn" title="Exporter en PDF">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </button>
            </div>

            <div class="toolbar-divider"></div>

            <!-- History Actions -->
            <div class="toolbar-group">
                <button @click="undo" :disabled="!canUndo" class="toolbar-btn" title="Annuler (Ctrl+Z)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                    </svg>
                </button>
                <button @click="redo" :disabled="!canRedo" class="toolbar-btn" title="Rétablir (Ctrl+Y)">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10h-10a8 8 0 00-8 8v2M21 10l-6 6m6-6l-6-6" />
                    </svg>
                </button>
            </div>

            <div class="toolbar-divider"></div>

            <!-- Insert Elements -->
            <div class="toolbar-group">
                <button @click="addText" class="toolbar-btn" title="Ajouter du texte">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                </button>
                <button @click="showImageUpload" class="toolbar-btn" title="Ajouter une image">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </button>
                <button @click="toggleShapeMenu" class="toolbar-btn" title="Ajouter une forme">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7" />
                    </svg>
                </button>
                <button @click="toggleTableMenu" class="toolbar-btn" title="Ajouter un tableau">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                </button>
            </div>

            <div class="toolbar-divider"></div>

            <!-- Advanced Tools -->
            <div class="toolbar-group">
                <button @click="toggleFormFieldMenu" class="toolbar-btn" title="Champs de formulaire">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </button>
                <button @click="toggleSignatureMode" class="toolbar-btn" title="Signature">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                </button>
                <button @click="toggleAnnotationMode" class="toolbar-btn" title="Annotations">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                    </svg>
                </button>
                <button @click="toggleHighlightMode" class="toolbar-btn" title="Surligner">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                        <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                    </svg>
                </button>
            </div>

            <div class="toolbar-divider"></div>

            <!-- View Options -->
            <div class="toolbar-group">
                <button @click="toggleGrid" :class="{ active: gridEnabled }" class="toolbar-btn" title="Grille">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg>
                </button>
                <button @click="toggleRulers" :class="{ active: rulersEnabled }" class="toolbar-btn" title="Règles">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </button>
                <button @click="toggleLayers" :class="{ active: layersVisible }" class="toolbar-btn" title="Calques">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                    </svg>
                </button>
            </div>

            <div class="toolbar-divider"></div>

            <!-- Zoom Controls -->
            <div class="toolbar-group">
                <button @click="zoomOut" class="toolbar-btn" title="Zoom -">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7" />
                    </svg>
                </button>
                <span class="zoom-level">{{ Math.round(zoomLevel * 100) }}%</span>
                <button @click="zoomIn" class="toolbar-btn" title="Zoom +">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7" />
                    </svg>
                </button>
                <button @click="fitToScreen" class="toolbar-btn" title="Ajuster à l'écran">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Dropdown Menus -->
        <div v-if="shapeMenuVisible" class="dropdown-menu shape-menu">
            <button @click="addShape('rectangle')" class="menu-item">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <rect x="4" y="6" width="16" height="12" stroke-width="2"/>
                </svg>
                Rectangle
            </button>
            <button @click="addShape('circle')" class="menu-item">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="8" stroke-width="2"/>
                </svg>
                Cercle
            </button>
            <button @click="addShape('line')" class="menu-item">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <line x1="4" y1="12" x2="20" y2="12" stroke-width="2"/>
                </svg>
                Ligne
            </button>
            <button @click="addShape('arrow')" class="menu-item">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path d="M5 12h14m-4-4l4 4-4 4" stroke-width="2"/>
                </svg>
                Flèche
            </button>
        </div>

        <div v-if="formFieldMenuVisible" class="dropdown-menu form-field-menu">
            <button @click="addFormField('text')" class="menu-item">Champ de texte</button>
            <button @click="addFormField('checkbox')" class="menu-item">Case à cocher</button>
            <button @click="addFormField('radio')" class="menu-item">Bouton radio</button>
            <button @click="addFormField('select')" class="menu-item">Liste déroulante</button>
            <button @click="addFormField('textarea')" class="menu-item">Zone de texte</button>
        </div>

        <!-- Main Editor Area -->
        <div class="editor-container">
            <!-- Left: Layers Panel -->
            <div v-if="layersVisible" class="layers-panel">
                <h3 class="panel-title">Calques</h3>
                <div class="layers-list">
                    <div 
                        v-for="element in sortedElements" 
                        :key="element.id"
                        :class="['layer-item', { selected: isSelected(element) }]"
                        @click="selectElement(element)"
                    >
                        <span class="layer-icon">{{ getElementIcon(element.type) }}</span>
                        <span class="layer-name">{{ getElementName(element) }}</span>
                        <div class="layer-actions">
                            <button @click.stop="toggleElementVisibility(element)" class="layer-btn">
                                {{ element.visible ? '👁' : '👁‍🗨' }}
                            </button>
                            <button @click.stop="toggleElementLock(element)" class="layer-btn">
                                {{ element.locked ? '🔒' : '🔓' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Center: Canvas -->
            <div class="editor-canvas-wrapper" @wheel="handleWheel">
                <div v-if="rulersEnabled" class="ruler ruler-horizontal"></div>
                <div v-if="rulersEnabled" class="ruler ruler-vertical"></div>
                
                <div 
                    ref="editorCanvas" 
                    class="editor-canvas"
                    :style="{ transform: `scale(${zoomLevel})` }"
                    :class="{ 'grid-enabled': gridEnabled }"
                >
                    <!-- PDF pages container -->
                    <div class="pdf-pages-wrapper" v-html="editorContent" @click="handleCanvasClick"></div>
                </div>
            </div>

            <!-- Right: Properties Panel -->
            <div v-if="selectedElement" class="properties-panel">
                <h3 class="panel-title">Propriétés</h3>
                
                <!-- Position -->
                <div class="property-group">
                    <label class="property-label">Position</label>
                    <div class="property-row">
                        <input 
                            type="number" 
                            v-model.number="elementPosition.x" 
                            @change="updateElementPosition"
                            class="property-input"
                            placeholder="X"
                        >
                        <input 
                            type="number" 
                            v-model.number="elementPosition.y" 
                            @change="updateElementPosition"
                            class="property-input"
                            placeholder="Y"
                        >
                    </div>
                </div>

                <!-- Dimensions -->
                <div class="property-group">
                    <label class="property-label">Dimensions</label>
                    <div class="property-row">
                        <input 
                            type="number" 
                            v-model.number="elementDimensions.width" 
                            @change="updateElementDimensions"
                            class="property-input"
                            placeholder="Largeur"
                        >
                        <input 
                            type="number" 
                            v-model.number="elementDimensions.height" 
                            @change="updateElementDimensions"
                            class="property-input"
                            placeholder="Hauteur"
                        >
                    </div>
                    <label class="property-checkbox">
                        <input type="checkbox" v-model="maintainAspectRatio">
                        Conserver les proportions
                    </label>
                </div>

                <!-- Rotation -->
                <div class="property-group">
                    <label class="property-label">Rotation</label>
                    <input 
                        type="range" 
                        v-model.number="elementRotation" 
                        @input="updateElementRotation"
                        min="-180" 
                        max="180" 
                        class="property-slider"
                    >
                    <span class="property-value">{{ elementRotation }}°</span>
                </div>

                <!-- Opacity -->
                <div class="property-group">
                    <label class="property-label">Opacité</label>
                    <input 
                        type="range" 
                        v-model.number="elementOpacity" 
                        @input="updateElementOpacity"
                        min="0" 
                        max="100" 
                        class="property-slider"
                    >
                    <span class="property-value">{{ elementOpacity }}%</span>
                </div>

                <!-- Text Properties (if text element) -->
                <div v-if="selectedElementType === 'text'" class="property-group">
                    <label class="property-label">Police</label>
                    <select v-model="textFontFamily" @change="updateTextStyle" class="property-select">
                        <option value="Arial">Arial</option>
                        <option value="Times New Roman">Times New Roman</option>
                        <option value="Courier New">Courier New</option>
                        <option value="Georgia">Georgia</option>
                        <option value="Verdana">Verdana</option>
                        <option value="Comic Sans MS">Comic Sans MS</option>
                    </select>
                    
                    <label class="property-label">Taille</label>
                    <input 
                        type="number" 
                        v-model.number="textFontSize" 
                        @change="updateTextStyle"
                        min="8" 
                        max="72" 
                        class="property-input"
                    >
                    
                    <label class="property-label">Couleur</label>
                    <input 
                        type="color" 
                        v-model="textColor" 
                        @change="updateTextStyle"
                        class="property-color"
                    >
                    
                    <div class="property-row">
                        <button 
                            @click="toggleTextStyle('bold')" 
                            :class="{ active: textBold }"
                            class="style-btn"
                        >B</button>
                        <button 
                            @click="toggleTextStyle('italic')" 
                            :class="{ active: textItalic }"
                            class="style-btn"
                        >I</button>
                        <button 
                            @click="toggleTextStyle('underline')" 
                            :class="{ active: textUnderline }"
                            class="style-btn"
                        >U</button>
                    </div>
                </div>

                <!-- Image Properties (if image element) -->
                <div v-if="selectedElementType === 'image'" class="property-group">
                    <label class="property-label">Filtres</label>
                    <label class="property-label">Luminosité</label>
                    <input 
                        type="range" 
                        v-model.number="imageBrightness" 
                        @input="updateImageFilters"
                        min="0" 
                        max="200" 
                        class="property-slider"
                    >
                    
                    <label class="property-label">Contraste</label>
                    <input 
                        type="range" 
                        v-model.number="imageContrast" 
                        @input="updateImageFilters"
                        min="0" 
                        max="200" 
                        class="property-slider"
                    >
                    
                    <label class="property-label">Saturation</label>
                    <input 
                        type="range" 
                        v-model.number="imageSaturation" 
                        @input="updateImageFilters"
                        min="0" 
                        max="200" 
                        class="property-slider"
                    >
                    
                    <button @click="replaceImage" class="property-btn">
                        Remplacer l'image
                    </button>
                </div>

                <!-- Z-Index -->
                <div class="property-group">
                    <label class="property-label">Ordre d'affichage</label>
                    <div class="property-row">
                        <button @click="bringToFront" class="property-btn">Avant</button>
                        <button @click="sendToBack" class="property-btn">Arrière</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Context Menu -->
        <div 
            v-if="contextMenuVisible" 
            :style="contextMenuStyle"
            class="context-menu"
        >
            <button @click="copyElement" class="context-menu-item">Copier</button>
            <button @click="pasteElement" class="context-menu-item" :disabled="!hasClipboard">Coller</button>
            <button @click="duplicateElement" class="context-menu-item">Dupliquer</button>
            <div class="context-menu-divider"></div>
            <button @click="deleteElement" class="context-menu-item">Supprimer</button>
        </div>

        <!-- Signature Pad (Modal) -->
        <div v-if="signatureModalVisible" class="modal-overlay" @click="closeSignatureModal">
            <div class="modal-content" @click.stop>
                <h3 class="modal-title">Dessiner votre signature</h3>
                <canvas 
                    ref="signatureCanvas" 
                    class="signature-canvas"
                    @mousedown="startDrawing"
                    @mousemove="draw"
                    @mouseup="stopDrawing"
                    @mouseleave="stopDrawing"
                ></canvas>
                <div class="modal-actions">
                    <button @click="clearSignature" class="btn btn-secondary">Effacer</button>
                    <button @click="saveSignature" class="btn btn-primary">Ajouter</button>
                    <button @click="closeSignatureModal" class="btn btn-cancel">Annuler</button>
                </div>
            </div>
        </div>

        <!-- Image Upload (Hidden) -->
        <input 
            ref="imageUpload" 
            type="file" 
            accept="image/*" 
            @change="handleImageUpload"
            style="display: none;"
        >

        <!-- Status Bar -->
        <div class="status-bar">
            <span class="status-item">{{ selectedCount }} élément(s) sélectionné(s)</span>
            <span class="status-item">{{ modifiedCount }} modification(s)</span>
            <span class="status-item" v-if="lastSaved">Dernière sauvegarde: {{ lastSaved }}</span>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { PDFEditorCore } from '@/Services/PDFEditorCore.js';
import axios from 'axios';

const props = defineProps({
    document: Object,
    initialContent: String
});

const emit = defineEmits(['save', 'export']);

// Editor instance
let editor = null;

// Refs
const editorCanvas = ref(null);
const signatureCanvas = ref(null);
const imageUpload = ref(null);

// State
const editorContent = ref('');
const zoomLevel = ref(1);
const gridEnabled = ref(false);
const rulersEnabled = ref(false);
const layersVisible = ref(false);
const annotationMode = ref(false);
const highlightMode = ref(false);
const highlightColor = ref('#ffff00');
const selectedElement = ref(null);
const selectedElements = ref([]);
const elements = ref([]);
const canUndo = ref(false);
const canRedo = ref(false);
const contextMenuVisible = ref(false);
const contextMenuStyle = ref({});
const shapeMenuVisible = ref(false);
const formFieldMenuVisible = ref(false);
const signatureModalVisible = ref(false);
const hasClipboard = ref(false);
const lastSaved = ref(null);

// Element properties
const elementPosition = ref({ x: 0, y: 0 });
const elementDimensions = ref({ width: 0, height: 0 });
const elementRotation = ref(0);
const elementOpacity = ref(100);
const maintainAspectRatio = ref(true);

// Text properties
const textFontFamily = ref('Arial');
const textFontSize = ref(14);
const textColor = ref('#000000');
const textBold = ref(false);
const textItalic = ref(false);
const textUnderline = ref(false);

// Image properties
const imageBrightness = ref(100);
const imageContrast = ref(100);
const imageSaturation = ref(100);

// Signature drawing
const isDrawing = ref(false);
let signatureContext = null;

// Computed
const selectedCount = computed(() => selectedElements.value.length);
const modifiedCount = computed(() => {
    return elements.value.filter(el => el.modified).length;
});
const selectedElementType = computed(() => {
    if (!selectedElement.value) return null;
    return editor?.getElementType(selectedElement.value);
});
const sortedElements = computed(() => {
    return [...elements.value].sort((a, b) => {
        const aZ = parseInt(a.element.style.zIndex) || 0;
        const bZ = parseInt(b.element.style.zIndex) || 0;
        return bZ - aZ;
    });
});

// Initialize editor
onMounted(async () => {
    // Initialize PDF Editor Core
    editor = new PDFEditorCore();
    
    // Load improved CSS for better rendering
    const improvedCSS = document.createElement('link');
    improvedCSS.rel = 'stylesheet';
    improvedCSS.href = '/css/pdf-editor-improved.css';
    document.head.appendChild(improvedCSS);
    
    // Load initial content
    if (props.initialContent) {
        console.log('Loading initial content, length:', props.initialContent.length);
        
        // Parse the HTML to extract just the body content
        const parser = new DOMParser();
        const doc = parser.parseFromString(props.initialContent, 'text/html');
        
        // Look for #pdfContent first
        let content = doc.querySelector('#pdfContent');
        
        if (content) {
            console.log('Found #pdfContent div');
            
            // Extract and inject styles from within the content
            const styles = content.querySelectorAll('style');
            styles.forEach(styleElement => {
                // Create a new style element and add it to the document head
                const newStyle = document.createElement('style');
                newStyle.textContent = styleElement.textContent;
                document.head.appendChild(newStyle);
                console.log('Injected style block with', styleElement.textContent.length, 'characters');
                // Remove the style element from the content
                styleElement.remove();
            });
            
            // Now set the content without the style tags
            editorContent.value = content.innerHTML;
        } else {
            // Fallback: look for pdf-page elements directly
            const pages = doc.querySelectorAll('.pdf-page');
            if (pages.length > 0) {
                console.log('Found', pages.length, 'pdf-page elements');
                const wrapper = document.createElement('div');
                pages.forEach(page => wrapper.appendChild(page.cloneNode(true)));
                editorContent.value = wrapper.innerHTML;
            } else {
                // Last fallback: use body content
                console.log('Using body content as fallback');
                editorContent.value = doc.body ? doc.body.innerHTML : props.initialContent;
            }
        }
        
        console.log('Editor content set, pages:', (editorContent.value.match(/pdf-page/g) || []).length);
    } else if (props.document) {
        await loadDocumentContent();
    }
    
    // Wait for DOM update
    await nextTick();
    
    // Initialize editor with canvas
    if (editorCanvas.value) {
        const canvas = editorCanvas.value.querySelector('div');
        if (canvas) {
            editor.init(canvas);
            
            // Subscribe to editor events
            editor.subscribe(handleEditorEvent);
            
            // Update elements list
            elements.value = Array.from(editor.elements.values());
        }
    }
    
    // Setup keyboard shortcuts
    document.addEventListener('keydown', handleKeyboard);
    
    // Setup auto-save
    startAutoSave();
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeyboard);
    if (editor) {
        editor.unsubscribe(handleEditorEvent);
    }
    stopAutoSave();
});

// Load document content
async function loadDocumentContent() {
    try {
        const response = await axios.post(route('documents.convert-to-html', props.document.id));
        if (response.data.success) {
            // Clean the HTML to remove any old editor toolbars
            let cleanHtml = response.data.html;
            
            // Create a temporary div to parse the HTML
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = cleanHtml;
            
            // Remove any old toolbar elements
            const toolbars = tempDiv.querySelectorAll('.editor-toolbar, .toolbar, .pdf-toolbar, .control-panel, .tools-panel');
            toolbars.forEach(toolbar => toolbar.remove());
            
            // Remove any old UI elements
            const uiElements = tempDiv.querySelectorAll('.resize-handle, .pdf-controls, .editor-controls');
            uiElements.forEach(el => el.remove());
            
            // Fix duplicate text issues
            removeDuplicateText(tempDiv);
            
            // Fix image layering
            fixImageLayering(tempDiv);
            
            // Get only the PDF content
            const pdfContent = tempDiv.querySelector('#pdfContent') || tempDiv.querySelector('.pdf-content');
            if (pdfContent) {
                editorContent.value = pdfContent.innerHTML;
            } else {
                // If no specific PDF content container, use cleaned HTML
                editorContent.value = tempDiv.innerHTML;
            }
        }
    } catch (error) {
        console.error('Error loading document:', error);
    }
}

// Editor event handler
function handleEditorEvent(event, data) {
    switch(event) {
        case 'element-selected':
            selectedElement.value = data;
            selectedElements.value = Array.from(editor.selectedElements);
            updatePropertyPanel(data);
            break;
        case 'selection-cleared':
            selectedElement.value = null;
            selectedElements.value = [];
            break;
        case 'element-moved':
        case 'elements-moved':
        case 'element-resized':
        case 'text-changed':
            if (data && data.element) {
                updatePropertyPanel(data.element);
            } else if (selectedElement.value) {
                updatePropertyPanel(selectedElement.value);
            }
            break;
        case 'state-saved':
            updateHistoryButtons();
            break;
        case 'context-menu':
            showContextMenu(data.event, data.element);
            break;
        case 'elements-copied':
            hasClipboard.value = true;
            break;
        case 'grid-changed':
            gridEnabled.value = data.enabled;
            break;
    }
    
    // Update elements list
    elements.value = Array.from(editor.elements.values());
}

// Update property panel
function updatePropertyPanel(element) {
    if (!element || !editor) return;
    
    const pos = editor.getPosition(element);
    const dim = editor.getDimensions(element);
    
    elementPosition.value = pos;
    elementDimensions.value = dim;
    
    const transform = element.style.transform || '';
    const rotateMatch = transform.match(/rotate\(([-\d.]+)deg\)/);
    elementRotation.value = rotateMatch ? parseFloat(rotateMatch[1]) : 0;
    
    const opacity = parseFloat(element.style.opacity || 1);
    elementOpacity.value = opacity * 100;
    
    // Text properties
    if (editor.getElementType(element) === 'text') {
        const computed = window.getComputedStyle(element);
        textFontFamily.value = computed.fontFamily.split(',')[0].replace(/['"]/g, '');
        textFontSize.value = parseInt(computed.fontSize);
        textColor.value = rgbToHex(computed.color);
        textBold.value = computed.fontWeight === 'bold' || parseInt(computed.fontWeight) >= 700;
        textItalic.value = computed.fontStyle === 'italic';
        textUnderline.value = computed.textDecoration.includes('underline');
    }
    
    // Image properties
    if (editor.getElementType(element) === 'image') {
        const filter = element.style.filter || '';
        const brightnessMatch = filter.match(/brightness\(([\d.]+)\)/);
        const contrastMatch = filter.match(/contrast\(([\d.]+)\)/);
        const saturateMatch = filter.match(/saturate\(([\d.]+)\)/);
        
        imageBrightness.value = brightnessMatch ? parseFloat(brightnessMatch[1]) * 100 : 100;
        imageContrast.value = contrastMatch ? parseFloat(contrastMatch[1]) * 100 : 100;
        imageSaturation.value = saturateMatch ? parseFloat(saturateMatch[1]) * 100 : 100;
    }
}

// Update history buttons
function updateHistoryButtons() {
    canUndo.value = editor.historyIndex > 0;
    canRedo.value = editor.historyIndex < editor.history.length - 1;
}

// Toolbar actions
function saveDocument() {
    const html = editor.exportHTML();
    emit('save', html);
    lastSaved.value = new Date().toLocaleTimeString();
}

function exportPDF() {
    const html = editor.exportHTML();
    emit('export', html);
}

function undo() {
    editor.undo();
}

function redo() {
    editor.redo();
}

function addText() {
    if (!editor) return;
    editor.addElement('text', {
        text: 'Nouveau texte',
        fontSize: 14,
        color: '#000000'
    });
}

function showImageUpload() {
    imageUpload.value.click();
}

function handleImageUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = (e) => {
        editor.addElement('image', {
            src: e.target.result,
            width: 200,
            height: 200
        });
    };
    reader.readAsDataURL(file);
}

function toggleShapeMenu() {
    shapeMenuVisible.value = !shapeMenuVisible.value;
    formFieldMenuVisible.value = false;
}

function addShape(shapeType) {
    editor.addElement('shape', {
        shapeType,
        width: 100,
        height: 100,
        stroke: '#000000',
        strokeWidth: 2,
        fill: 'transparent'
    });
    shapeMenuVisible.value = false;
}

function toggleTableMenu() {
    if (editor) {
        editor.addTable();
    }
}

function toggleFormFieldMenu() {
    formFieldMenuVisible.value = !formFieldMenuVisible.value;
    shapeMenuVisible.value = false;
}

function addFormField(fieldType) {
    editor.addElement('form-field', {
        fieldType,
        width: 200
    });
    formFieldMenuVisible.value = false;
}

function toggleSignatureMode() {
    signatureModalVisible.value = true;
    nextTick(() => {
        if (signatureCanvas.value) {
            signatureContext = signatureCanvas.value.getContext('2d');
            signatureCanvas.value.width = 400;
            signatureCanvas.value.height = 200;
            signatureContext.strokeStyle = '#000';
            signatureContext.lineWidth = 2;
            signatureContext.lineCap = 'round';
        }
    });
}

function toggleAnnotationMode() {
    if (!editor) return;
    
    // Toggle annotation mode
    annotationMode.value = !annotationMode.value;
    
    if (annotationMode.value) {
        // Disable other modes
        highlightMode.value = false;
        editor.disableHighlightMode();
        
        // Enable annotation mode
        editor.enableAnnotationMode();
    } else {
        editor.disableAnnotationMode();
    }
}

function toggleHighlightMode() {
    if (!editor) return;
    
    // Toggle highlight mode
    highlightMode.value = !highlightMode.value;
    
    if (highlightMode.value) {
        // Disable other modes
        annotationMode.value = false;
        editor.disableAnnotationMode();
        
        // Show color picker or use default
        showHighlightColorPicker();
    } else {
        editor.disableHighlightMode();
    }
}

function showHighlightColorPicker() {
    // Create color picker dialog
    const dialog = document.createElement('div');
    dialog.className = 'highlight-color-picker';
    dialog.style.cssText = `
        position: fixed;
        top: 100px;
        right: 20px;
        background: white;
        border: 1px solid #ccc;
        border-radius: 8px;
        padding: 15px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        z-index: 35;
    `;
    
    dialog.innerHTML = `
        <h4 style="margin: 0 0 10px 0; font-size: 14px;">Choose highlight color:</h4>
        <div class="color-options" style="display: flex; gap: 8px; margin-bottom: 10px;">
            <button data-color="#ffff00" style="width: 30px; height: 30px; background: #ffff00; border: 1px solid #666; cursor: pointer; opacity: 0.6;"></button>
            <button data-color="#00ff00" style="width: 30px; height: 30px; background: #00ff00; border: 1px solid #666; cursor: pointer; opacity: 0.6;"></button>
            <button data-color="#00ffff" style="width: 30px; height: 30px; background: #00ffff; border: 1px solid #666; cursor: pointer; opacity: 0.6;"></button>
            <button data-color="#ff00ff" style="width: 30px; height: 30px; background: #ff00ff; border: 1px solid #666; cursor: pointer; opacity: 0.6;"></button>
            <button data-color="#ffa500" style="width: 30px; height: 30px; background: #ffa500; border: 1px solid #666; cursor: pointer; opacity: 0.6;"></button>
        </div>
        <div style="text-align: center;">
            <button id="closeHighlightPicker" style="padding: 5px 15px; background: #666; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
        </div>
    `;
    
    document.body.appendChild(dialog);
    
    // Handle color selection
    dialog.querySelectorAll('[data-color]').forEach(btn => {
        btn.addEventListener('click', () => {
            const color = btn.dataset.color;
            highlightColor.value = color;
            editor.enableHighlightMode(color);
            
            // Visual feedback
            dialog.querySelectorAll('[data-color]').forEach(b => {
                b.style.outline = 'none';
            });
            btn.style.outline = '2px solid #007bff';
        });
    });
    
    // Close button
    dialog.querySelector('#closeHighlightPicker').addEventListener('click', () => {
        dialog.remove();
        if (!editor.mode === 'highlight') {
            highlightMode.value = false;
        }
    });
    
    // Auto-select first color
    const firstColor = dialog.querySelector('[data-color]');
    if (firstColor) {
        firstColor.click();
    }
}

function toggleGrid() {
    gridEnabled.value = !gridEnabled.value;
    if (editor) {
        editor.setGridEnabled(gridEnabled.value, 10);
    }
}

function toggleRulers() {
    rulersEnabled.value = !rulersEnabled.value;
}

function toggleLayers() {
    layersVisible.value = !layersVisible.value;
}

function zoomIn() {
    zoomLevel.value = Math.min(zoomLevel.value + 0.1, 3);
}

function zoomOut() {
    zoomLevel.value = Math.max(zoomLevel.value - 0.1, 0.3);
}

function fitToScreen() {
    // TODO: Calculate optimal zoom to fit content
    zoomLevel.value = 1;
}

function handleWheel(event) {
    if (event.ctrlKey || event.metaKey) {
        event.preventDefault();
        if (event.deltaY < 0) {
            zoomIn();
        } else {
            zoomOut();
        }
    }
}

// Property panel actions
function updateElementPosition() {
    if (!selectedElement.value) return;
    selectedElement.value.style.left = elementPosition.value.x + 'px';
    selectedElement.value.style.top = elementPosition.value.y + 'px';
    editor.markAsModified(selectedElement.value);
    editor.saveState();
}

function updateElementDimensions() {
    if (!selectedElement.value) return;
    
    if (maintainAspectRatio.value) {
        const currentDim = editor.getDimensions(selectedElement.value);
        const aspectRatio = currentDim.width / currentDim.height;
        
        if (elementDimensions.value.width !== currentDim.width) {
            elementDimensions.value.height = elementDimensions.value.width / aspectRatio;
        } else {
            elementDimensions.value.width = elementDimensions.value.height * aspectRatio;
        }
    }
    
    selectedElement.value.style.width = elementDimensions.value.width + 'px';
    selectedElement.value.style.height = elementDimensions.value.height + 'px';
    editor.markAsModified(selectedElement.value);
    editor.saveState();
}

function updateElementRotation() {
    if (!selectedElement.value) return;
    selectedElement.value.style.transform = `rotate(${elementRotation.value}deg)`;
    editor.markAsModified(selectedElement.value);
}

function updateElementOpacity() {
    if (!selectedElement.value) return;
    selectedElement.value.style.opacity = elementOpacity.value / 100;
    editor.markAsModified(selectedElement.value);
}

function updateTextStyle() {
    if (!selectedElement.value || editor.getElementType(selectedElement.value) !== 'text') return;
    
    selectedElement.value.style.fontFamily = textFontFamily.value;
    selectedElement.value.style.fontSize = textFontSize.value + 'px';
    selectedElement.value.style.color = textColor.value;
    
    editor.markAsModified(selectedElement.value);
    editor.saveState();
}

function toggleTextStyle(style) {
    if (!selectedElement.value || editor.getElementType(selectedElement.value) !== 'text') return;
    
    switch(style) {
        case 'bold':
            textBold.value = !textBold.value;
            selectedElement.value.style.fontWeight = textBold.value ? 'bold' : 'normal';
            break;
        case 'italic':
            textItalic.value = !textItalic.value;
            selectedElement.value.style.fontStyle = textItalic.value ? 'italic' : 'normal';
            break;
        case 'underline':
            textUnderline.value = !textUnderline.value;
            selectedElement.value.style.textDecoration = textUnderline.value ? 'underline' : 'none';
            break;
    }
    
    editor.markAsModified(selectedElement.value);
    editor.saveState();
}

function updateImageFilters() {
    if (!selectedElement.value || editor.getElementType(selectedElement.value) !== 'image') return;
    
    const img = selectedElement.value.querySelector('img') || selectedElement.value;
    const filters = [
        `brightness(${imageBrightness.value / 100})`,
        `contrast(${imageContrast.value / 100})`,
        `saturate(${imageSaturation.value / 100})`
    ];
    
    img.style.filter = filters.join(' ');
    editor.markAsModified(selectedElement.value);
}

function replaceImage() {
    // Store current selected element
    const currentElement = selectedElement.value;
    
    // Create temporary file input
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = (e) => {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (event) => {
                const img = currentElement.querySelector('img') || currentElement;
                img.src = event.target.result;
                editor.markAsModified(currentElement);
                editor.saveState();
            };
            reader.readAsDataURL(file);
        }
    };
    input.click();
}

function bringToFront() {
    if (!selectedElement.value) return;
    const maxZ = editor.getMaxZIndex();
    selectedElement.value.style.zIndex = maxZ + 1;
    editor.markAsModified(selectedElement.value);
    editor.saveState();
}

function sendToBack() {
    if (!selectedElement.value) return;
    selectedElement.value.style.zIndex = 1;
    // Adjust other elements
    editor.elements.forEach(item => {
        if (item.element !== selectedElement.value) {
            const z = parseInt(item.element.style.zIndex) || 0;
            if (z > 0) {
                item.element.style.zIndex = z + 1;
            }
        }
    });
    editor.markAsModified(selectedElement.value);
    editor.saveState();
}

// Helper functions to fix rendering issues
function removeDuplicateText(container) {
    const textMap = new Map();
    const allTextElements = container.querySelectorAll('p, span, div');
    
    allTextElements.forEach(element => {
        const text = element.textContent?.trim();
        if (!text) return;
        
        // Get position
        const style = element.getAttribute('style') || '';
        const leftMatch = style.match(/left:\s*([0-9.]+)/);
        const topMatch = style.match(/top:\s*([0-9.]+)/);
        
        const left = leftMatch ? parseFloat(leftMatch[1]) : 0;
        const top = topMatch ? parseFloat(topMatch[1]) : 0;
        
        // Create unique key based on position and text
        const key = `${Math.round(left/10)}_${Math.round(top/10)}_${text.substring(0, 20)}`;
        
        if (textMap.has(key)) {
            // Mark as duplicate
            element.setAttribute('data-duplicate', 'true');
            element.classList.add('duplicate-text');
        } else {
            textMap.set(key, element);
        }
    });
}

function fixImageLayering(container) {
    const images = container.querySelectorAll('img');
    
    images.forEach(img => {
        const width = parseInt(img.getAttribute('width') || '0');
        const height = parseInt(img.getAttribute('height') || '0');
        const src = img.getAttribute('src') || '';
        
        // Check if it's likely a background image
        if (width > 500 || height > 700) {
            img.classList.add('background-image');
            img.style.position = 'absolute';
            img.style.zIndex = '0';
            img.style.top = '0';
            img.style.left = '0';
            img.style.pointerEvents = 'none';
        } 
        // Check if it's likely a profile/author image
        else if (src.includes('profile') || src.includes('author') || src.includes('avatar') || 
                 (width > 50 && width < 200 && height > 50 && height < 200 && width === height)) {
            img.classList.add('author-image');
            img.style.borderRadius = '50%';
            img.style.zIndex = '15';
            img.style.position = 'relative';
            img.style.boxShadow = '0 2px 8px rgba(0,0,0,0.15)';
        }
        // Regular content images
        else {
            img.style.position = 'relative';
            img.style.zIndex = '5';
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
        }
    });
    
    // Ensure text is above backgrounds
    const textElements = container.querySelectorAll('p, span, h1, h2, h3, h4, h5, h6');
    textElements.forEach(el => {
        const currentZ = parseInt(el.style.zIndex || '0');
        if (currentZ < 10) {
            el.style.zIndex = '10';
            el.style.position = 'relative';
        }
    });
}

// Layers panel
function selectElement(elementData) {
    editor.clearSelection();
    editor.selectElement(elementData.element);
}

function isSelected(elementData) {
    return selectedElement.value === elementData.element;
}

function getElementIcon(type) {
    const icons = {
        text: '📝',
        image: '🖼️',
        shape: '⬛',
        vector: '✏️',
        'form-field': '📋',
        table: '📊'
    };
    return icons[type] || '📄';
}

function getElementName(elementData) {
    if (elementData.type === 'text') {
        const text = elementData.element.textContent || 'Texte';
        return text.substring(0, 20) + (text.length > 20 ? '...' : '');
    }
    return `${elementData.type} #${elementData.id.split('-').pop()}`;
}

function toggleElementVisibility(elementData) {
    const visible = elementData.element.style.display !== 'none';
    elementData.element.style.display = visible ? 'none' : '';
    elementData.visible = !visible;
}

function toggleElementLock(elementData) {
    elementData.locked = !elementData.locked;
    elementData.element.style.pointerEvents = elementData.locked ? 'none' : '';
}

// Context menu
function showContextMenu(event, element) {
    contextMenuStyle.value = {
        left: event.clientX + 'px',
        top: event.clientY + 'px'
    };
    contextMenuVisible.value = true;
    selectedElement.value = element;
}

function copyElement() {
    editor.copySelectedElements();
    contextMenuVisible.value = false;
}

function pasteElement() {
    editor.pasteElements();
    contextMenuVisible.value = false;
}

function duplicateElement() {
    editor.copySelectedElements();
    editor.pasteElements();
    contextMenuVisible.value = false;
}

function deleteElement() {
    editor.deleteSelectedElements();
    contextMenuVisible.value = false;
}

// Signature pad
function startDrawing(e) {
    if (!signatureContext) return;
    isDrawing.value = true;
    const rect = signatureCanvas.value.getBoundingClientRect();
    signatureContext.beginPath();
    signatureContext.moveTo(e.clientX - rect.left, e.clientY - rect.top);
}

function draw(e) {
    if (!isDrawing.value || !signatureContext) return;
    const rect = signatureCanvas.value.getBoundingClientRect();
    signatureContext.lineTo(e.clientX - rect.left, e.clientY - rect.top);
    signatureContext.stroke();
}

function stopDrawing() {
    isDrawing.value = false;
}

function clearSignature() {
    if (!signatureContext) return;
    signatureContext.clearRect(0, 0, signatureCanvas.value.width, signatureCanvas.value.height);
}

function saveSignature() {
    const dataUrl = signatureCanvas.value.toDataURL();
    editor.addElement('image', {
        src: dataUrl,
        width: 200,
        height: 100
    });
    closeSignatureModal();
}

function closeSignatureModal() {
    signatureModalVisible.value = false;
    clearSignature();
}

// Keyboard shortcuts
function handleKeyboard(e) {
    // Arrow keys for fine movement
    if (selectedElement.value && !e.target.closest('input, textarea, [contenteditable="true"]')) {
        const step = e.shiftKey ? 10 : 1; // Shift for larger steps
        let moved = false;
        
        switch(e.key) {
            case 'ArrowUp':
                e.preventDefault();
                elementPosition.value.y -= step;
                moved = true;
                break;
            case 'ArrowDown':
                e.preventDefault();
                elementPosition.value.y += step;
                moved = true;
                break;
            case 'ArrowLeft':
                e.preventDefault();
                elementPosition.value.x -= step;
                moved = true;
                break;
            case 'ArrowRight':
                e.preventDefault();
                elementPosition.value.x += step;
                moved = true;
                break;
        }
        
        if (moved) {
            updateElementPosition();
        }
    }
    
    // Handled by PDFEditorCore for other shortcuts
}

function handleCanvasClick(e) {
    // Close menus
    shapeMenuVisible.value = false;
    formFieldMenuVisible.value = false;
    contextMenuVisible.value = false;
}

// Auto-save
let autoSaveInterval = null;

function startAutoSave() {
    autoSaveInterval = setInterval(() => {
        if (modifiedCount.value > 0) {
            saveDocument();
        }
    }, 30000); // Auto-save every 30 seconds
}

function stopAutoSave() {
    if (autoSaveInterval) {
        clearInterval(autoSaveInterval);
    }
}

// Utilities
function rgbToHex(rgb) {
    const match = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
    if (!match) return '#000000';
    
    const hex = (x) => {
        const h = parseInt(x).toString(16);
        return h.length === 1 ? '0' + h : h;
    };
    
    return '#' + hex(match[1]) + hex(match[2]) + hex(match[3]);
}
</script>

<style scoped>
.pdf-wysiwyg-editor {
    display: flex;
    flex-direction: column;
    height: 100vh;
    background: #f0f0f0;
}

/* Toolbar */
.editor-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    background: white;
    border-bottom: 1px solid #ddd;
    padding: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    z-index: 30;
    overflow-x: auto;
    white-space: nowrap;
}

.toolbar-group {
    display: inline-flex;
    gap: 4px;
    margin: 0 4px;
    flex-shrink: 0;
}

.toolbar-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid transparent;
    background: transparent;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.toolbar-btn:hover {
    background: #f0f0f0;
    border-color: #ddd;
}

.toolbar-btn:active,
.toolbar-btn.active {
    background: #e0e0e0;
}

.toolbar-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.toolbar-divider {
    display: inline-block;
    width: 1px;
    height: 24px;
    background: #ddd;
    margin: 0 8px;
    vertical-align: middle;
    flex-shrink: 0;
}

.zoom-level {
    display: inline-block;
    min-width: 50px;
    text-align: center;
    font-size: 14px;
    flex-shrink: 0;
    line-height: 36px;
}

/* Dropdown Menus */
.dropdown-menu {
    position: absolute;
    top: 52px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 200;
    padding: 4px;
}

.shape-menu {
    left: 200px;
}

.form-field-menu {
    left: 400px;
}

.menu-item {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 8px 12px;
    border: none;
    background: transparent;
    text-align: left;
    cursor: pointer;
    transition: background 0.2s;
}

.menu-item:hover {
    background: #f0f0f0;
}

/* Editor Container */
.editor-container {
    display: flex;
    flex: 1;
    overflow: hidden;
    background: #f5f5f5;
}

/* Layers Panel */
.layers-panel {
    width: 250px;
    background: white;
    border-right: 1px solid #ddd;
    display: flex;
    flex-direction: column;
}

.panel-title {
    padding: 12px;
    font-size: 14px;
    font-weight: 600;
    border-bottom: 1px solid #eee;
}

.layers-list {
    flex: 1;
    overflow-y: auto;
}

.layer-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.2s;
}

.layer-item:hover {
    background: #f8f8f8;
}

.layer-item.selected {
    background: #e3f2fd;
}

.layer-icon {
    margin-right: 8px;
}

.layer-name {
    flex: 1;
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.layer-actions {
    display: flex;
    gap: 4px;
}

.layer-btn {
    padding: 2px;
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
}

/* Canvas Wrapper */
.editor-canvas-wrapper {
    flex: 1;
    position: relative;
    padding: 0;
    padding-right: 25px;
    margin: 0;
    overflow: auto;
    background: #f5f5f5;
    display: flex;
    justify-content: center;
    align-items: flex-start;
}

/* Drag and Drop Styles */
:deep(.pdf-text),
:deep(.pdf-image),
:deep(.pdf-vector),
:deep(.pdf-shape),
:deep(.pdf-form-field),
:deep(.pdf-added),
:deep(.pdf-image-container) {
    transition: box-shadow 0.2s, opacity 0.2s;
}

:deep(.pdf-text:hover),
:deep(.pdf-image:hover),
:deep(.pdf-vector:hover),
:deep(.pdf-shape:hover),
:deep(.pdf-form-field:hover),
:deep(.pdf-added:hover),
:deep(.pdf-image-container:hover) {
    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.2);
    outline: 1px solid rgba(0, 123, 255, 0.3);
}

:deep(.dragging) {
    cursor: grabbing !important;
    opacity: 0.7 !important;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3) !important;
    z-index: 35 !important;
}

:deep(.selected) {
    outline: 2px solid #007bff !important;
    outline-offset: 2px;
}

:deep(.selected.dragging) {
    outline: 2px dashed #007bff !important;
}

/* Selection Box */
:global(.selection-box) {
    position: fixed;
    border: 1px dashed #007bff;
    background: rgba(0, 123, 255, 0.1);
    pointer-events: none;
    z-index: 35;
}

/* Alignment Guides */
:deep(.alignment-guide) {
    position: absolute;
    background: #007bff;
    opacity: 0.5;
    z-index: 35;
    pointer-events: none;
}

/* Resize Handles */
:deep(.resize-handle) {
    position: absolute;
    width: 8px;
    height: 8px;
    background: #007bff;
    border: 1px solid #fff;
    border-radius: 50%;
    z-index: 32;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}

:deep(.resize-handle:hover) {
    background: #0056b3;
    transform: scale(1.2);
}

/* Drag Handle */
:deep(.drag-handle) {
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
    z-index: 33;
    box-shadow: 0 2px 8px rgba(0,0,0,0.25);
    transition: all 0.2s ease;
    border: 2px solid white;
}

:deep(.drag-handle:hover) {
    background: #0056b3;
    transform: translateX(-50%) scale(1.15);
    box-shadow: 0 4px 12px rgba(0,0,0,0.35);
}

:deep(.drag-handle svg) {
    width: 20px;
    height: 20px;
    pointer-events: none;
}

:deep(.selected .drag-handle),
:deep(.pdf-text:hover .drag-handle),
:deep(.pdf-image:hover .drag-handle),
:deep(.pdf-vector:hover .drag-handle),
:deep(.pdf-shape:hover .drag-handle),
:deep(.pdf-form-field:hover .drag-handle),
:deep(.pdf-added:hover .drag-handle),
:deep(.pdf-image-container:hover .drag-handle) {
    display: flex !important;
}

/* Alternative drag handle position for elements at the top */
:deep(.pdf-text:first-child .drag-handle),
:deep(.pdf-image:first-child .drag-handle),
:deep(.pdf-vector:first-child .drag-handle) {
    top: auto;
    bottom: -25px;
}

/* Handle container styles */
:deep(.drag-handle-container) {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 0;
}

.ruler {
    position: absolute;
    background: white;
    border: 1px solid #ccc;
    z-index: 50;
}

.ruler-horizontal {
    top: 0;
    left: 30px;
    right: 0;
    height: 30px;
}

.ruler-vertical {
    top: 30px;
    left: 0;
    bottom: 0;
    width: 30px;
}

.editor-canvas {
    background: white;
    margin: 0;
    padding: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform-origin: top left;
    transition: transform 0.2s;
    min-height: 842px;
    min-width: 595px;
    position: relative;
}

.pdf-pages-wrapper {
    width: 100%;
    height: 100%;
    position: relative;
}

/* Ensure PDF page containers are properly styled */
:deep(.pdf-page-container) {
    position: relative;
    margin: 0;
    padding: 0;
    background: white;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.editor-canvas.grid-enabled {
    background-image: 
        linear-gradient(rgba(0,0,0,0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,0,0,0.05) 1px, transparent 1px);
    background-size: 10px 10px;
    background-position: 0 0;
}

.editor-canvas.grid-enabled.grid-size-20 {
    background-size: 20px 20px;
}

.editor-canvas.grid-enabled.grid-size-5 {
    background-size: 5px 5px;
}

/* Properties Panel */
.properties-panel {
    width: 280px;
    background: white;
    border-left: 1px solid #ddd;
    overflow-y: auto;
}

.property-group {
    padding: 12px;
    border-bottom: 1px solid #eee;
}

.property-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #666;
    margin-bottom: 6px;
}

.property-row {
    display: flex;
    gap: 8px;
}

.property-input {
    flex: 1;
    padding: 6px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
}

.property-select {
    width: 100%;
    padding: 6px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 13px;
}

.property-slider {
    width: 100%;
}

.property-value {
    font-size: 12px;
    color: #666;
    margin-left: 8px;
}

.property-color {
    width: 100%;
    height: 32px;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
}

.property-checkbox {
    display: flex;
    align-items: center;
    font-size: 13px;
    margin-top: 8px;
}

.property-checkbox input {
    margin-right: 6px;
}

.property-btn {
    flex: 1;
    padding: 6px;
    border: 1px solid #ddd;
    background: white;
    border-radius: 4px;
    font-size: 13px;
    cursor: pointer;
    transition: background 0.2s;
}

.property-btn:hover {
    background: #f0f0f0;
}

.style-btn {
    width: 32px;
    height: 32px;
    border: 1px solid #ddd;
    background: white;
    border-radius: 4px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.2s;
}

.style-btn:hover {
    background: #f0f0f0;
}

.style-btn.active {
    background: #007bff;
    color: white;
    border-color: #007bff;
}

/* Context Menu */
.context-menu {
    position: fixed;
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 32;
    padding: 4px;
    min-width: 150px;
}

.context-menu-item {
    display: block;
    width: 100%;
    padding: 8px 12px;
    border: none;
    background: transparent;
    text-align: left;
    cursor: pointer;
    transition: background 0.2s;
}

.context-menu-item:hover:not(:disabled) {
    background: #f0f0f0;
}

.context-menu-item:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.context-menu-divider {
    height: 1px;
    background: #eee;
    margin: 4px 0;
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
}

.modal-content {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
}

.modal-title {
    margin: 0 0 16px;
    font-size: 18px;
    font-weight: 600;
}

.signature-canvas {
    border: 2px solid #ddd;
    border-radius: 4px;
    cursor: crosshair;
    background: white;
}

.modal-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
    justify-content: flex-end;
}

.btn {
    padding: 8px 16px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-primary {
    background: #007bff;
    color: white;
    border-color: #007bff;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-secondary {
    background: white;
}

.btn-secondary:hover {
    background: #f0f0f0;
}

.btn-cancel {
    background: white;
    color: #666;
}

/* Status Bar */
.status-bar {
    display: flex;
    align-items: center;
    background: white;
    border-top: 1px solid #ddd;
    padding: 4px 12px;
    font-size: 12px;
    color: #666;
}

.status-item {
    margin-right: 20px;
}

/* Selected element styling */
:deep(.selected) {
    outline: 2px solid #007bff !important;
    outline-offset: 2px;
}

/* Dragging state */
:deep(.dragging) {
    opacity: 0.8;
    box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    transition: none !important;
}

/* Draggable elements hover */
:deep(.pdf-text:hover),
:deep(.pdf-image:hover),
:deep(.pdf-image-container:hover),
:deep(.pdf-shape:hover),
:deep(.pdf-form-field:hover),
:deep(.pdf-added:hover) {
    cursor: move;
    outline: 1px dashed rgba(0, 123, 255, 0.5);
}

/* When editing text, don't show move cursor */
:deep(.pdf-text[contenteditable="true"]:focus) {
    cursor: text !important;
    outline: 2px solid #007bff !important;
}

/* Alignment guides */
:deep(.alignment-guide) {
    position: absolute;
    background: #ff0000;
    opacity: 0.5;
    z-index: 35;
    pointer-events: none;
}

:deep(.alignment-guide.horizontal) {
    height: 1px;
    width: 100%;
}

:deep(.alignment-guide.vertical) {
    width: 1px;
    height: 100%;
}

/* Multi-select box */
:deep(.selection-box) {
    position: absolute;
    border: 1px dashed #007bff;
    background: rgba(0, 123, 255, 0.1);
    pointer-events: none;
    z-index: 34;
}

/* Table styles */
:deep(.pdf-table-wrapper) {
    position: absolute;
    background: white;
    border: 1px solid #ccc;
    cursor: move;
    overflow: visible;
}

:deep(.pdf-table-wrapper.selected) {
    outline: 2px solid #007bff;
}

:deep(.pdf-table) {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

:deep(.pdf-table th),
:deep(.pdf-table td) {
    border: 1px solid #666;
    padding: 6px;
    position: relative;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

:deep(.pdf-table th) {
    background: #f0f0f0;
    font-weight: bold;
    border-color: #333;
}

:deep(.pdf-table td:focus),
:deep(.pdf-table th:focus) {
    outline: 2px solid #007bff;
    background: rgba(0, 123, 255, 0.05);
    z-index: 1;
}

:deep(.pdf-table td:hover),
:deep(.pdf-table th:hover) {
    background: rgba(0, 123, 255, 0.1);
}

/* Table dialog styles */
:deep(.pdf-table-dialog) {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    padding: 20px;
    z-index: 35;
}

:deep(.pdf-table-dialog h3) {
    margin: 0 0 15px 0;
    color: #333;
    font-size: 18px;
}

:deep(.pdf-table-dialog label) {
    color: #666;
    font-size: 14px;
}

:deep(.pdf-table-dialog input[type="number"]),
:deep(.pdf-table-dialog input[type="text"]) {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

:deep(.pdf-table-dialog input[type="checkbox"]) {
    margin-right: 5px;
}

:deep(.pdf-table-dialog button) {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

:deep(.pdf-table-dialog button:hover) {
    opacity: 0.8;
}

/* Annotation styles */
:deep(.pdf-annotation) {
    position: absolute;
    z-index: 30;
}

:deep(.annotation-marker) {
    width: 24px;
    height: 24px;
    cursor: pointer;
    color: #ff5722;
    transition: transform 0.2s;
}

:deep(.annotation-marker:hover) {
    transform: scale(1.2);
}

:deep(.annotation-bubble) {
    position: absolute;
    left: 30px;
    top: -10px;
    min-width: 200px;
    max-width: 300px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    padding: 0;
    display: none;
    z-index: 31;
}

:deep(.annotation-bubble.visible) {
    display: block;
}

:deep(.annotation-header) {
    padding: 8px 10px;
    background: #f5f5f5;
    border-bottom: 1px solid #ddd;
    border-radius: 8px 8px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #666;
}

:deep(.annotation-author) {
    font-weight: bold;
    color: #333;
}

:deep(.annotation-date) {
    font-size: 11px;
}

:deep(.annotation-delete) {
    background: none;
    border: none;
    color: #999;
    cursor: pointer;
    font-size: 20px;
    line-height: 1;
    padding: 0;
    width: 20px;
    height: 20px;
}

:deep(.annotation-delete:hover) {
    color: #dc3545;
}

:deep(.annotation-content) {
    padding: 10px;
    min-height: 50px;
    outline: none;
    font-size: 13px;
    line-height: 1.5;
}

:deep(.annotation-content:focus) {
    background: #fffef0;
}

:deep(.annotation-content:empty:before) {
    content: 'Click to add comment...';
    color: #999;
}

/* Highlight styles */
:deep(.pdf-highlight) {
    position: relative;
    display: inline;
    opacity: 0.4;
    cursor: pointer;
    transition: opacity 0.2s;
}

:deep(.pdf-highlight:hover) {
    opacity: 0.6;
}

/* Highlight color picker */
:deep(.highlight-color-picker) {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    padding: 15px;
    z-index: 35;
}

:deep(.highlight-color-picker h4) {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #333;
}

:deep(.highlight-color-picker button) {
    transition: transform 0.2s;
}

:deep(.highlight-color-picker button:hover) {
    transform: scale(1.1);
}

/* Highlight context menu */
:deep(.highlight-menu) {
    background: white;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    z-index: 35;
    min-width: 150px;
}

:deep(.highlight-menu > div) {
    padding: 5px 15px;
    cursor: pointer;
    transition: background 0.2s;
}

:deep(.highlight-menu > div:hover) {
    background: #f5f5f5;
}

/* Mode indicators */
:deep(.annotation-mode-indicator),
:deep(.highlight-mode-indicator) {
    position: fixed;
    top: 80px;
    right: 20px;
    background: #007bff;
    color: white;
    padding: 8px 16px;
    border-radius: 4px;
    font-size: 14px;
    z-index: 34;
    pointer-events: none;
}

:deep(.highlight-mode-indicator) {
    background: #ffc107;
    color: #333;
}
</style>