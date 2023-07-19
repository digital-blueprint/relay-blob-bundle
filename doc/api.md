# API

!!! warning "Currently in development"

    This bundle is currently in development, thus not everything may work as explained here.

The bundle provides GET endpoints for retrieving file metadata, file binary data and POST, DELETE endpoints for creating and deleting data.

## Endpoints

| Endpoint                   | Method | Description                                                               | Required Parameters                                               | Optional Parameter                                                   | Required formdata parameter               |
|----------------------------|--------|---------------------------------------------------------------------------|-------------------------------------------------------------------|----------------------------------------------------------------------|-------------------------------------------|
| `/blob/files/{identifier}` | GET    | Used to retrieve file metadata or file binary data of the file with {id}. | `bucketID`, `creationTime`, `action`, `sig`                       | `binary`                                                             | -                                         |
| `/blob/files`              | POST   | Used to create a file                                                     | `bucketID`, `creationTime`, `prefix`, `action`, `fileName`, `sig` | `notifyEmail`, `retentionDuration`, `additionalMetadata`, `fileHash` | `file`, `prefix`, `fileName`, `bucketID`  |
| `/blob/files`              | GET    | Used to GET a collection of files                                         | `bucketID`, `creationTime`, `prefix`, `sig`                       | `binary`                                                             | -                                         |
| `/blob/files/{identifier}` | DELETE | Used to DELETE the file with given {id}                                   | `bucketID`, `creationTime`, `action`, `sig`                       |                                                                      | -                                         |
| `/blob/files`              | DELETE | Used to DELETE the files with given prefix                                | `bucketID`, `creationTime`, `prefix`, `action`, `sig`             |                                                                      | -                                         |

In general, the parameters have to be given in the specified order while optional parameters can be selectively left out for the computation of the checksum. The only exception is the `sig` parameter, which always has to be the last parameter.

## Parameters

| Parameter            | Description                                                                                         | Type   | Possible values                                          |
|----------------------|-----------------------------------------------------------------------------------------------------|--------|----------------------------------------------------------|
| `bucketID`           | ID of the bucket in which the file(s) are located.                                                  | int    | all valid bucket IDs                                     |
| `creationTime`       | Current time (UTC) in seconds                                                                       | int    | all valid integers                                       |
| `prefix`             | prefix which the file(s) have                                                                       | string | all valid prefixes                                       |
| `action`             | action that is performed, e.g. `GETALL` to get all files                                            | string | `GETONE`,`GETALL`, `CREATEONE`, `DELETEONE`, `DELETEALL` |
| `sig`                | signature string of the checksum `cs`                                                               | string | all valid signature strings                              |
| `fileName`           | original filename of the file                                                                       | string | all valid strings                                        |
| `fileHash`           | the fileHash of the binary file                                                                     | string | all valid hash strings                                   |
| `binary`             | defines whether the base64 encoded binary data should be returned (=1) or a link to the binary data | int    | `0` or `1`                                               |
| `notifyEmail`        | email address of a person that wants to be notified before deletion                                 | string | all valid email addresses                                |
| `retentionDuration`  | defines the lifespan of the file                                                                    | int    | all non-negative int durations                           |
| `additionalMetadata` | some additional data the uploader want to add                                                       | string | all valid strings                                        |
| `file`               | the file to upload                                                                                  | file   |                                                          |

## Signature

!!! warning "Key security"

    The key should be kept confidential and safe and should NOT be leaked into the frontend or the user! The key has to remain secret!

The signatures are JWTs created with the algorithm `HS256` and are sent as a string. The only signed data is a `SHA-256` checksum that was generated using the url. 
Everything beginning from and including `/blob` has to be included when generating the checksum. The signature then has to be appended at the and of the url using the `sig` parameter.
The key used for signing and verifying the checksum has to be defined in the blob bucket config and the other backend system communicating with blob.

