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
    private Entity $componentEntity;
    private Mapping $componentMapping;
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

        return $this;
    }

    /**
     * Get the developer overheid source
     *
     * @return ?Source
     */
    public function getSource(): ?Source{
        if(!$this->source = $this->entityManager->getRepository("App:Gateway")->findOneBy(["location"=>"https://developer.overheid.nl/api"])){
            $this->io->error("No source found for https://developer.overheid.nl/api");
        }

        return $this->source;
    }

    /**
     * Get the repository entity
     *
     * @return ?Entity
     */
    public function getRepositoryEntity(): ?Entity{
        if(!$this->repositoryEntity = $this->entityManager->getRepository("App:Entity")->findOneBy(["reference"=>"https://opencatalogi.nl/oc.repository.schema.json"])){
            $this->io->error("No entity found for https://opencatalogi.nl/oc.repository.schema.json");
        }

        return $this->repositoryEntity;
    }

    /**
     * Get repositories through the repositories of developer.overheid.nl/repositories
     *
     * @return array
     */
    public function getRepositories(): array{

        $result = [];
        // Dow e have a source
        if(!$source = $this->getSource()){
            return $result;
        }

        $repositories = $this->callService->getAllResults($source, '/repositories');

        $this->io->success("Found ".count($repositories)." repositories");
        foreach($repositories as $repository){
            $result[] = $this->importRepository($repository);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get a repository trough the repositories of developer.overheid.nl/repositories/{id}
     *
     * @return array
     */
    public function getRepository(string $id){

        // Dow e have a source
        if(!$source = $this->getSource()){
            return;
        }

        $this->io->success('Getting repository '.$id);
        $repository = $this->callService->call($source, '/repositories/'.$id);

        if(!$repository){
            $this->io->error('Could not find repository '.$id.' an source '.$source);
            return;
        }
        $repository = $this->importRepository($repository);

        $this->entityManager->flush();

        return $repository->getObject();
    }

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

        $this->io->success("Checking repository ".$repository['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $repositoryEntity, $repository['id']);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $repository);

        return $synchronization->getObject();
    }

    /**
     * Get the component entity
     *
     * @return ?Entity
     */
    public function getComponentEntity(): ?Entity{
        if(!$this->componentEntity = $this->entityManager->getRepository("App:Entity")->findOneBy(["reference"=>"https://opencatalogi.nl/oc.component.schema.json"])){
            $this->io->error("No entity found for https://opencatalogi.nl/oc.component.schema.json");
        }

        return $this->componentEntity;
    }

    /**
     * Get the component mapping
     *
     * @return ?Mapping
     */
    public function getComponentMapping(): ?Mapping{
        if(!$this->componentMapping = $this->entityManager->getRepository("App:Mapping")->findOneBy(["reference"=>"https://developer.overheid.nl/api/components"])){
            $this->io->error("No mapping found for https://developer.overheid.nl/api/components");
        }

        return $this->componentMapping;
    }


    /**
     * Get components through the components of developer.overheid.nl/apis
     *
     * @return array
     */
    public function getComponents(): array{

        $result = [];
        // Dow e have a source
        if(!$source = $this->getSource()){
            return $result;
        }

        $this->io->comment('Trying to get all components from source '.$source->getName());

        $components = $this->callService->getAllResults($source, '/apis');

        $this->io->success("Found ".count($components)." components");
        foreach($components as $component){
            $result[] = $this->importComponent($component);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get a component trough the components of developer.overheid.nl/apis/{id}
     *
     * @return array
     */
    public function getComponent(string $id){

        // Dow e have a source
        if(!$source = $this->getSource()){
            return;
        }

        $this->io->comment('Trying to get component with id: '.$id);
        $response = $this->callService->call($source, '/apis/'.$id);

        $component = json_decode($response->getBody()->getContents(), true);

        if(!$component){
            $this->io->error('Could not find a component with id: '.$id.' and with source: '.$source->getName());
            return;
        }
        $component = $this->importComponent($component);

        $this->entityManager->flush();

        $this->io->success('Found component with id: '.$id);

        return $component->toArray();
    }

    /**
     * @return ObjectEntity
     */
    public function importComponent($component){

        // Dow e have a source
        if(!$source = $this->getSource()){
            return ;
        }
        if(!$componentEntity = $this->getComponentEntity()){
            return ;
        }
        if(!$mapping = $this->getComponentMapping()){
            return ;
        }


        $this->io->comment("Mapping object " . $mapping);

        $this->io->comment("Checking component ".$component['service_name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $componentEntity, $component['id']);
        $synchronization->setMapping($mapping);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $component);

        return $synchronization->getObject();
    }
}
