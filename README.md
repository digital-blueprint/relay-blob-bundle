Relay-Blob Bundle README
================================

# DbpRelayBlobBundle

[GitHub](https://github.com/digital-blueprint/relay-blob-bundle) |
[Packagist](https://packagist.org/packages/dbp/relay-blob-bundle) |
[Changelog](https://github.com/digital-blueprint/relay-blob-bundle/blob/main/CHANGELOG.md) 

The blob bundle provides an API for abstracting different shared filesystems.
You can upload a file unauthorized via the API to a configured bucket and gets a short ephemeral link. 
Authentication takes place via signed requests.
The file is attached to the bucket, not to an owner.

A bucket can be an application or an application space. For example you can have two buckets with a different target group for one application.
A bucket is configured in the config file.

## Requirements
You need a DbpRelayBlobConnector bundle installed to make this bundle working. E.g. [DbpRelayBlobConnectorFilesystemBundle](https://github.com/digital-blueprint/relay-blob-connector-filesystem-bundle)

<!--
## Bundle installation

You can install the bundle directly from [packagist.org](https://packagist.org/packages/{{package-name}}).

```bash
composer require {{package-name}}
```
-->
## Integration into the Relay API Server

* Add the bundle to your `config/bundles.php` in front of `DbpRelayCoreBundle`:

```php
...
Dbp\Relay\BlobBundle\DbpRelayBlobBundle::class => ['all' => true],
Dbp\Relay\CoreBundle\DbpRelayCoreBundle::class => ['all' => true],
];
```

If you were using the [DBP API Server Template](https://github.com/digital-blueprint/relay-server-template)
as template for your Symfony application, then this should have already been generated for you.

* Run `composer install` to clear caches

## Configuration

The bundle has multiple configuration values that you can specify in your
app, either by hard-coding it, or by referencing an environment variable.

For this create `config/packages/dbp_relay_blob.yaml` in the app with the following
content:

```yaml
dbp_relay_blob:
  database_url: %env(resolve:DATABASE_URL)%'
  reporting_interval: "0 11 * * MON" # when notification cronjob should run
  buckets:
    test_bucket:
      service: 'Dbp\Relay\BlobConnectorFilesystemBundle\Service\FilesystemService' # The path to a dbp relay blob connector service
      bucket_id: '1234' # A given id for a bucket
      bucket_name: 'Test bucket' # friendly name of the bucket
      key: '12345' # public key for signed request
      quota: 500 # Max quota in MB
      notify_when_quota_over: 70 # percent of quota when the bucket owner should be notified that the storage is running out
      report_when_expiry_in: 'P30D' # duration of how much in advance a bucket owner or user should be warned about the deletion of files
      bucket_owner: 'tamara.steiwnender@tugraz.at' # Email who will be notified when quota is reached
      max_retention_duration: 'P1Y' # Max retention duration of files in ISO 8601
      link_expire_time: 'P7D' # Max expire time of sharelinks in ISO 8601
      policies: # policies what can be done in the bucket
        create: true
        delete: true
        open: true
        download: true
        rename: true
        work: true
      notify_quota: # Notification configuration how emails are sent when the quota is reached
        dsn: '%env(TUGRAZ_MAILER_TRANSPORT_DSN)%'
        from: 'noreply@tugraz.at'
        to: 'tamara.steinwender@tugraz.at'
        subject: 'Blob notify quota'
        html_template: 'emails/notify-quota.html.twig'
      reporting: # Reporting configuration how emails are sent when file expires
        dsn: '%env(TUGRAZ_MAILER_TRANSPORT_DSN)%'
        from: 'noreply@tugraz.at'
        to: 'tamara.steinwender@tugraz.at' # this email is an fallback, if no email field of an file is set
        subject: 'Blob file deletion reporting'
        html_template: 'emails/reporting.html.twig'
```

For more info on bundle configuration see <https://symfony.com/doc/current/bundles/configuration.html>.

## Development & Testing

* Install dependencies: `composer install`
* Run tests: `composer test`
* Run linters: `composer run lint`
* Run cs-fixer: `composer run cs-fix`

## Bundle dependencies

Don't forget you need to pull down your dependencies in your main application if you are installing packages in a bundle.

```bash
# updates and installs dependencies of dbp/relay-blob-bundle
composer update dbp/relay-blob-bundle
```

## Scripts

### Database migration

Run this script to migrate the database. Run this script after installation of the bundle and
after every update to adapt the database to the new source code.

```bash
php bin/console doctrine:migrations:migrate --em=dbp_relay_blob_bundle
```

## Functionality & Error codes

### `/blob/files`

#### POST
Checks the signature to determine if the request is allowed.
Creates a fileData Entity, which is saved in the database. Saves the given file in the configured service of the connector bundle.
Returns the fileData with a contentUrl. This link expires in the configured link_expire_time.

##### Parameters

- bucketID: string
- creationTime: int
- action: string
- prefix (optional): string
- fileName (optional, default name of the file): string
- retentionDuration (optional): string ISO 8601, e.g. P2YT6H
- notifyMail (optional): string
- additionalMetadata (optional): object
- sig: string

##### Request body parameters

- file: string (binary)
- bucketID: string
- notifyMail (optional): string
- additionalMetadata (optional): object

##### Request body

```JSON
{
  "file": "string",
  "bucketID": "string",
  "additionalMetadata": "string",
  "notifyEmail": "string"
}
```


##### Error codes

| relay:errorId                                     | Status code | Description                                                                                                                               | relay:errorDetails | Example                          |
|---------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------------------|--------------------|----------------------------------|
| `blob:createFileData-missing-sig`                 | 401         | The signature parameter `sig` is missing                                                                                               | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:createFileData-unset-sig-params`            | 403         | One or multiple of the required url parameters are missing                                                                                | `message`          |                                             |
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

#### GET By prefix
Checks the signature if the request is allowed.
Returns fileDatas with ephemeral contentUrls of a specific prefix(path) in a given bucket.

##### Parameters

- bucketID: string
- creationTime: int
- action: string
- prefix: string
- page (optional, default 1)
- perPage (optional, default 30)
- sig: string

##### Error codes

| relay:errorId                                        | Status code | Description                                                                                                                   | relay:errorDetails | Example |
|------------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:getFileDataCollection-missing-sig`             | 401         | The signature parameter `sig` is missing                                                                                      | `message`          |         |
| `blob:getFileDataCollection-missing-bucketID`        | 400         | The bucketID parameter `bucketID` is missing                                                                                  | `message`          |         |
| `blob:getFileDataCollection-missing-creationTime`    | 400         | The creation time parameter `creationTime` is missing                                                                         | `message`          |         |
| `blob:getFileDataCollection-missing-method`          | 400         | The action/method parameter `method` is missing                                                                               | `message`          |         |
| `blob:getFileDataCollection-bucketID-not-configured` | 400         | Bucket with given `bucketID` is not configured                                                                                | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:getFileDataCollection-no-bucket-service`       | 400         | BucketService is not configured                                                                                               | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checkSignature-missing-sig`                    | 401         | The signature in parameter `sig` is missing                                                                                   | `message`          |         |
| `blob:checkSignature-missing-signature-params`       | 403         | One or multiple of the required url parameters are missing                                                                    | `message`          |         |
| `blob:checkSignature-creationtime-too-old`           | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent | `message`          |         |
| `blob:checkSignature-method-not-suitable`            | 405         | The method used is not compatible with the method/action specified in the url                                                 | `message`          |         |


#### DELETE by prefix
Checks the signature if the request is allowed.
Deletes all files in a given prefix(path) of a bucket.

##### Parameters

- bucketID: string
- creationTime: int
- action: string
- prefix: string
- sig: string

##### Error codes

| relay:errorId                                         | Status code | Description                                                                                                                               | relay:errorDetails | Example |
|-------------------------------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------------------|--------------------| ------- |
| `blob:deleteFileDataByPrefix-missing-sig`             | 401         | The signature in parameter `sig` is missing                                                                                               | `message`          |         |
| `blob:deleteFileDataByPrefix-unset-sig-params`        | 403         | One or multiple of the required url parameters are missing                                                                                | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:deleteFileDataByPrefix-bucketID-not-configured` | 400         | Bucket is not configured                                                                                                                  | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:deleteFileDataByPrefix-no-bucket-service`       | 400         | BucketService is not configured                                                                                                           | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:deleteFileDataByPrefix-method-not-suitable`     | 405         | The method used is not compatible with the method/action specified in the url                                                             | `message`          |                                             |
| `blob:deleteFileDataByPrefix-creationtime-too-old`    | 403         | The parameter `creationTime` is too old, therefore the request timed out and a new request has to be created, signed and sent             | `message`          |                                             |
| `blob:signature-invalid`                              | 403         | The signature is invalid, e.g. by using a wrong key                                                                                       | `message`          | `['message' => 'Signature cannot checked']` |
| `blob:checksum-invalid`                               | 403         | The checksum sent in the signature is invalid, e.g. by changing some params, using the wrong hash algorithm or forgetting some characters | `message`          |                                             |
| `blob:fileData-not-found`                             | 404         | No data was found for the specified bucketID and prefix combination                                                                       | `message`          |                                             |



### `/blob/files/{identifier}`

#### GET
Checks the signature if the request is allowed.
Returns fileData with ephemeral contentUrls of a specific id.

##### Parameters

- identifier: string
- creationTime: int
- action: string
- sig: string

##### Error codes

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
Checks the signature if the request is allowed.
Can update fileName, additionalMetadata and/or notifyEmail of a fileData.

##### Parameters

- identifier: string
- creationTime: int
- action: string
- fileName: string
- sig: string

##### Request body

```JSON
{
  "fileName": "string",
  "additionalMetadata": "string",
  "notifyEmail": "string"
}
```

##### Error codes

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
Checks the signature if the request is allowed.
Deletes a specific file and the links and the filedata with given identifier.

##### Parameters

- identifier: string
- creationTime: int
- action: string
- sig: string

##### Error codes

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


### `/blob/files/{identifier}/exists_until`

#### PUT exists until
Checks the signature if the request is allowed.
Updates existsUntil of a file.

##### Parameters

- identifier: string

##### Request body

```JSON
{
  "existsUntil": "2022-12-12T15:19:01.112Z"
}
```

| relay:errorId                    | Status code | Description                                                                                                                           | relay:errorDetails | Example |
| -------------------------------- |-------------|---------------------------------------------------------------------------------------------------------------------------------------| ------------------ | ------- |
| `blob:blob-service-invalid-max-retentiontime` | 400         | The given `exists until time` is longer then the max retention time of the bucket! Enter a time between now and $maxRententionFromNow |                    |         |


## CronJobs

### Cleanup Cronjob
`Blob File cleanup`: This cronjob is for cleanup purposes. It starts every hour and deletes old files.

### Send Report Cronjob
`Blob File send reports`: This cronjob sends reports to given email adresses, or the bucket owner. In this reports there are all files which are going to be deleted in the timeframe specified in the config. 
The email adresse are attached to these files or there is a default in the config. This cronjob starts every Monday at 9 o'clock in the Morning (UTC).