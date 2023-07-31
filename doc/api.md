# API

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.

The bundle provides GET endpoints for retrieving file metadata, file binary data and POST, DELETE endpoints for creating and deleting data.

## Endpoints

| Endpoint                                  | Method | Description                                                               | Required Parameters                                                           | Optional Parameter                                        | Required formdata parameter              |
|-------------------------------------------|--------|---------------------------------------------------------------------------|-------------------------------------------------------------------------------|-----------------------------------------------------------|------------------------------------------|
| `/blob/files/{identifier}`                | GET    | Used to retrieve file metadata or file binary data of the file with {id}. | `bucketID`, `creationTime`, `action`, `sig`                                   | `binary`                                                  | -                                        |
| `/blob/files`                             | POST   | Used to create a file                                                     | `bucketID`, `creationTime`, `prefix`, `action`, `fileName`, `fileHash`, `sig` | `notifyEmail`, `retentionDuration`, `additionalMetadata`  | `file`, `prefix`, `fileName`, `bucketID` |
| `/blob/files`                             | GET    | Used to GET a collection of files                                         | `bucketID`, `creationTime`, `prefix`, `sig`                                   | `binary`                                                  | -                                        |
| `/blob/files/{identifier}`                | DELETE | Used to DELETE the file with given {id}                                   | `bucketID`, `creationTime`, `action`, `sig`                                   |                                                           | -                                        |
| `/blob/files`                             | DELETE | Used to DELETE the files with given prefix                                | `bucketID`, `creationTime`, `prefix`, `action`, `sig`                         |                                                           | -                                        |
| `/blob/files/{identifier}`                | PUT    | Used to change the filename with given {id}                               | `bucketID`, `creationTime`, `action`, `fileName`, `sig`                       |                                                           | `fileName`                               |

In general, the parameters have to be given in the specified order while optional parameters can be selectively left out for the computation of the checksum. The only exception is the `sig` parameter, which always has to be the last parameter.

## Parameters

| Parameter            | Description                                                                                         | Type   | Possible values                                                    |
|----------------------|-----------------------------------------------------------------------------------------------------|--------|--------------------------------------------------------------------|
| `bucketID`           | ID of the bucket in which the file(s) are located.                                                  | int    | all valid bucket IDs                                               |
| `creationTime`       | Current time (UTC) in seconds                                                                       | int    | all valid integers                                                 |
| `prefix`             | prefix which the file(s) have                                                                       | string | all valid prefixes                                                 |
| `action`             | action that is performed, e.g. `GETALL` to get all files                                            | string | `GETONE`,`GETALL`, `CREATEONE`, `DELETEONE`, `DELETEALL`, `PUTONE` |
| `sig`                | signature string of the checksum `cs`                                                               | string | all valid signature strings                                        |
| `fileName`           | original filename of the file                                                                       | string | all valid strings                                                  |
| `fileHash`           | the fileHash of the binary file                                                                     | string | all valid hash strings                                             |
| `binary`             | defines whether the base64 encoded binary data should be returned (=1) or a link to the binary data | int    | `0` or `1`                                                         |
| `notifyEmail`        | email address of a person that wants to be notified before deletion                                 | string | all valid email addresses                                          |
| `retentionDuration`  | defines the lifespan of the file                                                                    | int    | all non-negative int durations                                     |
| `additionalMetadata` | some additional data the uploader want to add                                                       | string | all valid strings                                                  |
| `file`               | the file to upload                                                                                  | file   |                                                                    |

## Signature

!!! warning "Key security"

    The key should be kept confidential and safe and should NOT be leaked into the frontend or the user! The key has to remain secret!

The signatures are JWTs created with the algorithm `HS256` and are sent as a string. The only signed data is a `SHA-256` checksum that was generated using the url. 
Everything beginning from and including `/blob` has to be included when generating the checksum. The signature then has to be appended at the and of the url using the `sig` parameter.
The key used for signing and verifying the checksum has to be defined in the blob bucket config and the other backend system communicating with blob.

