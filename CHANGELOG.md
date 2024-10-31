# Changelog

## Unreleased

## v0.1.63
* remove FileData from the connector interface

## v0.1.62
* Rename `retentionDuration` to `deleteIn`
* Remove `deleteAt` as PATCH parameter
* Add `deleteIn` to PATCH parameters, patches the `deleteAt` date from the time of the request

## v0.1.61

* Minor cleanup of the connector interface

## v0.1.60

* Fix reading old files in the DB without a type set
* Fix reading empty files

## v0.1.59

* Cleanup of the public PHP API

## v0.1.58

* More pagination fixes

## v0.1.57

* FileApi: fix pagination for real
* Remove unused connector interface methods

## v0.1.56

* FileApi: fix pagination
* Add getters for the external bucket ID to FileData

## v0.1.55

* Throw 403 if the given 'bucketIdentifer' parameter and the bucket identifier of file in the DB do not match for item operations.
* Allow File instead of UploadedFile in the internal API
* Add a health check for the DB connection
* Fix schema validation in case the CWD was not default server dir
* Fix contentUrl return value when data is not base64 encoded

## v0.1.54

* Centralize set up of FileData from request for POST and PATCH in BlobService
* Centralize validation of FileData for POST and PATCH in BlobService
* Add PHP backend FileApi
* Refactor/restructure/modernize code; remove duplicate code; enhance performance
* Fix 404 error when deleting/patching a file with retention duration set (includeDeleteAt param missing)

## v0.1.52
* Optimize file cleanUp to prevent the returning of theoretically infinite entries

## v0.1.51
* Adapt latest migration description

## v0.1.50
* Fix bug that lead to 500 in GET requests

## v0.1.49
* Remove `max_retention_duration` from the bucket config

## v0.1.48
* Throw 404 if item identifier is in an invalid format
* Drop support for Symfony 5 and api-platform 2
* Rename `exists_until` to `delete_at`
* If `delete_at` is NULL, the file will be kept indefinitely
* Introduce new parameter `includeDeleteAt` for GET requests. If `=1`, all files where `delete_at` is not NULL will be returned. If not set, files where `delete_at` is not NULL will be ignored.  
* Optimize SQL queries
* Fix bulk GET to always return the set page size

## v0.1.47
* Provide `propertyName` in `errorDetail` for error `type mismatch`
* Make retrieval of expired but not deleted data impossible

## v0.1.46
* Fix bug that made referencing of json schemas without the extension `.jschema` impossible

## v0.1.45
* Add additional information about what went wrong in json schema validation in case of a `type mismatch` error

## v0.1.44
* Remove config key `project_dir` again

## v0.1.43
* Enable cross-referencing of json schemas in the same directory
* Add new config key `project_dir`, which should point to the root of the project

## v0.1.42
* Disable integrity check cronjob since its inefficient sql uses a lot of memory