### Example checksum and signature
A JWT generated using this system could look like this:
```
eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
The decoded JWT payload and header for this signature look like this:
Header:
```json
{
  "alg": "HS256"
}
```
Body:
```json
{
  "cs": "c8c103b727a27b9912597378eeeaa641a4800d08f0a3cc1006646f07fdab189d"
}
```
As seen in the example, the body consists of only one parameter `cs` which is the SHA-256 checksum of the request url (beginning from and including `/blob`).
In this case, the checksum `cs` was created using the following input:
```
/blob/filesystem/de1aaf61-bc52-4c91-a679-bef2f24e3cf7?validUntil=2023-07-17T07:50:14+00:00
```
To compute the request the signature has to be appended to the url using the `sig` parameter
The url (without origin) the looks like this :
```
/blob/filesystem/de1aaf61-bc52-4c91-a679-bef2f24e3cf7?validUntil=2023-07-17T07:50:14+00:00&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: This example uses an url from the `relay-blob-connector-filesystem-bundle`, but this doesnt make any difference while generating the signature.

### Signature url encoding
By default, blob verifies the url by generating the signature of the urlencoded url using [RFC3986](https://datatracker.ietf.org/doc/html/rfc3986]). This means that, among other things, `space` get converted to `%20`!
This means that systems communicating with blob have to also generate their checksum this way. It is not possible to just urlencode the whole url, since this would mean that valid symbols like `/` or `&` would be encoded too. Therefore, it is necessary to urlenode each parameter value separately before appending them in the url. 

## Example Requests
Examples of the API is use can be found in the [common-activities](https://github.com/digital-blueprint/common-activities/tree/main/activity-showcase/src/Blob) repository and the [tests](https://github.com/digital-blueprint/relay-blob-bundle/blob/main/tests/CurlGetTest.php) directory of the [relay-blob-bundle](https://github.com/digital-blueprint/relay-blob-bundle/blob/main/tests/CurlGetTest.php) repository. 

Furthermore, below are some examples of how to implement communication with blob in php.

### GET
GET can mean get a collection of items (GETALL) or get a single item (GETONE), thus this section is separated into two subesections.
#### GETONE
Setting:

Imagine that you have uploaded a file and got back the identifier `de1aaf61-bc52-4c91-a679-bef2f24e3cf7`. Therefore, you know that you can access the file using the `/blob/files/de1aaf61-bc52-4c91-a679-bef2f24e3cf7` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `action` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`action` is the action you want the endpoint to perform. For GET requests, this could be `GETONE` or `GETALL` depending on if you want to get a collection of resources or a single resource. The endpoint `/blob/files/{identifier}` is used to get one resource, therefore the correct action to use is `GETONE`, all other would fail.

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files/de1aaf61-bc52-4c91-a679-bef2f24e3cf7?bucketID=1248&creationTime=1689602245&action=GETONE
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `cs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).

Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `acf1a1aa8269438e3127cf863b531856c575a0cc4165cc75f7c865e39d2e9cce`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files/de1aaf61-bc52-4c91-a679-bef2f24e3cf7?bucketID=1248&creationTime=1689602245&action=GETONE&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.


##### Javascript Code Example
```javascript
    createSha256HexForUrl(payload) {
        return crypto.subtle.digest('SHA-256', new TextEncoder().encode(payload))
            .then(hashArray => {
                return  Array.from(new Uint8Array(hashArray)).map(b => b.toString(16).padStart(2, '0')).join('');
            });
    }
    
    createSignature(payload) {
        // not for production use!
        // secret keys shouldnt be leaked into the frontend!
        // this code is for demo purposes only.
        const apiKey = "<your-secret-key>";
    
        const pHeader = { alg: 'HS256' };
        const sHeader = JSON.stringify(pHeader);
    
        return jws.JWS.sign(
            pHeader.alg,
            sHeader,
            JSON.stringify(payload),
            this.hexEncode(apiKey),
        );
    }
    async sendGetOneFileRequest(id, binary) {
        let now = Math.floor(new Date().valueOf()/1000);
        let params = {};

        // if binary == 1, request binary file immediately
        if (binary == 1) {
            params = {
                bucketID: 1248,
                creationTime: now,
                binary: 1,
                action: 'GETONE',
            };
        }
        // else get metadata
        else {
            params = {
                bucketID: 1248,
                creationTime: now,
                action: 'GETONE',
            };
        }

        // in our example id is de1aaf61-bc52-4c91-a679-bef2f24e3cf7
        // id = "de1aaf61-bc52-4c91-a679-bef2f24e3cf7";

        params = {
            cs: await this.createSha256HexForUrl("/blob/files/" + id + "?" + new URLSearchParams(params)),
        };

        const sig = this.createSignature(params);

        // if binary == 1, request binary file immediately
        if (binary == 1) {
            params = {
                bucketID: 1248,
                creationTime: now,
                binary: 1,
                action: 'GETONE',
                sig: sig,
            };
        }
        // else get metadata
        else {
            params = {
                bucketID: 1248,
                creationTime: now,
                action: 'GETONE',
                sig: sig,
            };
        }

        const urlParams = new URLSearchParams(params);

        const options = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/ld+json',
            },
        };

        return await this.httpGetAsync(this.entryPointUrl + '/blob/files/' + id + '?' + urlParams, options);
    }
