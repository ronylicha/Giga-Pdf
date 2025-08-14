<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Éditeur de document : {{ document.original_name }}
                </h2>
                <div class="flex gap-2">
                    <Link
                        :href="route('documents.show', document.id)"
                        class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded"
                    >
                        Retour au document
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-0">
            <!-- Loading indicator -->
            <div v-if="loading" class="flex justify-center items-center h-96">
                <div class="text-center">
                    <svg class="animate-spin h-12 w-12 mx-auto mb-4 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <p class="text-gray-600">Chargement de l'éditeur avancé...</p>
                </div>
            </div>

            <!-- Error message -->
            <div v-else-if="error" class="max-w-7xl mx-auto p-6">
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <strong class="font-bold">Erreur!</strong>
                    <span class="block sm:inline">{{ error }}</span>
                </div>
            </div>

            <!-- Editor iframe -->
            <iframe
                v-else
                id="editorFrame"
                ref="editorFrame"
                :srcdoc="editorHtml"
                class="w-full"
                style="height: calc(100vh - 120px); border: none;"
                @load="onEditorLoad"
            ></iframe>
        </div>

        <!-- Success/Error Toast -->
        <div v-if="toast.show" 
             :class="[
                 'fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 z-50',
                 toast.type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
             ]">
            {{ toast.message }}
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';

const props = defineProps({
    document: Object,
});

const loading = ref(true);
const error = ref(null);
const editorHtml = ref('');
const editorFrame = ref(null);
const toast = ref({
    show: false,
    type: 'success',
    message: ''
});

// Load the HTML content
const loadHtmlContent = async () => {
    try {
        loading.value = true;
        error.value = null;
        
        const response = await axios.post(route('documents.convert-to-html', props.document.id));
        
        if (response.data.success) {
            // Add CSRF token to the HTML
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            editorHtml.value = response.data.html.replace(
                '</head>',
                `<meta name="csrf-token" content="${csrfToken}"></head>`
            );
        } else {
            error.value = response.data.error || 'Erreur lors de la conversion du document';
        }
    } catch (err) {
        console.error('Error loading HTML:', err);
        error.value = err.response?.data?.error || 'Erreur lors du chargement de l\'éditeur';
    } finally {
        loading.value = false;
    }
};

