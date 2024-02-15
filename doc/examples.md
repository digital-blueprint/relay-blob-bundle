# Examples
## Example checksum and signature
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
  "ucs": "c8c103b727a27b9912597378eeeaa641a4800d08f0a3cc1006646f07fdab189d"
}
```
As seen in the example, the body consists of only one parameter `ucs` which is the SHA-256 checksum of the request url (beginning from and including `/blob`).
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

## Example Requests
Examples of the API is use can be found in the [common-activities](https://github.com/digital-blueprint/common-activities/tree/main/activity-showcase/src/Blob) repository and the [tests](https://github.com/digital-blueprint/relay-blob-bundle/blob/main/tests/CurlGetTest.php) directory of the [relay-blob-bundle](https://github.com/digital-blueprint/relay-blob-bundle/blob/main/tests/CurlGetTest.php) repository.

Furthermore, below are some examples of how to implement communication with blob in php.

### GET
GET can mean get a collection of items or get a single item, thus this section is separated into two subesections.
#### GET item
Setting:

Imagine that you have uploaded a file and got back the identifier `de1aaf61-bc52-4c91-a679-bef2f24e3cf7`. Therefore, you know that you can access the file using the `/blob/files/de1aaf61-bc52-4c91-a679-bef2f24e3cf7` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `method` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`method` is the method you want the endpoint to perform. For GET requests, the correct method to use is `GET`, all other would fail.

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files/de1aaf61-bc52-4c91-a679-bef2f24e3cf7?bucketID=1248&creationTime=1689602245&method=GET
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `ucs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).

Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `5338afb41dc80ae0668975a9c198c8a58a43b175b84616ecc709a799da6a5982`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files/de1aaf61-bc52-4c91-a679-bef2f24e3cf7?bucketID=1248&creationTime=1689602245&method=GET&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.

##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob to GET an item. Make sure to replace the base url with your blob base url and the identitifer, bucketID and secretKey with your values.
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
$includeData = 1;

// create SHA-256 checksum of request parameters
$cs = hash('sha256', '/blob/files/'.$id.'?bucketID='.$bucketID.'&creationTime='.$creationTime.'&method=GET'.'&includeData='.$includeData);

// create payload for signature
$payload = [
    'ucs' => $cs
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
        'method' => 'GET',
        'includeData' => $includeData,
        'sig' => $sig,
    ]
];
// send request using the defined parameters
$response = $client->request('GET', '/blob/files/'.$id, $params);

// print response body
echo $response->getBody()."\n";
```
#### GET Collection
Setting:

Imagine that you have uploaded multiple files with the same `prefix` and you want to retrieve all files with this prefix. Therefore, you know that you can access the file using the `/blob/files` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `prefix`, `method` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`prefix` is the prefix you specified when uploading the files, lets assume this is `myData`.
`method` is the method you want the endpoint to perform. For GET requests, this should be `GET`, all others would fail

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files?bucketID=1248&creationTime=1689602245&prefix=myData&method=GET
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `cs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).

Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `7c2bdb6f8553cccee3934864e60d79c55d447a851b064f4e989293acca890bc2`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files?bucketID=1248&creationTime=1689602245&prefix=myData&method=GET&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.

##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob to GET a collection. Make sure to replace the base url with your blob base url and the bucketID, prefix and secretKey with your values.
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
$includeData = 1;

// create SHA-256 checksum of request parameters
$cs = hash('sha256', '/blob/files?bucketID='.$bucketID.'&creationTime='.$creationTime.'&prefix='.$prefix.'&method=GET'.'&includeData='.$includeData);

// create payload for signature
$payload = [
    'ucs' => $cs
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
        'method' => 'GET',
        'includeData' => $includeData,
        'sig' => $sig,
    ]
];
// send request using the defined parameters
$response = $client->request('GET', '/blob/files', $params);

// print response body
echo $response->getBody()."\n";

```
### POST
#### CREATE item
Setting:

Imagine that you want to upload a file. Therefore, you know that you can upload a file using the `/blob/files` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `prefix`, `method`, `fileName`, `fileHash` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`prefix` is the prefix that the data is stored in. Different prefixes store different items, therefore prefixes are a way to easily group up data that belongs together. Assume that the prefix our file was created with is `myData`.
`method` is the method you want the endpoint to perform. For POST requests, this should be `POST`, all others would fail
`fileName` is the new file name of the file you want to rename. Assume that the new file name should be `myFile.txt`.
`fileHash` is the hash of the file you want to upload. This hash has to be generated using `sha256`.

In this case, `myFile.txt` is a plaintext `.txt` file that has the following content:
```text
This is my file.
```

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files?bucketID=1248&creationTime=1689602245&prefix=myData&method=POST&fileName=myFile.txt&fileHash=c3707db513a88903c2c109c27550590c01fcb688ed9b4e1508197e0c973be0e3
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `cs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).
Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `e1e93cc0a57b20104d124cd0df3e28c8c61f172cd7df7c2c4405b7a41bb01d2d`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files?bucketID=1248&creationTime=1689602245&prefix=myData&method=POST&fileName=myFile.txt&fileHash=c3707db513a88903c2c109c27550590c01fcb688ed9b4e1508197e0c973be0e3&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.

