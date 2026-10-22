# API

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.

The bundle provides GET endpoints for retrieving file metadata and binary data, and POST, PATCH and DELETE endpoints for creating, updating and deleting files.

## Endpoints

Every endpoint requires `bucketIdentifier` and the required signature parameters described below. The table lists additional request query parameters; POST requires `prefix`, while the others are optional.

| Endpoint | Method | Description | Additional query parameters | Required body fields | Optional body fields |
|----------|--------|-------------|-----------------------------|----------------------|----------------------|
| `/blob/files` | GET | List files | `prefix`, `startsWith`, `includeData`, `includeDeleteAt`, `page`, `perPage` | - | - |
| `/blob/files/{identifier}` | GET | Retrieve metadata or base64-encoded file data | `includeData`, `includeDeleteAt`, `disableOutputValidation` | - | - |
| `/blob/files/{identifier}/download` | GET | Download binary file data | `includeDeleteAt`, `disableOutputValidation` | - | - |
| `/blob/files` | POST | Create a file | `prefix`, `notifyEmail`, `deleteIn`, `type`, `fileHash`, `metadataHash`, `allowedFields` | `file`, `fileName` | `metadata`, `fileHash`, `metadataHash` |
| `/blob/files/{identifier}` | DELETE | Delete a file | `includeDeleteAt` | - | - |
| `/blob/files/{identifier}` | PATCH | Update a file | `prefix`, `notifyEmail`, `deleteIn`, `type`, `fileHash`, `metadataHash`, `allowedFields`, `includeDeleteAt` | - | `file`, `fileName`, `metadata`, `fileHash`, `metadataHash` |

POST and PATCH bodies use `multipart/form-data`. A PATCH URL may specify changes without requiring body fields.

## Signed URL parameters

These parameters define the signature, allowed HTTP method and lifetime of a signed URL. `creationTime`, `method` and `sig` are required; `expireIn` is optional.

| Parameter                                                    | Description                                                                                                                                            | Type          | Possible values                  |
|--------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|----------------------------------|
| `creationTime`                                               | Time at which the signed URL was created                                                                                                               | string        | ISO 8601 timestamp, e.g. `2026-10-22T12:00:00Z` |
| `expireIn`                                                   | URL lifetime from `creationTime`. If omitted, uses the bucket's `link_expire_time`; if supplied, must not exceed it.                                       | string        | ISO 8601 duration, e.g. `PT1M`    |
| `method`                                                     | method that is used, e.g. `GET` to get files                                                                                                           | string        | `GET`, `POST`, `DELETE`, `PATCH` |
| `sig`                                                        | signature string of the checksums `ucs`                                                                                                      | string        | all valid signature strings      |

## Request query parameters

These parameters control the requested operation rather than the URL signature. `bucketIdentifier` is required for every request; POST also requires `prefix`. Other parameters are optional and apply only to the operations described below.

For signed URLs, these parameters are also covered by the signature. Their values can be chosen before signing, but adding, removing or changing them afterwards invalidates the signature. Preserve the signed order and encoding, with `sig` last.

| Parameter                                                    | Description                                                                                                                                            | Type          | Possible values                  |
|--------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|----------------------------------|
| `bucketIdentifier`                                           | ID of the bucket in which the file(s) are located.                                                                                                     | string        | all configured bucket IDs        |
| `prefix`                                                     | File prefix for POST/PATCH, or a prefix filter when listing files                                                                                       | string        | all valid prefixes               |
| `startsWith`                                                 | Use case-insensitive prefix matching when listing files with the `prefix` parameter                                                                     | string        | `1`                              |
| `fileHash`                                                   | SHA-256 constraint on the uploaded file for POST/PATCH                                                                                                  | string        | lowercase hexadecimal SHA-256    |
| `metadataHash`                                               | SHA-256 constraint on the exact submitted metadata string for POST/PATCH                                                                                | string        | lowercase hexadecimal SHA-256    |
| `allowedFields`                                              | Signed restriction on body fields for POST/PATCH; `metadataHash` and `fileHash` are always allowed                                                       | string        | comma-separated field names      |
| `includeData`                                                | Return base64-encoded file data instead of a download link when retrieving or listing file metadata                                                     | string        | `0` or `1`                       |
| `includeDeleteAt`                                            | Include files scheduled for deletion on GET/PATCH/DELETE; expired files remain unavailable                                                              | string        | `0` or `1`                       |
| `disableOutputValidation`                                    | Bypass the bucket's output validation for file retrieval and downloads                                                                                 | string        | `0` or `1`                       |
| `page`                                                      | Page number when listing files                                                                                                                         | int           | positive integer                 |
| `perPage`                                                   | Number of files per page                                                                                                                               | int           | `1` to `1000`                    |
| `notifyEmail`                                                | Notification email address set on POST/PATCH                                                                                                           | string        | all valid email addresses        |
| `deleteIn`                                                   | Duration from the POST/PATCH request time until deletion; an empty value or `null` clears the deletion time                                             | string        | ISO 8601 duration, e.g. `P1D`, empty, or `null` |
| `type`                                                      | Metadata type set on POST/PATCH, selecting the bucket's metadata schema and file validation profile                                                     | string        | types configured for the bucket  |

