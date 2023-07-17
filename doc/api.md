# API

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.

The bundle provides GET endpoints for retrieving file metadata, file binary data and POST, DELETE endpoints for creating and deleting data.

## Endpoints

| Endpoint                   | Method | Description                                                               | Required Parameters                                               | Optional Parameter                                                   | Required formdata parameter               |
|----------------------------|--------|---------------------------------------------------------------------------|-------------------------------------------------------------------|----------------------------------------------------------------------|-------------------------------------------|
| `/blob/files/{identifier}` | GET    | Used to retrieve file metadata or file binary data of the file with {id}. | `bucketID`, `creationTime`, `action`, `sig`                       | `binary`                                                             | -                                         |
| `/blob/files`              | POST   | Used to create a file                                                     | `bucketID`, `creationTime`, `prefix`, `action`, `fileName`, `sig` | `notifyEmail`, `retentionDuration`, `additionalMetadata`, `fileHash` | `file`, `prefix`, `fileName`, `bucketID`  |
| `/blob/files`              | GET    | Used to GET a collection of files                                         | `bucketID`, `creationTime`, `prefix`, `sig`                       | `binary`                                                             | -                                         |
| `/blob/files/{identifier}` | DELETE | Used to DELETE the file with given {id}                                   | `bucketID`, `creationTime`, `prefix`, `action`, `sig`             |                                                                      | -                                         |
| `/blob/files`              | DELETE | Used to DELETE the files with given prefix                                | `bucketID`, `creationTime`, `prefix`, `action`, `sig`             |                                                                      | -                                         |

In general, the parameters have to be given in the specified order while optional parameters can be selectively left out for the computation of the checksum. The only exception is the `sig` parameter, which always has to be the last parameter.

## Parameters

| Parameter            | Description                                                                 | Type   | Possible values                                          |
|----------------------|-----------------------------------------------------------------------------|--------|----------------------------------------------------------|
| `bucketID`           | ID of the bucket in which the file(s) are located.                          | int    | all valid bucket IDs                                     |
| `creationTime`       | Current time (UTC) in seconds                                               | int    | all valid integers                                       |
| `prefix`             | prefix which the file(s) have                                               | string | all valid prefixes                                       |
| `action`             | action that is performed, e.g. `GETALL` to get all files                    | string | `GETONE`,`GETALL`, `CREATEONE`, `DELETEONE`, `DELETEALL` |
| `sig`                | signature string of the checksum                                            | string | all valid signature strings                              |
| `fileName`           | original filename of the file                                               | string | all valid strings                                        |
| `fileHash`           | the fileHash of the binary file                                             | string | all valid hash strings                                   |
| `binary`             | defines whether a 302 redirect to the binary data should be returned or not | int    | `0` or `1`                                               |
| `notifyEmail`        | email address of a person that wants to be notified before deletion         | string | all valid email addresses                                |
| `retentionDuration`  | defines the lifespan of the file                                            | int    | all non-negative int durations                           |
| `additionalMetadata` | some additional data the uploader want to add                               | string | all valid strings                                        |
| `file`               | the file to upload                                                          | file   |                                                          |

## Signature

!!! warning "Key security"

    The key should be kept confidential and safe and should NOT be leaked into the frontend or the user! The key has to remain secret!

The signatures are JWTs created with the algorithm `HS256` and are sent as a string. The only signed data is a `SHA-256` checksum that was generated using the url. 
Everything beginning from and including `/blob` has to be included when generating the checksum. The signature then has to be appended at the and of the url using the `sig` parameter.
The key used for signing and verifying the checksum has to be defined in the blob bucket config and the other backend system communicating with blob.
A JWT generated using this system could look like this:
```
eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
The decoded JWT payload and header for this signature look like this:
Header:
```json
{
  "alg": "HS256"
}
```
Body:
```json
{
  "cs": "c8c103b727a27b9912597378eeeaa641a4800d08f0a3cc1006646f07fdab189d"
}
```
As seen in the example, the body consists of only one parameter `cs` which is the SHA-256 checksum of the request url (beginning from and including `/blob`).
In this case, the checksum `cs` was created using the following input:
```
/blob/filesystem/de1aaf61-bc52-4c91-a679-bef2f24e3cf7?validUntil=2023-07-17T07:50:14+00:00
```
To compute the request the signature has to be appended to the url using the `sig` parameter
The url (without origin) the looks like this :
```
/blob/filesystem/de1aaf61-bc52-4c91-a679-bef2f24e3cf7?validUntil=2023-07-17T07:50:14+00:00&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: This example uses an url from the `relay-blob-connector-filesystem-bundle`, but this doesnt make any difference while generating the signature.

## Error codes and descriptions

!!! warning "Currently in development"

    TODO