<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Core\Bridge\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Controller\CreateFileDataAction;
use Dbp\Relay\BlobBundle\Controller\DeleteFileDatasByPrefix;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\BlobBundle\Helper\PoliciesStruct;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\Service\ConfigurationService;
use Dbp\Relay\BlobBundle\Service\DatasystemProviderServiceInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\TestUtils\UserAuthTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Exception;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use function uuid_is_valid;

class DummyFileSystemService implements DatasystemProviderServiceInterface
{
    public static $fd = [];
    public static $data = [];

    public function saveFile(FileData $fileData): ?FileData
    {
        self::$fd[$fileData->getIdentifier()] = $fileData;
        self::$data[$fileData->getIdentifier()] = $fileData->getFile();

        return $fileData;
    }

    public function renameFile(FileData $fileData): ?FileData
    {
        self::$fd[$fileData->getIdentifier()] = $fileData;

        return $fileData;
    }

    public function getLink(FileData $fileData, PoliciesStruct $policiesStruct): ?FileData
    {
        $identifier = $fileData->getIdentifier();
        if (!isset(self::$fd[$identifier])) {
            echo "    DummyFileSystemService::getLink($identifier): not found!\n";

            return null;
        }

        $fileData->setContentUrl("https://localhost.lan/link/$identifier");
        self::$fd[$identifier] = $fileData;

        return self::$fd[$identifier];
    }

    public function removeFile(FileData $fileData): bool
    {
        unset(self::$fd[$fileData->getIdentifier()]);
        unset(self::$data[$fileData->getIdentifier()]);

        return true;
    }
}

class CurlGetTest extends ApiTestCase
{
    use UserAuthTrait;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var array[] */
    private $files;

    /**
     * @throws Exception
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
    }

    /**
     * Integration test for get all for a prefix with empty result.
     */
    public function testGet(): void
    {
        try {
            $client = static::createClient();
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getPublicKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';
            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETALL',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";
            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
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
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getPublicKey();
            $bucketId = $bucket->getIdentifier();

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file[0]\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'fileName' => $this->files[0]['name'],
                'fileHash' => $this->files[0]['hash'],
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $this->files[0]['retention'],
                'additionalMetadata' => '',
                'action' => 'CREATEONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
//                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                    ."HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
                    .'file='.base64_encode($this->files[0]['content'])
                    ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketId"
            );
            $c = new CreateFileDataAction($blobService);
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasAttribute('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();
            echo "    file identifier='{$this->files[0]['uuid']}' stored.\n";

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files (only 0)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETALL',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var \ApiPlatform\Core\Bridge\Symfony\Bundle\Test\Response $response */
            $response = $client->request('GET', $url, $options);
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
            echo "POST file [1]\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";
            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'fileName' => $this->files[1]['name'],
                'fileHash' => $this->files[1]['hash'],
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $this->files[1]['retention'],
                'additionalMetadata' => '',
                'action' => 'CREATEONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[1]['path'], $this->files[1]['name'], $this->files[1]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
//                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                    ."HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
                    .'file='.base64_encode($this->files[1]['content'])
                    ."&fileName={$this->files[1]['name']}&prefix=$prefix&bucketID=$bucketId"
            );
            $c = new CreateFileDataAction($blobService);
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasAttribute('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[1]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[1]['uuid'] = $fileData->getIdentifier();
            $this->files[1]['created'] = $fileData->getDateCreated();
            $this->files[1]['until'] = $fileData->getExistsUntil();

            echo "    file identifier='{$this->files[1]['uuid']}' stored.\n";
            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files (0 and 1)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETALL',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);
            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
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

            $creationTime = date('U');
            $prefix = 'playground';
            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'DELETEALL',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestDelete = Request::create($url, 'DELETE', [], [], [],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                ."HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
            );
            $d = new DeleteFileDatasByPrefix($blobService);
            try {
                $d->__invoke($requestDelete);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $query = $this->entityManager->getConnection()->createQueryBuilder();
            $this->files = $query->select('*')
                ->from('blob_files')
                ->where("prefix = '$prefix' AND bucket_id = '$bucketId'")
                ->fetchAllAssociativeIndexed();
            $this->assertEmpty($this->files, 'Files not deleted');

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files (empty)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETALL',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);
            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /** @var Response $response */
            $response = $client->request('GET', $url, $options);

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
//            $client = $this->withUser('foobar');
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getPublicKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file 0\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'fileName' => $this->files[0]['name'],
                'fileHash' => $this->files[0]['hash'],
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $this->files[0]['retention'],
                'additionalMetadata' => '',
                'action' => 'CREATEONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
//                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                ."HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketId"
            );
            $c = new CreateFileDataAction($blobService);
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString()."\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasAttribute('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($this->files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $this->files[0]['uuid'] = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();
            echo "    file identifier='{$this->files[0]['uuid']}' stored.\n";

            // =======================================================
            // GET a file by id
            // =======================================================
            echo "GET a file by id (0)\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            $this->assertArrayHasKey($this->files[0]['uuid'], DummyFileSystemService::$fd, 'File data not in dummy store.');
            /** @var Response $response */
            $response = $client->request('GET', $url."/{$this->files[0]['uuid']}", $options);

            $this->assertEquals(200, $response->getStatusCode());
            // TODO: further checks...

            // =======================================================
            // DELETE a file by id
            // =======================================================
            echo "DELETE a file by id\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'DELETEONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $url = "/blob/files/{$this->files[0]['uuid']}?prefix=$prefix&bucketID=$bucketId&creationTime=$creationTime";
            /** @var Response $response */
            $response = $client->request('DELETE', $url, $options);

            if ($response->getStatusCode() !== 200) {
                echo $response->getContent()."\n";
            }
            $this->assertEquals(204, $response->getStatusCode());
            // TODO: further checks...

            $query = $this->entityManager->getConnection()->createQueryBuilder();
            $this->files = $query->select('*')
                ->from('blob_files')
                ->where("prefix = '$prefix' AND bucket_id = '$bucketId' AND identifier = '{$this->files[0]['uuid']}'")
                ->fetchAllAssociativeIndexed();
            $this->assertEmpty($this->files, 'Files not deleted');

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETALL',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";
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
            $secret = $bucket->getPublicKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = strtotime('-1 hour');
            $prefix = 'playground';
            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETALL',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";
            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url, $options);

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
            $secret = $bucket->getPublicKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';
            $uuid = Uuid::v4();

            // =======================================================
            // GET a file by unknown id
            // =======================================================
            echo "GET a file by unknown id\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $url = "/blob/files/{$uuid}?prefix=$prefix&bucketID=$bucketId&creationTime=$creationTime";
            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url, $options);

