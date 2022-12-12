<?php

declare(strict_types=1);

namespace Dbp\Relay\BlobBundle\Service;

use Dbp\Relay\BlobBundle\Entity\Bucket;
use Dbp\Relay\BlobBundle\Entity\FileData;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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

    public function __construct(ManagerRegistry $managerRegistry, ConfigurationService $configurationService, DatasystemProviderService $datasystemService)
    {
        $manager = $managerRegistry->getManager('dbp_relay_blob_bundle');
        assert($manager instanceof EntityManagerInterface);
        $this->em = $manager;

        $this->configurationService = $configurationService;
        $this->datasystemService = $datasystemService;
    }

    public function checkConnection()
    {
        $this->em->getConnection()->connect();
    }

    public function createFileData(Request $request): FileData
    {
        $fileData = new FileData();

        $fileData->setIdentifier((string) Uuid::v4());

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
        //check bucket ID exists
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
        try {
            $this->em->persist($fileData);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    public function saveFile(FileData $fileData): ?FileData
    {
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $fileData = $datasystemService->saveFile($fileData);

        return $fileData;
    }

    public function getLink(FileData $fileData): ?FileData
    {
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $fileData = $datasystemService->getLink($fileData, $fileData->getBucket()->getPolicies());

        return $fileData;
    }

    public function getFileData(string $identifier): FileData
    {
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
        $expiring = $now->add(new \DateInterval('P30D'));

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
        $loader = new FilesystemLoader(dirname(__FILE__).'/../Resources/views/');
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
