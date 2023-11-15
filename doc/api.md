# API

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.

The bundle provides GET endpoints for retrieving file metadata, file binary data and POST, DELETE endpoints for creating and deleting data.

## Endpoints

| Endpoint                            | Method | Description                                                                                 | Required Parameters                                                           | Optional Parameter                                       | Required formdata parameter |
|-------------------------------------|--------|---------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------|----------------------------------------------------------|-----------------------------|
| `/blob/files`                       | GET    | Used to GET a collection of files                                                           | `bucketID`, `creationTime`, `sig`                                             | `prefix`, `includeData`, `startsWith`                    | -                           |
| `/blob/files/{identifier}`          | GET    | Used to retrieve file metadata or file base64 encoded data of the file with `{identifier}`. | `bucketID`, `creationTime`, `method`, `sig`                                   | `includeData` (was called `binary` below `v0.1.14`)      | -                           |
| `/blob/files/{identifier}/download` | GET    | Used to retrieve binary file data of the file with `{identifier}`                           | `bucketID`, `creationTime`, `method`, `sig`                                   |                                                          | -                           |
| `/blob/files`                       | POST   | Used to create a file                                                                       | `bucketID`, `creationTime`, `prefix`, `method`, `fileName`, `fileHash`, `sig` | `notifyEmail`, `retentionDuration`, `additionalMetadata` | `file`                      |
| `/blob/files`                       | DELETE | Used to DELETE the files with given prefix                                                  | `bucketID`, `creationTime`, `prefix`, `method`, `sig`                         | `startsWith`                                             | -                           |
| `/blob/files/{identifier}`          | DELETE | Used to DELETE the file with given {id}                                                     | `bucketID`, `creationTime`, `method`, `sig`                                   |                                                          | -                           |
| `/blob/files/{identifier}`          | PUT    | Used to change the filename with given {id}                                                 | `bucketID`, `creationTime`, `method`, `fileName`, `sig`                       |                                                          | `fileName`                  |

In general, the parameters have to be given in the specified order while optional parameters can be selectively left out for the computation of the checksum. The only exception is the `sig` parameter, which always has to be the last parameter.

## Parameters

| Parameter                                           | Description                                                                                         | Type   | Possible values                |
|-----------------------------------------------------|-----------------------------------------------------------------------------------------------------|--------|--------------------------------|
| `bucketID`                                          | ID of the bucket in which the file(s) are located.                                                  | int    | all valid bucket IDs           |
| `creationTime`                                      | Current time (UTC) in seconds                                                                       | int    | all valid integers             |
| `prefix`                                            | prefix which the file(s) have                                                                       | string | all valid prefixes             |
| `startsWith`                                        | if set, the request operation will affect all prefixes starting with the given prefix               | int    | `1`                            |
| `method` (was called `action` until `v0.1.14`)      | method that is used, e.g. `GET` to get files                                                        | string | `GET`, `POST`, `DELETE`, `PUT` |
| `sig`                                               | signature string of the checksums `ucs` and `bcs`                                                   | string | all valid signature strings    |
| `fileName`                                          | original filename of the file                                                                       | string | all valid strings              |
| `fileHash`                                          | the fileHash of the binary file                                                                     | string | all valid hash strings         |
| `includeData` (was called `binary` until `v0.1.14`) | defines whether the base64 encoded binary data should be returned (=1) or a link to the binary data | int    | `0` or `1`                     |
| `notifyEmail`                                       | email address of a person that wants to be notified before deletion                                 | string | all valid email addresses      |
| `retentionDuration`                                 | defines the lifespan of the file                                                                    | int    | all non-negative int durations |
| `additionalMetadata`                                | some additional data the uploader want to add                                                       | string | all valid strings              |
| `file`                                              | the file to upload                                                                                  | file   |                                |

## Signature

!!! warning "Key security"

    The key should be kept confidential and safe and should NOT be leaked into the frontend or the user! The key has to remain secret!

