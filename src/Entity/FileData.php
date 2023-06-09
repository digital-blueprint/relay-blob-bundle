<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

date_default_timezone_set('UTC');

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use Dbp\Relay\BlobBundle\Controller\CreateFileDataAction;
use Dbp\Relay\BlobBundle\Controller\DeleteFileDatasByPrefix;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ORM\Entity
 * @ORM\Table(name="blob_files")
 * @ApiResource(
 *     collectionOperations={
 *         "post" = {
 *             "method" = "POST",
 *             "path" = "/blob/files",
 *             "controller" = CreateFileDataAction::class,
 *             "deserialize" = false,
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "requestBody" = {
 *                     "content" = {
 *                         "multipart/form-data" = {
 *                             "schema" = {
 *                                 "type" = "object",
 *                                 "properties" = {
 *                                     "file" = {"type" = "string", "format" = "binary"},
 *                                     "prefix" = {"description" = "Prefix of the file", "type" = "string", "example" = "my-prefix/my-subprefix"},
 *                                     "fileName" = {"description" = "Friendly name of the file", "type" = "string", "example" = "myfile"},
 *                                     "bucketID" = {"description" = "ID of the bucket", "type" = "string", "example" = "1234"},
 *                                     "retentionDuration" = {"description" = "Max time in timestamp duration in ISO 8601 format from creation date when file will be deleted", "type" = "string", "example" = "P2YT6H"},
 *                                     "notifyMail" = {"description" = "An email address which gets notified before the files expires", "type" = "string", "example" = "test@test.com"},
 *                                     "additionalMetadata" = {"description" = "Additional Metadata for the file", "type" = "object", "example" = "{""myFileData"": ""my File additional Data""}"},
 *                                 },
 *                                 "required" = {"file", "bucketID"},
 *                             },
 *                         }
 *                     }
 *                 },
 *             },
 *         },
 *         "get" = {
 *             "pagination_client_partial" = true,
 *             "path" = "/blob/files",
 *             "pagination_client_partial" = true,
 *             "normalization_context" = {
 *                 "jsonld_embed_context" = true,
 *                 "groups" = {"BlobFiles:output"}
 *             },
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "summary" = "Get the fileshares of a specific bucket with a specific prefix",
 *                 "parameters" = {
 *                     {"name" = "bucketID", "in" = "query", "description" = "Identifier of bucket", "type" = "string", "required" = true, "example" = "1234"},
 *                     {"name" = "creationTime", "in" = "query", "description" = "Current timestamp in seconds", "type" = "string", "required" = true, "example" = "1688636927"},
 *                     {"name" = "prefix", "in" = "query", "description" = "Prefix of a file collection", "type" = "string", "required" = true, "example" = "my-prefix/my-subprefix"},
 *                     {"name" = "action", "in" = "query", "description" = "Action that gets executed", "type" = "string", "required" = true, "example" = "GETALL"},
 *                     {"name" = "binary", "in" = "query", "description" = "If the returned link redirects to the binary or not", "type" = "string", "required" = false, "example" = "1"},
 *                     {"name" = "sig", "in" = "query", "description" = "Signature containing the checksum required for the check", "type" = "string", "required" = true, "example" = ""}
 *                 }
 *             }
 *         },
 *         "delete_byPrefix" = {
 *             "method" = "DELETE",
 *             "path" = "/blob/files",
 *             "controller" = DeleteFileDatasByPrefix::class,
 *             "read" = false,
 *             "normalization_context" = {
 *                 "jsonld_embed_context" = true,
 *                 "groups" = {"BlobFiles:output"}
 *             },
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "summary" = "Deletes the files of a specific bucket with a specific prefix",
 *                 "parameters" = {
 *                     {"name" = "bucketID", "in" = "query", "description" = "Identifier of bucket", "type" = "string", "required" = true, "example" = "1234"},
 *                     {"name" = "creationTime", "in" = "query", "description" = "Current timestamp in seconds", "type" = "string", "required" = true, "example" = "1688636927"},
 *                     {"name" = "prefix", "in" = "query", "description" = "Prefix of a file collection", "type" = "string", "required" = true, "example" = "my-prefix/my-subprefix"},
 *                     {"name" = "action", "in" = "query", "description" = "Action that gets executed", "type" = "string", "required" = true, "example" = "DELTEALL"},
 *                     {"name" = "sig", "in" = "query", "description" = "Signature containing the checksum required for the check", "type" = "string", "required" = true, "example" = ""}
 *                 }
 *             }
 *         }
 *     },
 *     itemOperations={
 *         "get" = {
 *             "method" = "GET",
 *             "path" = "/blob/files/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "summary" = "Get the fileshare of a specific bucket with a specific prefix and a specific id",
 *                 "parameters" = {
 *                     {"name" = "bucketID", "in" = "query", "description" = "Identifier of bucket", "type" = "string", "required" = true, "example" = "1234"},
 *                     {"name" = "creationTime", "in" = "query", "description" = "Current timestamp in seconds", "type" = "string", "required" = true, "example" = "1688636927"},
 *                     {"name" = "prefix", "in" = "query", "description" = "Prefix of a file collection", "type" = "string", "required" = true, "example" = "my-prefix/my-subprefix"},
 *                     {"name" = "action", "in" = "query", "description" = "Action that gets executed", "type" = "string", "required" = true, "example" = "GETONE"},
 *                     {"name" = "binary", "in" = "query", "description" = "If the returned link redirects to the binary or not", "type" = "string", "required" = false, "example" = "1"},
 *                     {"name" = "sig", "in" = "query", "description" = "Signature containing the checksum required for the check", "type" = "string", "required" = true, "example" = ""}
 *                 }
 *             },
 *         },
 *         "put" = {
 *             "path" = "/blob/files/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "summary" = "Change the filename of a fileshare of a specific bucket with a specific prefix and a specific id",
 *                 "parameters" = {
 *                     {"name" = "bucketID", "in" = "query", "description" = "Identifier of bucket", "type" = "string", "required" = true, "example" = "1234"},
 *                     {"name" = "creationTime", "in" = "query", "description" = "Current timestamp in seconds", "type" = "string", "required" = true, "example" = "1688636927"},
 *                     {"name" = "prefix", "in" = "query", "description" = "Prefix of a file collection", "type" = "string", "required" = true, "example" = "my-prefix/my-subprefix"},
 *                     {"name" = "action", "in" = "query", "description" = "Action that gets executed", "type" = "string", "required" = true, "example" = "PUTONE"},
 *                     {"name" = "fileName", "in" = "query", "description" = "New filename", "type" = "string", "required" = true, "example" = ""},
 *                     {"name" = "sig", "in" = "query", "description" = "Signature containing the checksum required for the check", "type" = "string", "required" = true, "example" = ""}
 *                 }
 *             },
 *             "denormalization_context" = {
 *                 "jsonld_embed_context" = true,
 *                 "groups" = {"BlobFiles:update"}
 *             },
 *         },
 *         "put_exists_until" = {
 *             "method" = "PUT",
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "path" = "/blob/files/{identifier}/exists_until",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *             },
 *             "denormalization_context" = {
 *                 "jsonld_embed_context" = true,
 *                 "groups" = {"BlobFiles:update:exists"}
 *             },
 *         },
 *         "delete" = {
 *             "method" = "DELETE",
 *             "path" = "/blob/files/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Blob"},
 *                 "summary" = "Delete a fileshare of a specific bucket with a specific prefix and a specific id",
 *                 "parameters" = {
 *                     {"name" = "bucketID", "in" = "query", "description" = "Identifier of bucket", "type" = "string", "required" = true, "example" = "1234"},
 *                     {"name" = "creationTime", "in" = "query", "description" = "Current timestamp in seconds", "type" = "string", "required" = true, "example" = "1688636927"},
 *                     {"name" = "prefix", "in" = "query", "description" = "Prefix of a file collection", "type" = "string", "required" = true, "example" = "my-prefix/my-subprefix"},
 *                     {"name" = "action", "in" = "query", "description" = "Action that gets executed", "type" = "string", "required" = true, "example" = "DELETEONE"},
 *                     {"name" = "sig", "in" = "query", "description" = "Signature containing the checksum required for the check", "type" = "string", "required" = true, "example" = ""}
 *                 }
 *             },
 *             "denormalization_context" = {
 *                 "jsonld_embed_context" = true,
 *                 "groups" = {"BlobFiles:exists"}
 *             },
 *         }
 *     },
 *     iri="https://schema.org/DigitalDocument",
 *     shortName="BlobFiles",
 *     normalizationContext={
 *         "groups" = {"BlobFiles:output"},
 *         "jsonld_embed_context" = true
 *     },
 *     denormalizationContext={
 *         "groups" = {"BlobFiles:input"},
 *         "jsonld_embed_context" = true
 *     }
 * )
 */
