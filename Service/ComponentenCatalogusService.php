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
class ComponentenCatalogusService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;
    private Source $source;
    private SynchronizationService $synchronizationService;
    private Entity $applicationEntity;
    private Mapping $applicationMapping;
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
     * Get the componentencatalogus source
     *
     * @return ?Source
     */
    public function getSource(): ?Source{
        if(!$this->source = $this->entityManager->getRepository("App:Gateway")->findOneBy(["location"=>"https://componentencatalogus.commonground.nl/api"])){
            $this->io->error("No source found for https://componentencatalogus.commonground.nl/api");
        }

        return $this->source;
    }

    /**
     * Get the application entity
     *
     * @return ?Entity
     */
    public function getApplicationEntity(): ?Entity{
        if(!$this->applicationEntity = $this->entityManager->getRepository("App:Entity")->findOneBy(["reference"=>"https://opencatalogi.nl/oc.application.schema.json"])){
            $this->io->error("No entity found for https://opencatalogi.nl/oc.application.schema.json");
        }

        return $this->applicationEntity;
    }

    /**
     * Get applications through the products of https://componentencatalogus.commonground.nl/api/products
     *
     * @return array
     */
    public function getApplications(): array{

        $result = [];
        // Dow e have a source
        if(!$source = $this->getSource()){
            return $result;
        }

        // @TODO rows per page are 10, so i get only 10 results
        $response = $this->callService->call($source, '/products');

        $applications = json_decode($response->getBody()->getContents(), true);

        $this->io->success("Found ".count($applications)." applications");
        foreach($applications as $application){
            $result[] = $this->importApplication($application);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get an application through the products of https://componentencatalogus.commonground.nl/api/products/{id}
     *
     * @return array
     */
    public function getApplication(string $id){

        // Dow e have a source
        if(!$source = $this->getSource()){
            return;
        }

        $this->io->success('Getting application '.$id);
        $response = $this->callService->call($source, '/products/'.$id);

        $application = json_decode($response->getBody()->getContents(), true);

        if(!$application){
            $this->io->error('Could not find an application with id: '.$id.' and with source: '.$source->getName());
            return;
        }
        $application = $this->importApplication($application);

        $this->entityManager->flush();

        $this->io->success('Found application with id: '.$id);

        return $application->toArray();
    }

    /**
     * @return ObjectEntity
     */
    public function importApplication($application){

        // Dow e have a source
        if(!$source = $this->getSource()){
            return ;
        }
        if(!$applicationEntity = $this->getApplicationEntity()){
            return ;
        }

        $this->io->success("Checking application ".$application['name']);
        $synchronization = $this->synchronizationService->findSyncBySource($source, $applicationEntity, $application['id']);
        $synchronization = $this->synchronizationService->handleSync($synchronization, $application);

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
        if(!$this->componentMapping = $this->entityManager->getRepository("App:Mapping")->findOneBy(["reference"=>"https://componentencatalogus.commonground.nl/api/components"])){
            $this->io->error("No mapping found for https://componentencatalogus.commonground.nl/api/components");
        }

        return $this->componentMapping;
    }


    /**
     * Get components through the components of https://componentencatalogus.commonground.nl/api/components
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

        // @TODO rows per page are 10, so i get only 10 results
        $response = $this->callService->call($source, '/components');

        $components = json_decode($response->getBody()->getContents(), true);

        $this->io->success("Found ".count($components)." components");
        foreach($components['results'] as $component){
            $result[] = $this->importComponent($component);
        }

        $this->entityManager->flush();

        return $result;
    }

    /**
     * Get a component trough the components of https://componentencatalogus.commonground.nl/api/components/{id}
     *
     * @return array
     */
    public function getComponent(string $id){

        // Dow e have a source
        if(!$source = $this->getSource()){
            return;
        }

        $this->io->comment('Trying to get component with id: '.$id);
        $response = $this->callService->call($source, '/components/'.$id);

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