// Handle editor iframe load
const onEditorLoad = () => {
    const iframe = editorFrame.value;
    if (!iframe) return;
    
    const iframeWindow = iframe.contentWindow;
    const iframeDocument = iframe.contentDocument || iframeWindow.document;
    
    // Override save functions to use our Vue methods
    iframeWindow.saveDocument = saveDocument;
    iframeWindow.exportAsPdf = exportAsPdf;
    
    // Inject image fix helper functions into iframe
    const script = iframeDocument.createElement('script');
    script.textContent = `
        (function() {
            // Expose uploadImage function from parent window
            console.log('Setting up uploadImage function in iframe...');
            window.uploadImage = window.parent.uploadImage || null;
            console.log('uploadImage available in iframe:', typeof window.uploadImage);
            window.fixAllImages = function() {
                const images = document.querySelectorAll('img, .pdf-image, .pdf-vector');
                let fixed = 0;
                
                images.forEach(img => {
                    // Check if image is actually problematic (0px dimensions)
                    const currentWidth = img.offsetWidth;
                    const currentHeight = img.offsetHeight;
                    const styleWidth = img.style.width;
                    const styleHeight = img.style.height;
                    
                    // Only fix if image has 0px dimensions or explicit 0px in style
                    const needsFix = (currentWidth === 0 || currentHeight === 0) ||
                                    (styleWidth === '0px' || styleHeight === '0px') ||
                                    (styleWidth === '0' || styleHeight === '0');
                    
                    if (needsFix) {
                        const parent = img.parentElement;
                        if (!parent) return;
                        
                        // Get parent dimensions
                        const parentWidth = parent.offsetWidth || parseInt(parent.style.width) || 0;
                        const parentHeight = parent.offsetHeight || parseInt(parent.style.height) || 0;
                        
                        if (parentWidth > 0 && parentHeight > 0) {
                            // Fix the problematic image
                            img.style.width = '100%';
                            img.style.height = '100%';
                            img.style.objectFit = 'contain';
                            img.style.display = 'block';
                            img.style.visibility = 'visible';
                            img.style.opacity = '1';
                            
                            console.log('Fixed problematic image (was ' + styleWidth + ' x ' + styleHeight + ')');
                            fixed++;
                        } else {
                            // Fallback for images without proper parent
                            img.style.width = '200px';
                            img.style.height = 'auto';
                            img.style.display = 'block';
                            img.style.visibility = 'visible';
                            img.style.opacity = '1';
                            
                            console.log('Fixed problematic image with fallback');
                            fixed++;
                        }
                    } else {
                        // Image is fine, just ensure visibility
                        img.style.visibility = 'visible';
                        img.style.opacity = '1';
                    }
                });
                
                console.log('Fixed ' + fixed + ' problematic images out of ' + images.length + ' total');
                return fixed;
            };
            
            window.debugImages = function() {
                const images = document.querySelectorAll('img');
                console.log('=== Image Debug ===');
                console.log('Total images:', images.length);
                
                images.forEach((img, i) => {
                    const rect = img.getBoundingClientRect();
                    console.log('Image ' + (i+1) + ':', {
                        src: img.src.substring(0, 50) + '...',
                        displayed: img.offsetWidth + 'x' + img.offsetHeight,
                        natural: img.naturalWidth + 'x' + img.naturalHeight,
                        visible: rect.width > 0 && rect.height > 0,
                        parent: img.parentElement ? img.parentElement.className : 'no parent'
                    });
                    
                    // Highlight problematic images
                    if (img.offsetWidth === 0 || img.offsetHeight === 0) {
                        img.style.border = '3px solid red';
                        console.warn('Image ' + (i+1) + ' has 0 dimensions!');
                    }
                });
                
                // Remove borders after 5 seconds
                setTimeout(() => {
                    images.forEach(img => img.style.border = '');
                }, 5000);
            };
            
            // Force fix ONLY problematic images with !important
            window.forceFixImages = function() {
                const images = document.querySelectorAll('img, .pdf-image, .pdf-vector');
                let fixed = 0;
                
                images.forEach(img => {
                    // Check current state
                    const currentStyle = img.getAttribute('style') || '';
                    const hasZeroWidth = currentStyle.includes('width: 0') || currentStyle.includes('width:0');
                    const hasZeroHeight = currentStyle.includes('height: 0') || currentStyle.includes('height:0');
                    const isInvisible = img.offsetWidth === 0 || img.offsetHeight === 0;
                    
                    // Only force fix if actually problematic
                    if (hasZeroWidth || hasZeroHeight || isInvisible) {
                        const parent = img.parentElement;
                        if (!parent) return;
                        
                        const parentWidth = parent.offsetWidth || parseInt(parent.style.width) || 500;
                        const parentHeight = parent.offsetHeight || parseInt(parent.style.height) || 500;
                        
                        // Remove problematic style and replace
                        const newStyle = 
                            'width: 100% !important;' +
                            'height: 100% !important;' +
                            'display: block !important;' +
                            'visibility: visible !important;' +
                            'opacity: 1 !important;' +
                            'object-fit: contain !important;';
                        
                        img.setAttribute('style', newStyle);
                        
                        console.log('Force fixed problematic image - parent:', parentWidth + 'x' + parentHeight);
                        fixed++;
                    }
                });
                
                console.log('Force fixed ' + fixed + ' problematic images only');
                return fixed;
            };
            
            // Function to add image from file  
            window.addImageFromFile = function() {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                
                input.onchange = async function(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    
                    try {
                        // Show loading indicator
                        const loadingDiv = document.createElement('div');
                        loadingDiv.innerHTML = 'Téléchargement de l\\'image...';
                        loadingDiv.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px;border:2px solid #007bff;border-radius:5px;z-index:10000;';
                        document.body.appendChild(loadingDiv);
                        
                        // Use uploadImage if available (from parent window), otherwise fall back to base64
                        let imageUrl;
                        console.log('Checking for uploadImage function:', typeof window.uploadImage);
                        if (window.uploadImage && typeof window.uploadImage === 'function') {
                            console.log('Using server upload...');
                            // Use server upload
                            imageUrl = await window.uploadImage(file);
                        } else {
                            console.log('uploadImage not found, falling back to base64');
                            // Fallback to base64
                            imageUrl = await new Promise((resolve, reject) => {
                                const reader = new FileReader();
                                reader.onload = e => resolve(e.target.result);
                                reader.onerror = reject;
                                reader.readAsDataURL(file);
                            });
                        }
                        
                        // Create image element with URL
                        const container = document.createElement('div');
                        container.className = 'pdf-image-container';
                        container.style.cssText = 'position: absolute; left: 100px; top: 100px; width: 300px; height: auto; z-index: 100;';
                        
                        const img = document.createElement('img');
                        img.src = imageUrl;
                        img.className = 'pdf-image';
                        img.style.cssText = 'width: 100%; height: auto; display: block;';
                        
                        // Wait for image to load to adjust container height
                        img.onload = function() {
                            const aspectRatio = img.naturalHeight / img.naturalWidth;
                            container.style.height = (300 * aspectRatio) + 'px';
                            
                            // Remove loading indicator
                            loadingDiv.remove();
                        };
                        
                        img.onerror = function() {
                            alert('Impossible de charger l\\'image');
                            loadingDiv.remove();
                            container.remove();
                        };
                        
                        // Add controls
                        const controls = document.createElement('div');
                        controls.className = 'pdf-image-controls';
                        controls.innerHTML = '<button onclick="this.closest(\\'.pdf-image-container\\').remove()">Supprimer</button>';
                        
                        container.appendChild(img);
                        container.appendChild(controls);
                        
                        // Add to page
                        const pageContainer = document.querySelector('.pdf-page-container') || document.getElementById('pdfContent') || document.body;
                        pageContainer.appendChild(container);
                        
                        // Make draggable
                        window.makeImagesDraggable();
                        
                    } catch (error) {
                        alert('Erreur lors du téléchargement de l\\'image: ' + error.message);
                        console.error('Upload error:', error);
                        if (loadingDiv) loadingDiv.remove();
                    }
                };
                
                input.click();
            };
            
            // Function to replace an image
            window.replaceImage = async function(button) {
                const container = button.closest('.pdf-image-container');
                if (!container) return;
                
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                
                input.onchange = async function(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    
                    try {
                        // Show loading indicator
                        const loadingDiv = document.createElement('div');
                        loadingDiv.innerHTML = 'Remplacement de l\\'image...';
                        loadingDiv.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px;border:2px solid #007bff;border-radius:5px;z-index:10000;';
                        document.body.appendChild(loadingDiv);
                        
                        // Use server upload if available, otherwise base64
                        let imageUrl;
                        if (window.uploadImage && typeof window.uploadImage === 'function') {
                            imageUrl = await window.uploadImage(file);
                        } else {
                            imageUrl = await new Promise((resolve, reject) => {
                                const reader = new FileReader();
                                reader.onload = e => resolve(e.target.result);
                                reader.onerror = reject;
                                reader.readAsDataURL(file);
                            });
                        }
                        
                        // Replace the image
                        const img = container.querySelector('img');
                        if (img) {
                            img.src = imageUrl;
                            
                            // Update container height based on new aspect ratio
                            img.onload = function() {
                                const aspectRatio = img.naturalHeight / img.naturalWidth;
                                const containerWidth = container.offsetWidth;
                                container.style.height = (containerWidth * aspectRatio) + 'px';
                                loadingDiv.remove();
                            };
                            
                            img.onerror = function() {
                                alert('Impossible de charger la nouvelle image');
                                loadingDiv.remove();
                            };
                        }
                    } catch (error) {
                        alert('Erreur lors du remplacement: ' + error.message);
                        console.error('Replace error:', error);
                        if (loadingDiv) loadingDiv.remove();
                    }
                };
                
                input.click();
            };
            
            // Function to make images draggable (if needed)
            window.makeImagesDraggable = function() {
                const containers = document.querySelectorAll('.pdf-image-container');
                containers.forEach(container => {
                    // Skip if already has drag functionality
                    if (container.classList.contains('drag-enabled')) return;
                    
                    // Add replace button if not exists
                    const controls = container.querySelector('.pdf-image-controls');
                    if (controls && !controls.querySelector('button[onclick*="replaceImage"]')) {
                        const replaceBtn = document.createElement('button');
                        replaceBtn.textContent = 'Remplacer';
                        replaceBtn.onclick = function() { window.replaceImage(this); };
                        controls.insertBefore(replaceBtn, controls.firstChild);
                    }
                    
                    let isDragging = false;
                    let startX, startY, initialLeft, initialTop;
                    
                    container.addEventListener('mousedown', function(e) {
                        // Don't drag if clicking on buttons or resize handles
                        if (e.target.tagName === 'BUTTON' || 
                            e.target.classList.contains('resize-handle') ||
                            e.target.classList.contains('pdf-image-controls')) {
                            return;
                        }
                        
                        isDragging = true;
                        startX = e.clientX;
                        startY = e.clientY;
                        initialLeft = container.offsetLeft;
                        initialTop = container.offsetTop;
                        container.style.cursor = 'grabbing';
                        e.preventDefault();
                    });
                    
                    document.addEventListener('mousemove', function(e) {
                        if (!isDragging) return;
                        const dx = e.clientX - startX;
                        const dy = e.clientY - startY;
                        container.style.left = (initialLeft + dx) + 'px';
                        container.style.top = (initialTop + dy) + 'px';
                    });
                    
                    document.addEventListener('mouseup', function() {
                        if (isDragging) {
                            isDragging = false;
                            container.style.cursor = 'move';
                        }
                    });
                    
                    container.classList.add('drag-enabled');
                });
            };
            
            // Auto-fix images on load
            setTimeout(() => {
                window.fixAllImages();
                window.makeImagesDraggable();
                console.log('PDF Editor: Auto-fixed images on load');
            }, 500);
            
            // Monitor for new images (but don't duplicate controls)
            const observer = new MutationObserver(() => {
                setTimeout(() => {
                    window.fixAllImages();
                    window.makeImagesDraggable();
                }, 100);
            });
            observer.observe(document.body, { childList: true, subtree: true });
            
            console.log('PDF Editor Fix loaded. Use fixAllImages() or debugImages() to troubleshoot.');
        })();
    `;
    iframeDocument.head.appendChild(script);
    
    // Log that editor is ready
    console.log("HTML Editor loaded and ready");
    
    // Add message listener for status updates
    window.addEventListener('message', handleEditorMessage);
};

