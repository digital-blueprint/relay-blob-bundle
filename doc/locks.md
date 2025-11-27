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

## Example (PHP)
```php
<?php

declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';

use GuzzleHttp\Client;

// create guzzle client with localhost api as base url
$client = new Client([
    'base_uri' => 'http://127.0.0.1:8000',
    'timeout' => 2.0,
]);

// define bucketIdentifier and oauth client params
$bucketIdentifier = 'your-bucket';
$oauthIDPUrl = ''; // url including realm without trailing /
$clientID = '';
$clientSecret = '';

// create oauth client and fetch config url
$oauthClient = new Client();
$configUrl = $oauthIDPUrl.'/.well-known/openid-configuration';
$configBody = (string) $oauthClient->get($configUrl)->getBody();
$config = json_decode($configBody, true, 512, JSON_THROW_ON_ERROR);

// Fetch the token
$tokenUrl = $config['token_endpoint'];
$response = $oauthClient->post(
    $tokenUrl, [
        'auth' => [$clientID, $clientSecret],
        'form_params' => ['grant_type' => 'client_credentials'],
    ]);
$data = (string) $response->getBody();
$json = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
$token = $json['access_token'];
$tokenExpires = time() + ($json['expires_in'] - 20);

// define lock
$lock = [
    'getLock' => false,
    'postLock' => true,
    'patchLock' => true,
    'deleteLock' => true,
];

// define parameter needed for valid request
$params = [
    'query' => [
        'bucketIdentifier' => $bucketIdentifier,
    ],
    'body' => json_encode($lock),
];
$params['headers']['accept'] = 'application/ld+json';
$params['headers']['Authorization'] = "Bearer $token";
$params['headers']['Content-Type'] = 'application/ld+json';

// send request using the defined parameters
$response = $client->request('POST', '/blob/bucket-locks', $params);

$lockId = json_decode($response->getBody()->getContents(), true)['identifier'];
echo $lockId."\n";

// change lock to read lock
$lock = [
    'getLock' => true,
    'postLock' => false,
    'patchLock' => false,
    'deleteLock' => false,
];

// define parameter needed for valid request
$params = [
    'body' => json_encode($lock),
];
$params['headers']['accept'] = 'application/ld+json';
$params['headers']['Authorization'] = "Bearer $token";
$params['headers']['Content-Type'] = 'application/merge-patch+json';

// send request using the defined parameters
$response = $client->request('PATCH', "/blob/bucket-locks/$lockId", $params);

// print response body
echo $response->getBody()."\n";

$params = [];
$params['headers']['accept'] = 'application/ld+json';
$params['headers']['Authorization'] = "Bearer $token";
$params['headers']['Content-Type'] = 'application/merge-patch+json';

// delete lock again
$response = $client->request('DELETE', "/blob/bucket-locks/$lockId", $params);

// print response body
echo $response->getStatusCode()."\n";
```