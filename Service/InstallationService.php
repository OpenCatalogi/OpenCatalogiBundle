<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
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

        $this->entityManager->flush();
    }
}
