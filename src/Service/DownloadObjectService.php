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


    /**
     * @param EntityManagerInterface $entityManager The entity manager.
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;

    }//end __construct()


    /**
     * Returns the content type for well known file formats.
     *
     * @param string $extension The extension of the file.
     *
     * @return string The resulting content type, the extension if the content type is not known.
     */
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
        }//end switch

    }//end getContentType()


    /**
     * Perform a call on the server to retrieve the size of the download.
     *
     * NOTE: not all servers return a truthful answer, some might return just -1.
     *
     * @param string $url The url to perform the call on.
     *
     * @return int The size of the download.
     */
    public function getFileSize(string $url): int
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$url");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        // Headers are required to be sent by the server.
        curl_setopt($ch, CURLOPT_HEADER, 1);
        // Do not download the content body, just fetch metadata.
        curl_setopt($ch, CURLOPT_NOBODY, 1);
        curl_exec($ch);

        // Parses the response header and returns the size of the download, or -1 on error.
        $CONTENT_LENGTH = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        return (int) $CONTENT_LENGTH;

    }//end getFileSize()


    /**
     * Adds a name, size, content type to the download object if an url is set.
     *
     * @param array $data           The data from the
     * @param array $configuration
     * @return array
     */
    public function enrichDownloadObject(array $data, array $configuration): array
    {
        $object = $this->entityManager->find('App:ObjectEntity', $data['object']->getId()->toString());
        if ($object instanceof ObjectEntity === false) {
            return $data;
        }

        // Fetch the url from the object.
        $url = $object->getValue('url');

        // Get the filename by reading the last part of the url path.
        $parsedUrl    = \Safe\parse_url($url);
        $explodedPath = explode('/', $parsedUrl['path']);
        $filename     = end($explodedPath);

        // Get the extension by reading the part of the filename behind the last dot.
        $explodedFilename = explode('.', $filename);
        $extension        = end($explodedFilename);

        // Build the renewed download object.
        $objectArray = [
            'url'     => $url,
            'naam'    => $filename,
            'type'    => $this->getContentType($extension),
            'grootte' => $this->getFileSize($url),
        ];
        $object->hydrate($objectArray);

        return $data;

    }//end enrichDownloadObject()


}//end class