## v0.1.41
* Optimize dbp:relay-blob:check-integrity` command to either print only the number of affected files or also the ids of the affected files

## v0.1.40
* Optimize `dbp:relay-blob:check-storage` command since it could happen that the doctrine `em` loses connection to the SQL database

## v0.1.39
* Disable reporting emails for now due to potential to run out of memory if database is big

## v0.1.38
* Update core and adapt signatures of FileDataProvider and FileDataProcessor

## v0.1.37
* Fix bug that made it impossible to change the `type` in some cases in a `PATCH` request

## v0.1.36
* Disable sending the reporting email to the given `notifyEmail` for now since a bug would leak information about the amount of other files getting deleted

## v0.1.35
* Fix bug which prevented the sending of the reporting emails
* Add separate config option for when a quota warning email should be sent
* Minimize the contents of the `reporting` email to prevent it from getting too big (before it had every file that will soon expire in it)
* Implement the flag `disableValidation` that disables output validation for GET requests if set to `=1`
* `exists_until` is now set to NULL if should be the max retention duration, to reflect changes in the config immediately
* Rename `additionalMetadata` to `metadata` and `additionalType` to `type`
* Remove `bcs` from signature to decouple body from url
* Update documentation
* Code cleanup

## v0.1.34
* Fix bug which set the value of an entry in the `blob_bucket_sizes` to 0

## v0.1.33
* Fix missing `fileHash` check for file upload in `PATCH`
* Remove email when the bucket quota is reached
* Json schema validator now expects path to json schema instead of the json schema itself
* Change some `GET` error codes that didnt match for the case
* Implement output validation for `GET` requests to `/download`

## v0.1.32

* Add support for api-platform 3.3

## v0.1.31

* Port to PHPUnit 10
* Port from doctrine annotations to PHP attributes
* Improve error handling for file post, patch and delete. The database and the actual files should be consistent now.
* Take delete-bucket operation into consideration when updating the `blob_bucket_sizes` table 
* Better error handling if `post_max_size` is exceeded. This is now a separate error case.
* Add metadata output validation for `GET` requests

## v0.1.30

## v0.1.29
* Add support for api-platform 3.2

## v0.1.28
* The content type for the patch operation is now `application/merge-patch+json` instead of `application/json`

## v0.1.27
* Add new `blob_bucket_sizes` table that keeps track of the blob bucket sizes. This should improve performance massively for big tables.
* Add cronjob that regularly checks and updates the `blob_bucket_sizes` table.

## v0.1.26
* Support newer symfony/psr-http-message-bridge

## v0.1.25
* Update to ramsey/uuid-doctrine v2.0

## v0.1.24
* Add index on `date_created` for better GET request efficiency

## v0.1.23
* Temporary hotfix: Disable bucket size check with SUM() until a better solution is implemented

## v0.1.22
* Migrate db fields `prefix`, `file_name`, `date_accessed`, `file_size` and `additional_type` to more fitting, efficient types
* Add indexes on `prefix` and `internal_bucket_id`
* Remove unused `PoliciesStruct` and adapt code accordingly
* Enhance some `PATCH` error messages

## v0.1.21

* Registering the `uuid_binary` type in the doctrine config is no longer required,
  the bundle will now handle it automatically.

## v0.1.20
* **Breaking change**: Change `creationTime` to ISO8601 Date instead of unix timestamp
* **Breaking change**: Remove `exists_until` endpoint
* **Breaking change**: Change `PUT` to `PATCH`
* **Breaking change**: Move all `POST` url parameters but `creationTime`, `method` and `bucketID` to body
* **Breaking change**: Migrate db to save the uuid as BINARY instead of as VARCHAR
* **Breaking change**: Use UUIDv7 instead of UUIDv4
* **Breaking change**: Extend `DatasystemProviderServiceInterface` by one function that allows upload of base64 encoded files
* Add additional changeable parameters to body of `PATCH`
* Drop support for PHP 7.3
* Drop support for PHP 7.4/8.0
* Add support for Symfony 6

## v0.1.19
* Improve error handling for file not found cases
* Add validation of json schema to POST and PUT endpoints
* Add more and better tests
* Remove the need for urlencoding the additionalMetadata since it was moved to the body
* Update dbp/relay-blob-library to v0.2.0

## v0.1.18
* **Breaking change**: rework checksum `cs` to an url checksum `ucs` and a body checksum `bcs`. `ucs` is required in
  every request and works like `cs` did, and `bcs` builds a checksum over the json body of a request.
* fix missing `fileName` bug in 'PUT' request
* move cleanup interval to config, s.t. it is more easily configurable
* database: rename `last_access` column to `date_accessed`
* database: rework `extension` column to `mime_type` to store mime types instead extensions
* database: add `date_modified`, `additional_type` columns
* Add `justinrainbow/json-schema` to composer.json

## v0.1.17
* Implement parameter `startsWith`, which enables operations on all prefixes in one bucket starting with `prefix`.

## v0.1.16
* Refactor whole blob codebase
* Improve error handling by merging similar error cases into one method
* Fix wrong http status code was provided in /{id}/download endpoint

## v0.1.15
* Enforce [RFC 3986](https://datatracker.ietf.org/doc/html/rfc3986) by using [rawurldecode](https://www.php.net/manual/en/function.rawurldecode.php) on all url parameters. Before checksum calculation all
  non-alphanumeric characters have to be converted according to RFC 3986, otherwise the checksum check will fail.
* Increase `file_name` column size of `blob_files` to 1000 characters

## v0.1.14
* **Breaking change**: `/blob/files/{identifier}/download` action implemented which returns a binary response of the
  file with the given identifier
* **Breaking change**: Rename parameter `binary` to `includeData`, since it returns base64 encoded data not binary data
* **Breaking change**: Rename parameter `action` to `method` and only include the used method now. `CREATONE`, `GETONE`,
  `GETALL`, `DELETEALL`, `DELETEONE`, `PUTONE` are removed and replaced by `POST`, `GET`, `DELETE`, `PUT`.
* Add docs and new errorIDS for `/blob/files/{identifier}/download` action

## v0.1.13
* Refactor all errorIDs to kebapcase and adapt documentation
* Update relay-blob-library to v0.1.5
* Add an email warning when the used bucket memory reaches a defined percentage of the quota
* Add on-purpose failing testcases for missing parameters, wrong signatures, ...
* Enhance docs

## v0.1.12
* Use `\Dbp\Relay\BlobLibrary\Helpers\SignatureTools::verify`

## v0.1.11
* Use `dbp/relay-blob-library` and move some code from `\Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature`
  to `\Dbp\Relay\BlobLibrary\Helpers\SignatureTools`

## v0.1.10

## v0.1.9
* Change meaning of `binary` GET parameter
  * before it meant that a 302 redirect should be the answer
  * now it means that the base64 encoded data gets sent in the `contentUrl`
* Add more documentation

## v0.1.8
* Introduce new config options `notify_when_quota_over` and `report_when_expiry_in`
* Rename config option `public_key` to `key` and remove config option `path`
* Add first version of documentation, primarly endpoint documentation
* Introduce better error handling and response codes
* Add missing openapi_context for better api frontend usability
* Add more testcases

## v0.1.7
* Move reporting interval to bucket config
* Remove unnecessary config options (config cleanup)
* Add more requires and default values in the config
* Code cleanup

## v0.1.6
* Add "binary" option to GETALL action, which returns 302 redirect links to the binary 
* Introduce new config options for email reporting

## v0.1.5
* Add concrete implementation for /files/{identifier} endpoint
  * A request to /files/{identifier} returns metadata of the file
  * A request to /files/{identifier} with an parameter binary=1 returns a 302 redirect to the file binary download
* Code cleanup

## v0.1.3
* Retrieve link expire time from config 
* GET requests are now validated by signature in url, and validUntil date
* remove signature from header
* signature now signs a sha2 checksum over the url (to shorten the signature)

## v0.1.2
* remove phpunit functions (assertNotNull)

## v0.1.1
 * add signature to url, temporarily also allow signature in header
 * remove echos, remove dumps
 * update to api-platform 2.7