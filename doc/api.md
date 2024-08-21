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
| `/blob/files`                       | POST   | Used to create a file                                                                       | `bucketIdentifier`, `creationTime`, `prefix`, `method`, `sig` | `notifyEmail`, `retentionDuration`, `type`          | `file`, `fileName`,                          | `metadata`, `fileHash`, `metadataHash` |
| `/blob/files`                       | DELETE | Used to DELETE the files with given prefix                                                  | `bucketIdentifier`, `creationTime`, `prefix`, `method`, `sig` | `startsWith`                                        | -                                            |                                        |
| `/blob/files/{identifier}`          | DELETE | Used to DELETE the file with given {id}                                                     | `bucketIdentifier`, `creationTime`, `method`, `sig`           |                                                     | -                                            |                                        |
| `/blob/files/{identifier}`          | PATCH  | Used to change the filename with given {id}                                                 | `bucketIdentifier`, `creationTime`, `method`, `sig`           | `type`, `existsUntil`, `notifyEmail`                | at least one of the optional body parameters | `file`, `fileName`, `metadata`         |

In general, the parameters have to be given in the specified order while optional parameters can be selectively left out for the computation of the checksum. The only exception is the `sig` parameter, which always has to be the last parameter.

## Parameters

| Parameter                                                    | Description                                                                                                                                            | Type          | Possible values                  |
|--------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|---------------|----------------------------------|
| `bucketIdentifier`                                           | ID of the bucket in which the file(s) are located.                                                                                                     | int           | all valid bucket IDs             |
| `creationTime`                                               | Current time (UTC) in seconds                                                                                                                          | int           | all valid integers               |
| `prefix`                                                     | prefix which the file(s) have                                                                                                                          | string        | all valid prefixes               |
| `startsWith`                                                 | if set, the request operation will affect all prefixes starting with the given prefix                                                                  | int           | `1`                              |
| `method` (was called `action` until `v0.1.14`)               | method that is used, e.g. `GET` to get files                                                                                                           | string        | `GET`, `POST`, `DELETE`, `PATCH` |
| `sig`                                                        | signature string of the checksums `ucs`                                                                                                      | string        | all valid signature strings      |
| `fileName`                                                   | original filename of the file                                                                                                                          | string        | all valid strings                |
| `fileHash`                                                   | the fileHash of the binary file                                                                                                                        | string        | all valid hash strings           |
| `includeData` (was called `binary` until `v0.1.14`)          | defines whether the base64 encoded binary data should be returned (=1) or a link to the binary data                                                    | int           | `0` or `1`                       |
| `notifyEmail`                                                | email address of a person that wants to be notified before deletion                                                                                    | string        | all valid email addresses        |
| `retentionDuration`                                          | defines the lifespan of the file                                                                                                                       | int           | all non-negative int durations   |
| `metadata` (was called `additionalMetadata` until `v0.1.35`) | some additional metadata the uploader wants to add                                                                                                     | string / json | all valid json strings           |
| `type` (was called `additionalType` until `v0.1.35`)         | a type given to the `metadata`. If the type is also defined in the blob bundle config, then the `metadata` is also validated against the given schema. | string        | all valid strings                |
| `existsUntil`                                                | a timestamp in ISO 8601 that defines how long the resource should exist                                                                                | string        | datetime string                  |
| `file`                                                       | the file to upload                                                                                                                                     | file          |                                  |

## Signature

!!! warning "Key security"

    The key should be kept confidential and safe and should NOT be leaked into the frontend or the user! The key has to remain secret!

The signatures are JWTs created with the algorithm `HS256` and are sent as a string. There is one signed item, a `SHA-256` checksum `ucs` that was generated using the url, and it is needed in every request.
Everything beginning from and including `/blob` has to be included when generating the url checksum `ucs`. `POST` and `PATCH` requests should only have multipart/formdata bodys.
The signature then has to be appended at the end of the url using the `sig` parameter.
The key used for signing and verifying the checksum has to be defined in the blob bucket config and the other backend system communicating with blob.

