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
use Dbp\Relay\BlobBundle\ApiPlatform\CancelRestoreJobAction;
use Dbp\Relay\BlobBundle\ApiPlatform\MetadataRestoreJobProcessor;
use Dbp\Relay\BlobBundle\ApiPlatform\MetadataRestoreJobProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    shortName: 'BlobMetadataRestoreJobs',
    types: ['https://schema.org/DigitalDocument'],
    operations: [
        new GetCollection(
            uriTemplate: '/metadata-restore-jobs',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Get all metadata-restore-jobs for a given bucketId',
                parameters: [
                    new Parameter(
                        name: 'bucketIdentifier',
                        in: 'query',
                        description: 'Config identifier of bucket.',
                        required: true,
                        schema: ['type' => 'string'],
                        example: '019a0087-8614-71c1-99e5-42c087cfbdfb',
                    ),
                ],
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: MetadataRestoreJobProvider::class
        ),
        new Get(
            uriTemplate: '/metadata-restore-jobs/{identifier}',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Get the metadata-restore-job for a given identifier',
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: MetadataRestoreJobProvider::class
        ),
        new Delete(
            uriTemplate: '/metadata-restore-jobs/{identifier}',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Delete the metadata-restore-job for a given identifier',
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: MetadataRestoreJobProvider::class,
            processor: MetadataRestoreJobProcessor::class
        ),
        new Post(
            uriTemplate: '/metadata-restore-jobs',
            openapi: new Operation(
                tags: ['Blob'],
                parameters: [
                    new Parameter(
                        name: 'metadataBackupJobId',
                        in: 'query',
                        description: 'Identifier of the metadata-backup-job to restore.',
                        required: true,
                        schema: ['type' => 'string'],
                        example: '019a0087-8614-71c1-99e5-42c087cfbdfb',
                    ),
                ],
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            processor: MetadataRestoreJobProcessor::class,
        ),
        new Post(
            uriTemplate: '/metadata-restore-jobs/{identifier}/cancel',
            controller: CancelRestoreJobAction::class,
            openapi: new Operation(
                tags: ['Blob']
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
        ),
    ],
    routePrefix: '/blob',
    normalizationContext: [
        'groups' => ['BlobMetadataRestoreJobs:output'],
        'jsonld_embed_context' => true,
    ],
    denormalizationContext: [
        'groups' => ['BlobMetadataRestoreJobs:input'],
    ]
)]
#[ORM\Table(name: 'blob_metadata_restore_jobs')]
#[ORM\Entity]
class MetadataRestoreJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    #[ApiProperty(identifier: true)]
    #[Groups(['BlobMetadataRestoreJobs:output'])]
    private string $identifier = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataRestoreJobs:output'])]
    private string $status = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataRestoreJobs:output'])]
    private string $bucketId = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataRestoreJobs:output'])]
    private string $started = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataRestoreJobs:output'])]
    private ?string $finished = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataRestoreJobs:output'])]
    private ?string $errorId = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataRestoreJobs:output'])]
    private ?string $errorMessage = '';

    #[ORM\Column(type: 'string')]
    #[Groups(['BlobMetadataRestoreJobs:output'])]
    private ?string $hash = '';

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
}
