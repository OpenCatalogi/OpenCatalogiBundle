<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;

class DownloadObjectService
{
    /**
     * @var EntityManagerInterface The entity manager.
     */
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getContentType(string $extension): string
    {
        switch ($extension) {
            case 'pdf':
                return 'application/pdf';
            case 'json':
                return 'application/json';
            case 'yaml':
                return 'application/yaml';
            case 'xml':
                return 'text/xml';
            case 'csv':
                return 'text/csv';
            case 'html':
                return 'text/html';
            case 'xlsx':
                return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            case 'docx':
                return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            case 'zip':
                return 'application/zip';
            default:
                return $extension;
        }
    }

    public function getFileSize(string $url): int
    {
        $ch= curl_init();
        curl_setopt($ch, CURLOPT_URL,"$url");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,0);

        curl_setopt($ch, CURLOPT_HEADER,1);//HEADER REQURIED
        curl_setopt($ch, CURLOPT_NOBODY,1); // NO CONTENT BODY, DO NOT DOWNLOAD ACTUAL FILE
        curl_exec($ch);

        $CONTENT_LENGTH= curl_getinfo($ch,CURLINFO_CONTENT_LENGTH_DOWNLOAD); // PARSES THE RESPONSE HEADER AND GET FILE SIZE IN BYTES and -1 ON ERROR.
        curl_close($ch);
        return (int) $CONTENT_LENGTH;
    }

    public function enrichDownloadObject (array $data, array $configuration): array
    {
        $object = $this->entityManager->find($data['object']['_id'], 'App:ObjectEntity');
        if($object instanceof ObjectEntity === false) {
            return $data;
        }

        $url    = $object->getValue('url');

        $parsedUrl    = \Safe\parse_url($url);
        $explodedPath = explode('/', $parsedUrl['path']);
        $filename     = end($explodedPath);

        $explodedFilename = explode('.', $filename);
        $extension        = end($explodedFilename);

        $objectArray = [
            'url'     => $url,
            'naam'    => $filename,
            'type'    => $this->getContentType($extension),
            'grootte' => $this->getFileSize($url),
        ];

        $object->hydrate($objectArray);

        $this->entityManager->persist($object);
        $this->entityManager->flush();

        return $data;
    }
}