```
##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob using GETONE. Make sure to replace the base url with your blob base url and the identitifer, bucketID and secretKey with your values.
```php
<?php
require __DIR__ .'/vendor/autoload.php';

use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

// create guzzle client with localhost api as base url
$client = new Client([
    'base_uri' => 'http://127.0.0.1:8000',
    'timeout'  => 2.0,
]);

// define identifier, bucketID, creationTime and binary
$id = 'de1aaf61-bc52-4c91-a679-bef2f24e3cf7';
$bucketID = '1248';
$creationTime = time(); // get current timestamp using time()
$binary = 1;

// create SHA-256 checksum of request parameters
$cs = hash('sha256', '/blob/files/'.$id.'?bucketID='.$bucketID.'&creationTime='.$creationTime.'&action=GETONE'.'&binary='.$binary);

// create payload for signature
$payload = [
    'cs' => $cs
];

// 32 byte key required
// you should have gotten your key by your blob bucket owner
// an example key can be generated using php -r 'echo bin2hex(random_bytes(32))."\n";'
$secretKey = "your-key"; // replace this

// create JWK
$jwk = JWKFactory::createFromSecret(
    $secretKey,
    [
        'alg' => 'HS256',
        'use' => 'sig',
    ]
);
// create algorithm manager with HS256 (HMAC with SHA-256)
$algorithmManager = new AlgorithmManager([new HS256()]);
// create signature builder
$jwsBuilder = new JWSBuilder($algorithmManager);

// build jws out of payload (cs) using HS256
$jws = $jwsBuilder
    ->create()
    ->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
    ->addSignature($jwk, ['alg' => 'HS256'])
    ->build();

// serialize jws
$sig = (new CompactSerializer())->serialize($jws, 0);

// define parameter needed for valid request
$params = [
    'query' => [
        'bucketID' => $bucketID,
        'creationTime' => $creationTime,
        'action' => 'GETONE',
        'binary' => $binary,
        'sig' => $sig,
    ]
];
// send request using the defined parameters
$response = $client->request('GET', '/blob/files/'.$id, $params);

// print response body
echo $response->getBody()."\n";
```
#### GETALL
Setting:

Imagine that you have uploaded multiple files with the same `prefix` and you want to retrieve all files with this prefix. Therefore, you know that you can access the file using the `/blob/files` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `prefix`, `action` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`prefix` is the prefix you specified when uploading the files, lets assume this is `myData`.
`action` is the action you want the endpoint to perform. For GET requests, this could be `GETONE` or `GETALL` depending on if you want to get a collection of resources or a single resource. The endpoint `/blob/files` is used to get a collection of resources, therefore the correct action to use is `GETALL`, all other would fail.

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files?bucketID=1248&creationTime=1689602245&prefix=myData&action=GETALL
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `cs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).

Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `a03381ce2c8fa73851d1d26cb5d0a5b5a73fbf2ca9e67d66e4f471ae11a4075e`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files?bucketID=1248&creationTime=1689602245&prefix=myData&action=GETALL&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.

##### Javascript Code Example

