<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use Dbp\Relay\BlobBundle\ApiPlatform\CancelBackupJobAction;
use Dbp\Relay\BlobBundle\ApiPlatform\MetadataBackupJobProcessor;
use Dbp\Relay\BlobBundle\ApiPlatform\MetadataBackupJobProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    shortName: 'BlobMetadataBackupJobs',
    types: ['https://schema.org/DigitalDocument'],
    operations: [
        new GetCollection(
            uriTemplate: '/metadata-backup-jobs',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Get all metadata-backup-jobs for a given bucketId',
                parameters: [
                    new Parameter(
                        name: 'bucketIdentifier',
                        in: 'query',
                        description: 'Config identifier of bucket.',
                        required: true,
                        schema: ['type' => 'string'],
                        example: 'test-bucket',
                    ),
                ],
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: MetadataBackupJobProvider::class
        ),
        new Get(
            uriTemplate: '/metadata-backup-jobs/{identifier}',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Get the metadata-backup-job for a given identifier',
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: MetadataBackupJobProvider::class
        ),
        new Delete(
            uriTemplate: '/metadata-backup-jobs/{identifier}',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Delete the metadata-backup-job for a given identifier',
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: MetadataBackupJobProvider::class,
            processor: MetadataBackupJobProcessor::class
        ),
        new Post(
            uriTemplate: '/metadata-backup-jobs/{identifier}/cancel',
            controller: CancelBackupJobAction::class,
            openapi: new Operation(
                tags: ['Blob']
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
        ),
        new Post(
            uriTemplate: '/metadata-backup-jobs',
            status: 202,
            openapi: new Operation(
                tags: ['Blob'],
                parameters: [
                    new Parameter(
                        name: 'bucketIdentifier',
                        in: 'query',
                        description: 'Config identifier of bucket.',
                        required: true,
                        schema: ['type' => 'string'],
                        example: 'test-bucket',
                    ),
                ],
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            processor: MetadataBackupJobProcessor::class,
        ),
    ],
    routePrefix: '/blob',
    normalizationContext: [
        'groups' => ['BlobMetadataBackupJobs:output'],
        'jsonld_embed_context' => true,
    ],
    denormalizationContext: [
        'groups' => ['BlobMetadataBackupJobs:input'],
    ]
)]
#[ORM\Table(name: 'blob_metadata_backup_jobs')]
#[ORM\Entity]
class MetadataBackupJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    #[ApiProperty(identifier: true)]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private string $identifier = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private string $status = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private string $bucketId = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private string $started = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private ?string $finished = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private ?string $errorId = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private ?string $errorMessage = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private ?string $hash = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataBackupJobs:output'])]
    private ?string $fileRef = '';

    public const JOB_STATUS_RUNNING = 'RUNNING';
    public const JOB_STATUS_CANCELLED = 'CANCELLED';
    public const JOB_STATUS_ERROR = 'ERROR';
    public const JOB_STATUS_FINISHED = 'FINISHED';

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getBucketId(): string
    {
        return $this->bucketId;
    }

    public function setBucketId(string $bucketId): void
    {
        $this->bucketId = $bucketId;
    }

    public function getStarted(): string
    {
        return $this->started;
    }

    public function setStarted(string $started): void
    {
        $this->started = $started;
    }

    public function getFinished(): string
    {
        return $this->finished;
    }

    public function setFinished(?string $finished): void
    {
        $this->finished = $finished;
    }

    public function getErrorId(): string
    {
        return $this->errorId;
    }

    public function setErrorId(?string $errorId): void
    {
        $this->errorId = $errorId;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): void
    {
        $this->hash = $hash;
    }

    public function getFileRef(): string
    {
        return $this->fileRef;
    }

    public function setFileRef(?string $fileRef): void
    {
        $this->fileRef = $fileRef;
    }
}
