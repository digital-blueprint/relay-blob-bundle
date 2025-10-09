# Backup and Restore Metadata Backups

### Example (PHP)
This php example uses PHP 8.1 with composer and guzzlehttp/guzzle 7.7.0.
Guzzle can be installed using composer like this:

```cmd
composer require guzzlehttp/guzzle
```

The following php code starts a metadata backup:
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

// define parameter needed for valid request
$params = [
    'query' => [
        'bucketIdentifier' => $bucketIdentifier,
    ],
    'body' => '{}',
];
$params['headers']['accept'] = 'application/ld+json';
$params['headers']['Authorization'] = "Bearer $token";
$params['headers']['Content-Type'] = 'application/ld+json';

// send request using the defined parameters
$response = $client->request('POST', '/blob/metadata-backup-jobs', $params);

// print response body
echo $response->getBody()."\n";
```