##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob using GETALL. Make sure to replace the base url with your blob base url and the bucketID, prefix and secretKey with your values.
```php
<?php
require __DIR__ .'/vendor/autoload.php';

use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

// create guzzle client with localhost api as base url
$client = new Client([
    'base_uri' => 'http://127.0.0.1:8000',
    'timeout'  => 2.0,
]);

// define bucketID, creationTime, prefix and binary
$bucketID = '1248';
$creationTime = time(); // get current timestamp using time()
$prefix = 'myData';
$binary = 1;

// create SHA-256 checksum of request parameters
$cs = hash('sha256', '/blob/files?bucketID='.$bucketID.'&creationTime='.$creationTime.'&prefix='.$prefix.'&action=GETALL'.'&binary='.$binary);

// create payload for signature
$payload = [
    'cs' => $cs
];

// 32 byte key required
// you should have gotten your key by your blob bucket owner
// an example key can be generated using php -r 'echo bin2hex(random_bytes(32))."\n";'
$secretKey = 'your-key'; // replace this

// create JWK
$jwk = JWKFactory::createFromSecret(
    $secretKey,
    [
        'alg' => 'HS256',
        'use' => 'sig',
    ]
);
// create algorithm manager with HS256 (HMAC with SHA-256)
$algorithmManager = new AlgorithmManager([new HS256()]);
// create signature builder
$jwsBuilder = new JWSBuilder($algorithmManager);

// build jws out of payload (cs) using HS256
$jws = $jwsBuilder
    ->create()
    ->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
    ->addSignature($jwk, ['alg' => 'HS256'])
    ->build();

// serialize jws
$sig = (new CompactSerializer())->serialize($jws, 0);

// define parameter needed for valid request
$params = [
    'query' => [
        'bucketID' => $bucketID,
        'creationTime' => $creationTime,
        'prefix' => $prefix,
        'action' => 'GETALL',
        'binary' => $binary,
        'sig' => $sig,
    ]
];
// send request using the defined parameters
$response = $client->request('GET', '/blob/files', $params);

// print response body
echo $response->getBody()."\n";

```
### POST
#### CREATEONE
!!! warning "Currently in development"

    TODO

### PUT
#### PUTONE
!!! warning "Currently in development"

    TODO

### DELETE
#### DELETEONE
Setting:

Imagine that you have uploaded a file and got back the identifier `4da14ef0-d552-4e27-975e-e1f3db5a0e81`. Therefore, you know that you can delete the file using the `/blob/files/4da14ef0-d552-4e27-975e-e1f3db5a0e81` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `prefix`, `action` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`prefix` is the prefix that the data is stored in. Different prefixes store different items, therefore prefixes are a way to easily group up data that belongs together. Assume that the prefix our file was created with is `myData`.
`action` is the action you want the endpoint to perform. For DELETE requests, this could be `DELETEONE` or `DELETEALL` depending on if you want to delete a collection of resources or a single resource. The endpoint `/blob/files/{identifier}` is used to delete one resource, therefore the correct action to use is `DELETEONE`, all other would fail.

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files/4da14ef0-d552-4e27-975e-e1f3db5a0e81?bucketID=1248&creationTime=1689602245&prefix=myData&action=DELETEONE
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `cs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).
Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `619999459eb90e6bbf00362f7963cd741ed71f8848437e434b087b4fa1e87b3e`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files/4da14ef0-d552-4e27-975e-e1f3db5a0e81?bucketID=1248&creationTime=1689602245&action=DELETEONE&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.

##### Javascript Code Example
```javascript
    createSha256HexForUrl(payload) {
        return crypto.subtle.digest('SHA-256', new TextEncoder().encode(payload))
            .then(hashArray => {
                return  Array.from(new Uint8Array(hashArray)).map(b => b.toString(16).padStart(2, '0')).join('');
            });
    }

    createSignature(payload) {
        // not for production use!
        // secret keys shouldnt be leaked into the frontend!
        // this code is for demo purposes only.
        const apiKey = "<your-secret-key>";
    
        const pHeader = { alg: 'HS256' };
        const sHeader = JSON.stringify(pHeader);
    
        return jws.JWS.sign(
            pHeader.alg,
            sHeader,
            JSON.stringify(payload),
            this.hexEncode(apiKey),
        );
    }

    async sendDeleteFileRequest(id) {
        let creationtime = Math.floor(new Date().valueOf()/1000);
        let params = {
            bucketID: 1248,
            creationTime: creationtime,
            prefix: 'myData',
            action: 'DELETEONE',
        };
        
        // in our example id is 4da14ef0-d552-4e27-975e-e1f3db5a0e81
        // id = "4da14ef0-d552-4e27-975e-e1f3db5a0e81";

        params = {
            cs: await this.createSha256HexForUrl("/blob/files/" + id + "?" + new URLSearchParams(params)),
        };

        const sig = this.createSignature(params);

        params = {
            bucketID: 1248,
            creationTime: creationtime,
            prefix: 'myData',
            action: 'DELETEONE',
            sig: sig,
        };

        const urlParams = new URLSearchParams(params);

        const options = {
            method: 'DELETE',
        };
        return await this.httpGetAsync("<your-blob-url>" + '/blob/files/' + id + '?' + urlParams, options);
    }
