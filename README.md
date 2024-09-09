Relay-Blob Bundle README
================================

# DbpRelayBlobBundle

[GitHub](https://github.com/digital-blueprint/relay-blob-bundle) |
[Packagist](https://packagist.org/packages/dbp/relay-blob-bundle) |
[Changelog](https://github.com/digital-blueprint/relay-blob-bundle/blob/main/CHANGELOG.md) 

The blob bundle provides an API for abstracting different shared storage systems.
You can upload a file unauthorized via the API to a configured bucket and gets a short ephemeral link. 
Authentication takes place via signed requests.
The file is attached to the bucket, not to an owner.

A bucket can be an application or an application space. For example, you can have two buckets with a different target group for one application.
A bucket is configured in the config file.

## Requirements

You need a DbpRelayBlobConnector bundle installed to make this bundle working. E.g. [DbpRelayBlobConnectorFilesystemBundle](https://github.com/digital-blueprint/relay-blob-connector-filesystem-bundle)

## Bundle installation

You can install the bundle directly from [packagist.org](https://packagist.org/packages/dbp/relay-blob-bundle).

```bash
composer require dbp/relay-blob-bundle
```

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
  cleanup_interval: "*/5 * * * *" # when cleanup cronjob should run
  file_integrity_checks: true # if file integrity checks should be performed periodically
  integrity_check_interval: "0 0 1 * *" # when integrity check cronjob should run
  bucket_size_check_interval: "0 2 * * 1" # when bucket size check cronjob should run
  quota_warning_interval: "0 7 * * *" # when bucket quota should be checked and if needed warning emails should be sent
  additional_auth: true # enable client credential flow
  buckets:
    test_bucket:
      service: 'Dbp\Relay\BlobConnectorFilesystemBundle\Service\FilesystemService' # The path to a dbp relay blob connector service
      internal_bucket_id: '019072b9-7736-7430-aabd-ad1bbeeebacf' # A given internal id for a bucket
      bucket_id: 'test-bucket' # friendly name of the bucket thats also used for the request
      key: '12345' # public key for signed request
      quota: 500 # Max quota in MB
      notify_when_quota_over: 70 # percent of quota when the bucket owner should be notified that the storage is running out
      report_when_expiry_in: 'P30D' # duration of how much in advance a bucket owner or user should be warned about the deletion of files
      bucket_owner: 'john@example.com' # Email who will be notified when quota is reached
      link_expire_time: 'P7D' # Max expire time of sharelinks in ISO 8601
      warn_quota: # Notification configuration how emails are sent when the quota is about to be reached
        dsn: '%env(TUGRAZ_MAILER_TRANSPORT_DSN)%'
        from: 'noreply@tugraz.at'
        to: 'john@example.com'
        subject: 'Blob Bucket Quota Warning'
        html_template: 'emails/warn-quota.html.twig'
      reporting: # Reporting configuration how emails are sent when files are about to expire
        dsn: '%env(TUGRAZ_MAILER_TRANSPORT_DSN)%'
        from: 'noreply@tugraz.at'
        to: 'john@example.com' # this email is a fallback, if no email field of a file is set
        subject: 'Blob file Deletion Report'
        html_template: 'emails/reporting.html.twig'
      integrity: # Integrity check configuration how emails are sent when file hash or metadata hash do not match with the saved file or metadata
        dsn: '%env(TUGRAZ_MAILER_TRANSPORT_DSN)%'
        from: 'noreply@tugraz.at'
        to: 'john@example.com'
        subject: 'Blob File Integrity Check Report'
        html_template: 'emails/integrity.html.twig'
      additional_types:
        - generic_id_card: '%kernel.project_dir%/config/packages/schemas/relay-blob-bundle/test-bucket/generic_id_card.json'
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

## Error codes

See the [API documentation](doc/api.md).

## CronJobs

### Cleanup Cronjob

`Blob File cleanup`: This cronjob is for cleanup purposes. It starts every hour and deletes old files.

### Send Report Cronjob

`Blob File send reports`: This cronjob sends reports to given email addresses, or the bucket owner.
In these reports there are all files which are going to be deleted in the timeframe specified in the config. 
The email address are attached to these files or there is a default in the config.
This cronjob starts every Monday at 9 o'clock in the Morning (UTC).
