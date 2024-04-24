<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Controller\CreateFileDataAction;
use Dbp\Relay\BlobBundle\Controller\DeleteFileDatasByPrefix;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Service\ConfigurationService;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\TestUtils\UserAuthTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class CurlGetTest extends ApiTestCase
{
    use UserAuthTrait;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var array[] */
    private $files;

    /**
     * @throws \Exception
     */
    protected function setUp(): void
    {
        /** @var Kernel $kernel */
        $kernel = self::bootKernel();

        if ('test' !== $kernel->getEnvironment()) {
            throw new \RuntimeException('Execution only in Test environment possible!');
        }

        $this->entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        $metaData = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->updateSchema($metaData);

        $query = $this->entityManager->getConnection()->createQueryBuilder();
        $query->delete('blob_files')->executeQuery();

        $this->files = [
            0 => [
                'name' => $n = 'Test.php',
                'path' => $p = __DIR__.'/'.$n,
                'content' => $c = file_get_contents($p),
                'hash' => hash('sha256', $c),
                'size' => strlen($c),
                'mime' => 'application/x-php',
                'retention' => 'P1W',
            ],
            1 => [
                'name' => $n = 'Kernel.php',
                'path' => $p = __DIR__.'/'.$n,
                'content' => $c = file_get_contents($p),
                'hash' => hash('sha256', $c),
                'size' => strlen($c),
                'mime' => 'application/x-php',
                'retention' => 'P1M',
            ],
        ];

        // $this->markTestIncomplete();
    }

    /**
     * Integration test for get all for a prefix with empty result.
     */
    public function testGet(): void
    {
        try {
            $client = $this->withUser('user', [], '42');
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $action = 'GET';
            $payload = [
                'bucketID' => $bucketID,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'method' => $action,
            ];

            echo "GET collection\n";

            // $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $url = "/blob/files?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);
            $url = $url.'&sig='.$token;
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url, $options);

            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertCount(0, $data['hydra:member'], 'More files than expected');
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test for a full life cycle: create, use and destroy a blob
     *  - create blob no 1
     *  - get all blobs: blob no 1 is available
     *  - create blob no 2
     *  - get all blobs: two blobs are available
     *  - delete all blobs for the prefix: no entries in database
     *  - get all blobs: no blobs available.
     */
    public function testPostGetDelete(): void
    {
        try {
            $client = $this->withUser('user', [], '42');

            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file (0)-\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'Authorization' => 'Bearer 42',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                    .'file='.base64_encode($this->files[0]['content'])
            );

            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files (0)\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $action = 'GET';
            $url = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var \ApiPlatform\Symfony\Bundle\Test\Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);
            if ($response->getStatusCode() !== 200) {
                echo $response->getContent()."\n";
            }
            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertArrayHasKey(0, $data['hydra:member']);
            $resultFile = $data['hydra:member'][0];
            $this->assertEquals($prefix, $resultFile['prefix'], 'File data prefix not correct.');
            $this->assertEquals($this->files[0]['name'], $resultFile['fileName'], 'File name not correct.');
            $this->assertEquals($this->files[0]['size'], $resultFile['fileSize'], 'File size not correct.');
            $this->assertEquals($this->files[0]['uuid'], $resultFile['identifier'], 'File identifier not correct.');
            $this->assertEquals($notifyEmail, $resultFile['notifyEmail'], 'File data notify email not correct.');
            $this->assertCount(1, $data['hydra:member'], 'More files than expected');

            // =======================================================
            // POST another file
            // =======================================================
            echo "POST file 1\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $fileName = $this->files[1]['name'];
            $fileHash = $this->files[1]['hash'];
            $retentionDuration = $this->files[1]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[1]['path'], $this->files[1]['name'], $this->files[1]['mime']),
                ],
                [
                    'Authorization' => 'Bearer 42',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                    .'file='.base64_encode($this->files[1]['content'])
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[1]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[1]['uuid'] = $fileData->getIdentifier();
            $this->files[1]['created'] = $fileData->getDateCreated();
            $this->files[1]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files (0 and 1)\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $action = 'GET';
            $url = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $client = $this->withUser('user', [], '42');

            /** @var \ApiPlatform\Symfony\Bundle\Test\Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);

            if ($response->getStatusCode() !== 200) {
                echo $response->getContent()."\n";
            }

            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertCount(2, $data['hydra:member'], 'More files than expected');
            foreach ($data['hydra:member'] as $resultFile) {
                $found = false;
                foreach ($this->files as $file) {
                    if ($file['uuid'] === $resultFile['identifier']) {
                        $found = true;
                        $this->assertEquals($prefix, $resultFile['prefix'], 'File prefix not correct.');
                        $this->assertEquals($file['name'], $resultFile['fileName'], 'File name not correct.');
                        $this->assertEquals($file['size'], $resultFile['fileSize'], 'File size not correct.');
                        $until = $file['created']->add(new \DateInterval($file['retention']));
                        $this->assertEquals(
                            $until->format('c'),
                            $resultFile['existsUntil'],
                            'File retention time not correct.'
                        );

                        break;
                    }
                }
                $this->assertTrue($found, 'Uploaded file not found.');
            }

            // =======================================================
            // DELETE all files
            // =======================================================
            echo "DELETE all files (0 and 1)\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $action = 'DELETE';
            $url = "/blob/files/?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestDelete = Request::create($url.'&sig='.$token, 'DELETE', [], [], [],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'Authorization' => 'Bearer 42',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
            );

            $d = new DeleteFileDatasByPrefix($blobService);
            $d->setContainer($client->getContainer());
            try {
                $d->__invoke($requestDelete);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $query = $this->entityManager->getConnection()->createQueryBuilder();
            $this->files = $query->select('*')
                ->from('blob_files')
                ->where("prefix = '$prefix' AND internal_bucket_id = '$bucketID'")
                ->fetchAllAssociativeIndexed();
            $this->assertEmpty($this->files, 'Files not deleted');

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files (empty)\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $action = 'GET';
            $url = "/blob/files?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);

            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertCount(0, $data['hydra:member'], 'More files than expected');
        } catch (\Throwable $e) {
            //            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test for a full life cycle: create, use by id and destroy by id
     *  - create blob no 1
     *  - get blob no 1 by id: blob no 1 is available
     *  - delete blob no 1: no entries in database
     *  - get all blobs: no blobs available.
     */
    public function testGetDeleteById(): void
    {
        try {
            $client = $this->withUser('user', [], '42');
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $action = 'POST';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $retentionDuration = $this->files[0]['retention'];

            $url = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action";

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file 0 testGetDeleteById\n";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'Authorization' => 'Bearer 42',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();
            echo "    file identifier='{$this->files[0]['uuid']}' stored.\n";

            // =======================================================
            // GET a file by id
            // =======================================================
            echo "GET a file by id (0)\n";

            $creationTime = rawurlencode(date('c'));

            $url = '/blob/files/'.$this->files[0]['uuid']."?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            $this->assertArrayHasKey($this->files[0]['uuid'], DummyFileSystemService::$fd, 'File data not in dummy store.');
            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);

            $this->assertEquals(200, $response->getStatusCode());
            // TODO: further checks...

            // =======================================================
            // DELETE a file by id
            // =======================================================
            echo "DELETE a file by id\n";

            $url = "/blob/files/{$this->files[0]['uuid']}?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=DELETE";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('DELETE', $url.'&sig='.$token, $options);

            if ($response->getStatusCode() !== 200) {
                echo $response->getContent()."\n";
            }
            $this->assertEquals(204, $response->getStatusCode());
            // TODO: further checks...

            $query = $this->entityManager->getConnection()->createQueryBuilder();
            $this->files = $query->select('*')
                ->from('blob_files')
                ->where("prefix = '$prefix' AND internal_bucket_id = '$bucketID' AND identifier = '{$this->files[0]['uuid']}'")
                ->fetchAllAssociativeIndexed();
            $this->assertEmpty($this->files, 'Files not deleted');

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files\n";

            $url = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);

            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertCount(0, $data['hydra:member'], 'More files than expected');
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: get all with expired token creation time.
     */
    public function testGetExpired(): void
    {
        try {
            $client = static::createClient();
            $configService = $client->getContainer()->get(ConfigurationService::class);

            // =======================================================
            // GET a file with expired token
            // =======================================================
            echo "GET a file with expired token\n";

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c', time() - 3600));
            $prefix = 'playground';

            $url = "/blob/files/?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);

            $this->assertEquals(403, $response->getStatusCode());
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: get and delete blob with unknown id.
     */
    public function testGetDeleteByUnknownId(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $uuid = Uuid::v4();

            // =======================================================
            // GET a file by unknown id
            // =======================================================
            echo "GET a file by unknown id\n";

            $url = "/blob/files/{$uuid}?prefix=$prefix&bucketID=$bucketID&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);

            $this->assertEquals(404, $response->getStatusCode());

            // =======================================================
            // DELETE a file by unknown id
            // =======================================================
            echo "DELETE a file by unknown id\n";

            $url = "/blob/files/{$uuid}?prefix=$prefix&bucketID=$bucketID&creationTime=$creationTime&method=DELETE";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            /** @var Response $response */
            $response = $client->request('DELETE', $url.'&sig='.$token, $options);

            $this->assertEquals(404, $response->getStatusCode());
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: create with wrong method.
     */
    public function testPostWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $retentionDuration = $this->files[0]['retention'];

            $url = "/blob/files/?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime";

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file 0 with wrong method\n";

            $action = 'GET';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            $fileData = $c->__invoke($requestPost);
            $this->fail('    FileData incorrectly saved: '.$fileData->getIdentifier());
        } catch (ApiError $e) {
            $this->assertEquals(405, $e->getStatusCode());
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: get all with wrong method.
     */
    public function testGetAllWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files with wrong method\n";

            $url = "/blob/files/?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=DELETE";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);

            $this->assertEquals(405, $response->getStatusCode());
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: get one with wrong method.
     */
    public function testGETWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // GET one file
            // =======================================================
            echo "GET one file with wrong methods\n";

            $actions = ['GET', 'DELETE', 'DELETE', 'PATCH', 'POST'];

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file (0)\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketID"
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // GET one file with wrong action
            // =======================================================

            foreach ($actions as $action) {
                echo 'GET one file with wrong method '.$action."\n";
                $url = '/blob/files/".$fileData->getIdentifier()?bucketID=$bucketID&creationTime=$creationTime&method='.$action;

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($url),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                /* @noinspection PhpInternalEntityUsedInspection */
                $client->getKernelBrowser()->followRedirects();

                /** @var Response $response */
                $response = $client->request('GET', $url.'&sig='.$token, $options);

                $this->assertEquals(400, $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
            echo "\n";
        }
        echo "\n";
    }

    /**
     * Integration test: PATCH one with wrong method.
     */
    public function testPATCHWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // PATCH one file
            // =======================================================
            echo "PATCH one file with wrong methods\n";

            $actions = ['GET', 'DELETE', 'DELETE', 'GET', 'POST'];

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file (0)\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // PATCH one file with wrong action
            // =======================================================

            foreach ($actions as $action) {
                echo 'PATCH one file with wrong method '.$action."\n";
                $url = '/blob/files/'.$fileData->getIdentifier()."?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action&fileName=$fileName";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($url),
                    'bcs' => $this->generateSha256ChecksumFromUrl('{}'),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                        'Content-Type' => 'application/merge-patch+json',
                    ],
                ];

                /* @noinspection PhpInternalEntityUsedInspection */
                $client->getKernelBrowser()->followRedirects();

                /** @var Response $response */
                $response = $client->request('PATCH', $url.'&sig='.$token, $options);
                echo $url."\n";
                $this->assertEquals(405, $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
            echo "\n";
        }
        echo "\n";
    }

    /**
     * Integration test: PATCH one.
     */
    public function testPATCH(): void
    {
        try {
            $client = $this->withUser('user', [], '42');
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();

            // =======================================================
            // PATCH one file
            // =======================================================
            echo "PATCH one file\n";

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file (0)\n";

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'Authorization' => 'Bearer 42',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketID"
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // PATCH one file
            // =======================================================
            $action = 'PATCH';
            $newFileName = 'Test1.php';
            echo 'PATCH one file with method '.$action."\n";

            $url = '/blob/files/'.$fileData->getIdentifier()."?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl("{\"fileName\":\"$newFileName\"}"),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'Content-Type' => 'multipart/form-data; boundary=--------------------------1',
                ],
                'body' => "----------------------------1\r\nContent-Disposition: form-data; name=\"fileName\"\r\n\r\n$newFileName\r\n----------------------------1--\r\n",
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('PATCH', $url.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());

            echo "GET file to see if its changed\n";

            $action = 'GET';
            $url = '/blob/files/'.$fileData->getIdentifier()."?bucketID=$bucketID&creationTime=$creationTime&method=$action";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options2 = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            // get new token to make it work for some reason
            $client = $this->withUser('user', [], '42');

            /** @var \ApiPlatform\Symfony\Bundle\Test\Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options2);
            echo $response->getContent(false)."\n";
            $this->assertEquals(200, $response->getStatusCode());
            // check if fileName was indeed changed
            $this->assertEquals(json_decode($response->getContent())->fileName, $newFileName);
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
            echo "\n";
        }
        echo "\n";
    }

    /**
     * Integration test: try to call all methods with wrong methods.
     */
    public function testAllMethodsWithWrongActions(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            $actions = ['GET', 'DELETE', 'DELETE', 'GET', 'POST', 'PATCH'];
            $methods = ['GET', 'POST', 'DELETE', 'PATCH'];

            // =======================================================
            // POST a file
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketID"
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Test methods with wrong method
            // =======================================================

            foreach ($methods as $method) {
                foreach ($actions as $action) {
                    if ($method === substr($action, 0, strlen($method)) || ($method === 'POST' && substr($action, 0, 6) === 'CREATE')) {
                        continue;
                    }
                    echo $method.' file with wrong action '.$action."\n";
                    $url = "/blob/files?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action&fileName=$fileName";

                    $payload = [
                        'ucs' => $this->generateSha256ChecksumFromUrl($url),
                    ];

                    $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                    $options = [
                        'headers' => [
                            'Accept' => 'application/ld+json',
                            'HTTP_ACCEPT' => 'application/ld+json',
                            'Content-Type' => ($method === 'PATCH') ? 'application/merge-patch+json' : 'application/ld+json',
                            'Authorization' => 'Bearer 42',
                        ],
                    ];

                    /* @noinspection PhpInternalEntityUsedInspection */
                    $client->getKernelBrowser()->followRedirects();

                    $client = $this->withUser('user', [], '42');

                    /** @var Response $response */
                    $response = $client->request($method, $url.'&sig='.$token, $options);
                    $this->assertEquals(405, $response->getStatusCode());
                }
            }
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
            echo "\n";
        }
        echo "\n";
    }

    /**
     * Integration test: other prefix should remain when deleting one prefix.
     */
    public function testDeletePrefixOtherPrefixShouldRemain(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // POST two files
            // =======================================================

            for ($i = 0; $i < 2; ++$i) {
                $creationTime = rawurlencode(date('c'));
                $fileName = $this->files[$i]['name'];
                $fileHash = $this->files[$i]['hash'];
                $notifyEmail = 'eugen.neuber@tugraz.at';
                $retentionDuration = $this->files[$i]['retention'];
                $action = 'POST';

                $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

                $requestBody = [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ];

                $bodyJson = json_encode($requestBody);

                $data = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($url),
                    'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $data);

                $requestPost = Request::create($url.'&sig='.$token, 'POST',
                    [
                        'fileName' => $fileName,
                        'fileHash' => $fileHash,
                        'notifyEmail' => $notifyEmail,
                        'retentionDuration' => $retentionDuration,
                    ],
                    [],
                    [
                        'file' => new UploadedFile($this->files[$i]['path'], $this->files[$i]['name'], $this->files[$i]['mime']),
                    ],
                    [
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                    "HTTP_ACCEPT: application/ld+json\r\n"
                    .'file='.base64_encode($this->files[$i]['content'])
                );
                $c = new CreateFileDataAction($blobService);
                $c->setContainer($client->getContainer());
                try {
                    $fileData = $c->__invoke($requestPost);
                } catch (\Throwable $e) {
                    echo $e->getTraceAsString()."\n";
                    $this->fail($e->getMessage());
                }

                $this->assertNotNull($fileData);
                $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
                $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
                $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
                $this->assertEquals($this->files[$i]['name'], $fileData->getFileName(), 'File name not correct.');
                $this->files[$i]['uuid'] = $fileData->getIdentifier();
                $this->files[$i]['created'] = $fileData->getDateCreated();
                $this->files[$i]['until'] = $fileData->getExistsUntil();

                $prefix = $prefix.$i;
            }

            // =======================================================
            // DELETE all files in prefix playground
            // =======================================================
            echo "DELETE all files with prefix playground\n";
            $prefix = 'playground';
            $url = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=DELETE";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('DELETE', $url.'&sig='.$token, $options);
            $this->assertEquals(204, $response->getStatusCode());

            // =======================================================
            // GET all files in prefix playground0
            // =======================================================
            echo "GET all files with prefix playground0\n";
            $prefix = 'playground0';
            $url = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            // check if the one created element is there
            $this->assertEquals(1, count(json_decode($response->getContent(), true)['hydra:member']));

            // =======================================================
            // GET all files in prefix playground
            // =======================================================
            echo "GET all files with prefix playground\n";
            $prefix = 'playground';
            $url = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            // check if the created elements were properly deleted
            $this->assertEquals(0, count(json_decode($response->getContent(), true)['hydra:member']));
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: param includeData=1 should lead to a 200 with included base64 data.
     */
    public function testRedirectToBinary(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // POST two files
            // =======================================================

            for ($i = 0; $i < 2; ++$i) {
                $creationTime = rawurlencode(date('c'));
                $fileName = $this->files[$i]['name'];
                $fileHash = $this->files[$i]['hash'];
                $notifyEmail = 'eugen.neuber@tugraz.at';
                $retentionDuration = $this->files[$i]['retention'];
                $action = 'POST';

                $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

                $requestBody = [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ];

                $bodyJson = json_encode($requestBody);

                $data = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($url),
                    'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $data);

                $requestPost = Request::create($url.'&sig='.$token, 'POST',
                    [
                        'fileName' => $fileName,
                        'fileHash' => $fileHash,
                        'notifyEmail' => $notifyEmail,
                        'retentionDuration' => $retentionDuration,
                    ],
                    [],
                    [
                        'file' => new UploadedFile($this->files[$i]['path'], $this->files[$i]['name'], $this->files[$i]['mime']),
                    ],
                    [
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                    "HTTP_ACCEPT: application/ld+json\r\n"
                    .'file='.base64_encode($this->files[$i]['content'])
                );
                $c = new CreateFileDataAction($blobService);
                $c->setContainer($client->getContainer());
                try {
                    $fileData = $c->__invoke($requestPost);
                } catch (\Throwable $e) {
                    echo $e->getTraceAsString()."\n";
                    $this->fail($e->getMessage());
                }

                $this->assertNotNull($fileData);
                $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
                $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
                $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
                $this->assertEquals($this->files[$i]['name'], $fileData->getFileName(), 'File name not correct.');
                $this->files[$i]['uuid'] = $fileData->getIdentifier();
                $this->files[$i]['created'] = $fileData->getDateCreated();
                $this->files[$i]['until'] = $fileData->getExistsUntil();
            }

            // =======================================================
            // GET all files in prefix playground
            // =======================================================
            echo "GET all files with prefix playground\n";
            $url = '/blob/files/'.$this->files[0]['uuid']."?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&includeData=1&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            // check if the one created element is there
            $members = json_decode($response->getContent(), true);
            $this->assertEquals('data:text/x-php;base64', substr($members['contentUrl'], 0, strlen('data:text/x-php;base64')));
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: delete all with wrong action.
     */
    public function testDELETEWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // DELETE all files
            // =======================================================
            echo "DELETE all files\n";

            $url = "/blob/files/?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestDelete = Request::create($url.'&sig='.$token, 'DELETE', [], [], [],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
            );
            $d = new DeleteFileDatasByPrefix($blobService);
            $d->__invoke($requestDelete);
            $this->fail('    Delete by prefix incorrectly succeeded');
        } catch (ApiError $e) {
            $this->assertEquals(405, $e->getStatusCode());
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: missing param should lead to a 400 error.
     */
    public function testOperationsWithMissingParameters(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketID"
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // GET, DELETE all files in prefix playground without creationTime, sig, action, bucketID, prefix
            // =======================================================
            echo "GET, DELETE all files with prefix playground with missing params\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            foreach ($actions as $action) {
                $params = [
                    0 => "bucketID=$bucketID",
                    1 => "creationTime=$creationTime",
                    2 => "method=$action",
                    3 => 'sig=',
                ];

                for ($i = 0; $i < count($params) - 1; ++$i) {
                    $baseUrl = '/blob/files';

                    // first param needs ?
                    $connector = '?';

                    for ($j = 0; $j < count($params) - 1; ++$j) {
                        if ($i === $j) {
                            continue;
                        }

                        $baseUrl = $baseUrl.$connector.$params[$j];
                        // all other params need &
                        $connector = '&';
                    }

                    $payload = [
                        'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    ];

                    if ($i !== count($params) - 1) {
                        $baseUrl = $baseUrl.$connector.$params[$j];
                    }

                    $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                    $options = [
                        'headers' => [
                            'Authorization' => 'Bearer 42',
                            'Accept' => 'application/ld+json',
                            'HTTP_ACCEPT' => 'application/ld+json',
                        ],
                    ];

                    echo 'Test url '.$baseUrl.$token;
                    $client = $this->withUser('user', [], '42');

                    /** @var Response $response */
                    $response = $client->request($action, $baseUrl.$token, $options);
                    $this->assertEquals(400, $response->getStatusCode());
                }
            }

            // =======================================================
            // GET, DELETE all files in prefix playground without creationTime, sig, action, bucketID, prefix
            // =======================================================
            echo "GET, DELETE one file with prefix playground with missing params\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            foreach ($actions as $action) {
                $params = [
                    0 => "bucketID=$bucketID",
                    1 => "creationTime=$creationTime",
                    2 => "method=$action",
                    3 => 'sig=',
                ];

                for ($i = 0; $i < count($params) - 1; ++$i) {
                    $baseUrl = '/blob/files/'.$this->files[0]['uuid'];

                    // first param needs ?
                    $connector = '?';

                    for ($j = 0; $j < count($params) - 1; ++$j) {
                        if ($i === $j) {
                            continue;
                        }

                        $baseUrl = $baseUrl.$connector.$params[$j];
                        // all other params need &
                        $connector = '&';
                    }

                    $payload = [
                        'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    ];

                    if ($i !== count($params) - 1) {
                        $baseUrl = $baseUrl.$connector.$params[$j];
                    }

                    $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                    $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                    $options = [
                        'headers' => [
                            'Authorization' => 'Bearer 42',
                            'Accept' => 'application/ld+json',
                            'HTTP_ACCEPT' => 'application/ld+json',
                        ],
                        'extra' => [
                            'files' => [
                                'file' => $file,
                            ],
                        ],
                    ];

                    $client = $this->withUser('user', [], '42');

                    /** @var Response $response */
                    $response = $client->request($action, $baseUrl.$token, $options);
                    // echo $response->getContent()."\n";
                    $this->assertEquals(400, $response->getStatusCode());
                }
            }

            // =======================================================
            // POST file in prefix playground without creationTime, sig, action, bucketID, prefix, fileName, fileHash
            // =======================================================

            echo "POST one file with prefix playground with missing params\n";

            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];

            $params = [
                0 => "bucketID=$bucketID",
                1 => "prefix=$prefix",
                2 => "creationTime=$creationTime",
                3 => 'method=POST',
                4 => 'sig=',
            ];

            for ($i = 0; $i < count($params) - 1; ++$i) {
                $baseUrl = '/blob/files';

                // first param needs ?
                $connector = '?';

                for ($j = 0; $j < count($params) - 1; ++$j) {
                    if ($i === $j) {
                        continue;
                    }

                    $baseUrl = $baseUrl.$connector.$params[$j];
                    // all other params need &
                    $connector = '&';
                }

                $requestBody = [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ];

                $bodyJson = json_encode($requestBody);

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
                ];

                if ($i !== count($params) - 1) {
                    $baseUrl = $baseUrl.$connector.$params[$j];
                }

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer 42',
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                    'extra' => [
                        [
                            'name' => 'file',
                            'contents' => $this->files[0]['content'],
                            'filename' => $fileName,
                        ],
                        [
                            'name' => 'fileName',
                            'contents' => $fileName,
                        ],
                        [
                            'name' => 'fileHash',
                            'contents' => $fileHash,
                        ],
                        [
                            'name' => 'notifyEmail',
                            'contents' => $notifyEmail,
                        ],
                        [
                            'name' => 'retentionDuration',
                            'contents' => $retentionDuration,
                        ],
                    ],
                ];

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.$token, $options);
                $this->assertEquals(400, $response->getStatusCode());
            }

            // =======================================================
            // PATCH file in prefix playground without creationTime, sig, action, bucketID, prefix, fileName, fileHash
            // =======================================================

            echo "PATCH one file with prefix playground with missing params\n";

            $fileName = $this->files[0]['name'];

            $params = [
                0 => "bucketID=$bucketID",
                1 => "prefix=$prefix",
                2 => "creationTime=$creationTime",
                3 => 'method=PATCH',
                4 => "fileName=$fileName",
                5 => 'sig=',
            ];

            for ($i = 0; $i < count($params) - 1; ++$i) {
                $baseUrl = '/blob/files/'.$this->files[0]['uuid'];

                // first param needs ?
                $connector = '?';

                for ($j = 0; $j < count($params) - 1; ++$j) {
                    if ($i === $j) {
                        continue;
                    }

                    $baseUrl = $baseUrl.$connector.$params[$j];
                    // all other params need &
                    $connector = '&';
                }

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    'bcs' => $this->generateSha256ChecksumFromUrl('{}'),
                ];

                if ($i !== count($params) - 1) {
                    $baseUrl = $baseUrl.$connector.$params[$j];
                }

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                        'Content-Type' => 'application/merge-patch+json',
                    ],
                    'body' => '{}',
                ];

                /** @var Response $response */
                $response = $client->request('PATCH', $baseUrl, $options);
                $this->assertEquals(400, $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: correct signature with wrong checksum should lead to a error.
     */
    public function testOperationsWithCorrectSignatureButWrongChecksum(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check all operations with wrong checksum (in that case missing the first / in the cs generation)
            // =======================================================
            echo "Check GET, DELETE operations with wrong checksum\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "blob/files/$identifier?bucketID=$bucketID&creationTime=$creationTime&method=$action";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer 42',
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];
                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request($action, $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check GET, DELETE operations with wrong checksum\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);
                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request($action, $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check POST operation with wrong checksum\n";

            $actions = [
                0 => 'POST',
            ];

            foreach ($actions as $action) {
                $fileHash = $this->files[0]['hash'];
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action&fileName=test.txt&fileHash=$fileHash";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.'&sig='.$token,
                    [
                        'headers' => [
                            'Content-Type' => 'form-data',
                            'Authorization' => 'Bearer 42',
                        ],
                        'extra' => [
                            'files' => [
                                'file' => $file,
                            ],
                            'parameters' => [
                                'fileName' => $fileName,
                                'fileHash' => $fileHash,
                                'notifyEmail' => $notifyEmail,
                                'retentionDuration' => $retentionDuration,
                            ],
                        ],
                    ]
                );
                $this->assertEquals(403, $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: correct checksum with wrong signature should lead to a error.
     */
    public function testOperationsWithCorrectChecksumButWrongSignature(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check all operations with wrong signature
            // =======================================================
            echo "Check GET, DELETE operations with wrong signature\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files/$identifier?bucketID=$bucketID&creationTime=$creationTime&method=$action";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];
                // get key of wrong bucket
                $bucket = $configService->getBuckets()[1];
                $secret = $bucket->getKey();

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer 42',
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request($action, $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check GET, DELETE operations with wrong signature\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];
                // get key of wrong bucket
                $bucket = $configService->getBuckets()[1];
                $secret = $bucket->getKey();

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request($action, $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check POST operation with wrong checksum\n";

            $actions = [
                0 => 'POST',
            ];

            foreach ($actions as $action) {
                $fileHash = $this->files[0]['hash'];
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action&fileName=test.txt&fileHash=$fileHash";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    'bcs' => $this->generateSha256ChecksumFromUrl('{}'),
                ];
                // get key of wrong bucket
                $bucket = $configService->getBuckets()[1];
                $secret = $bucket->getKey();

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.'&sig='.$token,
                    [
                        'headers' => [
                            'Authorization' => 'Bearer 42',
                            'Content-Type' => 'form-data',
                        ],
                        'extra' => [
                            'files' => [
                                'file' => $file,
                            ],
                            'parameters' => [
                                'fileName' => $fileName,
                                'fileHash' => $fileHash,
                                'notifyEmail' => $notifyEmail,
                                'retentionDuration' => $retentionDuration,
                            ],
                        ],
                    ]
                );
                $this->assertEquals(403, $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: overdue creationtime should return an error.
     */
    public function testOperationsWithOverdueCreationTime(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketID"
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check all operations with overdue creationTime
            // =======================================================
            echo "Check GET, DELETE operations with overdue creationTime\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            $creationTime = rawurlencode(date('c', time() - 120));

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files/$identifier?bucketID=$bucketID&creationTime=$creationTime&method=$action";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer 42',
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request($action, $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check GET, DELETE operations with overdue creationTime\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request($action, $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check POST operation with overdue creationTime\n";

            $actions = [
                0 => 'POST',
            ];

            foreach ($actions as $action) {
                $fileHash = $this->files[0]['hash'];
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action&fileName=test.txt&fileHash=$fileHash";

                $requestBody = [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ];

                $bodyJson = json_encode($requestBody);

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.'&sig='.$token,
                    [
                        'headers' => [
                            'Content-Type' => 'form-data',
                            'Authorization' => 'Bearer 42',
                        ],
                        'extra' => [
                            'files' => [
                                'file' => $file,
                            ],
                            'parameters' => [
                                'fileName' => $fileName,
                                'fileHash' => $fileHash,
                                'notifyEmail' => $notifyEmail,
                                'retentionDuration' => $retentionDuration,
                            ],
                        ],
                    ]
                );
                $this->assertEquals(403, $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: Unconfigured bucket should return an error.
     */
    public function testOperationsWithUnconfiguredBucket(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check all operations with unconfigured bucket
            // =======================================================
            echo "Check GET, DELETE operations with unconfigured bucket\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            $bucketID = '2468';

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files/$identifier?bucketID=$bucketID&creationTime=$creationTime&method=$action";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer 42',
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request($action, $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(400, $response->getStatusCode());
            }

            echo "Check GET, DELETE operations with unconfigured bucket\n";

            $actions = [
                0 => 'GET',
                1 => 'DELETE',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request($action, $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(400, $response->getStatusCode());
            }

            echo "Check POST operation with unconfigured bucket\n";

            $actions = [
                0 => 'POST',
            ];

            foreach ($actions as $action) {
                $fileHash = $this->files[0]['hash'];
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=$action&fileName=test.txt&fileHash=$fileHash";

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    'bcs' => $this->generateSha256ChecksumFromUrl('{}'),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.'&sig='.$token,
                    [
                        'headers' => [
                            'Content-Type' => 'form-data',
                            'Authorization' => 'Bearer 42',
                        ],
                        'extra' => [
                            'files' => [
                                'file' => $file,
                            ],
                        ],
                    ]
                );
                $this->assertEquals(400, $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: Buckt data should be separate.
     */
    public function testGETPOSTDELETEforDifferentBuckets(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketID"
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check GET of other bucket shouldnt return file
            // =======================================================
            echo "Check GET on other bucket shouldnt return file\n";

            $bucketID = 'test-bucket-2';
            // get key of wrong bucket
            $bucket = $configService->getBuckets()[1];
            $secret = $bucket->getKey();

            // url with missing / at the beginning to create a wrong checksum
            $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $baseUrl.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals([], json_decode($response->getContent(), true)['hydra:member']);

            // =======================================================
            // POST file in other bucket
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketID"
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // DELETE all files in other bucket
            // =======================================================

            echo "Check DELETE on other bucket shouldnt delete original bucket\n";

            $bucketID = 'test-bucket-2';
            // get key of wrong bucket
            $bucket = $configService->getBuckets()[1];
            $secret = $bucket->getKey();

            // url with missing / at the beginning to create a wrong checksum
            $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=DELETE";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('DELETE', $baseUrl.'&sig='.$token, $options);
            $this->assertEquals(204, $response->getStatusCode());

            // =======================================================
            // Check GET of original bucket should still return file
            // =======================================================
            echo "Check GET on original bucket should still return file\n";

            $bucketID = 'test-bucket';
            // get key of wrong bucket
            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();

            // url with missing / at the beginning to create a wrong checksum
            $baseUrl = "/blob/files?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $baseUrl.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals(1, count(json_decode($response->getContent(), true)['hydra:member']));
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: endpoint /id/download should lead to a 200 with included binary data.
     */
    public function testBinaryDownload(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // POST two files
            // =======================================================

            for ($i = 0; $i < 2; ++$i) {
                $creationTime = rawurlencode(date('c'));
                $fileName = $this->files[$i]['name'];
                $fileHash = $this->files[$i]['hash'];
                $notifyEmail = 'eugen.neuber@tugraz.at';
                $retentionDuration = $this->files[$i]['retention'];
                $action = 'POST';

                $url = "/blob/files/?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

                $requestBody = [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ];

                $bodyJson = json_encode($requestBody);

                $data = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($url),
                    'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $data);

                $requestPost = Request::create($url.'&sig='.$token, 'POST',
                    [
                        'fileName' => $fileName,
                        'fileHash' => $fileHash,
                        'notifyEmail' => $notifyEmail,
                        'retentionDuration' => $retentionDuration,
                    ],
                    [],
                    [
                        'file' => new UploadedFile($this->files[$i]['path'], $this->files[$i]['name'], $this->files[$i]['mime']),
                    ],
                    [
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                    "HTTP_ACCEPT: application/ld+json\r\n"
                    .'file='.base64_encode($this->files[$i]['content'])
                );
                $c = new CreateFileDataAction($blobService);
                $c->setContainer($client->getContainer());
                try {
                    $fileData = $c->__invoke($requestPost);
                } catch (\Throwable $e) {
                    echo $e->getTraceAsString()."\n";
                    $this->fail($e->getMessage());
                }

                $this->assertNotNull($fileData);
                $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
                $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
                $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
                $this->assertEquals($this->files[$i]['name'], $fileData->getFileName(), 'File name not correct.');
                $this->files[$i]['uuid'] = $fileData->getIdentifier();
                $this->files[$i]['created'] = $fileData->getDateCreated();
                $this->files[$i]['until'] = $fileData->getExistsUntil();
            }

            // =======================================================
            // GET ONE file in prefix playground
            // =======================================================
            echo "GET download one file with prefix playground\n";
            $url = '/blob/files/'.$this->files[0]['uuid']."/download?bucketID=$bucketID&prefix=$prefix&creationTime=$creationTime&includeData=1&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('string', gettype($response->getContent()));
            // check if the one created element is there
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: missing param should lead to a 400 error.
     */
    public function testDownloadWithInvalidOrMissingParameters(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketID = $bucket->getBucketID();
            $creationTime = rawurlencode(date('c'));
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = rawurlencode(date('c'));
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'POST';

            $url = "/blob/files/download?bucketID=$bucketID&creationTime=$creationTime&prefix=$prefix&method=$action";

            $requestBody = [
                'fileName' => $fileName,
                'fileHash' => $fileHash,
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $retentionDuration,
            ];

            $bodyJson = json_encode($requestBody);

            $data = [
                'ucs' => $this->generateSha256ChecksumFromUrl($url),
                'bcs' => $this->generateSha256ChecksumFromUrl($bodyJson),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST',
                [
                    'fileName' => $fileName,
                    'fileHash' => $fileHash,
                    'notifyEmail' => $notifyEmail,
                    'retentionDuration' => $retentionDuration,
                ],
                [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
            );
            $c = new CreateFileDataAction($blobService);
            $c->setContainer($client->getContainer());
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasProperty('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(\uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // GET download one file in prefix playground without creationTime, sig, action, bucketID
            // =======================================================
            echo "GET download one file with prefix playground with missing params\n";

            $params = [
                0 => "bucketID=$bucketID",
                1 => "creationTime=$creationTime",
                2 => 'method=GET',
                3 => 'sig=',
            ];

            for ($i = 0; $i < count($params) - 1; ++$i) {
                $baseUrl = '/blob/files/'.$this->files[0]['uuid'].'/download';

                // first param needs ?
                $connector = '?';

                for ($j = 0; $j < count($params) - 1; ++$j) {
                    if ($i === $j) {
                        continue;
                    }

                    $baseUrl = $baseUrl.$connector.$params[$j];
                    // all other params need &
                    $connector = '&';
                }

                $payload = [
                    'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                if ($i !== count($params) - 1) {
                    $baseUrl = $baseUrl.$connector.$params[$j];
                }

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                $options = [
                    'headers' => [
                        'Authorization' => 'Bearer 42',
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                    'extra' => [
                        'files' => [
                            'file' => $file,
                        ],
                    ],
                ];

                $client = $this->withUser('user', [], '42');

                /** @var Response $response */
                $response = $client->request('GET', $baseUrl.$token, $options);
                // echo $response->getContent()."\n";
                $this->assertEquals(400, $response->getStatusCode());
            }

            // =======================================================
            // Check download operations with overdue creationTime
            // =======================================================
            echo "Check download with overdue creationTime\n";

            $creationTime = rawurlencode(date('c', time() - 120));

            $baseUrl = '/blob/files/'.$this->files[0]['uuid']."?bucketID=$bucketID&creationTime=$creationTime&method=GET";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer 42',
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $baseUrl.'&sig='.$token, $options);
            $this->assertEquals(403, $response->getStatusCode());

            // =======================================================
            // Check download with invalid action
            // =======================================================
            echo "Check download with invalid action\n";

            $creationTime = rawurlencode(date('c'));

            $baseUrl = '/blob/files/'.$this->files[0]['uuid']."?bucketID=$bucketID&creationTime=$creationTime&method=DELETE";

            $payload = [
                'ucs' => $this->generateSha256ChecksumFromUrl($baseUrl),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $client = $this->withUser('user', [], '42');

            /** @var Response $response */
            $response = $client->request('GET', $baseUrl.'&sig='.$token, $options);
            $this->assertEquals(405, $response->getStatusCode());
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    private function generateSha256ChecksumFromUrl($url): string
    {
        return hash('sha256', $url);
    }
}