```

##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob using DELETEONE. Make sure to replace the base url with your blob base url and the identitifer, bucketID and secretKey with your values.
```php
<?php
require __DIR__ .'/vendor/autoload.php';

use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

// create guzzle client with localhost api as base url
$client = new Client([
    'base_uri' => 'http://127.0.0.1:8000',
    'timeout'  => 2.0,
]);

// define identifier, bucketID, creationTime and binary
$id = '4da14ef0-d552-4e27-975e-e1f3db5a0e81';
$bucketID = '1248';
$creationTime = time(); // get current timestamp using time()

// create SHA-256 checksum of request parameters
$cs = hash('sha256', '/blob/files/'.$id.'?bucketID='.$bucketID.'&creationTime='.$creationTime.'&action=DELETEONE');

// create payload for signature
$payload = [
    'cs' => $cs
];

// 32 byte key required
// you should have gotten your key by your blob bucket owner
// an example key can be generated using php -r 'echo bin2hex(random_bytes(32))."\n";'
$secretKey = "your-key"; // replace this

// create JWK
$jwk = JWKFactory::createFromSecret(
    $secretKey,
    [
        'alg' => 'HS256',
        'use' => 'sig',
    ]
);
// create algorithm manager with HS256 (HMAC with SHA-256)
$algorithmManager = new AlgorithmManager([new HS256()]);
// create signature builder
$jwsBuilder = new JWSBuilder($algorithmManager);

// build jws out of payload (cs) using HS256
$jws = $jwsBuilder
    ->create()
    ->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
    ->addSignature($jwk, ['alg' => 'HS256'])
    ->build();

// serialize jws
$sig = (new CompactSerializer())->serialize($jws, 0);

// define parameter needed for valid request
$params = [
    'query' => [
        'bucketID' => $bucketID,
        'creationTime' => $creationTime,
        'action' => 'DELETEONE',
        'sig' => $sig,
    ]
];
// send request using the defined parameters
$response = $client->request('DELETE', '/blob/files/'.$id, $params);

// print response body
echo $response->getBody()."\n";
```
#### DELETEALL


##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob using DELETEALL. Make sure to replace the base url with your blob base url and the bucketID, prefix and secretKey with your values.
```php
<?php
require __DIR__ .'/vendor/autoload.php';

use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

// create guzzle client with localhost api as base url
$client = new Client([
    'base_uri' => 'http://127.0.0.1:8000',
    'timeout'  => 2.0,
]);

// define bucketID, creationTime and prefix
$bucketID = '1248';
$creationTime = time(); // get current timestamp using time()
$prefix = 'myData';

// create SHA-256 checksum of request parameters
$cs = hash('sha256', '/blob/files?bucketID='.$bucketID.'&creationTime='.$creationTime.'&prefix='.$prefix.'&action=DELETEALL');

// create payload for signature
$payload = [
    'cs' => $cs
];

// 32 byte key required
// you should have gotten your key by your blob bucket owner
// an example key can be generated using php -r 'echo bin2hex(random_bytes(32))."\n";'
$secretKey = 'your-key'; // replace this

// create JWK
$jwk = JWKFactory::createFromSecret(
    $secretKey,
    [
        'alg' => 'HS256',
        'use' => 'sig',
    ]
);
// create algorithm manager with HS256 (HMAC with SHA-256)
$algorithmManager = new AlgorithmManager([new HS256()]);
// create signature builder
$jwsBuilder = new JWSBuilder($algorithmManager);

// build jws out of payload (cs) using HS256
$jws = $jwsBuilder
    ->create()
    ->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
    ->addSignature($jwk, ['alg' => 'HS256'])
    ->build();

// serialize jws
$sig = (new CompactSerializer())->serialize($jws, 0);

// define parameter needed for valid request
$params = [
    'query' => [
        'bucketID' => $bucketID,
        'creationTime' => $creationTime,
        'prefix' => $prefix,
        'action' => 'DELETEALL',
        'sig' => $sig,
    ]
];
// send request using the defined parameters
$response = $client->request('DELETE', '/blob/files', $params);

// print response body
echo $response->getBody()."\n";
```

## Error codes and descriptions

!!! warning "Currently in development"

    TODO