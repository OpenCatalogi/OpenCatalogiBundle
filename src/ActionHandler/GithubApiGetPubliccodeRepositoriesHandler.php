<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\GithubPubliccodeService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class GithubApiGetPubliccodeRepositoriesHandler implements ActionHandlerInterface
{

    /**
     * @var GithubPubliccodeService
     */
    private GithubPubliccodeService $service;


    /**
     * @param GithubPubliccodeService $service The  githubPubliccodeService
     */
    public function __construct(GithubPubliccodeService $service)
    {
        $this->service = $service;

    }//end __construct()


    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @return array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://opencatalogi.nl/ActionHandler/GithubApiGetPubliccodeRepositoriesHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'GithubApiGetPubliccodeRepositoriesHandler',
            'description' => 'This is a action to create objects from the fetched applications from the componenten catalogus.',
            'required'    => [
                'githubSource',
                'repositorySchema',
                'repositoryMapping',
                'repositoriesMapping',
            ],
            'properties'  => [
                'githubSource'        => [
                    'type'        => 'string',
                    'description' => 'The source of the github api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true,
                ],
                'repositorySchema'    => [
                    'type'        => 'string',
                    'description' => 'The repository schema.',
                    'example'     => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.repository.schema.json',
                    'required'    => true,
                ],
                'repositoryMapping'   => [
                    'type'        => 'string',
                    'description' => 'The mapping for github repository to oc repository.',
                    'example'     => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubRepository.mapping.json',
                    'required'    => true,
                ],
                'repositoriesMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github repositories to oc repository.',
                    'example'     => 'https://api.github.com/oc.githubPubliccodeRepository.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubPubliccodeRepository.mapping.json',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the application to gateway service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->service->getRepositories();

    }//end run()


}//end class