### Signature url encoding
By default, blob verifies the url by generating the signature of the urlencoded url using [RFC3986](https://datatracker.ietf.org/doc/html/rfc3986). This means that, among other things, `space` get converted to `%20`!
This means that systems communicating with blob have to also generate their checksum this way. It is not possible to just urlencode the whole url, since this would mean that valid symbols like `/` or `&` would be encoded too. Therefore, it is necessary to urlenode each parameter value separately before appending them in the url.

## Error codes and descriptions

### Collection operations (by prefix) `/blob/files`

#### GET

| relay:errorId                                            | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|----------------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:get-file-data-collection-missing-sig`              | 400         | The signature parameter `sig` is missing                                                                                      | `message`          |         |
| `blob:get-file-data-collection-missing-bucket-id`        | 400         | The bucket id parameter `bucketIdentifier` is missing                                                                                 | `message`          |         |
| `blob:get-file-data-collection-missing-creation-time`    | 400         | The creation time parameter `creationTime` is missing                                                                         | `message`          |         |
| `blob:get-file-data-collection-missing-method`           | 400         | The method/action parameter `method` is missing                                                                               | `message`          |         |
| `blob:get-file-data-collection-bucket-id-not-configured` | 400         | Bucket with given `bucketIdentifier` is not configured                                                                                | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:get-file-data-collection-no-bucket-service`        | 400         | BucketService is not configured                                                                                               | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:check-signature-missing-sig`                       | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:check-signature-missing-signature-params`          | 400         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:check-signature-creation-time-too-old`             | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:check-signature-method-not-suitable`               | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |

#### POST 

| relay:errorId                                          | Status code | Description                                                                                                                                | relay:errorDetails | Example                          |
|--------------------------------------------------------|-------------|--------------------------------------------------------------------------------------------------------------------------------------------|--------------------|----------------------------------|
| `blob:create-file-data-missing-sig`                    | 400         | The signature parameter `sig` is missing                                                                                                   | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:create-file-data-unset-params`                   | 400         | One or multiple of the required url parameters are missing                                                                                 | `message`          |                                             |
| `blob:create-file-data-data-upload-failed`             | 400         | Data upload failed.                                                                                                                        | `message`          |                                  |
| `blob:create-file-data-no-bucket-service`              | 400         | BucketService is not configured.                                                                                                           | `message`          |                                  |
| `blob:create-file-data-missing-file`                   | 400         | No file with parameter key "file" was received!                                                                                            | `message`          |                                  |
| `blob:create-file-data-upload-error`                   | 400         | File upload pload went wrong.                                                                                                              | `message`          |                                  |
| `blob:create-file-data-empty-files-not-allowed`        | 400         | Empty files cannot be added!                                                                                                               | `message`          |                                  |
| `blob:create-file-data-not-configured-bucket-id`       | 400         | BucketIdentifier is not configured                                                                                                         | `message`          |                                  |
| `blob:create-file-data-bad-metadata`                   | 400         | The parameter `metadata` is not a valid json                                                                                               |                    |                                  |
| `blob:create-file-data-file-too-big`                   | 400         | The attached file is too big for the webserver to accept                                                                                   |                    |                                  |
| `blob:create-file-data-prefix-missing`                 | 400         | The required parameter `prefix` is missing                                                                                                 |                    |                                  |
| `blob:create-file-data-file-name-missing`              | 400         | The required parameter `fileName` is missing                                                                                               |                    |                                  |
| `blob:create-file-data-bad-type`                       | 400         | The given `type` is not configured in the api                                                                                              |                    |                                  |
| `blob:create-file-data-metadata-does-not-match-type`   | 400         | The given `metadata` does not match the schema configured for the given `type`                                                             |                    |                                  |
| `blob:create-file-data-metadata-hash-change-forbidden` | 400         | The given `metadataHash` does not match the calculated hash of the `metadata`. It either corrupted in transport, or a wrong hash was given |                    |                                  |
| `blob:create-file-data-file-hash-change-forbidden`     | 403         | The parameter `fileHash` does not match with the hash of the uploaded file                                                                 | `message`          |                                             |
| `blob:create-file-data-creation-time-too-old`          | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent              | `message`          |                                             |
| `blob:signature-invalid`                               | 403         | The signature is invalid, e.g. by using a wrong key                                                                                        | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checksum-invalid`                                | 403         | The checksum sent in the signature is invalid, e.g. by changing some params, using the wrong hash algorithm or forgetting some characters  | `message`          |                                             |
| `blob:create-file-data-method-not-suitable`            | 405         | The method used is not compatible with the method/action specified in the url                                                              | `message`          |                                             |
| `blob:blob-service-invalid-json`                       | 422         | The Metadata doesn't contain valid json!                                                                                                   | `message`          |                                  |
| `blob:file-not-saved`                                  | 500         | File could not be saved!                                                                                                                   | `message`          |                                  |
| `blob:create-file-data-save-file-failed`               | 500         | File data could not be saved.                                                                                                              |                    |                                  |
| `blob:create-file-data-bucket-quota-reached`           | 507         | Bucket quota is reached.                                                                                                                   | `message`          |                                  |

#### DELETE

| relay:errorId                                              | Status code | Description                                                                                                                               | relay:errorDetails | Example |
|------------------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:delete-file-data-by-prefix-missing-sig`              | 400         | The signature in parameter `sig` is missing                                                                                               | `message`          |         |
| `blob:delete-file-data-by-prefix-missing-bucket-id`        | 400         | The parameter `bucketIdentifier` is missing                                                                                               |                    |         |
| `blob:delete-file-data-by-prefix-missing-creation-time`    | 400         | The parameter `creationTime` is missing                                                                                                   |                    |         |
| `blob:delete-file-data-by-prefix-missing-method`           | 400         | The parameter `method` is missing                                                                                                         | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:delete-file-data-by-prefix-bucket-id-not-configured` | 400         | Bucket is not configured                                                                                                                  | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:delete-file-data-by-prefix-no-bucket-service`        | 400         | BucketService is not configured                                                                                                           | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:delete-file-data-by-prefix-creation-time-too-old`    | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent             | `message`          |                                             |
| `blob:signature-invalid`                                   | 403         | The signature is invalid, e.g. by using a wrong key                                                                                       | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checksum-invalid`                                    | 403         | The checksum sent in the signature is invalid, e.g. by changing some params, using the wrong hash algorithm or forgetting some characters | `message`          |                                             |
| `blob:file-data-not-found`                                 | 404         | No data was found for the specified bucketIdentifier and prefix combination                                                                       | `message`          |                                             |
| `blob:delete-file-data-by-prefix-method-not-suitable`      | 405         | The method used is not compatible with the method/action specified in the url                                                             | `message`          |                                             |

### Item operations (by identifier) `/blob/files/{identifier}` 

#### GET

| relay:errorId                                       | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|-----------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:get-file-data-by-id-missing-sig`              | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-bucket-id`        | 400         | The parameter `bucketIdentifier` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-method`           | 400         | The parameter `method` is missing                                                                                             |                    |         |
| `blob:get-file-data-by-id-missing-creation-time`    | 400         | The parameter `creationTime` is missing                                                                                       |                    |         |
| `blob:get-file-data-by-id-bucket-id-not-configured` | 400         | The bucket with given `bucketIdentifier` is not configured                                                                    | `message`          |         |
| `blob:get-file-data-by-id-no-bucket-service`        | 400         | The requested bucketService is not configured                                                                                 |                    |         |
| `blob:check-signature-missing-sig`                  | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:check-signature-missing-signature-params`     | 400         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:check-signature-creation-time-too-old`        | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:get-file-data-by-id-creation-time-too-old`    | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:file-data-not-found`                          | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:get-file-data-by-id-method-not-suitable`      | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:check-signature-method-not-suitable`          | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |

#### PATCH

| relay:errorId                                         | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|-------------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:get-file-data-by-id-missing-sig`                | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-bucket-id`          | 400         | The parameter `bucketIdentifier` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-method`             | 400         | The parameter `method` is missing                                                                                             |                    |         |
| `blob:get-file-data-by-id-missing-creation-time`      | 400         | The parameter `creationTime` is missing                                                                                       |                    |         |
| `blob:get-file-data-by-id-bucket-id-not-configured`   | 400         | The bucket with given `bucketIdentifier` is not configured                                                                    | `message`          |         |
| `blob:get-file-data-by-id-no-bucket-service`          | 400         | The requested bucketService is not configured                                                                                 |                    |         |
| `blob:check-signature-missing-sig`                    | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:check-signature-missing-signature-params`       | 400         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:patch-file-data-missing`                        | 400         | At least one parameter has to be provided                                                                                     | `message`          |         |
| `blob:patch-file-data-bad-type`                       | 400         | The given `type` is not defined in the config                                                                                 |                    |         |
| `blob:patch-file-data-bad-metadata`                   | 400         | The given `metadata` is no valid JSON                                                                                         |                    |         |
| `blob:patch-file-data-metadata-does-not-match-type`   | 400         | The given `metadata` does not match the JSON schema defined in `type`                                                         |                    |         |
| `blob:patch-file-data-exists-until-bad-format`        | 400         | The given `existsUntil` is not a valid DateTime format!                                                                       |                    |         |
| `blob:check-signature-creation-time-too-old`          | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:patch-file-data-metadata-hash-change-forbidden` | 403         | The given `metadataHash` does not match the calculated hash of the file                                                       |                    |         |
| `blob:patch-file-data-file-hash-change-forbidden`     | 403         | The given `fileHash` does not match the calculated hash of the file                                                           |                    |         |
| `blob:get-file-data-by-id-creation-time-too-old`      | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:file-data-not-found`                            | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:get-file-data-by-id-method-not-suitable`        | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:check-signature-method-not-suitable`            | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:file-not-saved`                                 | 500         | File could not be saved!                                                                                                      | `message`          |         |
| `blob:patch-file-data-bucket-quota-reached`           | 507         | Bucket quota is reached.                                                                                                                              |                    |         |

#### DELETE

| relay:errorId                                       | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|-----------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:get-file-data-by-id-missing-sig`              | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-bucket-id`        | 400         | The parameter `bucketIdentifier` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-method`           | 400         | The parameter `method` is missing                                                                                             |                    |         |
| `blob:get-file-data-by-id-missing-creation-time`    | 400         | The parameter `creationTime` is missing                                                                                       |                    |         |
| `blob:get-file-data-by-id-bucket-id-not-configured` | 400         | The bucket with given `bucketIdentifier` is not configured                                                                    | `message`          |         |
| `blob:get-file-data-by-id-no-bucket-service`        | 400         | The requested bucketService is not configured                                                                                 |                    |         |
| `blob:check-signature-missing-sig`                  | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:check-signature-missing-signature-params`     | 400         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:check-signature-creation-time-too-old`        | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:get-file-data-by-id-creation-time-too-old`    | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:file-data-not-found`                          | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:get-file-data-by-id-method-not-suitable`      | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:check-signature-method-not-suitable`          | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |

### Download operation `/blob/files/{identifier}/download`

#### GET

| relay:errorId                                       | Status code | Description                                                | relay:errorDetails | Example |
|-----------------------------------------------------|-------------|------------------------------------------------------------|--------------------| ------- |
| `blob:download-file-by-id-missing-identifier`       | 400         | The identifier `{identifier}` is missing                   | `message`          |         |
| `blob:download-file-by-id-missing-bucket-id`        | 400         | The bucket id parameter `bucketIdentifier` is missing      | `message`          |         |
| `blob:download-file-by-id-missing-method`           | 400         | The prefix parameter `prefix` is missing                   | `message`          |         |
| `blob:download-file-by-id-missing-creation-time`    | 400         | The creation time parameter `creationTime` is missing      | `message`          |         |
| `blob:download-file-by-id-bucket-id-not-configured` | 400         | The bucket with given `bucketIdentifier` is not configured | `message`          |         |
| `blob:download-file-by-id-invalid-method`           | 400         | The action/method combination is not valid                 | `message`          |         |
| `blob:download-file-by-id-missing-sig`              | 400         | The signature parameter `sig` is missing                   | `message`          |         |
| `blob:download-file-by-id-creation-time-too-old`    | 403         | The creation time parameter `creationTime` is too old      | `message`          |         |
| `blob:checksum-invalid`                             | 403         | The checksum `ucs` inside the signature is not valid       | `message`          |         |
| `blob:signature-invalid`                            | 403         | The signature in parameter `sig` is invalid                | `message`          |         |
