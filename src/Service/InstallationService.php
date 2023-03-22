<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;

class InstallationService implements InstallerInterface
{
    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @param EntityManagerInterface $entityManager The entity manager
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }//end __construct()

    /**
     * @return void
     */
    public function install()
    {
        $this->checkDataConsistency();
    }//end install()

    /**
     * @return void
     */
    public function update()
    {
        $this->checkDataConsistency();
    }//end update()

    /**
     * @return void
     */
    public function uninstall()
    {
        // Do some cleanup
    }//end uninstall()

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
    }//end setEntityMaxDepth()

    /**
     * @return void
     */
    public function checkDataConsistency()
    {
        // set all entity maxDepth to 5
        $this->setEntityMaxDepth();

        $this->entityManager->flush();
    }//end checkDataConsistency()
}//end class