## Multipart body fields

Callers submit these fields on POST/PATCH, subject to the signed URL's `allowedFields` restriction. Omitted PATCH fields retain their existing values. Text fields must be strings; `file` must be a single uploaded file.

| Field | Description | Type |
|-------|-------------|------|
| `file` | A single, non-empty uploaded file | file |
| `fileName` | Filename; must not contain `/` | string |
| `metadata` | JSON-encoded object (must be `{...}`) | string |
| `fileHash` | SHA-256 hash of the submitted file; requires `file` | string |
| `metadataHash` | SHA-256 hash of the exact submitted metadata string; requires `metadata` | string |

Body hashes must match the submitted content and any corresponding signed URL hash constraint. They cannot override a constraint set by the URL issuer.

## Restricting request body fields

For POST and PATCH, the optional `allowedFields` query parameter specifies a comma-separated list of permitted multipart body fields, including uploaded files. Names are case-sensitive; whitespace around each name is ignored.

- Omit `allowedFields` to preserve unrestricted body-field handling.
- Leave `allowedFields` empty for no extra allowed fields. `metadataHash` and `fileHash` are always allowed.
- The two hash fields are always permitted, even when not listed. Their existing validation still applies: they require the corresponding file or metadata and must match its SHA-256 hash.
- Any body field not permitted by the list causes HTTP 400; fields are not silently ignored.
- This only restricts body fields, not other query parameters, and does not relax required-field checks.

For example, the URL issuer can set `allowedFields=file,fileName,metadata` for a POST upload, or `allowedFields=metadata` for a metadata-only PATCH. Include the URL-encoded parameter in the URL checksum before appending `sig`; changing or removing it invalidates the signature.

## Signature

!!! warning "Key security"

    The key should be kept confidential and safe and should NOT be leaked into the frontend or the user! The key has to remain secret!

The signatures are JWTs created with the algorithm `HS256` and are sent as a string. There is one signed item, a `SHA-256` checksum `ucs` that was generated using the url, and it is needed in every request.
Everything beginning from and including `/blob` has to be included when generating the URL checksum `ucs`. Any deployment prefix before `/blob` is excluded.
The signature then has to be appended at the end of the url using the `sig` parameter. The `sig` parameter must be the final query parameter; requests with query parameters appended after it are rejected.
The key used for signing and verifying the checksum has to be defined in the blob bucket config and the other backend system communicating with blob.

### Signature url encoding
By default, blob verifies the url by generating the signature of the urlencoded url using [RFC3986](https://datatracker.ietf.org/doc/html/rfc3986). This means that, among other things, `space` get converted to `%20`!
This means that systems communicating with blob have to also generate their checksum this way. It is not possible to just urlencode the whole url, since this would mean that valid symbols like `/` or `&` would be encoded too. Therefore, it is necessary to urlenode each parameter value separately before appending them in the url.

## Retention
Files can be scheduled for deletion using the `deleteIn` query parameter on POST or PATCH (e.g. `deleteIn=P1D` to delete the file one day after the request). An empty `deleteIn` value or the literal `null` clears the deletion time. Omitting it on PATCH preserves the existing deletion time.
This duration is converted to a timestamp, stored in the database as `delete_at` and returned as `deleteAt` in API responses. Files scheduled for deletion require `includeDeleteAt=1` for retrieval; after their deletion time has passed, they are unavailable even with this option. The cleanup cron job removes expired database entries and stored files.


## Error codes and descriptions

Invalid request input returns HTTP 400 with a message explaining the problem. Basic input checks omit blob-specific `relay:errorId` and `relay:errorDetails`; shared metadata and file validators retain their error IDs and structured validation details.

### Signature verification (all signed file endpoints)

| `relay:errorId` | Status code | Description |
|----------------|-------------|-------------|
| `blob:creation-time-out-of-range` | 403 | The request is expired or its creation time is too far in the future. |
| `blob:signature-invalid` | 403 | The signature or URL checksum is invalid, or `sig` is not the final query parameter. |

HEAD is accepted with a GET signature where GET is allowed. Unsupported HTTP methods may still return HTTP 405 from routing before input validation runs.

### Validation and bucket locks

Validation errors apply to POST/PATCH input validation. Bucket locks apply to the requested method.

| `relay:errorId` | Status code | Description |
|----------------|-------------|-------------|
| `blob:metadata-invalid` | 400 | Metadata is not valid JSON or its top-level value is not an object. |
| `blob:type-unknown` | 400 | The requested type is not configured for the bucket. |
| `blob:metadata-validation-failed` | 400 | Metadata does not match the type's JSON schema; `relay:errorDetails` contains validation errors. |
| `blob:file-validation-failed` | 400 | The file fails the type's validation profile; `relay:errorDetails` contains validation errors. |
| `blob:bucket-locked` | 403 | The requested method is locked for the bucket. |

Other failures, such as missing authentication or bucket access denial, may use generic Relay errors rather than a blob-specific error ID.
