# Sequence Diagrams

Storing and retrieving files from a *blob* storage service requires some well defined steps.

## Store a file

On page *ABC* the user want to store a file in the context of the page.

```mermaid
sequenceDiagram
    autonumber
    title: Page ABC
    Browser->>Application: I want to "store a file"
    note over Application: Check permissions
    note over Application: set path to App/ABC
    Application->>Browser: Signed "store a file under path App/ABC"
    Browser-->>API-Gateway: Give me a link for signed "store a file under path App/ABC"
    note over API-Gateway: Check signature
    API-Gateway-->>Storage Service: store file for prefix App/ABC
    note over Storage Service: Generate share link
    Storage Service-->>API-Gateway: Share link
    API-Gateway-->>Browser: Share link
    note over Browser: display share link(s)
```

## Retrieve all files

On page *DEF* the user wants to get a list of all files in the context of this page.

```mermaid
sequenceDiagram
    autonumber
    title: Page DEF
    Browser->>Application: I want to display links
    note over Application: Check permission
    note over Application: set path to App/DEF
    Application->>Browser: Signed "get file(s) under path App/DEF"
    Browser-->>API-Gateway: Give me links for signed "get file(s) under path App/DEF"
    note over API-Gateway: Check signature
    API-Gateway-->>Storage Service: Get links for prefix App/DEF
    note over Storage Service: Generate share link(s)
    Storage Service-->>API-Gateway: Share link(s)
    API-Gateway-->>Browser: Share link(s)
    note over Browser: display share link(s)
```
