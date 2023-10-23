# Stored data

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.

## Tables
Below a table is provided with all the fields that are stored in the SQL table for blob

| Field               | Required (not nullable)     | Type                | Description                                                                                                                                       | Max. Size |
|---------------------|-----------------------------|---------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|-----------|
| identifier          | Yes                         | string              | An unique identifier of the resource, implemented using a [UUID](https://en.wikipedia.org/wiki/Universally_unique_identifier)                     | 128       |
| prefix              | Yes (at least empty string) | string              | The prefix assigned to the resource                                                                                                               | 512       |
| file_name           | Yes (at least empty string) | string              | The original file name given when uploading the file                                                                                              | 512       |
| bucket_id           | Yes                         | string              | The bucketID of the bucket in which the resource is stored                                                                                        | 50        |
| date_created        | Yes                         | datetime_immutable  | The date the resource was created in blob stored as a [datetime immutable](https://www.php.net/manual/de/class.datetimeimmutable.php)             | -         |
| last_access         | Yes                         | datetime_immutable  | The date the resource was last modified in blob stored as a [datetime immutable](https://www.php.net/manual/de/class.datetimeimmutable.php)       | -         |
| additional_metadata | No                          | text                | Additional metadata the user can provide                                                                                                          | -         |
| extension           | Yes (at least empty string) | string              | The file extension of the provided file                                                                                                           | 64        |
| exists_until        | Yes                         | datetime_immutable  | The date the resource will expire and be deleted from blob as a [datetime immutable](https://www.php.net/manual/de/class.datetimeimmutable.php)   | -         |
| file_size           | Yes                         | integer             | The size of the file in byte                                                                                                                      | -         |
| notify_email        | Yes (at least empty string) | string              | The email of someone who should get notified before the resource is deleted                                                                       | 255       |
