<?php

// src/Service/LarpingService.php
namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\DashboardCard;
use App\Entity\Endpoint;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InstallationService implements InstallerInterface
{
    private EntityManagerInterface $entityManager;
    private SymfonyStyle $io;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Set symfony style in order to output to the console
     *
     * @param SymfonyStyle $io
     * @return self
     */
    public function setStyle(SymfonyStyle $io):self
    {
        $this->io = $io;

        return $this;
    }

    public function install(){
        $this->checkDataConsistency();
    }

    public function update(){
        $this->checkDataConsistency();
    }

    public function uninstall(){
        // Do some cleanup
    }

    public function checkDataConsistency(){

        // Lets create some genneric dashboard cards
        $objectsThatShouldHaveCards = ['https://opencatalogi.nl/component.schema.json'];

        foreach($objectsThatShouldHaveCards as $object){
            (isset($this->io)?$this->io->writeln('Looking for a dashboard card for: '.$object):'');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);
            if(
               !$dashboardCard = $this->entityManager->getRepository('App:DashboardCard')->findOneBy(['entityId'=>$entity->getId()])
            ){
                $dashboardCard = New DashboardCard();
                $dashboardCard->setType('schema');
                $dashboardCard->setEntity('App:Entity');
                $dashboardCard->setObject('App:Entity');
                $dashboardCard->setName($entity->getName());
                $dashboardCard->setDescription($entity->getDescription());
                $dashboardCard->setEntityId($entity->getId());
                $dashboardCard->setOrdering(1);
                $this->entityManager->persist($dashboardCard);
                (isset($this->io) ?$this->io->writeln('Dashboard card created'):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Dashboard card found'):'');
        }

        // Let create some endpoints
        $objectsThatShouldHaveEndpoints = ['https://opencatalogi.nl/component.schema.json'];

        foreach($objectsThatShouldHaveEndpoints as $object){
            (isset($this->io)?$this->io->writeln('Looking for a endpoint for: '.$object):'');
            $entity = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference'=>$object]);

            if(
                count($entity->getEndpoints()) == 0
            ){
                $endpoint = New Endpoint($entity);
                $this->entityManager->persist($endpoint);
                (isset($this->io)?$this->io->writeln('Endpoint created'):'');
                continue;
            }
            (isset($this->io)?$this->io->writeln('Endpoint found'):'');
        }

        $this->entityManager->flush();

        // Lets see if there is a generic search endpoint

        /** Aanmaken actions
        1. array van action classes
         * 2. daar doorheen lopen per item kijke is er een action met die class, zo ja contie
         * 3 bij nee action aanmaken via actie($actionhandler)
         * 4. Daarvoor is loopje nodig op action handlers om default config aan te leveren
        *.
        // Aanmaken 1 cronjob (indien nodig)


    }
}
