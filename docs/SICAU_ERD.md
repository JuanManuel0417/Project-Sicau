# SICAU - Diagrama ER

```mermaid
erDiagram
    USERS ||--o{ STUDENT_DOCUMENTS : uploads
    USERS ||--o{ DOCUMENT_HISTORIES : creates
    USERS ||--o{ DOCUMENT_OBSERVATIONS : makes
    USERS }|..|{ ROLES : has
    ROLES }|--|{ ROLE_PERMISSIONS : includes
    PERMISSIONS }|--|{ ROLE_PERMISSIONS : belongs_to
    STUDENT_DOCUMENTS }o--|| DOCUMENT_TYPES : belongs_to
    STUDENT_DOCUMENTS }o--|| DOCUMENT_STATUSES : current_state
    DOCUMENT_HISTORIES }o--|| DOCUMENT_STATUSES : state
    DOCUMENT_HISTORIES }o--|| STUDENT_DOCUMENTS : document
    DOCUMENT_OBSERVATIONS }o--|| STUDENT_DOCUMENTS : document
    VALIDATION_RULES }o--|| DOCUMENT_TYPES : type
```
