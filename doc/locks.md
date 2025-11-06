# Locks

Blob can restrict the access to certain buckets through locks.
This allows authorized users to restrict write or read access to buckets whenever needed through the API. 

## Authorization
All blob locks endpoints can only be used with a valid OIDC token. 
In keycloak terms, a client needs to have a configured scope to be able to use the endpoints.

## Usage
Each bucket can at most have one lock, which has different properties.
Each lock can prevent all relevant HTTP methods, thus each lock can prevent GET, POST, PATCH and/or DELETE requests.
For example, if a bucket should be write-locked, then a new lock that prevents POST, PATCH and DELETE should be created.
When creating a bucket lock through a `POST` request, a body containing information about all properties must be given as shown below
```json
{
  "getLock": false,
  "postLock": false,
  "patchLock": true,
  "deleteLock": false
}
```
A similar body must be given for `PATCH` requests.
