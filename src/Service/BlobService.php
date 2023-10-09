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

    /**
     * Creates a new fileData element and saves all the data from the request in it, if the request is valid.
     *
     * @param Request $request request which provides all the data
     *
     * @throws \Exception
     */
    public function createFileData(Request $request): FileData
    {
        // create new identifier for new file
        $fileData = new FileData();
        $fileData->setIdentifier((string) Uuid::v4());

        // get file from request
        /** @var ?UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('file');

        // check if there is an uploaded file
        if (!$uploadedFile) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'No file with parameter key "file" was received!', 'blob:create-file-data-missing-file');
        }

        // If the upload failed, figure out why
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, $uploadedFile->getErrorMessage(), 'blob:create-file-data-upload-error');
        }

        // check if file is empty
        if ($uploadedFile->getSize() === 0) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'Empty files cannot be added!', 'blob:create-file-data-empty-files-not-allowed');
        }

        // set the file in the fileData
        $fileData->setFile($uploadedFile);

        // set prefix, filename and filesize
        $fileData->setPrefix($request->get('prefix', ''));
        $fileData->setFileName($request->get('fileName', 'no-name-file.txt'));
        $fileData->setFilesize(filesize($uploadedFile->getRealPath()));

        // set creationTime and last access time
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

        // set metadata, bucketID and retentionDuration
        $fileData->setAdditionalMetadata($metadata);
        $fileData->setBucketID($request->get('bucketID', ''));
        $retentionDuration = $request->get('retentionDuration', '0');
        $fileData->setRetentionDuration($retentionDuration);

        return $fileData;
    }

    /**
     * Sets the bucket of the given fileData and returns the fileData with set bucket.
     *
     * @param FileData $fileData fileData which is missing the bucket
     */
    public function setBucket(FileData $fileData): Filedata
    {
        // get bucket by bucketID
        $bucket = $this->configurationService->getBucketByID($fileData->getBucketID());
        // bucket is not configured
        if (!$bucket) {
            throw ApiError::withDetails(Response::HTTP_BAD_REQUEST, 'BucketID is not configured', 'blob:create-file-data-not-configured-bucket-id');
        }
        $fileData->setBucket($bucket);

        return $fileData;
    }

    /**
     * Used to persist given fileData in entity manager, and automatically adapts last access timestamp.
     *
     * @param FileData $fileData fileData to be persisted
     *
     * @throws \Exception
     */
    public function saveFileData(FileData $fileData): void
    {
        // save last access time
        $time = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $fileData->setLastAccess($time);

        // try to persist fileData, or throw error
        try {
            $this->em->persist($fileData);
            $this->em->flush();
        } catch (\Exception $e) {
            throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'File could not be saved!', 'blob:file-not-saved', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Saves the file using the connector.
     *
     * @param FileData $fileData fileData that carries the file which should be saved
     */
    public function saveFile(FileData $fileData): ?FileData
    {
        // get the service of the bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());

        // save the file using the connector
        $fileData = $datasystemService->saveFile($fileData);

        return $fileData;
    }

    /**
     * Get HTTP link to binary content.
     *
     * @param FileData $fileData fileData for which a link should be provided
     *
     * @throws \Exception
     */
    public function getLink(FileData $fileData): FileData
    {
        // set bucket of fileData by bucketID
        $fileData->setBucket($this->configurationService->getBucketByID($fileData->getBucketID()));

        // get service from bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());

        // get HTTP link with connector for fileData
        $fileData = $datasystemService->getLink($fileData, $fileData->getBucket()->getPolicies());

        // if !fileData, then something went wrong
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Link could not be generated', 'blob:file-data-invalid');
        }

        return $fileData;
    }

    /**
     * Get file content as base64 contentUrl.
     *
     * @param FileData $fileData fileData for which the base64 encoded file should be provided
     */
    public function getBase64Data(FileData $fileData): FileData
    {
        // set bucket of fileData
        $fileData->setBucket($this->configurationService->getBucketByID($fileData->getBucketID()));

        // get service of bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());

        // get base64 encoded file with connector
        $fileData = $datasystemService->getBase64Data($fileData, $fileData->getBucket()->getPolicies());

        // if !fileData, then something went wrong
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'base64 encoded data could not be generated', 'blob:file-data-invalid');
        }

        return $fileData;
    }

    /**
     * Get file as binary response.
     *
     * @param FileData $fileData fileData from which the file is taken
     */
    public function getBinaryResponse(FileData $fileData): Response
    {
        // set bucket of fileData
        $fileData->setBucket($this->configurationService->getBucketByID($fileData->getBucketID()));

        // get service of bucket
        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());

        // get binary response of file with connector
        $response = $datasystemService->getBinaryResponse($fileData, $fileData->getBucket()->getPolicies());

        // if !response, then something went wrong
        if (!$response) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Response could not be generated', 'blob:file-data-invalid');
        }

        return $response;
    }

    /**
     * Get the secret of the bucket with given bucketID.
     *
     * @param string $bucketID bucketID of bucket from which the secret should be taken
     */
    public function getSecretOfBucketWithBucketID(string $bucketID): string
    {
        $bucket = $this->configurationService->getBucketByID($bucketID);

        return $bucket->getKey();
    }

    /**
     * Generate HTTP link to blob resource.
     *
     * @throws \JsonException
     */
    public function generateGETLink(string $baseUrl, FileData $fileData, string $includeData = ''): string
    {
        // check if fileData is present
        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_FORBIDDEN, 'Link could not be generated', 'blob:file-data-invalid');
        }

        // set bucket of fileData
        $fileData->setBucket($this->configurationService->getBucketByID($fileData->getBucketID()));

        // get time now
        $now = new \DateTimeImmutable('now', new DateTimeZone('UTC'));

        // generate checksum and encode it in payload
        $payload = [
            'cs' => $this->generateChecksumFromFileData($fileData, 'GET', $now, $includeData),
        ];

        // set content url
        $filePath = $this->generateSignedContentUrl($fileData, 'GET', $now, $includeData, DenyAccessUnlessCheckSignature::create($fileData->getBucket()->getKey(), $payload));

        // build and return HTTP path
        return $baseUrl.'/'.substr($filePath, 1);
    }

    /**
     * Generate signed content url for get requests by identifier
     * This is useful for generating the HTTP contentUrls for every fileData.
     *
     * @param $fileData fileData for which the HTTP url should be generated
     * @param $urlMethod method which is used
     * @param $now timestamp of now which is used as creationTime
     * @param $includeData specifies whether includeData should be =1 or left out
     * @param $sig signature with checksum that is used
     */
    public function generateSignedContentUrl($fileData, $urlMethod, $now, $includeData, $sig): string
    {
        if ($includeData) {
            return '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getBucketID().'&creationTime='.strtotime($now->format('c')).'&includeData=1'.'&method='.$urlMethod.'&sig='.$sig;
        } else {
            return '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getBucketID().'&creationTime='.strtotime($now->format('c')).'&method='.$urlMethod.'&sig='.$sig;
        }
    }

    /**
     * Generate the sha256 hash from a HTTP url.
     *
     * @param $fileData fileData for which the HTTP url should be generated
     * @param $urlMethod method used in the request
     * @param $now timestamp of now which is used as creationTime
     * @param $includeData specified whether includeData should be =1 or left out
     */
    public function generateChecksumFromFileData($fileData, $urlMethod, $now, $includeData = ''): ?string
    {
        // check whether includeData should be in url or not
        if (!$includeData) {
            // create url to hash
            $contentUrl = '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getBucketID().'&creationTime='.strtotime($now->format('c')).'&method='.$urlMethod;
        } else {
            // create url to hash
            $contentUrl = '/blob/files/'.$fileData->getIdentifier().'?bucketID='.$fileData->getBucketID().'&creationTime='.strtotime($now->format('c')).'&includeData=1'.'&method='.$urlMethod;
        }

        // create sha256 hash
        $cs = hash('sha256', $contentUrl);

        return $cs;
    }

    /**
     * Get fileData for file with given identifier.
     */
    public function getFileData(string $identifier): FileData
    {
        /** @var FileData $fileData */
        $fileData = $this->em
            ->getRepository(FileData::class)
            ->find($identifier);

        if (!$fileData) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileData was not found!', 'blob:file-data-not-found');
        }

        return $fileData;
    }

    /**
     * Get all the fileDatas of a given bucketID and prefix.
     */
    public function getFileDataByBucketIDAndPrefix(string $bucketID, string $prefix): array
    {
        $fileDatas = $this->em
            ->getRepository(FileData::class)
            ->findBy(['bucketID' => $bucketID, 'prefix' => $prefix]);

        if (!$fileDatas) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileDatas was not found!', 'blob:file-data-not-found');
        }

        return $fileDatas;
    }

    /**
     * Get all the fileDatas of a given bucketID and that starts with prefix.
     */
    public function getFileDataByBucketIDAndStartsWithPrefix(string $bucketID, string $prefix): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.bucketID = :bucketID')
            ->andWhere('f.prefix LIKE :prefix')
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setParameter('prefix', $prefix.'%');

        $result = $query->getQuery()->getResult();

        if (!$result) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND, 'FileDatas was not found!', 'blob:file-data-not-found');
        }

        return $result;
    }

    /**
     * Get all the fileDatas of a given bucketID and prefix with pagination limits.
     */
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

    /**
     * Get all the fileDatas of a given bucketID that start with prefix with pagination limits.
     */
    public function getFileDataByBucketIDAndStartsWithPrefixWithPagination(string $bucketID, string $prefix, int $currentPageNumber, int $maxNumItemsPerPage): array
    {
        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.bucketID = :bucketID')
            ->andWhere('f.prefix LIKE :prefix')
            ->orderBy('f.dateCreated', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setParameter('prefix', $prefix.'%')
            ->setFirstResult($maxNumItemsPerPage * ($currentPageNumber - 1))
            ->setMaxResults($maxNumItemsPerPage);

        return $query->getQuery()->getResult();
    }

    /**
     * Get quota of the bucket with given bucketID.
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
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

    /**
     * Get all the fileDatas which expire in the defined time period by bucketID.
     *
     * @throws \Exception
     */
    public function getAllExpiringFiledatasByBucket(string $bucketID): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiring = $now->add(new \DateInterval($this->configurationService->getBucketByID($bucketID)->getReportExpiryWhenIn()));

        $query = $this->em
            ->getRepository(FileData::class)
            ->createQueryBuilder('f')
            ->where('f.bucketID = :bucketID')
            ->andWhere('f.existsUntil <= :expiring')
            ->orderBy('f.notifyEmail', 'ASC')
            ->orderBy('f.existsUntil', 'ASC')
            ->setParameter('bucketID', $bucketID)
            ->setParameter('expiring', $expiring);

        return $query->getQuery()->getResult();
    }

    /**
     * Remove a given fileData from the entity manager.
     *
     * @return void
     */
    public function removeFileData(FileData $fileData)
    {
        $bucket = $this->configurationService->getBucketByID($fileData->getBucketID());
        $fileData->setBucket($bucket);

        $datasystemService = $this->datasystemService->getServiceByBucket($fileData->getBucket());
        $datasystemService->removeFile($fileData);

        $this->em->remove($fileData);
        $this->em->flush();
    }

    /**
     * Increases the exists_until time for a given fileData.
     *
     * @return void
     *
     * @throws \Exception
     */
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

    /**
     * Cleans the table from resources that exceeded their existsUntil date.
     *
     * @return void
     *
     * @throws \Exception
     */
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

    /**
     * Sends an warning email with information about the buckets used quota.
     *
     * @return void
     */
    public function sendQuotaWarning(Bucket $bucket, float $bucketQuotaByte)
    {
        $notifyQuotaConfig = $bucket->getNotifyQuotaOverConfig();

        $id = $bucket->getIdentifier();
        $name = $bucket->getName();
        $quota = $bucket->getQuota();

        $context = [
            'bucketId' => $id,
            'bucketName' => $name,
            'quota' => $quota,
            'filledTo' => ($bucketQuotaByte / ($quota * 1024 * 1024)) * 100,
        ];

        $this->sendEmail($notifyQuotaConfig, $context);
    }

    /**
     * Sends an email that the quota of the bucket is reached.
     *
     * @return void
     */
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

        //$this->sendEmail($notifyQuotaConfig, $context);
    }

    /**
     * Sends reporting and bucket quota warning email if needed.
     *
     * @return void
     */
    public function sendReporting()
    {
        $buckets = $this->configurationService->getBuckets();
        foreach ($buckets as $bucket) {
            $this->sendReportingForBucket($bucket);
            $this->sendBucketQuotaWarning($bucket);
        }
    }

    /**
     * Checks whether the bucket is filled to a preconfigured percentage, and sends a warning email if so.
     *
     * @return void
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function sendBucketQuotaWarning(Bucket $bucket)
    {
        // Check quota
        $bucketQuotaByte = $this->getQuotaOfBucket($bucket->getIdentifier())['bucketSize']; // Convert mb to Byte
        $bucketWarningQuotaByte = $bucket->getQuota() * 1024 * 1024 * ($bucket->getNotifyWhenQuotaOver() / 100); // Convert mb to Byte and then calculate the warning quota
        if (floatval($bucketQuotaByte) > floatval($bucketWarningQuotaByte)) {
            $this->sendQuotaWarning($bucket, floatval($bucketQuotaByte));
        }
    }

    /**
     * Checks whether some files will expire soon, and sends a email to the bucket owner
     * or owner of the file (if configured as notifyEmail).
     *
     * @return void
     *
     * @throws \Exception
     */
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

    /**
     * Wrapper to send an email from a given context.
     *
     * @return void
     *
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
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
