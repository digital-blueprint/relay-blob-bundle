<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

date_default_timezone_set('UTC');

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity
 *
 * @ORM\Table(name="blob_files")
 */
class FileData
{
    /**
     * @var string
     *
     * @ORM\Id
     * @ORM\Column(type="uuid_binary", unique=true)
     *
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     */
    private $identifier;

    /**
     * @ORM\Column(type="string", length=512)
     *
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     *
     * @var string
     */
    private $prefix;

    /**
     * @ORM\Column(type="string", length=512)
     *
     * @Groups({"BlobFiles:output", "BlobFiles:input", "BlobFiles:update"})
     *
     * @var string
     */
    private $fileName;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Groups({"BlobFiles:output"})
     *
     * @var string
     */
    private $mimeType;

    /**
     * @ORM\Column(type="string", length=50)
     *
     * @Groups({"BlobFiles:input"})
     *
     * @var string
     */
    private $internalBucketId;

    /**
     * @var Bucket
     */
    private $bucket;

    /**
     * @ORM\Column(type="datetime_immutable")
     *
     * @Groups({"BlobFiles:output"})
     *
     * @var \DateTimeImmutable
     */
    private $dateCreated;

    /**
     * @ORM\Column(type="datetime_immutable")
     *
     * @Groups({"BlobFiles:output"})
     *
     * @var \DateTimeImmutable
     */
    private $dateAccessed;

    /**
     * @ORM\Column(type="datetime_immutable")
     *
     * @Groups({"BlobFiles:output"})
     *
     * @var \DateTimeImmutable
     */
    private $dateModified;

    /**
     * @Groups({"BlobFiles:input"})
     *
     * @var string|null
     */
    private $retentionDuration;

    /**
     * @ORM\Column(type="datetime_immutable")
     *
     * @Groups({"BlobFiles:output", "BlobFiles:update:exists"})
     *
     * @var \DateTimeImmutable
     */
    private $existsUntil;

    /**
     * @Groups({"BlobFiles:output"})
     *
     * @var string
     */
    private $contentUrl;

    /**
     * @Groups({"BlobFiles:input"})
     *
     * @var resource
     */
    private $file;

    /**
     * @ORM\Column(type="json", nullable=true)
     *
     * @Groups({"BlobFiles:output", "BlobFiles:input", "BlobFiles:update"})
     *
     * @var string
     */
    private $additionalMetadata;

    /**
     * @ORM\Column(type="text", nullable=true)
     *
     * @Groups({"BlobFiles:output", "BlobFiles:input", "BlobFiles:update"})
     *
     * @var string
     */
    private $additionalType;

    /**
     * @ORM\Column(type="integer")
     *
     * @Groups({"BlobFiles:output"})
     *
     * @var int
     */
    private $fileSize;

    /**
     * @ORM\Column(type="string", length=64)
     *
     * @Groups({"BlobFiles:output"})
     *
     * @var string|null
     */
    private $fileHash;

    /**
     * @ORM\Column(type="string", length=64)
     *
     * @Groups({"BlobFiles:output"})
     *
     * @var string|null
     */
    private $metadataHash;

    /**
     * @ORM\Column(type="string", length=255)
     *
     * @Groups({"BlobFiles:output", "BlobFiles:input", "BlobFiles:update"})
     *
     * @var string
     */
    private $notifyEmail;

    public function getIdentifier(): string
    {
        return (string)$this->identifier;
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

    public function getBucket(): Bucket
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

    public function setRetentionDuration($retentionDuration): void
    {
        $this->retentionDuration = $retentionDuration;
    }

    public function getExistsUntil(): \DateTimeImmutable
    {
        return $this->existsUntil;
    }

    public function setExistsUntil(\DateTimeImmutable $existsUntil): void
    {
        $this->existsUntil = $existsUntil;
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

    public function getAdditionalMetadata(): ?string
    {
        return $this->additionalMetadata;
    }

    public function setAdditionalMetadata($additionalMetadata): void
    {
        $this->additionalMetadata = $additionalMetadata;
    }

    public function getAdditionalType(): ?string
    {
        return $this->additionalType;
    }

    public function setAdditionalType($additionalType): void
    {
        $this->additionalType = $additionalType;
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

    public function setFileHash(string $fileHash): void
    {
        $this->fileHash = $fileHash;
    }

    public function getMetadataHash(): ?string
    {
        return $this->metadataHash;
    }

    public function setMetadataHash(string $metadataHash): void
    {
        $this->metadataHash = $metadataHash;
    }

    public function getNotifyEmail(): ?string
    {
        return $this->notifyEmail;
    }

    public function setNotifyEmail(string $notifyEmail): void
    {
        $this->notifyEmail = $notifyEmail;
    }
}
