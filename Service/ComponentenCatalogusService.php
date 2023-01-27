<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use CommonGateway\CoreBundle\Service\CallService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ComponentenCatalogusService
{
    private EntityManagerInterface $entityManager;
    private CallService $callService;

    public function __construct(
        EntityManagerInterface $entityManager,
        CallService $callService
    ) {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
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
}
