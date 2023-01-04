<?php declare(strict_types=1);

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
use Dbp\Relay\CoreBundle\TestUtils\UserAuthTrait;
use Doctrine\ORM\Tools\SchemaTool;
use Exception;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use function uuid_is_valid;

class DummyFileSystemService implements DatasystemProviderServiceInterface
{
    public function saveFile(FileData &$fileData): ?FileData
    {
        return $fileData;
    }

    public function renameFile(FileData &$fileData): ?FileData
    {
        return $fileData;
    }

    public function getLink(FileData &$fileData, PoliciesStruct $policiesStruct): ?FileData
    {
        return $fileData;
    }

    public function removeFile(FileData &$fileData): bool
    {
        return true;
    }
}

class CurlGetTest extends ApiTestCase
{
    use UserAuthTrait;

    /** @var \Doctrine\ORM\EntityManagerInterface $entityManager */
    private $entityManager;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        /** @var KernelInterface $kernel */
        $kernel = self::bootKernel();

        if ('test' !== $kernel->getEnvironment()) {
            throw new \RuntimeException('Execution only in Test environment possible!');
        }

        $this->entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        $metaData = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->updateSchema($metaData);
    }

    public function testGet(): void
    {
        try {
            $client = $this->withUser('foobar');
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
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $url = "/blob/files/?bucketID=$bucketId&prefix=$prefix&creationTime=$creationTime";
            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /** @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            /** @var Response $response */
            $response = $client->request('GET', $url, $options);

            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertCount(0, $data['hydra:member'], 'More files than expected');
        } catch (\Throwable $e) {
            echo $e->getTraceAsString() . "\n";
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
     *  - get all blobs: no blobs available
     *
     * @return void
     */
    public function testPostGetDelete(): void
    {
        try {

            $files = [
                0 => [
                    'name' => $n = 'Test.php',
                    'path' => $p = __DIR__ . '/' . $n,
                    'content' => $c = file_get_contents($p),
                    'hash' => hash('sha256', $c),
                    'size' => strlen($c),
                    'mime' => 'application/x-php',
                    'retention' => 'P1W',
                ],
                1 => [
                    'name' => $n = 'Kernel.php',
                    'path' => $p = __DIR__ . '/' . $n,
                    'content' => $c = file_get_contents($p),
                    'hash' => hash('sha256', $c),
                    'size' => strlen($c),
                    'mime' => 'application/x-php',
                    'retention' => 'P1M',
                ],
            ];

            $client = $this->withUser('foobar');
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
            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'fileName' => $files[0]['name'],
                'fileHash' => $files[0]['hash'],
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $files[0]['retention'],
                'additionalMetadata' => '',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url, 'POST', [], [],
                [
                    'file' => new UploadedFile($files[0]['path'], $files[0]['name'], $files[0]['mime'])
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
//                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                    . "HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
                    . "file=" . base64_encode($files[0]['content'])
                    . "&fileName={$files[0]['name']}&prefix=$prefix&bucketID=$bucketId"
            );
            $c = new CreateFileDataAction($blobService);
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString() . "\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasAttribute('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($files[0]['name'], $fileData->getFileName(), 'File name not correct.');
            $files[0]['uuid'] = $fileData->getIdentifier();
            $files[0]['created'] = $fileData->getDateCreated();
            $files[0]['until'] = $fileData->getExistsUntil();
            $this->assertEquals(
                $files[0]['created']->format('c'),
                date('c', (int)$creationTime),
                'File creation time not correct.'
            );

            // =======================================================
            // GET a file
            // =======================================================
            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /** @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            $response = $client->request('GET', $url, $options);

            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertArrayHasKey(0, $data['hydra:member']);
            $resultFile = $data['hydra:member'][0];
            $this->assertEquals($prefix, $resultFile['prefix'], 'File data prefix not correct.');
            $this->assertEquals($files[0]['name'], $resultFile['fileName'], 'File name not correct.');
            $this->assertEquals($files[0]['size'], $resultFile['fileSize'], 'File size not correct.');
            $this->assertEquals($files[0]['uuid'], $resultFile['identifier'], 'File identifier not correct.');
            $this->assertEquals($notifyEmail, $resultFile['notifyEmail'], 'File data notify email not correct.');
            $this->assertCount(1, $data['hydra:member'], 'More files than expected');
//            dump($data);

            // =======================================================
            // POST another file
            // =======================================================
            $payload = [
                'bucketID' => $bucketId,
                'creationTime' => $creationTime,
                'prefix' => $prefix,
                'fileName' => $files[1]['name'],
                'fileHash' => $files[1]['hash'],
                'notifyEmail' => $notifyEmail,
                'retentionDuration' => $files[1]['retention'],
                'additionalMetadata' => '',
            ];

            $token = DenyAccessUnlessCheckSignature::create($secret, $payload);

            $requestPost = Request::create($url, 'POST', [], [],
                [
                    'file' => new UploadedFile($files[1]['path'], $files[1]['name'], $files[1]['mime'])
                ],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
//                    'x-dbp-signature' => $token,
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                    . "HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
                    . "file=" . base64_encode($files[1]['content'])
                    . "&fileName={$files[1]['name']}&prefix=$prefix&bucketID=$bucketId"
            );
            $c = new CreateFileDataAction($blobService);
            try {
                $fileData = $c->__invoke($requestPost);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString() . "\n";
                $this->fail($e->getMessage());
            }

            $this->assertNotNull($fileData);
            $this->assertEquals($prefix, $fileData->getPrefix(), 'File data prefix not correct.');
            $this->assertObjectHasAttribute('identifier', $fileData, 'File data has no identifier.');
            $this->assertTrue(uuid_is_valid($fileData->getIdentifier()), 'File data identifier is not a valid UUID.');
            $this->assertEquals($files[1]['name'], $fileData->getFileName(), 'File name not correct.');
            $files[1]['uuid'] = $fileData->getIdentifier();
            $files[1]['created'] = $fileData->getDateCreated();
            $files[1]['until'] = $fileData->getExistsUntil();
            dump($fileData);

            // =======================================================
            // GET all files
            // =======================================================
            $options = [
                'headers' => [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'x-dbp-signature' => $token,
                ],
            ];

            /** @noinspection PhpInternalEntityUsedInspection */
            $client->getKernelBrowser()->followRedirects();

            $response = $client->request('GET', $url, $options);

            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertCount(2, $data['hydra:member'], 'More files than expected');
            foreach ($data['hydra:member'] as $resultFile) {
                $found = false;
                foreach ($files as $file) {
                    if ($file['uuid'] === $resultFile['identifier']) {
                        $found = true;
                        $this->assertEquals($prefix, $resultFile['prefix'], 'File prefix not correct.');
                        $this->assertEquals($file['name'], $resultFile['fileName'], 'File name not correct.');
                        $this->assertEquals($file['size'], $resultFile['fileSize'], 'File size not correct.');
                        $this->assertEquals(
                            $file['created']->format('c'),
                            date('c', (int)$creationTime),
                            'File creation time not correct.'
                        );
                        $until = $file['created']->add(new \DateInterval($file['retention']));
                        dump([$until->format('c'), $resultFile['existsUntil']]);
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
//            dump($data['hydra:member']);
//            dump($data);

            // =======================================================
            // DELETE all files
            // =======================================================
            $requestDelete = Request::create($url, 'DELETE', [], [], [],
                [
                    'HTTP_ACCEPT' => 'application/ld+json',
                    'HTTP_X_DBP_SIGNATURE' => $token,
                ],
                "HTTP_ACCEPT: application/ld+json\r\n"
                . "HTTP_X_DBP_SIGNATURE: $token\r\n\r\n"
            );
            $d = new DeleteFileDatasByPrefix($blobService);
            try {
                $d->__invoke($requestDelete);
            } catch (\Throwable $e) {
                echo $e->getTraceAsString() . "\n";
                $this->fail($e->getMessage());
            }

            $query = $this->entityManager->getConnection()->createQueryBuilder();
            $files = $query->select('*')
                ->from('blob_files')
                ->where("prefix = '$prefix' AND bucket_id = '$bucketId'")
                ->fetchAllAssociativeIndexed();
            $this->assertEmpty($files, 'Files not deleted');

            // =======================================================
            // GET all files
            // =======================================================
            /** @var Response $response */
            $response = $client->request('GET', $url, $options);

            $this->assertEquals(200, $response->getStatusCode());

            $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('hydra:view', $data);
            $this->assertArrayHasKey('hydra:member', $data);
            $this->assertCount(0, $data['hydra:member'], 'More files than expected');

        } catch (\Throwable $e) {
            echo $e->getTraceAsString() . "\n";
            $this->fail($e->getMessage());
        }
    }
}
