<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Controller;

use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Service\BlobService;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CreateFileAction extends BaseBlobController
{
    /**
     * @var BlobService
     */
    private $blobService;

    public function __construct(BlobService $blobService)
    {
        $this->blobService = $blobService;
    }

    /**
     * @throws HttpException
     */
    public function __invoke(FileData $fileData): FileData
    {
        // Check bucketID
        // create id

        // check folder
        // create folder
        // rename datei
        // Uploadfile

        //check if file uploaded
        // if upload failed, figure aut why

        // create share link
        // save share link to database with valid unit date from config

        // return sharelink
        $contentUrl = 'my-url';
        $fileDataIdentifier = '1234';

        return $this->blobService->saveFile($fileData, $fileDataIdentifier, $contentUrl);
    }
}
