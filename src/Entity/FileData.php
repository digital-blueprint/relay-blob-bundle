<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

date_default_timezone_set('UTC');

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Dbp\Relay\BlobBundle\ApiPlatform\CreateFileDataAction;
use Dbp\Relay\BlobBundle\ApiPlatform\DownloadAction;
use Dbp\Relay\BlobBundle\ApiPlatform\FileDataProcessor;
use Dbp\Relay\BlobBundle\ApiPlatform\FileDataProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    shortName: 'BlobFiles',
    types: ['https://schema.org/DigitalDocument'],
    operations: [
        new GetCollection(
            uriTemplate: '/files',
            openapiContext: [
                'tags' => ['Blob'],
                'summary' => 'Get the fileshares of a specific bucket with a specific prefix',
                'parameters' => [
                    [
                        'name' => 'bucketIdentifier',
                        'in' => 'query',
                        'description' => 'Config dentifier of bucket',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'test-bucket',
                    ],
                    [
                        'name' => 'creationTime',
                        'in' => 'query',
                        'description' => 'Current time in ATOM',
                        'type' => 'string',
                        'required' => true,
                        'example' => '2024-09-25T12:51:01+00:00',
                    ],
                    [
                        'name' => 'method',
                        'in' => 'query',
                        'description' => 'Method that gets executed',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'GET',
                    ],
                    [
                        'name' => 'includeData',
                        'in' => 'query',
                        'description' => 'If the returned contentUrl is a http link or the base64 encoded data',
                        'type' => 'string',
                        'required' => false,
                        'example' => '1',
                    ],
                    [
                        'name' => 'prefix',
                        'in' => 'query',
                        'description' => 'prefix equals filter',
                        'type' => 'string',
                        'required' => false,
                        'example' => 'my-prefix/my-subprefix',
                    ],
                    [
                        'name' => 'startsWith',
                        'in' => 'query',
                        'description' => 'prefix starts with filter',
                        'type' => 'string',
                        'required' => false,
                        'example' => 'my-prefix',
                    ],
                    [
                        'name' => 'sig',
                        'in' => 'query',
                        'description' => 'Signature containing the checksum required for the check',
                        'type' => 'string',
                        'required' => true,
                        'example' => '',
                    ],
                    [
                        'name' => 'page',
                        'in' => 'query',
                        'description' => 'Page of data that should be accessed',
                        'type' => 'string',
                        'required' => false,
                        'example' => '1',
                    ],
                    [
                        'name' => 'perPage',
                        'in' => 'query',
                        'description' => 'Number of items per page',
                        'type' => 'string',
                        'required' => false,
                        'example' => '30',
                    ],
                ],
            ],
            normalizationContext: [
                'groups' => ['BlobFiles:output'],
                'jsonld_embed_context' => true,
            ],
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: FileDataProvider::class
        ),
        new Get(
            uriTemplate: '/files/{identifier}',
            openapiContext: [
                'tags' => ['Blob'],
                'summary' => 'Get the fileshare of a specific bucket with a specific prefix and a specific id',
                'parameters' => [
                    [
                        'name' => 'bucketIdentifier',
                        'in' => 'query',
                        'description' => 'Config identifier of bucket.',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'test-bucket',
                    ],
                    [
                        'name' => 'creationTime',
                        'in' => 'query',
                        'description' => 'Current time in ATOM',
                        'type' => 'string',
                        'required' => true,
                        'example' => '2024-09-25T12:51:01+00:00',
                    ],
                    [
                        'name' => 'includeData',
                        'in' => 'query',
                        'description' => 'If the returned contentUrl is a http link or the base64 encoded data',
                        'type' => 'string',
                        'required' => false,
                        'example' => '1',
                    ],
                    [
                        'name' => 'method',
                        'in' => 'query',
                        'description' => 'Method that gets executed',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'GET',
                    ],
                    [
                        'name' => 'sig',
                        'in' => 'query',
                        'description' => 'Signature containing the checksum required for the check',
                        'type' => 'string',
                        'required' => true,
                        'example' => '',
                    ],
                ],
            ],
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: FileDataProvider::class
        ),
        new Delete(
            uriTemplate: '/files/{identifier}',
            openapiContext: [
                'tags' => ['Blob'],
                'summary' => 'Delete a fileshare of a specific bucket with a specific prefix and a specific id',
                'parameters' => [
                    [
                        'name' => 'bucketIdentifier',
                        'in' => 'query',
                        'description' => 'Config identifier of bucket',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'test-bucket',
                    ],
                    [
                        'name' => 'creationTime',
                        'in' => 'query',
                        'description' => 'Current times as ATOM',
                        'type' => 'string',
                        'required' => true,
                        'example' => '2024-09-25T12:51:01+00:00',
                    ],
                    [
                        'name' => 'method',
                        'in' => 'query',
                        'description' => 'Method that gets executed',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'DELETE',
                    ],
                    [
                        'name' => 'sig',
                        'in' => 'query',
                        'description' => 'Signature containing the checksum required for the check',
                        'type' => 'string',
                        'required' => true,
                        'example' => '',
                    ],
                ],
            ],
            denormalizationContext: [
                'groups' => ['BlobFiles:exists'],
            ],
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            provider: FileDataProvider::class,
            processor: FileDataProcessor::class
        ),
        new Patch(
            uriTemplate: '/files/{identifier}',
            inputFormats: [
                'json' => ['application/merge-patch+json'],
                'multipart' => ['multipart/form-data'],
            ],
            openapiContext: [
                'tags' => ['Blob'],
                'parameters' => [
                    [
                        'name' => 'bucketIdentifier',
                        'in' => 'query',
                        'description' => 'Config identifier of bucket.',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'test-bucket',
                    ],
                    [
                        'name' => 'creationTime',
                        'in' => 'query',
                        'description' => 'Current time as ATOM',
                        'type' => 'string',
                        'required' => true,
                        'example' => '2024-09-25T12:51:01+00:00',
                    ],
                    [
                        'name' => 'method',
                        'in' => 'query',
                        'description' => 'Method that gets executed',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'PATCH',
                    ],
                    [
                        'name' => 'notifyEmail',
                        'in' => 'query',
                        'description' => 'An email address which gets notified before the files expires',
                        'type' => 'string',
                        'required' => false,
                        'example' => '',
                    ],
                    [
                        'name' => 'prefix',
                        'in' => 'query',
                        'description' => 'Prefix of a file collection',
                        'type' => 'string',
                        'required' => false,
                        'example' => 'my-prefix/my-subprefix',
                    ],
                    [
                        'name' => 'type',
                        'in' => 'query',
                        'description' => 'Type of the added metadata',
                        'type' => 'string',
                        'required' => false,
                        'example' => 'generic_id_card',
                    ],
                    [
                        'name' => 'sig',
                        'in' => 'query',
                        'description' => 'Signature containing the checksum required for the check',
                        'type' => 'string',
                        'required' => true,
                        'example' => '',
                    ],
                ],
                'requestBody' => [
                    'content' => [
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                    'fileName' => [
                                        'description' => 'Friendly name of the file',
                                        'type' => 'string',
                                        'example' => 'myfile.txt',
                                    ],
                                    'fileHash' => [
                                        'description' => 'Sha256 hash of the file. If one is provided, then it has to match the actual sha256 hash of the uploaded file!',
                                        'type' => 'string',
                                        'example' => '0938744de39e1afc2d6bca532b937848918c9b4db41689576930d636bd73d275',
                                    ],
                                    'metadata' => [
                                        'description' => 'Metadata for the file',
                                        'type' => 'string',
                                        'example' => '{"key":"value"}',
                                    ],
                                    'metadataHash' => [
                                        'description' => 'Sha256 hash of the metadata. If one is provided, then it has to match the actual sha256 hash of the uploaded metadata!',
                                        'type' => 'string',
                                        'example' => '0938744de39e1afc2d6bca532b937848918c9b4db41689576930d636bd73d275',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            denormalizationContext: [
                'groups' => ['BlobFiles:update'],
            ],
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            deserialize: false,
            provider: FileDataProvider::class,
            processor: FileDataProcessor::class
        ),
        new Post(
            uriTemplate: '/files',
            inputFormats: [
                'multipart' => 'multipart/form-data',
            ],
            controller: CreateFileDataAction::class,
            openapiContext: [
                'tags' => ['Blob'],
                'parameters' => [
                    [
                        'name' => 'bucketIdentifier',
                        'in' => 'query',
                        'description' => 'Config identifier of bucket.',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'test-bucket',
                    ],
                    [
                        'name' => 'creationTime',
                        'in' => 'query',
                        'description' => 'Current time as ATOM',
                        'type' => 'string',
                        'required' => true,
                        'example' => '2024-09-25T12:51:01+00:00',
                    ],
                    [
                        'name' => 'method',
                        'in' => 'query',
                        'description' => 'Method that gets executed',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'POST',
                    ],
                    [
                        'name' => 'notifyEmail',
                        'in' => 'query',
                        'description' => 'An email address which gets notified before the files expires',
                        'type' => 'string',
                        'required' => false,
                        'example' => '',
                    ],
                    [
                        'name' => 'retentionDuration',
                        'in' => 'query',
                        'description' => 'Max time in timestamp duration in ATOM format from creation date when file will be deleted',
                        'type' => 'string',
                        'required' => false,
                        'example' => 'P1D',
                    ],
                    [
                        'name' => 'type',
                        'in' => 'query',
                        'description' => 'Type of the added metadata',
                        'type' => 'string',
                        'required' => false,
                        'example' => 'generic_id_card',
                    ],
                    [
                        'name' => 'prefix',
                        'in' => 'query',
                        'description' => 'Prefix of a file collection',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'my-prefix/my-subprefix',
                    ],
                    [
                        'name' => 'sig',
                        'in' => 'query',
                        'description' => 'Signature containing the checksum required for the check',
                        'type' => 'string',
                        'required' => true,
                        'example' => '',
                    ],
                ],
                'requestBody' => [
                    'content' => [
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['file', 'fileName', 'metadata'],
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                    'fileName' => [
                                        'description' => 'Friendly name of the file',
                                        'type' => 'string',
                                        'example' => 'myfile.txt',
                                    ],
                                    'fileHash' => [
                                        'description' => 'Sha256 hash of the file. If one is provided, then it has to match the actual sha256 hash of the uploaded file!',
                                        'type' => 'string',
                                        'example' => '0938744de39e1afc2d6bca532b937848918c9b4db41689576930d636bd73d275',
                                    ],
                                    'metadata' => [
                                        'description' => 'Metadata for the file',
                                        'type' => 'string',
                                        'example' => '{"key":"value"}',
                                    ],
                                    'metadataHash' => [
                                        'description' => 'Sha256 hash of the metadata. If one is provided, then it has to match the actual sha256 hash of the uploaded metadata!',
                                        'type' => 'string',
                                        'example' => '0938744de39e1afc2d6bca532b937848918c9b4db41689576930d636bd73d275',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            deserialize: false
        ),
        new Get(
            uriTemplate: '/files/{identifier}/download',
            controller: DownloadAction::class,
            openapiContext: [
                'tags' => ['Blob'],
                'summary' => 'Returns the file with given identifier as a binary response',
                'parameters' => [
                    [
                        'name' => 'bucketIdentifier',
                        'in' => 'query',
                        'description' => 'Config identifier of bucket.',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'test-bucket',
                    ],
                    [
                        'name' => 'creationTime',
                        'in' => 'query',
                        'description' => 'Current time as ATOM',
                        'type' => 'string',
                        'required' => true,
                        'example' => '2024-09-26T07:36:01+00:00',
                    ],
                    [
                        'name' => 'method',
                        'in' => 'query',
                        'description' => 'Method that gets executed',
                        'type' => 'string',
                        'required' => true,
                        'example' => 'GET',
                    ],
                    [
                        'name' => 'sig',
                        'in' => 'query',
                        'description' => 'Signature containing the checksum required for the check',
                        'type' => 'string',
                        'required' => true,
                        'example' => '',
                    ],
                ],
            ],
            normalizationContext: [
                'groups' => ['BlobFiles:output'],
                'jsonld_embed_context' => true,
            ],
            security: 'is_granted("IS_AUTHENTICATED_FULLY")',
            read: false,
            name: 'download'
        ),
    ],
    routePrefix: '/blob',
    normalizationContext: [
        'groups' => ['BlobFiles:output'],
        'jsonld_embed_context' => true,
    ],
    denormalizationContext: [
        'groups' => ['BlobFiles:input'],
    ]
)]
#[ORM\Table(name: 'blob_files')]
#[ORM\Entity]
class FileData
{
    #[ORM\Id]
    #[ORM\Column(type: 'relay_blob_uuid_binary', unique: true)]
    #[ApiProperty(identifier: true)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input'])]
    private ?string $identifier = null;

    #[ORM\Column(type: 'string', length: 512)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input'])]
    private ?string $prefix = null;

    #[ORM\Column(type: 'string', length: 512)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input', 'BlobFiles:update'])]
    private ?string $fileName = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['BlobFiles:output'])]
    private string $mimeType = '';

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['BlobFiles:input'])]
    private ?string $internalBucketId = null;

    private ?string $bucketId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['BlobFiles:output'])]
    private ?\DateTimeImmutable $dateCreated = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['BlobFiles:output'])]
    private ?\DateTimeImmutable $dateAccessed = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['BlobFiles:output'])]
    private ?\DateTimeImmutable $dateModified = null;

    #[Groups(['BlobFiles:input'])]
    private ?string $retentionDuration = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['BlobFiles:output', 'BlobFiles:update:exists'])]
    private ?\DateTimeImmutable $deleteAt = null;

    #[Groups(['BlobFiles:output'])]
    private ?string $contentUrl = null;

    private ?File $file = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input', 'BlobFiles:update'])]
    private ?string $metadata = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input', 'BlobFiles:update'])]
    private ?string $type = null;

    #[ORM\Column(type: 'integer')]
    #[Groups(['BlobFiles:output'])]
    private int $fileSize = 0;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    #[Groups(['BlobFiles:output'])]
    private ?string $fileHash = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    #[Groups(['BlobFiles:output'])]
    private ?string $metadataHash = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input', 'BlobFiles:update'])]
    private ?string $notifyEmail = null;

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(?string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): void
    {
        $this->mimeType = $mimeType;
    }

    public function getInternalBucketId(): ?string
    {
        return $this->internalBucketId;
    }

    public function setInternalBucketId(?string $internalBucketId): void
    {
        $this->internalBucketId = $internalBucketId;
    }

    public function getDateCreated(): ?\DateTimeImmutable
    {
        return $this->dateCreated;
    }

    public function setDateCreated(?\DateTimeImmutable $dateCreated): void
    {
        $this->dateCreated = $dateCreated;
    }

    public function getLastAccess(): ?\DateTimeImmutable
    {
        return $this->dateAccessed;
    }

    public function setLastAccess(?\DateTimeImmutable $dateAccessed): void
    {
        $this->dateAccessed = $dateAccessed;
    }

    public function getDateModified(): ?\DateTimeImmutable
    {
        return $this->dateModified;
    }

    public function setDateModified(?\DateTimeImmutable $dateModified): void
    {
        $this->dateModified = $dateModified;
    }

    public function getRetentionDuration(): ?string
    {
        return $this->retentionDuration;
    }

    public function setRetentionDuration(?string $retentionDuration): void
    {
        $this->retentionDuration = $retentionDuration;
    }

    public function getDeleteAt(): ?\DateTimeImmutable
    {
        return $this->deleteAt;
    }

    public function setDeleteAt(?\DateTimeImmutable $deleteAt): void
    {
        $this->deleteAt = $deleteAt;
    }

    public function getContentUrl(): ?string
    {
        return $this->contentUrl;
    }

    public function setContentUrl(?string $contentUrl): void
    {
        $this->contentUrl = $contentUrl;
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function setPrefix(?string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): void
    {
        $this->file = $file;
    }

    public function getMetadata(): ?string
    {
        return $this->metadata;
    }

    public function setMetadata(?string $additionalMetadata): void
    {
        $this->metadata = $additionalMetadata;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): void
    {
        $this->fileSize = $fileSize;
    }

    public function getFileHash(): ?string
    {
        return $this->fileHash;
    }

    public function setFileHash(?string $fileHash): void
    {
        $this->fileHash = $fileHash;
    }

    public function getMetadataHash(): ?string
    {
        return $this->metadataHash;
    }

    public function setMetadataHash(?string $metadataHash): void
    {
        $this->metadataHash = $metadataHash;
    }

    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }

    public function setNotifyEmail(?string $notifyEmail): void
    {
        $this->notifyEmail = $notifyEmail;
    }

    public function getBucketId(): ?string
    {
        return $this->bucketId;
    }

    public function setBucketId(?string $bucketId): void
    {
        $this->bucketId = $bucketId;
    }
}
