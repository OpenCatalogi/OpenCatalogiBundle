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
     * For these schemas we want to set max depth to 2, not to 5
     */
    private const MAX_DEPTH_2 = [
        "https://opencatalogi.nl/oc.application.schema.json",
        "https://opencatalogi.nl/oc.organisation.schema.json",
    ];


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
     * Except for entities defined in $this::MAX_DEPTH_2
     *
     * @TODO: find a better solution for this? We can set max depth in the schema.json files as well.
     *
     * @return void
     */
    public function setEntityMaxDepth()
    {
        $entities = $this->entityManager->getRepository('App:Entity')->findAll();
        foreach ($entities as $entity) {
            if (in_array($entity->getReference(), $this::MAX_DEPTH_2)) {
                $entity->setMaxDepth(2);
                $this->entityManager->persist($entity);

                continue;
            }

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
