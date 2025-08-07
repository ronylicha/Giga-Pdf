# Comptes de Test Giga-PDF

## Identifiants de connexion

Tous les comptes utilisent le mot de passe : **password**

### Super Administrateur (Accès complet au système)
- **Email**: admin@demo.com
- **Mot de passe**: password
- **Rôle**: Super Admin
- **Accès**: 
  - Gestion complète du système
  - Dashboard Super Admin
  - Gestion de tous les tenants
  - Gestion de tous les utilisateurs

### Administrateur Tenant (Gestion complète du tenant)
- **Email**: tenant.admin@demo.com
- **Mot de passe**: password
- **Rôle**: Tenant Admin
- **Accès**:
  - Dashboard d'administration du tenant
  - Gestion des utilisateurs du tenant
  - Gestion des rôles et permissions
  - Accès à tous les outils PDF
  - Paramètres du tenant

### Manager (Gestion étendue)
- **Email**: manager@demo.com
- **Mot de passe**: password
- **Rôle**: Manager
- **Accès**:
  - Gestion des utilisateurs (création, modification)
  - Tous les outils PDF
  - Gestion des documents
  - Invitations

### Éditeur (Création et édition)
- **Email**: editor@demo.com
- **Mot de passe**: password
- **Rôle**: Editor
- **Accès**:
  - Création et édition de documents
  - Outils PDF (fusion, division, rotation, etc.)
  - Partage de documents
  - Téléchargement

### Lecteur (Lecture seule)
- **Email**: viewer@demo.com
- **Mot de passe**: password
- **Rôle**: Viewer
- **Accès**:
  - Visualisation des documents
  - Téléchargement uniquement

## Navigation dans l'application

### Menu principal (visible selon les permissions)
- **Dashboard**: Page d'accueil avec statistiques
- **Documents**: Gestion des documents PDF
- **Outils PDF**: Tous les outils de manipulation PDF
- **Administration**: (Tenant Admin uniquement) Gestion du tenant

### Menu profil
- **Mon Profil**: Paramètres du compte
- **Super Admin**: (Super Admin uniquement) Dashboard système
- **Paramètres**: Configuration du compte
- **Déconnexion**: Sortir de l'application

## URL d'accès
- Application: http://localhost (ou votre domaine)
- Login: http://localhost/login

## Fonctionnalités par rôle

| Fonctionnalité | Super Admin | Tenant Admin | Manager | Editor | Viewer |
|----------------|-------------|--------------|---------|--------|--------|
| Dashboard | ✅ | ✅ | ✅ | ✅ | ✅ |
| Voir documents | ✅ | ✅ | ✅ | ✅ | ✅ |
| Créer documents | ✅ | ✅ | ✅ | ✅ | ❌ |
| Éditer documents | ✅ | ✅ | ✅ | ✅ | ❌ |
| Supprimer documents | ✅ | ✅ | ✅ | ✅ | ❌ |
| Partager documents | ✅ | ✅ | ✅ | ✅ | ❌ |
| Outils PDF | ✅ | ✅ | ✅ | ✅ | ❌ |
| Gérer utilisateurs | ✅ | ✅ | ✅ | ❌ | ❌ |
| Gérer rôles | ✅ | ✅ | ❌ | ❌ | ❌ |
| Dashboard Super Admin | ✅ | ❌ | ❌ | ❌ | ❌ |
| Gérer tenants | ✅ | ❌ | ❌ | ❌ | ❌ |