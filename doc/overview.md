# Overview 

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.


This bundle provides an API for managing files, providing the possiblity to upload and retrieve files using endpoints.
The files are stored with an expiry date, and expired files get deleted automatically. The storage is grouped into so-called buckets, where a bucket represents the storage for one application.
Buckets can be configured to have different expiry times, usage rights, storage quotas, etc.

Installation Requirements:
 - A MySQL / MariaDB database