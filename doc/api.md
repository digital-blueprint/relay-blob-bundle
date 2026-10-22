# API

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.

The bundle provides GET endpoints for retrieving file metadata, file binary data and POST, DELETE endpoints for creating and deleting data.

## Endpoints

| Endpoint                            | Method | Description                                                                                 | Required Url Parameters                               | Optional Url Parameter                              | Required body parameter (as formData)        | Optional body parameter (as formData)  |
|-------------------------------------|--------|---------------------------------------------------------------------------------------------|-------------------------------------------------------|-----------------------------------------------------|----------------------------------------------|----------------------------------------|
| `/blob/files`                       | GET    | Used to GET a collection of files                                                           | `bucketIdentifier`, `creationTime`, `method`, `sig`           | `prefix`, `includeData`, `startsWith`               | -                                            |                                        |
| `/blob/files/{identifier}`          | GET    | Used to retrieve file metadata or file base64 encoded data of the file with `{identifier}`. | `bucketIdentifier`, `creationTime`, `method`, `sig`           | `includeData` (was called `binary` below `v0.1.14`) | -                                            |                                        |
| `/blob/files/{identifier}/download` | GET    | Used to retrieve binary file data of the file with `{identifier}`                           | `bucketIdentifier`, `creationTime`, `method`, `sig`           |                                                     | -                                            |                                        |
| `/blob/files`                       | POST   | Used to create a file                                                                       | `bucketIdentifier`, `creationTime`, `prefix`, `method`, `sig` | `notifyEmail`, `retentionDuration`, `type`, `fileHash`, `metadataHash`, `allowedFields` | `file`, `fileName`,                          | `metadata`, `fileHash`, `metadataHash` |
| `/blob/files/{identifier}`          | DELETE | Used to DELETE the file with given {id}                                                     | `bucketIdentifier`, `creationTime`, `method`, `sig`           |                                                     | -                                            |                                        |
| `/blob/files/{identifier}`          | PATCH  | Used to update the file with given {id}                                                     | `bucketIdentifier`, `creationTime`, `method`, `sig`           | `type`, `deleteAt`, `notifyEmail`, `fileHash`, `metadataHash`, `allowedFields` | at least one of the optional body parameters | `file`, `fileName`, `metadata`, `fileHash`, `metadataHash` |

In general, the parameters have to be given in the specified order while optional parameters can be selectively left out for the computation of the checksum. The only exception is the `sig` parameter, which always has to be the last parameter.

## Parameters

| Parameter                                                    | Description                                                                                                                                            | Type          | Possible values                  |
|--------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|----------------------------------|
| `bucketIdentifier`                                           | ID of the bucket in which the file(s) are located.                                                                                                     | int           | all valid bucket IDs             |
| `creationTime`                                               | Current time (UTC) in seconds                                                                                                                          | int           | all valid integers               |
| `prefix`                                                     | prefix which the file(s) have                                                                                                                          | string        | all valid prefixes               |
| `startsWith`                                                 | if set, the request operation will affect all prefixes starting with the given prefix                                                                  | int           | `1`                              |
| `method`                                                     | method that is used, e.g. `GET` to get files                                                                                                           | string        | `GET`, `POST`, `DELETE`, `PATCH` |
| `sig`                                                        | signature string of the checksums `ucs`                                                                                                      | string        | all valid signature strings      |
| `fileName`                                                   | original filename of the file                                                                                                                          | string        | all valid strings                |
| `fileHash`                                                   | SHA-256 hash of the uploaded file; can be a signed URL constraint for POST/PATCH                                                                         | string        | lowercase hexadecimal SHA-256    |
| `metadataHash`                                               | SHA-256 hash of the exact submitted metadata string; can be a signed URL constraint for POST/PATCH                                                       | string        | lowercase hexadecimal SHA-256    |
| `allowedFields`                                              | Signed restriction on body fields for POST/PATCH; `metadataHash` and `fileHash` are always allowed                                                       | string        | comma-separated field names      |
| `includeData`                                                | defines whether the base64 encoded binary data should be returned (=1) or a link to the binary data                                                    | int           | `0` or `1`                       |
| `notifyEmail`                                                | email address of a person that wants to be notified before deletion                                                                                    | string        | all valid email addresses        |
| `retentionDuration`                                          | defines the lifespan of the file                                                                                                                       | int           | all non-negative int durations   |
| `metadata` (was called `additionalMetadata` until `v0.1.35`) | some additional metadata the uploader wants to add                                                                                                     | string / json | a valid json object (must be `{...}`) |
| `type` (was called `additionalType` until `v0.1.35`)         | a type given to the `metadata`. If the type is also defined in the blob bundle config, then the `metadata` is also validated against the given schema. | string        | all valid strings                |
| `deleteAt`                                                   | a timestamp in ISO 8601 that defines how long the resource should exist                                                                                | string        | datetime string                  |
| `file`                                                       | the file to upload                                                                                                                                     | file          |                                  |

## Restricting request body fields

For POST and PATCH, the optional `allowedFields` query parameter specifies a comma-separated list of permitted multipart body fields, including uploaded files. Names are case-sensitive; whitespace around each name is ignored.

- Omit `allowedFields` to preserve unrestricted body-field handling.
- Leave `allowedFields` empty for no extra allowed fields. `metadataHash` and `fileHash` are always allowed.
- The two hash fields are always permitted, even when not listed. Their existing validation still applies: they require the corresponding file or metadata and must match its SHA-256 hash.
- Any body field not permitted by the list causes HTTP 400; fields are not silently ignored.
- This only restricts body fields, not other query parameters, and does not relax required-field checks.

For example, use `allowedFields=file,fileName,metadata` for a POST upload, or `allowedFields=metadata` for a metadata-only PATCH. Include the URL-encoded parameter in the URL checksum before appending `sig`; changing or removing it invalidates the signature.

## Signature

!!! warning "Key security"

    The key should be kept confidential and safe and should NOT be leaked into the frontend or the user! The key has to remain secret!

The signatures are JWTs created with the algorithm `HS256` and are sent as a string. There is one signed item, a `SHA-256` checksum `ucs` that was generated using the url, and it is needed in every request.
Everything beginning from and including `/blob` has to be included when generating the url checksum `ucs`. `POST` and `PATCH` requests should only have multipart/formdata bodys.
The signature then has to be appended at the end of the url using the `sig` parameter. The `sig` parameter must be the final query parameter; requests with query parameters appended after it are rejected.
The key used for signing and verifying the checksum has to be defined in the blob bucket config and the other backend system communicating with blob.

### Signature url encoding
By default, blob verifies the url by generating the signature of the urlencoded url using [RFC3986](https://datatracker.ietf.org/doc/html/rfc3986). This means that, among other things, `space` get converted to `%20`!
This means that systems communicating with blob have to also generate their checksum this way. It is not possible to just urlencode the whole url, since this would mean that valid symbols like `/` or `&` would be encoded too. Therefore, it is necessary to urlenode each parameter value separately before appending them in the url.

## Retention
Files can be set to be automatically deleted at some date in the future. User's of the api can set a timespan for which the uploaded file should be kept by setting the `retentionDuration` parameters to a ISO8601 timespan (e.g. `retentionDuration=P1D` to keep the file for one day).
This timespan gets converted to a timestamp and stored in the database in the field `delete_at`. After the `delete_at` date a file is not retrievable anymore even if the entry is still available in the database. The database and the file get cleaned up by the cleanup cronjob, which is configured in the config.


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
