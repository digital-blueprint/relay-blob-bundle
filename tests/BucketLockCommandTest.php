<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BlobBundle\Command\ListBucketsCommand;
use Dbp\Relay\BlobBundle\Command\LockBucketCommand;
use Dbp\Relay\BlobBundle\Command\UnlockBucketCommand;
use Dbp\Relay\BlobBundle\Entity\BucketLock;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Dbp\Relay\BlobBundle\TestUtils\BlobTestUtils;
use Dbp\Relay\BlobBundle\TestUtils\TestEntityManager;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class BucketLockCommandTest extends ApiTestCase
{
    private const TEST_BUCKET_IDENTIFIER = 'test-bucket';

    private TestEntityManager $testEntityManager;
    private BlobService $blobService;

    protected function setUp(): void
    {
        $this->testEntityManager = new TestEntityManager(self::bootKernel()->getContainer());
        $this->blobService = BlobTestUtils::createTestBlobService($this->testEntityManager->getEntityManager());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        BlobTestUtils::tearDown();
    }

    public function testLockAllMethods(): void
    {
        $tester = $this->createCommandTester(new LockBucketCommand($this->blobService));
        $exitCode = $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
            '--all' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $lock = $this->getSingleLock();
        $this->assertTrue($lock->getGetLock());
        $this->assertTrue($lock->getPostLock());
        $this->assertTrue($lock->getPatchLock());
        $this->assertTrue($lock->getDeleteLock());
    }

    public function testLockReadOnlyReplacesExistingLockAfterConfirmation(): void
    {
        $tester = $this->createCommandTester(new LockBucketCommand($this->blobService));
        $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
            '--all' => true,
        ]);

        $tester = $this->createCommandTester(new LockBucketCommand($this->blobService));
        $tester->setInputs(['y']);
        $exitCode = $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
            '--read-only' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $lock = $this->getSingleLock();
        $this->assertFalse($lock->getGetLock());
        $this->assertTrue($lock->getPostLock());
        $this->assertTrue($lock->getPatchLock());
        $this->assertTrue($lock->getDeleteLock());
    }

    public function testLockSelectedMethods(): void
    {
        $tester = $this->createCommandTester(new LockBucketCommand($this->blobService));
        $exitCode = $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
            '--method' => ['POST', 'PATCH'],
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $lock = $this->getSingleLock();
        $this->assertFalse($lock->getGetLock());
        $this->assertTrue($lock->getPostLock());
        $this->assertTrue($lock->getPatchLock());
        $this->assertFalse($lock->getDeleteLock());
    }

    public function testLockRequiresExplicitSelector(): void
    {
        $tester = $this->createCommandTester(new LockBucketCommand($this->blobService));
        $exitCode = $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Specify what to lock', $tester->getDisplay());
    }

    public function testUnlockRemovesLockAndIsIdempotent(): void
    {
        $tester = $this->createCommandTester(new LockBucketCommand($this->blobService));
        $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
            '--all' => true,
        ]);

        $tester = $this->createCommandTester(new UnlockBucketCommand($this->blobService));
        $exitCode = $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([], $this->getLocks());

        $exitCode = $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('already unlocked', $tester->getDisplay());
    }

    public function testListBucketsShowsLockedMethodsInJson(): void
    {
        $tester = $this->createCommandTester(new LockBucketCommand($this->blobService));
        $tester->execute([
            'bucketIdentifier' => self::TEST_BUCKET_IDENTIFIER,
            '--read-only' => true,
        ]);

        $tester = $this->createCommandTester(new ListBucketsCommand($this->blobService));
        $exitCode = $tester->execute([
            '--format' => 'json',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $rows = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $bucket = $this->getBucketRow($rows, self::TEST_BUCKET_IDENTIFIER);

        $this->assertSame(['POST', 'PATCH', 'DELETE'], $bucket['locks']);
        $this->assertArrayNotHasKey('lock', $bucket);
    }

    private function getSingleLock(): BucketLock
    {
        $locks = $this->getLocks();
        $this->assertCount(1, $locks);

        return $locks[0];
    }

    private function createCommandTester(Command $command): CommandTester
    {
        $application = new Application();
        $application->add($command);
        $commandName = $command->getName();
        $this->assertNotNull($commandName);

        return new CommandTester($application->find($commandName));
    }

    /**
     * @return BucketLock[]
     */
    private function getLocks(): array
    {
        /** @var BucketLock[] $locks */
        $locks = $this->testEntityManager->getEntityManager()
            ->getRepository(BucketLock::class)
            ->findBy(['internalBucketId' => $this->getInternalBucketId()]);

        return $locks;
    }

    private function getInternalBucketId(): string
    {
        $internalBucketId = $this->blobService->getInternalBucketIdByBucketID(self::TEST_BUCKET_IDENTIFIER);
        $this->assertNotNull($internalBucketId);

        return $internalBucketId;
    }

    private function getBucketRow(array $rows, string $bucketIdentifier): array
    {
        foreach ($rows as $row) {
            if ($row['bucketId'] === $bucketIdentifier) {
                return $row;
            }
        }

        $this->fail('Bucket row not found: '.$bucketIdentifier);
    }
}
