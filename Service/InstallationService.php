<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class InstallationService implements InstallerInterface
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $io;

    /**
     * @var CatalogiService
     */
    private CatalogiService $catalogiService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    public const OBJECTS_THAT_SHOULD_HAVE_CARDS = [
        'https://opencatalogi.nl/oc.component.schema.json',
        'https://opencatalogi.nl/oc.application.schema.json',
        'https://opencatalogi.nl/oc.catalogi.schema.json',
    ];

    public const SCHEMAS_THAT_SHOULD_HAVE_ENDPOINTS = [
        ['reference' => 'https://opencatalogi.nl/oc.component.schema.json',        'path' => '/components',        'methods' => []],
        ['reference' => 'https://opencatalogi.nl/oc.organisation.schema.json',     'path' => '/organizations',     'methods' => []],
        ['reference' => 'https://opencatalogi.nl/oc.application.schema.json',      'path' => '/applications',      'methods' => []],
        ['reference' => 'https://opencatalogi.nl/oc.catalogi.schema.json',         'path' => '/catalogi',        'methods' => []],
        ['reference' => 'https://opencatalogi.nl/oc.repository.schema.json',       'path' => '/repositories',        'methods' => []],
    ];

    public const ACTION_HANDLERS = [
        //        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\CatalogiHandler',
        "OpenCatalogi\OpenCatalogiBundle\ActionHandler\GithubEventHandler",
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\ComponentenCatalogusApplicationToGatewayHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\ComponentenCatalogusComponentToGatewayHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\DeveloperOverheidApiToGatewayHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\DeveloperOverheidRepositoryToGatewayHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\EnrichPubliccodeFromGithubUrlHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\EnrichPubliccodeHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\FindGithubRepositoryThroughOrganizationHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\FindOrganizationThroughRepositoriesHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\GithubApiGetPubliccodeRepositoriesHandler',
        'OpenCatalogi\OpenCatalogiBundle\ActionHandler\RatingHandler',
    ];

    /**
     * @param EntityManagerInterface $entityManager
     * @param ContainerInterface     $container
     * @param CatalogiService        $catalogiService
     * @param LoggerInterface        $pluginLogger    The plugin version of the loger interface
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ContainerInterface $container,
        CatalogiService $catalogiService,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->container = $container;
        $this->catalogiService = $catalogiService;
        $this->logger = $pluginLogger;
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
     *
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

    public function checkDataConsistency()
    {
        // set all entity maxDepth to 5
        $this->setEntityMaxDepth();

        $this->entityManager->flush();
    }
}//end class
