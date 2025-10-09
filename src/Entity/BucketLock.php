<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\RequestBody;
use Dbp\Relay\BlobBundle\ApiPlatform\BucketLockProcessor;
use Dbp\Relay\BlobBundle\ApiPlatform\BucketLockProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    shortName: 'BlobBucketLocks',
    types: ['https://schema.org/DigitalDocument'],
    operations: [
        new Get(
            uriTemplate: '/bucket-locks/{identifier}',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Get the lock of a bucket',
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: BucketLockProvider::class
        ),
        new Delete(
            uriTemplate: '/bucket-locks/{identifier}',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Delete a lock of a specific bucket',
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: BucketLockProvider::class,
            processor: BucketLockProcessor::class
        ),
        new Patch(
            uriTemplate: '/bucket-locks/{identifier}',
            openapi: new Operation(
                tags: ['Blob'],
                summary: 'Patch a lock of a specific bucket',
                requestBody: new RequestBody(
                    content: new \ArrayObject([
                        'application/merge-patch+json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['getLock', 'postLock', 'patchLock', 'deleteLock'],
                                'properties' => [
                                    'getLock' => [
                                        'type' => 'boolean',
                                        'default' => false,
                                    ],
                                    'postLock' => [
                                        'type' => 'boolean',
                                        'default' => false,
                                    ],
                                    'patchLock' => [
                                        'type' => 'boolean',
                                        'default' => false,
                                    ],
                                    'deleteLock' => [
                                        'type' => 'boolean',
                                        'default' => false,
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: BucketLockProvider::class,
            processor: BucketLockProcessor::class
        ),
        new Post(
            uriTemplate: '/bucket-locks',
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
                requestBody: new RequestBody(
                    content: new \ArrayObject([
                        'application/ld+json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['getLock', 'postLock', 'patchLock', 'deleteLock'],
                                'properties' => [
                                    'getLock' => [
                                        'type' => 'boolean',
                                        'default' => false,
                                    ],
                                    'postLock' => [
                                        'type' => 'boolean',
                                        'default' => false,
                                    ],
                                    'patchLock' => [
                                        'type' => 'boolean',
                                        'default' => true,
                                    ],
                                    'deleteLock' => [
                                        'type' => 'boolean',
                                        'default' => false,
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            processor: BucketLockProcessor::class,
        ),
    ],
    routePrefix: '/blob',
    normalizationContext: [
        'groups' => ['BlobLocks:output'],
        'jsonld_embed_context' => true,
    ],
    denormalizationContext: [
        'groups' => ['BlobLocks:input'],
    ]
)]
#[ORM\Table(name: 'blob_bucket_locks')]
#[ORM\Entity]
class BucketLock
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    #[ApiProperty(identifier: true)]
    #[Groups(['BlobLocks:output'])]
    private string $identifier = '';

    #[ORM\Column(type: 'boolean')]
    #[Groups(['BlobLocks:output', 'BlobLocks:input'])]
    private bool $postLock = false;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['BlobLocks:output', 'BlobLocks:input'])]
    private bool $getLock = false;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['BlobLocks:output', 'BlobLocks:input'])]
    private bool $patchLock = false;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['BlobLocks:output', 'BlobLocks:input'])]
    private bool $deleteLock = false;

    #[ORM\Column(type: 'string', length: 36)]
    #[Groups(['BlobLocks:output'])]
    private string $internalBucketId;

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getInternalBucketId(): string
    {
        return $this->internalBucketId;
    }

    public function setInternalBucketId(string $internalBucketId): void
    {
        $this->internalBucketId = $internalBucketId;
    }

    public function getPostLock(): bool
    {
        return $this->postLock;
    }

    public function setPostLock(bool $postLock): void
    {
        $this->postLock = $postLock;
    }

    public function getGetLock(): bool
    {
        return $this->getLock;
    }

    public function setGetLock(bool $getLock): void
    {
        $this->getLock = $getLock;
    }

    public function getPatchLock(): bool
    {
        return $this->patchLock;
    }

    public function setPatchLock(bool $patchLock): void
    {
        $this->patchLock = $patchLock;
    }

    public function getDeleteLock(): bool
    {
        return $this->deleteLock;
    }

    public function setDeleteLock(bool $deleteLock): void
    {
        $this->deleteLock = $deleteLock;
    }
}
