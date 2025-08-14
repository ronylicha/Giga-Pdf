<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur de conversion PDF - {{ config('app.name', 'Giga-PDF') }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
            animation: slideUp 0.4s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .error-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background: linear-gradient(135deg, #ff6b6b, #ee5a6f);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(238, 90, 111, 0.4);
            }
            70% {
                box-shadow: 0 0 0 20px rgba(238, 90, 111, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(238, 90, 111, 0);
            }
        }
        
        .error-icon svg {
            width: 40px;
            height: 40px;
            color: white;
        }
        
        h1 {
            color: #1a202c;
            font-size: 24px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 16px;
        }
        
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
        }
        
        .error-message h2 {
            color: #991b1b;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .error-message p {
            color: #7f1d1d;
            font-size: 14px;
            line-height: 1.6;
            font-family: 'Courier New', monospace;
            word-break: break-word;
        }
        
        .suggestions {
            margin-bottom: 24px;
        }
        
        .suggestions h3 {
            color: #4b5563;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .suggestions ul {
            list-style: none;
        }
        
        .suggestions li {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.8;
            padding-left: 24px;
            position: relative;
        }
        
        .suggestions li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #10b981;
            font-weight: bold;
        }
        
        .document-info {
            background: #f9fafb;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 24px;
        }
        
        .document-info h4 {
            color: #6b7280;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .document-info p {
            color: #374151;
            font-size: 14px;
            margin-bottom: 4px;
        }
        
        .document-info span {
            color: #9ca3af;
            font-size: 12px;
        }
        
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            flex: 1;
            min-width: 120px;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #4b5563;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        .support-link {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }
        
        .support-link p {
            color: #9ca3af;
            font-size: 13px;
            margin-bottom: 8px;
        }
        
        .support-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .support-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        
        @media (max-width: 640px) {
            .error-container {
                padding: 24px;
            }
            
            h1 {
                font-size: 20px;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                      d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
        </div>
        
        <h1>Erreur lors de la conversion PDF</h1>
        
        <div class="error-message">
            <h2>
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
                Détails de l'erreur
            </h2>
            <p>{{ $error }}</p>
        </div>
        
        @if(isset($document))
        <div class="document-info">
            <h4>Document concerné</h4>
            <p><strong>{{ $document->original_name }}</strong></p>
            <span>ID: {{ $document->id }} | Taille: {{ number_format($document->size / 1024 / 1024, 2) }} MB</span>
        </div>
        @endif
        
        <div class="suggestions">
            <h3>Solutions possibles :</h3>
            <ul>
                <li>Vérifiez que le document ne dépasse pas 50 MB</li>
                <li>Assurez-vous que le contenu HTML est valide</li>
                <li>Réduisez la taille ou le nombre d'images dans le document</li>
                <li>Essayez de simplifier la mise en page complexe</li>
                <li>Rafraîchissez la page et réessayez</li>
            </ul>
        </div>
        
        <div class="actions">
            <button onclick="window.history.back()" class="btn btn-secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Retour
            </button>
            
            <button onclick="location.reload()" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Réessayer
            </button>
        </div>
        
        <div class="support-link">
            <p>Besoin d'aide supplémentaire ?</p>
            <a href="mailto:{{ $supportEmail ?? 'support@gigapdf.com' }}">
                Contactez notre support technique
            </a>
        </div>
    </div>
    
    <script>
        // Auto-close error modal after user action
        document.addEventListener('DOMContentLoaded', function() {
            // Si c'est dans un iframe ou une modal, permettre la fermeture
            if (window.parent !== window) {
                setTimeout(() => {
                    // Notifier le parent de l'erreur
                    window.parent.postMessage({
                        type: 'pdf-conversion-error',
                        error: {{ json_encode($error) }}
                    }, '*');
                }, 100);
            }
        });
    </script>
</body>
</html>