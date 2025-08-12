# Giga-PDF API Documentation

## Base URL
```
https://api.giga-pdf.com/api/v1
```

## Authentication

All API requests require authentication using Bearer tokens obtained through the login endpoint.

### Headers
```http
Authorization: Bearer {access_token}
Content-Type: application/json
Accept: application/json
```

## Endpoints

### Authentication

#### Register
```http
POST /register
```

**Request Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password",
  "password_confirmation": "password",
  "tenant_name": "My Company",
  "tenant_domain": "mycompany" // optional
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "user": {...},
    "tenant": {...},
    "access_token": "token...",
    "token_type": "Bearer"
  }
}
```

#### Login
```http
POST /login
```

**Request Body:**
```json
{
  "email": "john@example.com",
  "password": "password",
  "device_name": "My Device" // optional
}
```

#### Logout
```http
POST /logout
```

### Documents

#### List Documents
```http
GET /documents
```

**Query Parameters:**
- `page` (int): Page number
- `per_page` (int): Items per page (default: 15)
- `search` (string): Search query
- `sort` (string): Sort field
- `order` (string): Sort order (asc/desc)
- `extension` (string): Filter by file extension
- `date_from` (date): Filter from date
- `date_to` (date): Filter to date

#### Upload Document
```http
POST /documents/upload
```

**Request Body (multipart/form-data):**
```
file: (binary)
name: "Document Name" (optional)
description: "Description" (optional)
```

#### Get Document
```http
GET /documents/{id}
```

#### Update Document
```http
PUT /documents/{id}
```

**Request Body:**
```json
{
  "name": "New Name",
  "description": "New Description"
}
```

#### Delete Document
```http
DELETE /documents/{id}
```

#### Download Document
```http
GET /documents/{id}/download
```

### PDF Operations

#### Merge PDFs
```http
POST /documents/merge
```

**Request Body:**
```json
{
  "document_ids": [1, 2, 3],
  "output_name": "Merged Document"
}
```

#### Split PDF
```http
POST /documents/{id}/split
```

**Request Body:**
```json
{
  "pages": [1, 3, 5] // optional, splits specific pages
}
```

#### Rotate PDF
```http
POST /documents/{id}/rotate
```

**Request Body:**
```json
{
  "angle": 90, // 90, 180, or 270
  "pages": [1, 2] // optional, rotates specific pages
}
```

#### Extract Pages
```http
POST /documents/{id}/extract
```

**Request Body:**
```json
{
  "pages": [1, 3, 5, 7]
}
```

#### Compress PDF
```http
POST /documents/{id}/compress
```

**Request Body:**
```json
{
  "quality": "medium" // low, medium, high
}
```

#### Add Watermark
```http
POST /documents/{id}/watermark
```

**Request Body:**
```json
{
  "text": "CONFIDENTIAL",
  "opacity": 0.3,
  "position": "center", // center, top-left, top-right, bottom-left, bottom-right
  "rotation": 45,
  "font_size": 48,
  "color": "#FF0000"
}
```

#### Encrypt PDF
```http
POST /documents/{id}/encrypt
```

**Request Body:**
```json
{
  "user_password": "userpass",
  "owner_password": "ownerpass",
  "permissions": {
    "print": false,
    "copy": false,
    "modify": false
  }
}
```

#### OCR Processing
```http
POST /documents/{id}/ocr
```

**Request Body:**
```json
{
  "language": "eng" // eng, fra, deu, spa, etc.
}
```

### Advanced PDF Features

#### Sign Document
```http
POST /pdf-advanced/documents/{id}/sign
```

**Request Body (multipart/form-data):**
```
certificate: (file) .crt, .pem, or .p12
private_key: (file) if not using .p12
password: "certificate_password"
signer_name: "John Doe"
reason: "Document approval"
location: "San Francisco"
visible_signature: true
signature_image: (file) optional
```

#### Verify Signature
```http
GET /pdf-advanced/documents/{id}/verify-signature
```

#### Redact Document
```http
POST /pdf-advanced/documents/{id}/redact
```

**Request Body:**
```json
{
  "areas": [
    {
      "page": 1,
      "x": 50,
      "y": 100,
      "width": 200,
      "height": 30
    }
  ],
  "reason": "Privacy protection",
  "redaction_text": "REDACTED"
}
```

#### Redact Sensitive Data
```http
POST /pdf-advanced/documents/{id}/redact-sensitive
```

**Request Body:**
```json
{
  "patterns": ["SSN", "Credit Card", "Email", "Phone"],
  "custom_patterns": [
    {
      "pattern": "\\b[A-Z]{2}\\d{6}\\b",
      "type": "regex"
    }
  ]
}
```

#### Convert to PDF/A
```http
POST /pdf-advanced/documents/{id}/convert-pdfa
```

**Request Body:**
```json
{
  "version": "1b", // 1a, 1b, 2a, 2b, 2u, 3a, 3b, 3u
  "validate": true,
  "author": "John Doe",
  "subject": "Archive Document",
  "keywords": "archive, pdf/a"
}
```

#### Convert to PDF/X
```http
POST /pdf-advanced/documents/{id}/convert-pdfx
```

**Request Body:**
```json
{
  "version": "1a", // 1a, 3, 4
  "add_crop_marks": true,
  "add_color_bars": true,
  "bleed": 3
}
```

#### Compare Documents
```http
POST /pdf-advanced/compare
```

**Request Body:**
```json
{
  "document1_id": 1,
  "document2_id": 2,
  "threshold": 95,
  "detailed_analysis": true,
  "generate_diff_pdf": true
}
```

#### Create Form
```http
POST /pdf-advanced/documents/{id}/create-form
```

**Request Body:**
```json
{
  "fields": [
    {
      "type": "text",
      "name": "full_name",
      "label": "Full Name",
      "x": 50,
      "y": 100,
      "width": 200,
      "height": 20,
      "page": 1,
      "required": true
    },
    {
      "type": "checkbox",
      "name": "agree",
      "label": "I agree",
      "x": 50,
      "y": 150,
      "size": 10,
      "page": 1
    }
  ],
  "add_validation": true
}
```

#### Fill Form
```http
POST /pdf-advanced/documents/{id}/fill-form
```

**Request Body:**
```json
{
  "data": {
    "full_name": "John Doe",
    "agree": true
  },
  "flatten": false
}
```

### Conversions

#### Create Conversion
```http
POST /conversions
```

**Request Body:**
```json
{
  "document_id": 1,
  "target_format": "pdf" // pdf, docx, xlsx, pptx, html, txt, etc.
}
```

#### Get Conversion Status
```http
GET /conversions/{id}
```

#### Retry Failed Conversion
```http
POST /conversions/{id}/retry
```

#### Batch Conversion
```http
POST /conversions/batch
```

**Request Body:**
```json
{
  "conversions": [
    {
      "document_id": 1,
      "target_format": "pdf"
    },
    {
      "document_id": 2,
      "target_format": "docx"
    }
  ]
}
```

### Sharing

#### Create Share
```http
POST /documents/{id}/share
```

**Request Body:**
```json
{
  "type": "public", // public, password, email
  "password": "sharepass", // if type is password
  "expires_at": "2024-12-31 23:59:59",
  "permissions": {
    "download": true,
    "print": true
  },
  "recipients": ["email@example.com"] // if type is email
}
```

#### Get Share Info
```http
GET /shares/{id}
```

#### Revoke Share
```http
POST /shares/{id}/revoke
```

#### Extend Share
```http
POST /shares/{id}/extend
```

**Request Body:**
```json
{
  "expires_at": "2025-01-31 23:59:59"
}
```

### Search

#### Basic Search
```http
GET /search?q=invoice
```

#### Advanced Search
```http
GET /search/advanced
```

**Query Parameters:**
- `q`: Search query
- `content`: Search in document content
- `extension`: File extension filter
- `size_min`: Minimum file size (bytes)
- `size_max`: Maximum file size (bytes)
- `date_from`: Created from date
- `date_to`: Created to date
- `user_id`: Filter by user
- `has_ocr`: Documents with OCR
- `is_encrypted`: Encrypted documents

### Statistics

#### Overview Stats
```http
GET /stats/overview
```

**Response:**
```json
{
  "total_documents": 150,
  "total_size": 1073741824,
  "total_conversions": 45,
  "total_shares": 23,
  "storage_used_percentage": 45.5
}
```

#### Usage Stats
```http
GET /stats/usage
```

**Query Parameters:**
- `period`: day, week, month, year
- `from`: Start date
- `to`: End date

### Admin Endpoints

#### List Users (Admin only)
```http
GET /admin/users
```

#### Create User (Admin only)
```http
POST /admin/users
```

**Request Body:**
```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "password": "password",
  "role": "editor"
}
```

#### Suspend User (Admin only)
```http
POST /admin/users/{id}/suspend
```

#### Tenant Settings (Admin only)
```http
GET /admin/settings
PUT /admin/settings
```

#### Activity Logs (Admin only)
```http
GET /admin/activity
```

#### Storage Management (Admin only)
```http
GET /admin/storage
POST /admin/storage/cleanup
POST /admin/storage/optimize
```

## Error Responses

All errors follow this format:

```json
{
  "success": false,
  "message": "Error message",
  "errors": {
    "field": ["Validation error message"]
  }
}
```

### HTTP Status Codes

- `200`: Success
- `201`: Created
- `204`: No Content
- `400`: Bad Request
- `401`: Unauthorized
- `403`: Forbidden
- `404`: Not Found
- `422`: Validation Error
- `429`: Too Many Requests
- `500`: Internal Server Error

## Rate Limiting

- General endpoints: 10 requests/second
- API endpoints: 30 requests/second
- Authentication endpoints: 5 requests/minute

Rate limit headers:
```
X-RateLimit-Limit: 30
X-RateLimit-Remaining: 29
X-RateLimit-Reset: 1640995200
```

## Pagination

Paginated responses include:

```json
{
  "data": [...],
  "links": {
    "first": "url",
    "last": "url",
    "prev": "url",
    "next": "url"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 10,
    "per_page": 15,
    "to": 15,
    "total": 150
  }
}
```

## Webhooks (Coming Soon)

Configure webhooks for events:
- `document.created`
- `document.deleted`
- `conversion.completed`
- `conversion.failed`
- `share.accessed`
- `storage.limit.reached`

## SDK Examples

### PHP
```php
$client = new GigaPdfClient('your-api-key');
$document = $client->documents->upload('/path/to/file.pdf');
$merged = $client->pdf->merge([$doc1->id, $doc2->id]);
```

### JavaScript
```javascript
const client = new GigaPdfClient('your-api-key');
const document = await client.documents.upload(file);
const merged = await client.pdf.merge([doc1.id, doc2.id]);
```

### Python
```python
client = GigaPdfClient('your-api-key')
document = client.documents.upload('/path/to/file.pdf')
merged = client.pdf.merge([doc1.id, doc2.id])
```

## Support

For API support, contact: api-support@giga-pdf.com
Documentation: https://docs.giga-pdf.com
Status Page: https://status.giga-pdf.com