##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob to POST an item. Make sure to replace the base url with your blob base url and the bucketID, prefix, fileName and secretKey with your values. Also dont forget to replace the path to the file you want to upload.
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

// define identifier, bucketID, creationTime and b
$bucketID = '1248';
$creationTime = time(); // get current timestamp using time()
$prefix = 'myData';
$fileName = "myFile.txt";
$fileHash = hash_file("sha256", "myFile.txt");

// create SHA-256 checksum of request parameters
$cs = hash('sha256', '/blob/files?bucketID='.$bucketID.'&creationTime='.$creationTime.'&prefix='.$prefix.'&method=POST'.'&fileName='.$fileName.'&fileHash='.$fileHash);

// create payload for signature
$payload = [
    'ucs' => $cs,
    'bcs' => hash('sha256', '{}')
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

echo file_get_contents('myFile.txt');
// define parameter needed for valid request
$params = [
    'query' => [
        'bucketID' => $bucketID,
        'creationTime' => $creationTime,
        'prefix' => $prefix,
        'method' => 'POST',
        'fileName' => $fileName,
        'fileHash' => $fileHash,
        'sig' => $sig,
    ],
    'multipart' => [
        [
            'name' => 'file',
            'contents' => file_get_contents('myFile.txt'),
            'filename' => $fileName,
        ],
    ]
];
// send request using the defined parameters
$response = $client->request('POST', '/blob/files', $params);

// print response body
echo $response->getBody()."\n";
```

### PATCH
#### PATCH item
Setting:

Imagine that you have uploaded a file and got back the identifier `4da14ef0-d552-4e27-975e-e1f3db5a0e81`. Therefore, you know that you can rename the file using the `/blob/files/4da14ef0-d552-4e27-975e-e1f3db5a0e81` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `method`, `fileName` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`prefix` is the prefix that the data is stored in. Different prefixes store different items, therefore prefixes are a way to easily group up data that belongs together. Assume that the prefix our file was created with is `myData`.
`method` is the method you want the endpoint to perform. For PUT requests, this can only be `PUT`, all others would fail.
`fileName` is the new file name of the file you want to rename. Assume that the new file name should be `myNewFile.txt`.

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files/8183d841-4783-4a4c-9680-e8d7c22c896e?bucketID=1248&creationTime=1689602245&method=PUT&fileName=myNewFile.txt
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `cs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).
Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `4b8ba380f59dfd6b83bd1db8f37ad8e7855df38f456e5d1c98debf8e7014de7b`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files/8183d841-4783-4a4c-9680-e8d7c22c896e?bucketID=1248&creationTime=1689602245&method=PUT&fileName=myNewFile.txt&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.

##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob using PUT. Make sure to replace the base url with your blob base url and the identifier, bucketID, fileName and secretKey with your values.
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

// define identifier, bucketID, creationTime and fileName
$id = '8183d841-4783-4a4c-9680-e8d7c22c896e';
$bucketID = '1248';
$creationTime = time(); // get current timestamp using time()
$fileName = "newName.txt";

$body = "{\"fileName\":\"$fileName\"}";

// create SHA-256 checksum of request parameters
$cs = hash('sha256', '/blob/files/'.$id.'?bucketID='.$bucketID.'&creationTime='.$creationTime.'&method=PUT');

// create payload for signature
$payload = [
    'ucs' => $cs,
    'bcs' => hash('sha256', $body),
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
    'headers' => [
        'Content-Type' => 'application/json',
    ],
    'query' => [
        'bucketID' => $bucketID,
        'creationTime' => $creationTime,
        'method' => 'PATCH',
        'sig' => $sig,
    ],
    'body' => $body,
];
// send request using the defined parameters
$response = $client->request('PATCH', '/blob/files/'.$id, $params);