### Signature url encoding
By default, blob verifies the url by generating the signature of the urlencoded url using [RFC3986](https://datatracker.ietf.org/doc/html/rfc3986]). This means that, among other things, `space` get converted to `%20`!
This means that systems communicating with blob have to also generate their checksum this way. It is not possible to just urlencode the whole url, since this would mean that valid symbols like `/` or `&` would be encoded too. Therefore, it is necessary to urlenode each parameter value separately before appending them in the url.

## Error codes and descriptions

### Collection operations (by prefix) `/blob/files`

#### GET

| relay:errorId                                        | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|------------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:getFileDataCollection-missing-sig`             | 400         | The signature parameter `sig` is missing                                                                                      | `message`          |         |
| `blob:getFileDataCollection-missing-bucketID`        | 400         | The bucketID parameter `bucketID` is missing                                                                                  | `message`          |         |
| `blob:getFileDataCollection-missing-creationTime`    | 400         | The creation time parameter `creationTime` is missing                                                                         | `message`          |         |
| `blob:getFileDataCollection-missing-method`          | 400         | The action/method parameter `method` is missing                                                                               | `message`          |         |
| `blob:getFileDataCollection-bucketID-not-configured` | 400         | Bucket with given `bucketID` is not configured                                                                                | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:getFileDataCollection-no-bucket-service`       | 400         | BucketService is not configured                                                                                               | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checkSignature-missing-sig`                    | 401         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:checkSignature-missing-signature-params`       | 403         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:checkSignature-creationtime-too-old`           | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:checkSignature-method-not-suitable`            | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |

#### POST 

| relay:errorId                                     | Status code | Description                                                                                                                               | relay:errorDetails | Example                          |
|---------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------------------|--------------------|----------------------------------|
| `blob:createFileData-missing-sig`                 | 400         | The signature parameter `sig` is missing                                                                                               | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:createFileData-unset-sig-params`            | 400         | One or multiple of the required url parameters are missing                                                                                | `message`          |                                             |
| `blob:createFileData-method-not-suitable`         | 405         | The method used is not compatible with the method/action specified in the url                                                             | `message`          |                                             |
| `blob:createFileData-creationtime-too-old`        | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent             | `message`          |                                             |
| `blob:createFileData-file-hash-change-forbidden`  | 403         | The parameter `fileHash` does not match with the hash of the uploaded file                                                                | `message`          |                                             |
| `blob:createFileData-data-upload-failed`          | 400         | Data upload failed.                                                                                                                       | `message`          |                                  |
| `blob:createFileData-no-bucket-service`           | 400         | BucketService is not configured.                                                                                                          | `message`          |                                  |
| `blob:createFileData-missing-file`                | 400         | No file with parameter key "file" was received!                                                                                           | `message`          |                                  |
| `blob:createFileData-upload-error`                | 400         | File upload pload went wrong.                                                                                                             | `message`          |                                  |
| `blob:createFileData-empty-files-not-allowed`     | 400         | Empty files cannot be added!                                                                                                              | `message`          |                                  |
| `blob:createFileData-not-configured-bucketID`     | 400         | BucketID is not configured                                                                                                                | `message`          |                                  |
| `blob:blob-service-invalid-json`                  | 422         | The additional Metadata doesn't contain valid json!                                                                                       | `message`          |                                  |
| `blob:file-not-saved`                             | 500         | File could not be saved!                                                                                                                  | `message`          |                                  |
| `blob:createFileData-bucket-quota-reached`        | 507         | Bucket quote is reached.                                                                                                                  | `message`          |                                  |
| `blob:signature-invalid`                          | 403         | The signature is invalid, e.g. by using a wrong key                                                                                       | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checksum-invalid`                           | 403         | The checksum sent in the signature is invalid, e.g. by changing some params, using the wrong hash algorithm or forgetting some characters | `message`          |                                             |

#### DELETE

| relay:errorId                                         | Status code | Description                                                                                                                               | relay:errorDetails | Example |
|-------------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:deleteFileDataByPrefix-missing-sig`             | 400         | The signature in parameter `sig` is missing                                                                                               | `message`          |         |
| `blob:deleteFileDataByPrefix-unset-sig-params`        | 400         | One or multiple of the required url parameters are missing                                                                                | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:deleteFileDataByPrefix-bucketID-not-configured` | 400         | Bucket is not configured                                                                                                                  | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:deleteFileDataByPrefix-no-bucket-service`       | 400         | BucketService is not configured                                                                                                           | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:deleteFileDataByPrefix-method-not-suitable`     | 405         | The method used is not compatible with the method/action specified in the url                                                             | `message`          |                                             |
| `blob:deleteFileDataByPrefix-creationtime-too-old`    | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent             | `message`          |                                             |
| `blob:signature-invalid`                              | 403         | The signature is invalid, e.g. by using a wrong key                                                                                       | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checksum-invalid`                               | 403         | The checksum sent in the signature is invalid, e.g. by changing some params, using the wrong hash algorithm or forgetting some characters | `message`          |                                             |
| `blob:fileData-not-found`                             | 404         | No data was found for the specified bucketID and prefix combination                                                                       | `message`          |                                             |

### Item operations (by identifier) `/blob/files/{identifier}` 

#### GET

| relay:errorId                                  | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:getFileDataByID-missing-sig`             | 401         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:getFileDataByID-missing-bucketID`        | 400         | The parameter `bucketID` is missing                                                                                           | `message`          |         |
| `blob:getFileDataByID-bucketID-not-configured` | 400         | The bucket with given `bucketID` is not configured                                                                            | `message`          |         |
| `blob:getFileDataByID-method-not-suitable`     | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:getFileDataByID-fileData-not-found`      | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:checkSignature-missing-sig`              | 401         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:checkSignature-missing-signature-params` | 403         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:checkSignature-creationtime-too-old`     | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:checkSignature-method-not-suitable`      | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |

#### PUT

| relay:errorId                                   | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|-------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:getFileDataByID-missing-sig`              | 401         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:getFileDataByID-missing-bucketID`         | 400         | The parameter `bucketID` is missing                                                                                           | `message`          |         |
| `blob:getFileDataByID-bucketID-not-configured`  | 400         | The bucket with given `bucketID` is not configured                                                                            | `message`          |         |
| `blob:getFileDataByID-method-not-suitable`      | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:getFileDataByID-fileData-not-found`       | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:checkSignature-missing-sig`               | 401         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:checkSignature-missing-signature-params`  | 403         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:checkSignature-creationtime-too-old`      | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:checkSignature-method-not-suitable`       | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:file-not-saved`                           | 500         | File could not be saved!                                                                                                      | `message`          |         |

#### DELETE

| relay:errorId                                   | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|-------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:getFileDataByID-missing-sig`              | 401         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:getFileDataByID-missing-bucketID`         | 400         | The parameter `bucketID` is missing                                                                                           | `message`          |         |
| `blob:getFileDataByID-bucketID-not-configured`  | 400         | The bucket with given `bucketID` is not configured                                                                            | `message`          |         |
| `blob:getFileDataByID-method-not-suitable`      | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
| `blob:getFileDataByID-fileData-not-found`       | 404         | No FileData for the given identifier was not found!                                                                           | `message`          |         |
| `blob:checkSignature-missing-sig`               | 401         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:checkSignature-missing-signature-params`  | 403         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:checkSignature-creationtime-too-old`      | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:checkSignature-method-not-suitable`       | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |
