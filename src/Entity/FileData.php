<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

date_default_timezone_set('UTC');

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Table(name: 'blob_files')]
#[ORM\Entity]
class FileData
{
    #[ORM\Id]
    #[ORM\Column(type: 'relay_blob_uuid_binary', unique: true)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input'])]
    private string $identifier = '';

    #[ORM\Column(type: 'string', length: 512)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input'])]
    private string $prefix = '';

    #[ORM\Column(type: 'string', length: 512)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input', 'BlobFiles:update'])]
    private string $fileName = '';

    #[ORM\Column(type: 'string', length: 255)]
    #[Groups(['BlobFiles:output'])]
    private string $mimeType = '';

    #[ORM\Column(type: 'string', length: 50)]
    #[Groups(['BlobFiles:input'])]
    private string $internalBucketId = '';

    private ?Bucket $bucket = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['BlobFiles:output'])]
    private \DateTimeImmutable $dateCreated;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['BlobFiles:output'])]
    private \DateTimeImmutable $dateAccessed;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['BlobFiles:output'])]
    private \DateTimeImmutable $dateModified;

    #[Groups(['BlobFiles:input'])]
    private ?string $retentionDuration = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['BlobFiles:output', 'BlobFiles:update:exists'])]
    private ?\DateTimeImmutable $deleteAt = null;

    #[Groups(['BlobFiles:output'])]
    private string $contentUrl = '';

    /**
     * @var resource
     */
    #[Groups(['BlobFiles:input'])]
    private $file;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['BlobFiles:output', 'BlobFiles:input', 'BlobFiles:update'])]
    private string $metadata = '';

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

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): void
    {
        $this->mimeType = $mimeType;
    }

    public function getInternalBucketID(): string
    {
        return $this->internalBucketId;
    }

    public function setInternalBucketID(string $internalBucketId): void
    {
        $this->internalBucketId = $internalBucketId;
    }

    public function getBucket(): ?Bucket
    {
        return $this->bucket;
    }

    public function setBucket(Bucket $bucket): void
    {
        $this->bucket = $bucket;
    }

    public function getDateCreated(): \DateTimeImmutable
    {
        return $this->dateCreated;
    }

    public function setDateCreated(\DateTimeImmutable $dateCreated): void
    {
        $this->dateCreated = $dateCreated;
    }

    public function getLastAccess(): \DateTimeImmutable
    {
        return $this->dateAccessed;
    }

    public function setLastAccess(\DateTimeImmutable $dateAccessed): void
    {
        $this->dateAccessed = $dateAccessed;
    }

    public function getDateModified(): \DateTimeImmutable
    {
        return $this->dateModified;
    }

    public function setDateModified(\DateTimeImmutable $dateModified): void
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

    public function setContentUrl(string $contentUrl): void
    {
        $this->contentUrl = $contentUrl;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function getFile()
    {
        return $this->file;
    }

    public function setFile($file): void
    {
        $this->file = $file;
    }

    public function getMetadata(): ?string
    {
        return $this->metadata;
    }

    public function setMetadata($additionalMetadata): void
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
}
