# Configuration



Example configuration of the blob bundle:
```yaml
parameters:
  defaults: &defaults
    dbp_relay_blob:
      database_url: '<mysql-url>'
      reporting_interval: "0 11 * * MON" # when notification cronjob should run
      buckets:
        test_bucket:
          service: 'Dbp\Relay\BlobConnectorFilesystemBundle\Service\FilesystemService' # service implementation of the installed connector 
          bucket_id: '4242' # ID of bucket 
          bucket_name: 'Bucket Name'
          key: '<your-key>' # should be at least 256 bit (hex encoded)
          quota: 500 # in MB
          notify_when_quota_over: 70 # in percent of the quota
          report_when_expiry_in: 'P30D' # in Days
          bucket_owner: '<bucket-owner-email>'
          link_expire_time: 'PT1M' # how long until link exipres
          policies:
            create: true
            delete: true
            open: true
            download: true
            rename: true
            work: true
          notify_quota:
            dsn: '<your-dsn>' 
            from: '<noreply-email>' # from whom the email gets sent
            to: '<bucket-owner-email>' # who to notify
            subject: 'Blob notify quota'
            html_template: 'emails/notify-quota.html.twig'
          reporting:
            dsn: '<your-dsn>'
            from: '<noreply-email>'
            to: '<bucket-owner-email>'
            subject: 'Blob file deletion reporting'
            html_template: 'emails/reporting.html.twig'
```

To generate a key you can use: `openssl rand -hex 32`
