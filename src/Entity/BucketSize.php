<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'blob_bucket_sizes')]
#[ORM\Entity]
class BucketSize
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $identifier = '';

    #[ORM\Column(type: 'integer')]
    private ?int $currentBucketSize = null;

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getCurrentBucketSize(): int
    {
        return $this->currentBucketSize ?? 0;
    }

    public function setCurrentBucketSize(int $currentBucketSize): self
    {
        $this->currentBucketSize = $currentBucketSize;

        return $this;
    }
}