The signatures are JWTs created with the algorithm `HS256` and are sent as a string. There are two signed items, one is a `SHA-256` checksum `ucs` that was generated using the url and one is a `SHA-256` checksum `bcs` that was generated using the body. `ucs` is needed in every request, while `bcs` is only needed in `POST` and `PUT` requests!
Everything beginning from and including `/blob` has to be included when generating the url checksum `ucs`.
Everything in the body except the `file` formData has to be included when generating the body checksum `bcs`. A `POST` request should only have form data as body while a `PUT` request should only have a json as body. For `POST`, everything except the `file` formData needs to be included in the checksum `bcs` while for `PUT` the whole JSON has to be included in the checksum `bcs`.
The signature then has to be appended at the and of the url using the `sig` parameter.
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
| `blob:get-file-data-collection-missing-bucket-id`        | 400         | The bucket id parameter `bucketID` is missing                                                                                 | `message`          |         |
| `blob:get-file-data-collection-missing-creation-time`    | 400         | The creation time parameter `creationTime` is missing                                                                         | `message`          |         |
| `blob:get-file-data-collection-missing-method`           | 400         | The method/action parameter `method` is missing                                                                               | `message`          |         |
| `blob:get-file-data-collection-bucket-id-not-configured` | 400         | Bucket with given `bucketID` is not configured                                                                                | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:get-file-data-collection-no-bucket-service`        | 400         | BucketService is not configured                                                                                               | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:check-signature-missing-sig`                       | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:check-signature-missing-signature-params`          | 400         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:check-signature-creation-time-too-old`             | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:check-signature-method-not-suitable`               | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |

#### POST 

| relay:errorId                                       | Status code | Description                                                                                                                               | relay:errorDetails | Example                          |
|-----------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------------------|--------------------|----------------------------------|
| `blob:create-file-data-missing-sig`                 | 400         | The signature parameter `sig` is missing                                                                                                  | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:create-file-data-unset-params`                | 400         | One or multiple of the required url parameters are missing                                                                                | `message`          |                                             |
| `blob:create-file-data-data-upload-failed`          | 400         | Data upload failed.                                                                                                                       | `message`          |                                  |
| `blob:create-file-data-no-bucket-service`           | 400         | BucketService is not configured.                                                                                                          | `message`          |                                  |
| `blob:create-file-data-missing-file`                | 400         | No file with parameter key "file" was received!                                                                                           | `message`          |                                  |
| `blob:create-file-data-upload-error`                | 400         | File upload pload went wrong.                                                                                                             | `message`          |                                  |
| `blob:create-file-data-empty-files-not-allowed`     | 400         | Empty files cannot be added!                                                                                                              | `message`          |                                  |
| `blob:create-file-data-not-configured-bucket-id`    | 400         | BucketID is not configured                                                                                                                | `message`          |                                  |
| `blob:create-file-bad-additional-metadata`          | 400         | The parameter `additionalMetadata` is not a valid json                                                                                    |                    |                                  |
| `blob:create-file-data-file-hash-change-forbidden`  | 403         | The parameter `fileHash` does not match with the hash of the uploaded file                                                                | `message`          |                                             |
| `blob:create-file-data-creation-time-too-old`       | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent             | `message`          |                                             |
| `blob:signature-invalid`                            | 403         | The signature is invalid, e.g. by using a wrong key                                                                                       | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checksum-invalid`                             | 403         | The checksum sent in the signature is invalid, e.g. by changing some params, using the wrong hash algorithm or forgetting some characters | `message`          |                                             |
| `blob:create-file-data-method-not-suitable`         | 405         | The method used is not compatible with the method/action specified in the url                                                             | `message`          |                                             |
| `blob:blob-service-invalid-json`                    | 422         | The additional Metadata doesn't contain valid json!                                                                                       | `message`          |                                  |
| `blob:file-not-saved`                               | 500         | File could not be saved!                                                                                                                  | `message`          |                                  |
| `blob:create-file-data-bucket-quota-reached`        | 507         | Bucket quota is reached.                                                                                                                  | `message`          |                                  |

#### DELETE

| relay:errorId                                              | Status code | Description                                                                                                                               | relay:errorDetails | Example |
|------------------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:delete-file-data-by-prefix-missing-sig`              | 400         | The signature in parameter `sig` is missing                                                                                               | `message`          |         |
| `blob:delete-file-data-by-prefix-unset-sig-params`         | 400         | One or multiple of the required url parameters are missing                                                                                | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:delete-file-data-by-prefix-bucket-id-not-configured` | 400         | Bucket is not configured                                                                                                                  | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:delete-file-data-by-prefix-no-bucket-service`        | 400         | BucketService is not configured                                                                                                           | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:delete-file-data-by-prefix-creation-time-too-old`    | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent             | `message`          |                                             |
| `blob:signature-invalid`                                   | 403         | The signature is invalid, e.g. by using a wrong key                                                                                       | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checksum-invalid`                                    | 403         | The checksum sent in the signature is invalid, e.g. by changing some params, using the wrong hash algorithm or forgetting some characters | `message`          |                                             |
| `blob:file-data-not-found`                                 | 404         | No data was found for the specified bucketID and prefix combination                                                                       | `message`          |                                             |
| `blob:delete-file-data-by-prefix-method-not-suitable`      | 405         | The method used is not compatible with the method/action specified in the url                                                             | `message`          |                                             |