class FileData
{
    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=50)
     *
     * @ApiProperty(identifier=true)
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     */
    private $identifier;

    /**
     * @ORM\Column(type="string", length=255)
     * @ApiProperty(iri="https://schema.org/Text")
     * @Groups({"BlobFiles:output", "BlobFiles:input"})
     *
     * @var string
     */
    private $prefix;

    /**
     * @ORM\Column(type="string", length=50)
     * @ApiProperty(iri="https://schema.org/name")
     * @Groups({"BlobFiles:output", "BlobFiles:input", "BlobFiles:update"})
     *
     * @var string
     */
    private $fileName;

    /**
     * @ORM\Column(type="string", length=50)
     * @Groups({"BlobFiles:output"})
     *
     * @var string
     */
    private $extension;

    /**
     * @ORM\Column(type="string", length=50)
     * @ApiProperty(iri="https://schema.org/identifier")
     * @Groups({"BlobFiles:input"})
     *
     * @var string
     */
    private $bucketID;

    /**
     * @var Bucket
     */
    private $bucket;

    /**
     * @ORM\Column(type="datetime_immutable")
     * @ApiProperty(iri="https://schema.org/dateCreated")
     * @Groups({"BlobFiles:output"})
     *
     * @var \DateTimeImmutable
     */
    private $dateCreated;

    /**
     * @ORM\Column(type="datetime_immutable")
     * @ApiProperty(iri="https://schema.org/dateRead")
     * @Groups({"BlobFiles:output"})
     *
     * @var \DateTimeImmutable
     */
    private $lastAccess;

    /**
     * @ApiProperty(iri="https://schema.org/duration")
     * @Groups({"BlobFiles:input"})
     *
     * @var string|null
     */
    private $retentionDuration;

    /**
     * @ORM\Column(type="datetime_immutable")
     * @ApiProperty(iri="https://schema.org/expires")
     * @Groups({"BlobFiles:output", "BlobFiles:update:exists"})
     *
     * @var \DateTimeImmutable
     */
    private $existsUntil;

    /**
     * @ApiProperty(iri="https://schema.org/url")
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
     * @ORM\Column(type="text", nullable=true)
     * @ApiProperty(iri="https://schema.org/DataFeed")
     * @Groups({"BlobFiles:output", "BlobFiles:input", "BlobFiles:update"})
     *
     * @var string
     */
    private $additionalMetadata;

    /**
     * @ORM\Column(type="integer")
     * @ApiProperty(iri="https://schema.org/contentSize")
     * @Groups({"BlobFiles:output"})
     *
     * @var int
     */
    private $fileSize;

    /**
     * @ORM\Column(type="string", length=255)
     * @ApiProperty(iri="https://schema.org/email")
     * @Groups({"BlobFiles:output", "BlobFiles:input", "BlobFiles:update"})
     *
     * @var string
     */
    private $notifyEmail;

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

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function setExtension(string $extension): void
    {
        $this->extension = $extension;
    }

    public function getBucketID(): string
    {
        return $this->bucketID;
    }

    public function setBucketID(string $bucketID): void
    {
        $this->bucketID = $bucketID;
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
        return $this->lastAccess;
    }

    public function setLastAccess(\DateTimeImmutable $lastAccess): void
    {
        $this->lastAccess = $lastAccess;
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

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): void
    {
        $this->fileSize = $fileSize;
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
