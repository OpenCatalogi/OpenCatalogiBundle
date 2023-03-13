<?php

// src/Service/LarpingService.php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\Cronjob;
use App\Entity\Endpoint;
use App\Entity\Entity;
use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private ContainerInterface $container;
    private SymfonyStyle $io;
    private CatalogiService $catalogiService;

    public function __construct(EntityManagerInterface $entityManager, ContainerInterface $container, CatalogiService $catalogiService)
    {
        $this->entityManager = $entityManager;
        $this->container = $container;
        $this->catalogiService = $catalogiService;
    }

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $io
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $io): self
    {
        $this->io = $io;

        return $this;
    }

    public function install()
    {
        $this->checkDataConsistency();
    }

    public function update()
    {
        $this->checkDataConsistency();
    }

    public function uninstall()
    {
        // Do some cleanup
    }
    
    /**
     * Sets the max depth of all entities to 5 because OC has a lot of nested objects.
     * @TODO: find a better solution for this?
     *
     * @return void
     */
    public function setEntityMaxDepth()
    {
        $entities = $this->entityManager->getRepository('App:Entity')->findAll();
        foreach ($entities as $entity) {
            if ($entity->getMaxDepth() !== 5) {
                // set maxDepth for an entity to 5
                $entity->setMaxDepth(5);
                $this->entityManager->persist($entity);
            }
        }
    }

    public function createCronjobs()
    {
        (isset($this->io) ? $this->io->writeln(['', '<info>Looking for cronjobs</info>']) : '');
        // We only need 1 cronjob so lets set that
        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Open Catalogi'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Open Catalogi');
            $cronjob->setDescription('This cronjob fires all the open catalogi actions ever 5 minutes');
            $cronjob->setThrows(['opencatalogi.default.listens']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for '.$cronjob->getName()]) : '');
        } else {
            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for '.$cronjob->getName()]) : '');
        }

        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Bronnen trigger'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Bronnen trigger');
            $cronjob->setDescription('This cronjob fires all the open catalogi bronnen actions ever 5 minutes');
            $cronjob->setThrows(['opencatalogi.bronnen.trigger']);
            $cronjob->setIsEnabled(true);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for '.$cronjob->getName()]) : '');
        } else {
            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for '.$cronjob->getName()]) : '');
        }

        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Github scrapper'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Github scrapper');
            $cronjob->setDescription('This cronjob fires all the open catalogi github actions ever 5 minutes');
            $cronjob->setThrows(['opencatalogi.github']);
            // What does this do
            $cronjob->setIsEnabled(false);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for '.$cronjob->getName()]) : '');
        } else {
            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for '.$cronjob->getName()]) : '');
        }

        if (!$cronjob = $this->entityManager->getRepository('App:Cronjob')->findOneBy(['name' => 'Federation'])) {
            $cronjob = new Cronjob();
            $cronjob->setName('Federation');
            $cronjob->setDescription('This cronjob fires all the open catalogi federation actions ever 5 minutes');
            $cronjob->setThrows(['opencatalogi.federation']);
            // Doesn't work?
            $cronjob->setIsEnabled(false);

            $this->entityManager->persist($cronjob);

            (isset($this->io) ? $this->io->writeln(['', 'Created a cronjob for '.$cronjob->getName()]) : '');
        } else {
            (isset($this->io) ? $this->io->writeln(['', 'There is alreade a cronjob for '.$cronjob->getName()]) : '');
        }
    }

    public function createSources()
    {
        $sourceRepository = $this->entityManager->getRepository('App:Gateway');

        // componentencatalogus
        $componentenCatalogusSource = $sourceRepository->findOneBy(['name' => 'componentencatalogus']) ?? new Source();
        $componentenCatalogusSource->setName('componentencatalogus');
        $componentenCatalogusSource->setAuth('none');
        $componentenCatalogusSource->setLocation('https://componentencatalogus.commonground.nl/api');
        $componentenCatalogusSource->setIsEnabled(true);
        $this->entityManager->persist($componentenCatalogusSource);
        isset($this->io) && $this->io->writeln('Gateway: '.$componentenCatalogusSource->getName().' created');

        // developer.overheid
        $developerOverheid = $sourceRepository->findOneBy(['name' => 'developerOverheid']) ?? new Source();
        $developerOverheid->setName('developerOverheid');
        $developerOverheid->setAuth('none');
        $developerOverheid->setLocation('https://developer.overheid.nl/api');
        $developerOverheid->setIsEnabled(true);
        $this->entityManager->persist($developerOverheid);
        isset($this->io) && $this->io->writeln('Gateway: '.$developerOverheid->getName().' created');

        // GitHub API
        $gitHubAPI = $sourceRepository->findOneBy(['name' => 'GitHub API']) ?? new Source();
        $gitHubAPI->setName('GitHub API');
        $gitHubAPI->setAuth('apikey');
        $gitHubAPI->setHeaders(['Accept' => 'application/vnd.github+json']);
        $gitHubAPI->setAuthorizationHeader('Authorization');
        $gitHubAPI->setAuthorizationPassthroughMethod('header');
        $gitHubAPI->setLocation('https://api.github.com');
        $gitHubAPI->setIsEnabled(true);
        $this->entityManager->persist($gitHubAPI);
        
        // TODO: move this to installation.json!!!
//        $dashboardCard = new DashboardCard($gitHubAPI);
//        $this->entityManager->persist($dashboardCard);
        isset($this->io) && $this->io->writeln('Gateway: '.$gitHubAPI->getName().' created');

        // GitHub usercontent
        $gitHubUserContentSource = $sourceRepository->findOneBy(['name' => 'GitHub usercontent']) ?? new Source();
        $gitHubUserContentSource->setName('GitHub usercontent');
        $gitHubUserContentSource->setAuth('none');
        $gitHubUserContentSource->setLocation('https://raw.githubusercontent.com');
        $gitHubUserContentSource->setIsEnabled(true);
        $this->entityManager->persist($gitHubUserContentSource);
        isset($this->io) && $this->io->writeln('Gateway: '.$gitHubUserContentSource->getName().' created');

        // flush the sources before adding actions via the addActions function
        // we need the id of the sources
        $this->entityManager->flush();
    }

    public function checkDataConsistency()
    {
        // set all entity maxDepth to 5
        $this->setEntityMaxDepth();

        // Doesn't work so let's let search endpoint return all
        $schemasToAddToSearchEndpoint = [
            'https://opencatalogi.nl/oc.application.schema.json',
            'https://opencatalogi.nl/oc.organisation.schema.json',
            'https://opencatalogi.nl/oc.component.schema.json',
        ];

        $schemas = [];
        foreach ($schemasToAddToSearchEndpoint as $schema) {
            $foundSchema = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $schema]);
            if ($foundSchema instanceof Entity) {
                $schemas[] = $foundSchema;
            } else {
                isset($this->io) && $this->io->writeln('Schema: '.$schema.' could not be found. Installation failed');

                throw new Exception('Schema: '.$schema.' could not be found. Installation failed');
            }
        }

        // Lets see if there is a generic search endpoint
        if (!$searchEndpoint = $this->entityManager->getRepository('App:Endpoint')->findOneBy(['pathRegex' => '^(search)$'])) {
            $searchEndpoint = new Endpoint();
            $searchEndpoint->setName('Search');
            $searchEndpoint->setDescription('Generic Search Endpoint');
            $searchEndpoint->setPath(['search']);
            $searchEndpoint->setPathRegex('^(search)$');
            $searchEndpoint->setMethod('GET');
            $searchEndpoint->setMethods(['GET']);
            $searchEndpoint->setOperationType('collection');
            foreach ($schemas as $schema) {
                $searchEndpoint->addEntity($schema);
            }
            $this->entityManager->persist($searchEndpoint);
        }

        if (!$githubEventEndpoint = $this->entityManager->getRepository('App:Endpoint')->findOneBy(['pathRegex' => '^(github_events)$'])) {
            $githubEventEndpoint = new Endpoint();
            $githubEventEndpoint->setName('Github Event');
            $githubEventEndpoint->setDescription('Github Event Endpoint');
            $githubEventEndpoint->setPath(['github_events']);
            $githubEventEndpoint->setPathRegex('^(github_events)$');
            $githubEventEndpoint->setMethod('POST');
            $githubEventEndpoint->setMethods(['POST']);
            $githubEventEndpoint->setThrows(['opencatalogi.githubevents.trigger']);
            $githubEventEndpoint->setOperationType('collection');
//            $repoSchema = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => 'https://opencatalogi.nl/oc.repository.schema.json']);
//            $githubEventEndpoint->addEntity($repoSchema);
            $this->entityManager->persist($githubEventEndpoint);
        }

        // create cronjobs
        $this->createCronjobs();

        // create sources
        $this->createSources();

        // Now we kan do a first federation
        isset($this->io) && $this->catalogiService->setStyle($this->io);
        //$this->catalogiService->readCatalogi($opencatalogi);

        /*@todo register this catalogi to the federation*/
        // This requers a post to a pre set webhook

        $this->entityManager->flush();
    }
}
