# Stored data

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.

## Tables
Below a table is provided with all the fields that are stored in the SQL table for blob

| Field               | Type                | Description                                                                                                                                     | Max. Size |
|---------------------|---------------------|-------------------------------------------------------------------------------------------------------------------------------------------------|-----------|
| identifier          | string              | An unique identifier of the resource, implemented using a [UUID](https://en.wikipedia.org/wiki/Universally_unique_identifier)                   | 128       |
| prefix              | string              | The prefix assigned to the resource                                                                                                             | 512       |
| file_name           | string              | The original file name given when uploading the file                                                                                            | 512       |
| bucket_id           | string              | The bucketID of the bucket in which the resource is stored                                                                                      | 50        |
| date_created        | datetime_immutable  | The date the resource was created in blob stored as a [datetime immutable](https://www.php.net/manual/de/class.datetimeimmutable.php)           | -         |
| last_access         | datetime_immutable  | The date the resource was last modified in blob stored as a [datetime immutable](https://www.php.net/manual/de/class.datetimeimmutable.php)     | -         |
| additional_metadata | text                | Additional metadata the user can provide                                                                                                        | -         |
| extension           | string              | The file extension of the provided file                                                                                                         | 64        |
| exists_until        | datetime_immutable  | The date the resource will expire and be deleted from blob as a [datetime immutable](https://www.php.net/manual/de/class.datetimeimmutable.php) | -         |
| file_size           | integer             | The size of the file in byte                                                                                                                    | -         |
| notify_email        | string              | The email of someone who should get notified before the resource is deleted                                                                     | 255       |
