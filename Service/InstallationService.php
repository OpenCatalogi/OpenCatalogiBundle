<?php

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
    private CatalogiService $catalogiService;

    public function __construct(EntityManagerInterface $entityManager, CatalogiService $catalogiService)
    {
        $this->entityManager = $entityManager;
        $this->catalogiService = $catalogiService;
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

    public function checkDataConsistency()
    {
        // set all entity maxDepth to 5
        $this->setEntityMaxDepth();

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

        $this->entityManager->flush();
    }
}
