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

!!! warning "Currently in development"

    TODO