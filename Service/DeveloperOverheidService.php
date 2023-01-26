<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Entity;
use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use App\Entity\ObjectEntity;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\MappingService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;

/**
 *  This class handles the interaction with developer.overheid.nl
 */
class DeveloperOverheidService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private Source $source;
    private SynchronizationService $synchronizationService;
    private Entity $repositoryEntity;
    private Mapping $mapping;
    private MappingService $mappingService;

    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService,
        SynchronizationService $synchronizationService,
        MappingService $mappingService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->synchronizationService = $synchronizationService;
        $this->mappingService = $mappingService;
    }

    /**
     * Set symfony style in order to output to the console
     *
     * @param SymfonyStyle $io
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;
        $this->synchronizationService->setStyle($io);
        $this->mappingService->setStyle($io);

        return $this;
    }

    /**
     * Get the developer overheid source
     *
     * @return ?Source
     */
    public function getSource(): ?Source{
        if($this->source){
            return $this->source;
        }

        $this->source = $this->entityManager->getRepository("App:Gateway")->findOneBy(["location"=>"https://developer.overheid.nl/api/repositories"]);

        if(!$this->source){
            $this->io->error("No source found for https://developer.overheid.nl/api/repositories");
        }

        return $this->source;
    }

    /**
     * Get the repository entity
     *
     * @return ?Source
     */
    public function getEntity(): ?Entity{
        if($this->repositoryEntity){
            return $this->repositoryEntity;
        }

        $this->repositoryEntity = $this->entityManager->getRepository("App:Entity")->findOneBy(["reference"=>"https://developer.overheid.nl/api/repositories"]);

        if(!$this->repositoryEntity){
            $this->io->error("No entity found for https://developer.overheid.nl/api/repositories");
        }

        return $this->repositoryEntity;
    }

    /**
     * Get the repository entity
     *
     * @return ?Source
     */
    public function getMapping(): ?Entity{
        if($this->mapping){
            return $this->mapping;
        }

        $this->mapping = $this->entityManager->getRepository("App:Mapping")->findOneBy(["reference"=>"https://developer.overheid.nl/api"]);

        if(!$this->mapping){
            $this->io->error("No mapping found for https://developer.overheid.nl/api/repositories");
        }

        return $this->mapping;
    }

    /**
     * Get components trough the repositories of developer.overheid.nl
     *
     * @return array
     */
    public function getRepositories(): array{

        $result = [];

        // Dow e have a source
        if(!$source = $this->getSource()){
            return $result;
        }

        $repositories = $this->callService->call($source,'/repositories')['results'];

        $this->io->debug("Found ".count($repositories)." repositories");
        foreach($repositories as $repository){
            $result[] = $this->importRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * @return ObjectEntity
     */
    public function getGetComponent(string $id){

        // Dow e have a source
        if(!$source = $this->getSource()){
            return;
        }

        $this->io->debug('Getting repository '.$id);
        $repository = $this->callService->call($source, '/repositories/'.$id);

        if(!$repository){
            $this->io->error('Could not find repository '.$id.' an source '.$source);
            return ;
        }
        $repository = $this->importRepository($repository);

        $this->entityManager->flush();

        return $repository->getObject()
;    }

    /**
     * @return ObjectEntity
     */
    public function importRepository($repository){

        // Dow e have a source
        if(!$source = $this->getSource()){
            return ;
        }
        if(!$repositoryEntity = $this->getRepositoryEntity()){
            return ;
        }
        if(!$mapping = $this->getMapping()){
            return ;
        }

        $this->io->debug("Mapping object".$repository['name']);
        $repository = $this->mappingService->mapping($mapping, $repository);

        $this->io->debug("Importing object".$repository['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $synchronization->setMapping($this->mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository['id']);

        return $synchronization->getObject();
    }


}