// print response body
echo $response->getBody()."\n";
```

### DELETE
#### DELETE item
Setting:

Imagine that you have uploaded a file and got back the identifier `4da14ef0-d552-4e27-975e-e1f3db5a0e81`. Therefore, you know that you can delete the file using the `/blob/files/4da14ef0-d552-4e27-975e-e1f3db5a0e81` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `prefix`, `method` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`prefix` is the prefix that the data is stored in. Different prefixes store different items, therefore prefixes are a way to easily group up data that belongs together. Assume that the prefix our file was created with is `myData`.
`method` is the method you want the endpoint to perform. For DELETE requests, the correct method to use is `DELETE`, all other would fail.

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files/4da14ef0-d552-4e27-975e-e1f3db5a0e81?bucketID=1248&creationTime=1689602245&prefix=myData&method=DELETE
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `cs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).
Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `f481ec6f9b544b2f24bf7e0b9eec225e4401e26f2053cc260e5eea3448628c93`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files/4da14ef0-d552-4e27-975e-e1f3db5a0e81?bucketID=1248&creationTime=1689602245&method=DELETE&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.

##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob using DELETE. Make sure to replace the base url with your blob base url and the identitifer, bucketID and secretKey with your values.
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
$cs = hash('sha256', '/blob/files/'.$id.'?bucketID='.$bucketID.'&creationTime='.$creationTime.'&method=DELETE');

// create payload for signature
$payload = [
    'ucs' => $cs
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
        'method' => 'DELETE',
        'sig' => $sig,
    ]
];
// send request using the defined parameters
$response = $client->request('DELETE', '/blob/files/'.$id, $params);

// print response body
echo $response->getBody()."\n";
```
#### DELETE collection
Setting:

Imagine that you have uploaded multiple files with the same `prefix` and you want to delete all files with this prefix. Therefore, you know that you can access the file using the `/blob/files` endpoint.
However, you also need to specify the `bucketID`, `creationTime`, `prefix`, `method` and `sig` parameters. You already should know the `bucketID`, this is the ID of the bucket blob configured for you, lets assume this is `1248`.
`creationTime` is the creation time of the request, thus this is a timestamp of the current time. At the time of writing, it is the 17.07.2023 15:57:25, thus the current timestamp is `1689602245`.
`prefix` is the prefix you specified when uploading the files, lets assume this is `myData`.
`method` is the method you want the endpoint to perform. For GET requests, the correct method to use is `DELETE`, all other would fail.

Assuming the above mentioned setting, the url part so far would look like this:
```
/blob/files?bucketID=1248&creationTime=1689602245&prefix=myData&method=DELETE
```
This only missing parameter is `sig`, which represents the signature of the SHA-256 checksum `cs` of the above mentioned url part. More on this can be found in the section [Signature](##signature).

Before creating the signature, the SHA-256 checksum has to be created. In this case, this would be `be675bcaed9a8116afc7d1bc0fe6ef35f669efe31e9326e49677318ae9b180cf`. This checksum then has to be added to a json with the key `cs`.
This then has to be signed using the secret key, and appended to the url. The result will look something like this:
```
/blob/files?bucketID=1248&creationTime=1689602245&prefix=myData&method=DELETE&sig=eyJhbGciOiJIUzI1NiJ9.eyJjcyI6ImM4YzEwM2I3MjdhMjdiOTkxMjU5NzM3OGVlZWFhNjQxYTQ4MDBkMDhmMGEzY2MxMDA2NjQ2ZjA3ZmRhYjE4OWQifQ.o9IPdjFZ5BDXz2Y_vVsZtk5jQ3lpczFE5DtghJZ0mW0
```
Note: the signature in this case is faked, your signature will have another value, but the basic syntax will look the same.

##### PHP Code Example
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0, web-token/jwt-core 2.2.11, web-token/jwt-key-mgmt 2.2.11, and web-token/jwt-signature-algorithm-hmac 2.2.11
They can be installed using composer like this:
```cmd
composer require guzzlehttp/guzzle
composer require web-token/jwt-core
composer require web-token/jwt-key-mgmt
composer require web-token/jwt-signature-algorithm-hmac
```
The following script is a simple example of how to communicate with blob using DELETE. Make sure to replace the base url with your blob base url and the bucketID, prefix and secretKey with your values.
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
$cs = hash('sha256', '/blob/files?bucketID='.$bucketID.'&creationTime='.$creationTime.'&prefix='.$prefix.'&method=DELETE');

// create payload for signature
$payload = [
    'ucs' => $cs
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
        'method' => 'DELETE',
        'sig' => $sig,
    ]
];
// send request using the defined parameters
$response = $client->request('DELETE', '/blob/files', $params);

// print response body
echo $response->getBody()."\n";
```