            $this->assertEquals(404, $response->getStatusCode());

            // =======================================================
            // DELETE a file by unknown id
            // =======================================================
            echo "DELETE a file by unknown id\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'DELETEONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects(false);

            $url = "/blob/files/{$uuid}?prefix=$prefix&bucketID=$bucketId&creationTime=$creationTime";
            /** @var Response $response */
            $response = $client->request('DELETE', $url, $options);

            $this->assertEquals(404, $response->getStatusCode());
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: create with wrong action.
     */
    public function testPostWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getPublicKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file 0 with wrong action\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'fileName' => $this->files[0]['name'],
                'fileHash' => $this->files[0]['hash'],
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $this->files[0]['retention'],
                'additionalMetadata' => '',
                'action' => 'GETONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
//                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                ."HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketId"
            );
            $c = new CreateFileDataAction($blobService);
            $fileData = $c->__invoke($requestPost);

            $this->fail('    FileData incorrectly saved: '.$fileData->getIdentifier());
        } catch (ApiError $e) {
            $this->assertEquals($e->getStatusCode(), 403);
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: get all with wrong action.
     */
    public function testGetAllWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getPublicKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files with wrong action\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'DELETEONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";
            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url, $options);

            $this->assertEquals(403, $response->getStatusCode());
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }

    /**
     * Integration test: delete all with wrong action.
     */
    public function testDeleteAllWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getPublicKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // DELETE all files
            // =======================================================
            echo "DELETE all files with wrong action\n";

            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => 'GETONE',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";
            $requestDelete = Request::create($url, 'DELETE', [], [], [],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                ."HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
            );
            $d = new DeleteFileDatasByPrefix($blobService);
            $d->__invoke($requestDelete);
            $this->fail('    Delete by prefix incorrectly succeeded');
        } catch (ApiError $e) {
            $this->assertEquals($e->getStatusCode(), 403);
        } catch (\Throwable $e) {
            echo $e->getTraceAsString()."\n";
            $this->fail($e->getMessage());
        }
    }
}