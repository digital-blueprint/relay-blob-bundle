<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use DateTimeZone;
use Dbp\Relay\BlobBundle\Entity\Bucket;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\BlobBundle\Helper\DenyAccessUnlessCheckSignature;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\TextUI\XmlConfiguration\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

date_default_timezone_set('UTC');

class BlobService
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var ConfigurationService
     */
    public $configurationService; //TODO maybe private

    /**
     * @var DatasystemProviderService
     */
    private $datasystemService;

    public function __construct(EntityManagerInterface $em, ConfigurationService $configurationService, DatasystemProviderService $datasystemService)
    {
        $this->em = $em;
        $this->configurationService = $configurationService;
        $this->datasystemService = $datasystemService;
    }

    public function setDatasystemService(DatasystemProviderService $datasystemService): void
    {
        $this->datasystemService = $datasystemService;
    }

    public function checkConnection()
    {
        $this->em->getConnection()->connect();
    }

    public function createFileData(Request $request): FileData
    {
        if ($identifier = $request->get('identifier')) {
            $fileData = $this->em->find(FileData::class, $identifier);
        } else {
            $fileData = new FileData();
            $fileData->setIdentifier((string) Uuid::v4());
        }

        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('file');

        // check if there is an uploaded file
        if (!$uploadedFile) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'No file with parameter key "file" was received!', 'blob:create-file-missing-file');
        }

        // If the upload failed, figure out why
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, $uploadedFile->getErrorMessage(), 'blob:create-file-upload-error');
        }

        // check if file is empty
        if ($uploadedFile->getSize() === 0) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Empty files cannot be added!', 'blob:create-file-empty-files-not-allowed');
        }

        $fileData->setFile($uploadedFile);

        $fileData->setPrefix($request->get('prefix', ''));
        $fileData->setFileName($request->get('fileName', 'no-name-file.txt'));
        $fileData->setFilesize(filesize($uploadedFile->getRealPath()));

        $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fileData->setDateCreated($time);
        $fileData->setLastAccess($time);

        // Check if json is valid
        $metadata = $request->get('additionalMetadata'); // default is null
        if ($metadata) {
            try {
                json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw ApiError::withDetails(Response::HTTP_UNPROCESSABLE_ENTITY, 'The additional Metadata doesn\'t contain valid json!', 'blob:blob-service-invalid-json');
            }
        }

        $fileData->setAdditionalMetadata($metadata);

        $fileData->setBucketID($request->get('bucketID', ''));

        $retentionDuration = $request->get('retentionDuration', '0');
        $fileData->setRetentionDuration($retentionDuration);

        return $fileData;
    }

    public function setBucket(FileData $fileData): Filedata
    {
        $bucket = $this->configurationService->getBucketByID($fileData->getBucketID());
        // bucket is not configured
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:create-file-not-configured-bucketID');
        }
        $fileData->setBucket($bucket);

        return $fileData;
    }

    public function saveFileData(FileData $fileData): void
    {
        $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fileData->setLastAccess($time);

        try {
//            dump($fileData);

            //throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
            $this->em->persist($fileData);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    public function saveFile(FileData $fileData): ?FileData
    {
        $i = $fileData->getIdentifier();
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $fileData = $datasystemService->saveFile($fileData);

        return $fileData;
    }

    public function getLink(FileData $fileData): FileData
    {
        $fileData->setBucket($this->configurationService->getBucketByID($fileData->getBucketID()));
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $fileData = $datasystemService->getLink($fileData, $fileData->getBucket()->getPolicies());

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Link could not be generated', 'blob:filedata-invalid');
        }

        return $fileData;
    }

    public function generateGETONELink(FileData $fileData, string $binary=''): string
    {
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Link could not be generated', 'blob:filedata-invalid');
        }

        $fileData->setBucket($this->configurationService->getBucketByID($fileData->getBucketID()));

        // get time now
        $now = new \DateTimeImmutable('now', new DateTimeZone('UTC'));

        $payload = [
            'cs' => $this->generateChecksumFromFileData($fileData, $binary, 'GETONE', $now),
        ];

        // set content url
        $filePath = $this->generateSignedContentUrl($fileData, 'GETONE', $now, $binary, DenyAccessUnlessCheckSignature::create($fileData->getBucket()->getPublicKey(), $payload));

        return $this->configurationService->getLinkUrl().substr($filePath, 1);
    }

    public function generateSignedContentUrl($fileData, $action, $now, $binary, $sig): string
    {
        if ($binary) {
            return '/blob/files/' . $fileData->getIdentifier() . '?bucketID=' . $fileData->getBucketID() . '&creationTime=' . strtotime($now->format('c')) . '&binary=1' . '&action=' . $action . '&sig=' . $sig;
        } else {
            return '/blob/files/' . $fileData->getIdentifier() . '?bucketID=' . $fileData->getBucketID() . '&creationTime=' . strtotime($now->format('c')) . '&action=' . $action . '&sig=' . $sig;
        }
    }

    public function generateChecksumFromFileData($fileData, $binary='', $action, $now): ?string
    {
        if (!$binary) {
            // create url to hash
            $contentUrl = '/blob/files/' . $fileData->getIdentifier() . '?bucketID=' . $fileData->getBucketID() . '&creationTime=' . strtotime($now->format('c')) . '&action=' . $action;
        } else {
            // create url to hash
            $contentUrl = '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getBucketID().'&creationTime='.strtotime($now->format('c')).'&binary=1'.'&action='.$action;
        }

        // create sha256 hash
        $cs = hash('sha256', $contentUrl);

        return $cs;
    }

    public function getFileData(string $identifier): FileData
    {
        //echo "    BlobService::getFileData($identifier)\n";
        /** @var FileData $fileData */
        $fileData = $this->em
            ->getRepository(FileData::class)
            ->find($identifier);

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:fileData-not-found');
        }

        return $fileData;
    }

    public function getFileDataByBucketIDAndPrefix(string $bucketID, string $prefix): array
    {
        //echo "    BlobService::getFileDataByBucketIDAndPrefix($bucketID, $prefix)\n";
        $fileDatas = $this->em
            ->getRepository(FileData::class)
            ->findBy(['bucketID' => $bucketID, 'prefix' => $prefix]);

        if (!$fileDatas) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileDatas was not found!', 'blob:fileDatas-not-found');
        }

        return $fileDatas;
    }

    public function getFileDataByBucketIDAndPrefixWithPagination(string $bucketID, string $prefix, int $currentPageNumber, int $maxNumItemsPerPage): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.bucketID = :bucketID')
            ->andWhere('f.prefix = :prefix')
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setParameter('prefix', $prefix)
            ->setFirstResult($maxNumItemsPerPage * ($currentPageNumber - 1))
            ->setMaxResults($maxNumItemsPerPage);

        return $query->getQuery()->getResult();
    }

    public function getQuotaOfBucket(string $bucketID): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.bucketID = :bucketID')
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->select('SUM(f.fileSize) as bucketSize');

        return $query->getQuery()->getOneOrNullResult();
    }

    public function getAllExpiringFiledatasByBucket(string $bucketId): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiring = $now->add(new \DateInterval($configurationService->getReportTime()));

        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.bucketID = :bucketID')
            ->andWhere('f.existsUntil <= :expiring')
            ->orderBy('f.notifyEmail', 'ASC')
            ->orderBy('f.existsUntil', 'ASC')
            ->setParameter('bucketID', $bucketId)
            ->setParameter('expiring', $expiring);

        return $query->getQuery()->getResult();
    }

    public function removeFileData(FileData $fileData)
    {
//        $i = $fileData->getIdentifier();
//        echo "    BlobService::removeFileData(identifier: {$i})\n";
        $bucket = $this->configurationService->getBucketByID($fileData->getBucketID());
        $fileData->setBucket($bucket);

        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $datasystemService->removeFile($fileData);

        $this->em->remove($fileData);
        $this->em->flush();
    }

    public function renameFileData(FileData $fileData)
    {
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $datasystemService->renameFile($fileData);

        $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fileData->setLastAccess($time);

        $this->em->persist($fileData);
        $this->em->flush();
    }

    public function increaseExistsUntil(FileData $fileData)
    {
        $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fileData->setLastAccess($time);

        //Check new date is not greater than maxretentiondate from bucket
        $maxRetentionTimeFromNow = $time->add(new \DateInterval($fileData->getBucket()->getMaxRetentionDuration()));
        if ($fileData->getExistsUntil() > $maxRetentionTimeFromNow) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'The given `exists until time` is longer then the max retention time of the bucket! Enter a time between now and '.$maxRetentionTimeFromNow->format('c'), 'blob:blob-service-invalid-max-retentiontime');
        }

        $this->em->persist($fileData);
        $this->em->flush();
    }

    public function cleanUp()
    {
        // get all invalid filedatas
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $invalidFileDataQuery = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.existsUntil < :now')
            ->setParameter('now', $now)
            ->getQuery();

        $invalidFileDatas = $invalidFileDataQuery->getResult();

        // Remove all links, files and reference
        foreach ($invalidFileDatas as $invalidFileData) {
            $invalidFileData = $this->setBucket($invalidFileData);
            $this->removeFileData($invalidFileData);
        }
    }

    public function sendNotifyQuota(Bucket $bucket)
    {
        $notifyQuotaConfig = $bucket->getNotifyQuotaConfig();

        $id = $bucket->getIdentifier();
        $name = $bucket->getName();
        $quota = $bucket->getQuota();

        $context = [
            'bucketId' => $id,
            'bucketName' => $name,
            'quota' => $quota,
        ];

        $this->sendEmail($notifyQuotaConfig, $context);
    }

    public function sendReporting()
    {
        $buckets = $this->configurationService->getBuckets();
        foreach ($buckets as $bucket) {
            $this->sendReportingForBucket($bucket);
        }
    }

    public function sendReportingForBucket(Bucket $bucket)
    {
        $reportingConfig = $bucket->getReportingConfig();

        $id = $bucket->getIdentifier();
        $name = $bucket->getName();

        $fileDatas = $this->getAllExpiringFiledatasByBucket($bucket->getIdentifier());

        if (!empty($fileDatas)) {
            // create for each email to be notified an array with expiring filedatas
            $notifyEmails = [];
            foreach ($fileDatas as $fileData) {
                /* @var ?FileData $fileData */
                $file['id'] = $fileData->getIdentifier();
                $file['fileName'] = $fileData->getFileName();
                $file['prefix'] = $fileData->getPrefix();
                $file['dateCreated'] = $fileData->getDateCreated()->format('c');
                $file['lastAccess'] = $fileData->getLastAccess()->format('c');
                $file['existsUnitl'] = $fileData->getExistsUntil()->format('c');
                if (empty($notifyEmails[$fileData->getNotifyEmail()])) {
                    $notifyEmails[$fileData->getNotifyEmail()] = [];
                }
                array_push($notifyEmails[$fileData->getNotifyEmail()], $file);
            }

            foreach ($notifyEmails as $email => $files) {
                $context = [
                    'bucketId' => $id,
                    'bucketName' => $name,
                    'files' => $files,
                ];

                $config = $reportingConfig;
                // replace the default email with a given email
                if ($email) {
                    $config['to'] = $email;
                }

                $this->sendEmail($config, $context);
            }
        }
    }

    private function sendEmail(array $config, array $context)
    {
        $loader = new FilesystemLoader(__DIR__.'/../Resources/views/');
        $twig = new Environment($loader);

        $template = $twig->load($config['html_template']);
        $html = $template->render($context);

        $transport = Transport::fromDsn($config['dsn']);
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from($config['from'])
            ->to($config['to'])
            ->subject($config['subject'])
            ->html($html);

        $mailer->send($email);
    }
}
