<?php

namespace OpenCatalogi\OpenCatalogiBundle\Service;

use App\Entity\ObjectEntity;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

class PubliccodeService
{
    private EntityManagerInterface $entityManager;
    private GithubApiService $githubService;
    private array $configuration;
    private array $data;

    public function __construct(
        EntityManagerInterface $entityManager,
        GithubApiService $githubService
    ) {
        $this->entityManager = $entityManager;
        $this->githubService = $githubService;
        $this->configuration = [];
        $this->data = [];
    }

    /**
     * @param ObjectEntity $organization
     *
     * @throws GuzzleException
     *
     * @return array|null dataset at the end of the handler
     */
    public function getOrganizationCatalogi(ObjectEntity $organization): ?array
    {
        if ($this->githubService->getGithubRepoFromOrganization($organization->getValue('name'))) {
            if ($catalogi = $this->githubService->getOpenCatalogiFromGithubRepo($organization->getValue('name'))) {
                try {
                    // Old
                    // $organization->setValue('name', $catalogi['name']);
                    // $organization->setValue('description', $catalogi['description']);
                    // $organization->setValue('type', $catalogi['type']);
                    // $organization->setValue('telephone', $catalogi['telephone']);
                    // $organization->setValue('email', $catalogi['email']);
                    // $organization->setValue('website', $catalogi['website']);
                    // $organization->setValue('logo', $catalogi['logo']);
                    // $organization->setValue('catalogusAPI', $catalogi['catalogusAPI']);
                    // $organization->setValue('uses', $catalogi['uses']);
                    // $organization->setValue('supports', $catalogi['supports']);

                    // New
                    $organization->hydrate([
                        'name'         => $catalogi['name'],
                        'description'  => $catalogi['description'],
                        'type'         => $catalogi['type'],
                        'telephone'    => $catalogi['telephone'],
                        'email'        => $catalogi['email'],
                        'website'      => $catalogi['website'],
                        'logo'         => $catalogi['logo'],
                        'catalogusAPI' => $catalogi['catalogusAPI'],
                        'uses'         => $catalogi['uses'],
                        'supports'     => $catalogi['supports'],
                    ]);
                } catch (Exception $exception) {
                    var_dump("Data error for {$organization->getValue('name')}, {$exception->getMessage()}");
                }

                $this->entityManager->persist($organization);
                $this->entityManager->flush();
            }
        }

        return null;
    }

    /**
     * @param array $data          data set at the start of the handler
     * @param array $configuration configuration of the action
     *
     * @throws GuzzleException
     *
     * @return array dataset at the end of the handler
     */
    public function enrichOrganizationWithCatalogi(array $data, array $configuration): array
    {
        $this->configuration = $configuration;
        $this->data = $data;

        $organizationEntity = $this->entityManager->getRepository('App:Entity')->find($this->configuration['organisationEntityId']);
        // If we want to do it for al repositories
        foreach ($organizationEntity->getObjectEntities() as $organization) {
            if ($organization->getValue('github')) {
                // get org name and search if the org has an .github repository
                $this->getOrganizationCatalogi($organization);
            }
        }

        return $this->data;
    }
}