// Handle messages from the editor
const handleEditorMessage = (event) => {
    if (event.data.type === 'status') {
        showToast(event.data.message, 'success');
    } else if (event.data.type === 'error') {
        showToast(event.data.message, 'error');
    }
};

// Save document
const saveDocument = async () => {
    try {
        const iframe = editorFrame.value;
        const content = iframe.contentDocument.getElementById('pdfContent').innerHTML;
        
        const response = await axios.post(
            route('documents.save-html', props.document.id),
            { html: content }
        );
        
        if (response.data.success) {
            showToast('Document sauvegardé avec succès!', 'success');
        } else {
            showToast('Erreur lors de la sauvegarde', 'error');
        }
    } catch (err) {
        console.error('Save error:', err);
        showToast('Erreur lors de la sauvegarde', 'error');
    }
};

// Export as PDF
const exportAsPdf = async () => {
    try {
        const iframe = editorFrame.value;
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        
        // Get only the content from pdfContent div
        const pdfContentDiv = iframeDoc.getElementById('pdfContent');
        if (!pdfContentDiv) {
            showToast('Erreur: Contenu PDF non trouvé', 'error');
            return;
        }
        
        // Get the inner HTML (just the content, not the container itself)
        const content = pdfContentDiv.innerHTML;
        
        // Log for debugging
        console.log('Exporting PDF with content length:', content.length);
        console.log('Has images:', content.includes('<img'));
        
        // Create a form to submit the HTML content
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = route('documents.save-html-as-pdf', props.document.id);
        form.style.display = 'none';
        
        // Add CSRF token
        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = '_token';
        csrfToken.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
        form.appendChild(csrfToken);
        
        // Add HTML content (only the inner content)
        const htmlInput = document.createElement('input');
        htmlInput.type = 'hidden';
        htmlInput.name = 'html';
        htmlInput.value = content;
        form.appendChild(htmlInput);
        
        // Show loading toast
        showToast('Génération du PDF en cours...', 'success');
        
        // Submit form - will redirect to documents list
        document.body.appendChild(form);
        form.submit();
        // Note: form.removeChild not needed as page will redirect
    } catch (err) {
        console.error('Export error:', err);
        showToast('Erreur lors de l\'export PDF', 'error');
    }
};

// Show toast notification
const showToast = (message, type = 'success') => {
    toast.value = {
        show: true,
        type,
        message
    };
    
    setTimeout(() => {
        toast.value.show = false;
    }, 3000);
};

// Keyboard shortcuts
const handleKeyboard = (e) => {
    // Ctrl/Cmd + S to save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveDocument();
    }
    // Ctrl/Cmd + E to export as PDF
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        exportAsPdf();
    }
};

onMounted(() => {
    loadHtmlContent();
    document.addEventListener('keydown', handleKeyboard);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeyboard);
    window.removeEventListener('message', handleEditorMessage);
});
</script>

<style scoped>
/* Ensure iframe takes full height */
iframe {
    min-height: 600px;
}
</style>