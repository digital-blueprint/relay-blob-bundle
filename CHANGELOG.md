# v0.1.20
* Remove `exists_until` endpoint
* Change `PUT` to `PATCH`
* 

# v0.1.19
* Improve error handling for file not found cases
* Add validation of json schema to POST and PUT endpoints
* Add more and better tests
* Remove the need for urlencoding the additionalMetadata since it was moved to the body
* Update dbp/relay-blob-library to v0.2.0

# v0.1.18
* **Breaking change**: rework checksum `cs` to an url checksum `ucs` and a body checksum `bcs`. `ucs` is required in
  every request and works like `cs` did, and `bcs` builds a checksum over the json body of a request.
* fix missing `fileName` bug in 'PUT' request
* move cleanup interval to config, s.t. it is more easily configurable
* database: rename `last_access` column to `date_accessed`
* database: rework `extension` column to `mime_type` to store mime types instead extensions
* database: add `date_modified`, `additional_type` columns
* Add `justinrainbow/json-schema` to composer.json

# v0.1.17
* Implement parameter `startsWith`, which enables operations on all prefixes in one bucket starting with `prefix`.

# v0.1.16
* Refactor whole blob codebase
* Improve error handling by merging similar error cases into one method
* Fix wrong http status code was provided in /{id}/download endpoint

# v0.1.15
* Enforce [RFC 3986](https://datatracker.ietf.org/doc/html/rfc3986) by using [rawurldecode](https://www.php.net/manual/en/function.rawurldecode.php) on all url parameters. Before checksum calculation all
  non-alphanumeric characters have to be converted according to RFC 3986, otherwise the checksum check will fail.
* Increase `file_name` column size of `blob_files` to 1000 characters

# v0.1.14
* **Breaking change**: `/blob/files/{identifier}/download` action implemented which returns a binary response of the
  file with the given identifier
* **Breaking change**: Rename parameter `binary` to `includeData`, since it returns base64 encoded data not binary data
* **Breaking change**: Rename parameter `action` to `method` and only include the used method now. `CREATONE`, `GETONE`,
  `GETALL`, `DELETEALL`, `DELETEONE`, `PUTONE` are removed and replaced by `POST`, `GET`, `DELETE`, `PUT`.
* Add docs and new errorIDS for `/blob/files/{identifier}/download` action

# v0.1.13
* Refactor all errorIDs to kebapcase and adapt documentation
* Update relay-blob-library to v0.1.5
* Add an email warning when the used bucket memory reaches a defined percentage of the quota
* Add on-purpose failing testcases for missing parameters, wrong signatures, ...
* Enhance docs

# v0.1.12
* Use `\Dbp\Relay\BlobLibrary\Helpers\SignatureTools::verify`

# v0.1.11
* Use `dbp/relay-blob-library` and move some code from `\Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature`
  to `\Dbp\Relay\BlobLibrary\Helpers\SignatureTools`

# v0.1.10

# v0.1.9
* Change meaning of `binary` GET parameter
  * before it meant that a 302 redirect should be the answer
  * now it means that the base64 encoded data gets sent in the `contentUrl`
* Add more documentation

# v0.1.8
* Introduce new config options `notify_when_quota_over` and `report_when_expiry_in`
* Rename config option `public_key` to `key` and remove config option `path`
* Add first version of documentation, primarly endpoint documentation
* Introduce better error handling and response codes
* Add missing openapi_context for better api frontend usability
* Add more testcases

# v0.1.7
* Move reporting interval to bucket config
* Remove unnecessary config options (config cleanup)
* Add more requires and default values in the config
* Code cleanup

# v0.1.6
* Add "binary" option to GETALL action, which returns 302 redirect links to the binary 
* Introduce new config options for email reporting

# v0.1.5
* Add concrete implementation for /files/{identifier} endpoint
  * A request to /files/{identifier} returns metadata of the file
  * A request to /files/{identifier} with an parameter binary=1 returns a 302 redirect to the file binary download
* Code cleanup

# v0.1.3
* Retrieve link expire time from config 
* GET requests are now validated by signature in url, and validUntil date
* remove signature from header
* signature now signs a sha2 checksum over the url (to shorten the signature)

# v0.1.2
* remove phpunit functions (assertNotNull)

# v0.1.1
 * add signature to url, temporarily also allow signature in header
 * remove echos, remove dumps
 * update to api-platform 2.7