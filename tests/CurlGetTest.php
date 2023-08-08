<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

    public function getBase64Data(FileData $fileData, PoliciesStruct $policiesStruct): FileData
    {
        $identifier = $fileData->getIdentifier();
        if (!isset(self::$fd[$identifier])) {
            echo "    DummyFileSystemService::getLink($identifier): not found!\n";
        }

        // build binary response
        $file = file_get_contents(self::$data[$identifier]->getRealPath());
        $mimeType = self::$data[$identifier]->getMimeType();

        $filename = $fileData->getFileName();

        $fileData->setContentUrl('data:'.$mimeType.';base64,'.base64_encode($file));
        self::$fd[$identifier] = $fileData;

        return self::$fd[$identifier];
    }

    public function getBinaryResponse(FileData $fileData, PoliciesStruct $policiesStruct): Response
    {
        $identifier = $fileData->getIdentifier();
        if (!isset(self::$fd[$identifier])) {
            echo "    DummyFileSystemService::getLink($identifier): not found!\n";
        }

        // build binary response
        $response = new BinaryFileResponse(self::$data[$identifier]->getRealPath());
        $response->headers->set('Content-Type', self::$data[$identifier]->getMimeType());
        $filename = $fileData->getFileName();

        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
    }

    public function removeFile(FileData $fileData): bool
    {
        unset(self::$fd[$fileData->getIdentifier()]);
        unset(self::$data[$fileData->getIdentifier()]);

        return true;
    }

    public function generateChecksumFromFileData($fileData, $validUntil = ''): ?string
    {
        // if no validUntil is given, use bucket link expiry time per default
        if ($validUntil === '') {
            $now = new \DateTimeImmutable('now', new DateTimeZone('UTC'));
            $now = $now->add(new \DateInterval($fileData->getBucket()->getLinkExpireTime()));
            $validUntil = $now->format('c');
        }

        // create url to hash
        $contentUrl = '/blob/filesystem/'.$fileData->getIdentifier().'?validUntil='.$validUntil;

        // create hmac sha256 keyed hash
        //$cs = hash_hmac('sha256', $contentUrl, $fileData->getBucket()->getKey());

        // create sha256 hash
        $cs = hash('sha256', $contentUrl);

        return $cs;
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
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';
            $action = 'GETALL';
            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'action' => $action,
            ];

            //$token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $url = "/blob/files?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);
            $url = $url.'&sig='.$token;
            $options = [
                'headers' => [
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
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file (0)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files (0)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $action = 'GETALL';
            $url = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
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

            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $fileName = $this->files[1]['name'];
            $fileHash = $this->files[1]['hash'];
            $retentionDuration = $this->files[1]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[1]['path'], $this->files[1]['name'], $this->files[1]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files (0 and 1)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $action = 'GETALL';
            $url = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
            $action = 'DELETEALL';
            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestDelete = Request::create($url.'&sig='.$token, 'DELETE', [], [], [],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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
            $action = 'GETALL';
            $url = "/blob/files?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);
            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

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
//            $client = $this->withUser('foobar');
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $action = 'CREATEONE';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $retentionDuration = $this->files[0]['retention'];

            $url = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&fileName=$fileName&fileHash=$fileHash&retentionDuration=$retentionDuration&action=$action";

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file 0\n";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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

            $creationTime = date('U');

            $url = '/blob/files/'.$this->files[0]['uuid']."?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=GETONE";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
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

            $url = "/blob/files/{$this->files[0]['uuid']}?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=DELETEONE";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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

            $url = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=GETALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
            $bucketId = $bucket->getIdentifier();
            $creationTime = strtotime('-1 hour');
            $prefix = 'playground';

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=GETALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';
            $uuid = Uuid::v4();

            // =======================================================
            // GET a file by unknown id
            // =======================================================
            echo "GET a file by unknown id\n";

            $url = "/blob/files/{$uuid}?prefix=$prefix&bucketID=$bucketId&creationTime=$creationTime&action=GETONE";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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

            $url = "/blob/files/{$uuid}?prefix=$prefix&bucketID=$bucketId&creationTime=$creationTime&action=DELETEONE";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $retentionDuration = $this->files[0]['retention'];

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file 0 with wrong action\n";

            $action = 'GETONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                .'file='.base64_encode($this->files[0]['content'])
                ."&fileName={$this->files[0]['name']}&prefix=$prefix&bucketID=$bucketId"
            );
            $c = new CreateFileDataAction($blobService);
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
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // GET all files
            // =======================================================
            echo "GET all files with wrong action\n";

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=DELETEONE";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
     * Integration test: get one with wrong action.
     */
    public function testGetOneWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // GET one file
            // =======================================================
            echo "GET one file with wrong actions\n";

            $actions = ['GETALL', 'DELETEONE', 'DELETEALL', 'PUTONE', 'CREATEONE'];

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file (0)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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

            // =======================================================
            // GET one file with wrong action
            // =======================================================

            foreach ($actions as $action) {
                echo 'GET one file with wrong action '.$action."\n";
                $url = '/blob/files/".$fileData->getIdentifier()'.'?bucketID=$bucketId&creationTime=$creationTime&action='.$action;

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($url),
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
     * Integration test: put one with wrong action.
     */
    public function testPutOneWithWrongAction(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // PUT one file
            // =======================================================
            echo "PUT one file with wrong actions\n";

            $actions = ['GETALL', 'DELETEONE', 'DELETEALL', 'GETONE', 'CREATEONE'];

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file (0)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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

            // =======================================================
            // PUT one file with wrong action
            // =======================================================

            foreach ($actions as $action) {
                echo 'PUT one file with wrong action '.$action."\n";
                $url = '/blob/files/'.$fileData->getIdentifier()."?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($url),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                        'Content-Type' => 'application/json',
                    ],
                ];

                /* @noinspection PhpInternalEntityUsedInspection */
                $client->getKernelBrowser()->followRedirects();

                /** @var Response $response */
                $response = $client->request('PUT', $url.'&sig='.$token, $options);
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
     * Integration test: put one.
     */
    public function testPutOne(): void
    {
        try {
            $client = static::createClient();
            /** @var BlobService $blobService */
            $blobService = $client->getContainer()->get(BlobService::class);
            $configService = $client->getContainer()->get(ConfigurationService::class);

            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // PUT one file
            // =======================================================
            echo "PUT one file\n";

            $actions = ['GETALL', 'DELETEONE', 'DELETEALL', 'GETONE', 'CREATEONE'];

            // =======================================================
            // POST a file
            // =======================================================
            echo "POST file (0)\n";

            $creationTime = date('U');
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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

            // =======================================================
            // PUT one file
            // =======================================================
            $action = 'PUTONE';
            $newFileName = 'Test1.php';
            echo 'PUT one file with action '.$action."\n";

            $url = '/blob/files/'.$fileData->getIdentifier()."?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$newFileName";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'Content-Type' => 'application/json',
                ],
                'body' => '{}',
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('PUT', $url.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());

            $action = 'GETONE';
            $url = '/blob/files/'.$fileData->getIdentifier()."?bucketID=$bucketId&creationTime=$creationTime&action=$action";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'Content-Type' => 'application/json',
                ],
            ];

            /* @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url.'&sig='.$token, $options);
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
     * Integration test: try to call all actions with wrong methods.
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
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            $actions = ['GETALL', 'DELETEONE', 'DELETEALL', 'GETONE', 'CREATEONE', 'PUTONE'];
            $methods = ['GET', 'POST', 'DELETE', 'PUT'];

            // =======================================================
            // POST a file
            // =======================================================

            $creationTime = date('U');
            $prefix = 'playground';
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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

            // =======================================================
            // Test methods with wrong action
            // =======================================================

            foreach ($methods as $method) {
                foreach ($actions as $action) {
                    if ($method === substr($action, 0, strlen($method)) || ($method === 'POST' && substr($action, 0, 6) === 'CREATE')) {
                        continue;
                    }
                    echo $method.' file with wrong action '.$action."\n";
                    $url = "/blob/files?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName";

                    $payload = [
                        'cs' => $this->generateSha256ChecksumFromUrl($url),
                    ];

                    $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                    $options = [
                        'headers' => [
                            'Accept' => 'application/ld+json',
                            'HTTP_ACCEPT' => 'application/ld+json',
                            'Content-Type' => 'application/json',
                        ],
                    ];

                    /* @noinspection PhpInternalEntityUsedInspection */
                    $client->getKernelBrowser()->followRedirects();

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
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // POST two files
            // =======================================================

            for ($i = 0; $i < 2; ++$i) {
                $creationTime = date('U');
                $fileName = $this->files[$i]['name'];
                $fileHash = $this->files[$i]['hash'];
                $notifyEmail = 'eugen.neuber@tugraz.at';
                $retentionDuration = $this->files[$i]['retention'];
                $action = 'CREATEONE';

                $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

                $data = [
                    'cs' => $this->generateSha256ChecksumFromUrl($url),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $data);

                $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                    [
                        'file' => new UploadedFile($this->files[$i]['path'], $this->files[$i]['name'], $this->files[$i]['mime']),
                    ],
                    [
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                    "HTTP_ACCEPT: application/ld+json\r\n"
                    .'file='.base64_encode($this->files[$i]['content'])
                    ."&fileName={$this->files[$i]['name']}&prefix=$prefix&bucketID=$bucketId"
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
            $url = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=DELETEALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
            $this->assertEquals(204, $response->getStatusCode());

            // =======================================================
            // GET all files in prefix playground0
            // =======================================================
            echo "GET all files with prefix playground0\n";
            $prefix = 'playground0';
            $url = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=GETALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
            $response = $client->request('GET', $url.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            // check if the one created element is there
            $this->assertEquals(1, count(json_decode($response->getContent(), true)['hydra:member']));

            // =======================================================
            // GET all files in prefix playground
            // =======================================================
            echo "GET all files with prefix playground\n";
            $prefix = 'playground';
            $url = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=GETALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
     * Integration test: param binary=1 should lead to a 302 redirect.
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
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // POST two files
            // =======================================================

            for ($i = 0; $i < 2; ++$i) {
                $creationTime = date('U');
                $fileName = $this->files[$i]['name'];
                $fileHash = $this->files[$i]['hash'];
                $notifyEmail = 'eugen.neuber@tugraz.at';
                $retentionDuration = $this->files[$i]['retention'];
                $action = 'CREATEONE';

                $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

                $data = [
                    'cs' => $this->generateSha256ChecksumFromUrl($url),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $data);

                $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                    [
                        'file' => new UploadedFile($this->files[$i]['path'], $this->files[$i]['name'], $this->files[$i]['mime']),
                    ],
                    [
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                    "HTTP_ACCEPT: application/ld+json\r\n"
                    .'file='.base64_encode($this->files[$i]['content'])
                    ."&fileName={$this->files[$i]['name']}&prefix=$prefix&bucketID=$bucketId"
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
                $this->assertEquals($this->files[$i]['name'], $fileData->getFileName(), 'File name not correct.');
                $this->files[$i]['uuid'] = $fileData->getIdentifier();
                $this->files[$i]['created'] = $fileData->getDateCreated();
                $this->files[$i]['until'] = $fileData->getExistsUntil();
            }

            // =======================================================
            // GET all files in prefix playground
            // =======================================================
            echo "GET all files with prefix playground\n";
            $url = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&binary=1&action=GETALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
            $response = $client->request('GET', $url.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            // check if the one created element is there
            $members = json_decode($response->getContent(), true)['hydra:member'];
            $this->assertEquals(2, count($members));

            $response = $client->request('GET', $members[0]['contentUrl'], $options);
            // check if response is valid
            $this->assertEquals(200, $response->getStatusCode());
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
            $secret = $bucket->getKey();
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // DELETE all files
            // =======================================================
            echo "DELETE all files\n";

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=GETONE";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
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
            $bucketId = $bucket->getIdentifier();
            $creationTime = date('U');
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = date('U');
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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

            // =======================================================
            // GET, DELETE all files in prefix playground without creationTime, sig, action, bucketID, prefix
            // =======================================================
            echo "GET, DELETE all files with prefix playground with missing params\n";

            $actions = [
                0 => 'GETALL',
                1 => 'DELETEALL',
            ];

            foreach ($actions as $action) {
                $params = [
                    0 => "bucketID=$bucketId",
                    1 => "prefix=$prefix",
                    2 => "creationTime=$creationTime",
                    3 => "action=$action",
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

                    $payload = [
                        'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    ];

                    if ($i !== count($params) - 1) {
                        $baseUrl = $baseUrl.$connector.$params[$j];
                    }

                    $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                    $options = [
                        'headers' => [
                            'Accept' => 'application/ld+json',
                            'HTTP_ACCEPT' => 'application/ld+json',
                        ],
                    ];

                    /** @var Response $response */
                    $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.$token, $options);
                    $this->assertEquals(400, $response->getStatusCode());
                }
            }

            // =======================================================
            // GET, DELETE all files in prefix playground without creationTime, sig, action, bucketID, prefix
            // =======================================================
            echo "GET, DELETE one file with prefix playground with missing params\n";

            $actions = [
                0 => 'GETONE',
                1 => 'DELETEONE',
            ];

            foreach ($actions as $action) {
                $params = [
                    0 => "bucketID=$bucketId",
                    1 => "creationTime=$creationTime",
                    2 => "action=$action",
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
                        'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                    ];

                    if ($i !== count($params) - 1) {
                        $baseUrl = $baseUrl.$connector.$params[$j];
                    }

                    $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                    $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                    $options = [
                        'headers' => [
                            'Accept' => 'application/ld+json',
                            'HTTP_ACCEPT' => 'application/ld+json',
                        ],
                        'extra' => [
                            'files' => [
                                'file' => $file,
                            ],
                        ],
                    ];

                    /** @var Response $response */
                    $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.$token, $options);
                    //echo $response->getContent()."\n";
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
                0 => "bucketID=$bucketId",
                1 => "prefix=$prefix",
                2 => "creationTime=$creationTime",
                3 => 'action=CREATEONE',
                4 => "fileName=$fileName",
                5 => "fileHash=$fileHash",
                6 => 'sig=',
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
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                if ($i !== count($params) - 1) {
                    $baseUrl = $baseUrl.$connector.$params[$j];
                }

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.$token, $options);
                $this->assertEquals(400, $response->getStatusCode());
            }

            // =======================================================
            // PUT file in prefix playground without creationTime, sig, action, bucketID, prefix, fileName, fileHash
            // =======================================================

            echo "PUT one file with prefix playground with missing params\n";

            $fileName = $this->files[0]['name'];

            $params = [
                0 => "bucketID=$bucketId",
                1 => "prefix=$prefix",
                2 => "creationTime=$creationTime",
                3 => 'action=PUTONE',
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
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                if ($i !== count($params) - 1) {
                    $baseUrl = $baseUrl.$connector.$params[$j];
                }

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => "{fileName: $fileName}",
                ];

                /** @var Response $response */
                $response = $client->request('PUT', $baseUrl.$token, $options);
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
            $bucketId = $bucket->getIdentifier();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = date('U');
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check all operations with wrong checksum (in that case missing the first / in the cs generation)
            // =======================================================
            echo "Check GETONE, DELETEONE operations with wrong checksum\n";

            $actions = [
                0 => 'GETONE',
                1 => 'DELETEONE',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "blob/files/$identifier?bucketID=$bucketId&creationTime=$creationTime&action=$action";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                /** @var Response $response */
                $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check GETALL, DELETEALL operations with wrong checksum\n";

            $actions = [
                0 => 'GETALL',
                1 => 'DELETEALL',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                /** @var Response $response */
                $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check CREATEONE operation with wrong checksum\n";

            $actions = [
                0 => 'CREATEONE',
            ];

            foreach ($actions as $action) {
                $fileHash = $this->files[0]['hash'];
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action&fileName=test.txt&fileHash=$fileHash";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.'&sig='.$token,
                    [
                        'headers' => ['Content-Type' => 'form-data'],
                        'extra' => [
                            'files' => [
                                'file' => $file,
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
            $bucketId = $bucket->getIdentifier();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = date('U');
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check all operations with wrong signature
            // =======================================================
            echo "Check GETONE, DELETEONE operations with wrong signature\n";

            $actions = [
                0 => 'GETONE',
                1 => 'DELETEONE',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files/$identifier?bucketID=$bucketId&creationTime=$creationTime&action=$action";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];
                // get key of wrong bucket
                $bucket = $configService->getBuckets()[1];
                $secret = $bucket->getKey();

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                /** @var Response $response */
                $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check GETALL, DELETEALL operations with wrong signature\n";

            $actions = [
                0 => 'GETALL',
                1 => 'DELETEALL',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];
                // get key of wrong bucket
                $bucket = $configService->getBuckets()[1];
                $secret = $bucket->getKey();

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                /** @var Response $response */
                $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check CREATEONE operation with wrong checksum\n";

            $actions = [
                0 => 'CREATEONE',
            ];

            foreach ($actions as $action) {
                $fileHash = $this->files[0]['hash'];
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action&fileName=test.txt&fileHash=$fileHash";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];
                // get key of wrong bucket
                $bucket = $configService->getBuckets()[1];
                $secret = $bucket->getKey();

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.'&sig='.$token,
                    [
                        'headers' => ['Content-Type' => 'form-data'],
                        'extra' => [
                            'files' => [
                                'file' => $file,
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
            $bucketId = $bucket->getIdentifier();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = date('U');
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check all operations with overdue creationTime
            // =======================================================
            echo "Check GETONE, DELETEONE operations with overdue creationTime\n";

            $actions = [
                0 => 'GETONE',
                1 => 'DELETEONE',
            ];

            $creationTime = new \DateTime('now');
            $creationTime->sub(new \DateInterval('PT2M'));
            $creationTime = strtotime($creationTime->format('c'));

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files/$identifier?bucketID=$bucketId&creationTime=$creationTime&action=$action";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                /** @var Response $response */
                $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check GETALL, DELETEALL operations with overdue creationTime\n";

            $actions = [
                0 => 'GETALL',
                1 => 'DELETEALL',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                /** @var Response $response */
                $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(403, $response->getStatusCode());
            }

            echo "Check CREATEONE operation with overdue creationTime\n";

            $actions = [
                0 => 'CREATEONE',
            ];

            foreach ($actions as $action) {
                $fileHash = $this->files[0]['hash'];
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action&fileName=test.txt&fileHash=$fileHash";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.'&sig='.$token,
                    [
                        'headers' => ['Content-Type' => 'form-data'],
                        'extra' => [
                            'files' => [
                                'file' => $file,
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
            $bucketId = $bucket->getIdentifier();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = date('U');
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check all operations with unconfigured bucket
            // =======================================================
            echo "Check GETONE, DELETEONE operations with unconfigured bucket\n";

            $actions = [
                0 => 'GETONE',
                1 => 'DELETEONE',
            ];

            $bucketId = '2468';

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files/$identifier?bucketID=$bucketId&creationTime=$creationTime&action=$action";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $options = [
                    'headers' => [
                        'Accept' => 'application/ld+json',
                        'HTTP_ACCEPT' => 'application/ld+json',
                    ],
                ];

                /** @var Response $response */
                $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(400, $response->getStatusCode());
            }

            echo "Check GETALL, DELETEALL operations with unconfigured bucket\n";

            $actions = [
                0 => 'GETALL',
                1 => 'DELETEALL',
            ];

            foreach ($actions as $action) {
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                /** @var Response $response */
                $response = $client->request(substr($action, 0, strlen($action) - 3), $baseUrl.'&sig='.$token, $options);
                $this->assertEquals(400, $response->getStatusCode());
            }

            echo "Check CREATEONE operation with unconfigured bucket\n";

            $actions = [
                0 => 'CREATEONE',
            ];

            foreach ($actions as $action) {
                $fileHash = $this->files[0]['hash'];
                // url with missing / at the beginning to create a wrong checksum
                $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=$action&fileName=test.txt&fileHash=$fileHash";

                $payload = [
                    'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
                ];

                $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

                $file = new UploadedFile($this->files[0]['path'], $this->files[0]['name']);

                /** @var Response $response */
                $response = $client->request('POST', $baseUrl.'&sig='.$token,
                    [
                        'headers' => ['Content-Type' => 'form-data'],
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
            $bucketId = $bucket->getIdentifier();
            $prefix = 'playground';

            // =======================================================
            // POST file
            // =======================================================

            $creationTime = date('U');
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // Check GETALL of other bucket shouldnt return file
            // =======================================================
            echo "Check GETALL on other bucket shouldnt return file\n";

            $bucketId = 4321;
            // get key of wrong bucket
            $bucket = $configService->getBuckets()[1];
            $secret = $bucket->getKey();

            // url with missing / at the beginning to create a wrong checksum
            $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=GETALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /** @var Response $response */
            $response = $client->request('GET', $baseUrl.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals([], json_decode($response->getContent(), true)['hydra:member']);

            // =======================================================
            // POST file in other bucket
            // =======================================================

            $creationTime = date('U');
            $fileName = $this->files[0]['name'];
            $fileHash = $this->files[0]['hash'];
            $notifyEmail = 'eugen.neuber@tugraz.at';
            $retentionDuration = $this->files[0]['retention'];
            $action = 'CREATEONE';

            $url = "/blob/files/?bucketID=$bucketId&creationTime=$creationTime&prefix=$prefix&action=$action&fileName=$fileName&fileHash=$fileHash&notifyEmail=$notifyEmail&retentionDuration=$retentionDuration";

            $data = [
                'cs' => $this->generateSha256ChecksumFromUrl($url),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $data);

            $requestPost = Request::create($url.'&sig='.$token, 'POST', [], [],
                [
                    'file' => new UploadedFile($this->files[0]['path'], $this->files[0]['name'], $this->files[0]['mime']),
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
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
            $identifier = $fileData->getIdentifier();
            $this->files[0]['created'] = $fileData->getDateCreated();
            $this->files[0]['until'] = $fileData->getExistsUntil();

            // =======================================================
            // DELETE all files in other bucket
            // =======================================================

            echo "Check DELETEALL on other bucket shouldnt delete original bucket\n";

            $bucketId = 4321;
            // get key of wrong bucket
            $bucket = $configService->getBuckets()[1];
            $secret = $bucket->getKey();

            // url with missing / at the beginning to create a wrong checksum
            $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=DELETEALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /** @var Response $response */
            $response = $client->request('DELETE', $baseUrl.'&sig='.$token, $options);
            $this->assertEquals(204, $response->getStatusCode());

            // =======================================================
            // Check GETALL of original bucket should still return file
            // =======================================================
            echo "Check GETALL on original bucket should still return file\n";

            $bucketId = 1234;
            // get key of wrong bucket
            $bucket = $configService->getBuckets()[0];
            $secret = $bucket->getKey();

            // url with missing / at the beginning to create a wrong checksum
            $baseUrl = "/blob/files?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime&action=GETALL";

            $payload = [
                'cs' => $this->generateSha256ChecksumFromUrl($baseUrl),
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $options = [
                'headers' => [
                    'Accept' => 'application/ld+json',
                    'HTTP_ACCEPT' => 'application/ld+json',
                ],
            ];

            /** @var Response $response */
            $response = $client->request('GET', $baseUrl.'&sig='.$token, $options);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals(1, count(json_decode($response->getContent(), true)['hydra:member']));
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
