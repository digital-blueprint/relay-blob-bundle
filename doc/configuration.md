# Configuration



Example configuration of the blob bundle:
```yaml
parameters:
  defaults: &defaults
    dbp_relay_blob:
      database_url: '<mysql-url>'
      reporting_interval: "0 11 * * MON" # when notification cronjob should run
      cleanup_interval: "*/5 * * * *" # when file cleanup of expired files should run
      integrity_check_interval: "0 1 * * MON" # when file integrity check should run
      bucket_size_check_interval: "0 6 * * MON" # when bucket size check cronjob should run
      quota_warning_interval: "0 10 * * MON" # when bucket quota should be checked and if needed warning emails should be sent
      file_integrity_checks: true # enable file integrity checks
      additional_auth: true # enable client credential flow
      buckets:
        - service: 'Dbp\Relay\BlobConnectorFilesystemBundle\Service\FilesystemService' # service implementation of the installed connector 
          internal_bucket_id: '4242' # internal Id of an bucket which is only used internally and stored in the db, should be a UUIDv7
          bucket_id: 'Bucket Name' # human readable Id of an bucket which is used for requests
          key: '<your-key>' # should be at least 256 bit (hex encoded)
          quota: 500 # in MB
          output_validation: true # if on file output the metadata should be validated or not
          notify_when_quota_over: 70 # in percent of the quota
          report_when_expiry_in: 'P30D' # in Days
          bucket_owner: '<bucket-owner-email>'
          link_expire_time: 'PT1M' # how long until link exipres
          warn_quota:
            dsn: '<your-dsn>'
            from: '<noreply-email>' # from whom the email gets sent
            to: '<bucket-owner-email>' # who to notify
            subject: 'Blob notify quota'
            html_template: 'emails/warn-quota.html.twig'
          reporting:
            dsn: '<your-dsn>'
            from: '<noreply-email>'
            to: '<bucket-owner-email>'
            subject: 'Blob file deletion reporting'
            html_template: 'emails/reporting.html.twig'
          integrity:
            dsn: '<your-dsn>'
            from: '<noreply-email>'
            to: '<bucket-owner-email>'
            subject: 'Blob File Integrity Check Report'
            html_template: 'emails/integrity.html.twig'
          bucket_size:
            dsn: '<your-dsn>'
            from: '<noreply-email>'
            to: '<bucket-owner-email>'
            subject: 'Blob Bucket Size Check Warning'
            html_template: 'emails/bucketsize.html.twig'
          additional_types:
            - test_type: '<path-to-your-json-schema>'
```

To generate a key you can use: `openssl rand -hex 32`