### Item operations (by identifier) `/blob/files/{identifier}` 

#### GET

| relay:errorId                                    | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|--------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:get-file-data-by-id-missing-sig`           | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-bucket-id`     | 400         | The parameter `bucketID` is missing                                                                                           | `message`          |         |
| `blob:get-file-data-by-id-bucket-not-configured` | 400         | The bucket with given `bucketID` is not configured                                                                            | `message`          |         |
| `blob:get-file-data-by-id-missing-bucket-id`     | 400         | The request has not specified a action/method                                                                                 |                    |         |
| `blob:check-signature-missing-sig`               | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:check-signature-missing-signature-params`  | 400         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:check-signature-creation-time-too-old`     | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:get-file-data-by-id-file-data-not-found`   | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:get-file-data-by-id-method-not-suitable`   | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:check-signature-method-not-suitable`       | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |

#### PUT

| relay:errorId                                    | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|--------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:get-file-data-by-id-missing-sig`           | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-bucket-id`     | 400         | The parameter `bucketID` is missing                                                                                           | `message`          |         |
| `blob:get-file-data-by-id-bucket-not-configured` | 400         | The bucket with given `bucketID` is not configured                                                                            | `message`          |         |
| `blob:check-signature-missing-sig`               | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:check-signature-missing-signature-params`  | 400         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:put-file-data-missing-filename`            | 400         | The parameter `fileName` is missing                                                                                           | `message`          |         |
| `blob:check-signature-creation-time-too-old`     | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:get-file-data-by-id-file-data-not-found`   | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:get-file-data-by-id-method-not-suitable`   | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:check-signature-method-not-suitable`       | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:file-not-saved`                            | 500         | File could not be saved!                                                                                                      | `message`          |         |

#### DELETE

| relay:errorId                                    | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|--------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:get-file-data-by-id-missing-sig`           | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:get-file-data-by-id-missing-bucket-id`     | 400         | The parameter `bucketID` is missing                                                                                           | `message`          |         |
| `blob:get-file-data-by-id-bucket-not-configured` | 400         | The bucket with given `bucketID` is not configured                                                                            | `message`          |         |
| `blob:check-signature-missing-sig`               | 400         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:check-signature-missing-signature-params`  | 400         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:check-signature-creation-time-too-old`     | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:get-file-data-by-id-file-data-not-found`   | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:get-file-data-by-id-method-not-suitable`   | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:check-signature-method-not-suitable`       | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |

### Download operation `/blob/files/{identifier}/download`

#### GET

| relay:errorId                                    | Status code | Description                                                   | relay:errorDetails | Example |
|--------------------------------------------------|-------------|---------------------------------------------------------------|--------------------| ------- |
| `blob:download-file-by-id-missing-identifier`    | 400         | The identifier `{identifier}` is missing                      | `message`          |         |
| `blob:download-file-by-id-missing-bucket-id`     | 400         | The bucket id parameter `bucketID` is missing                 | `message`          |         |
| `blob:download-file-by-id-missing-method`        | 400         | The prefix parameter `prefix` is missing                      | `message`          |         |
| `blob:download-file-by-id-missing-creation-time` | 400         | The creation time parameter `creationTime` is missing         | `message`          |         |
| `blob:download-file-by-id-bucket-not-configured` | 400         | The bucket with given `bucketID` is not configured            | `message`          |         |
| `blob:download-file-by-id-invalid-method`        | 400         | The action/method combination is not valid                    | `message`          |         |
| `blob:download-file-by-id-missing-sig`           | 400         | The signature parameter `sig` is missing                      | `message`          |         |
| `blob:download-file-by-id-creation-time-too-old` | 403         | The creation time parameter `creationTime` is too old         | `message`          |         |
| `blob:checksum-invalid`                          | 403         | The checksum `ucs` or `bcs` inside the signature is not valid | `message`          |         |
| `blob:signature-invalid`                         | 403         | The signature in parameter `sig` is invalid                   | `message`          |         |
