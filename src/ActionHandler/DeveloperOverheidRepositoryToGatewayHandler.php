<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\DeveloperOverheidService;

/**
 * Haalt applications op van de componenten catalogus.
 */
class DeveloperOverheidRepositoryToGatewayHandler implements ActionHandlerInterface
{

    /**
     * @var DeveloperOverheidService
     */
    private DeveloperOverheidService $service;


    /**
     * @param DeveloperOverheidService $service The developer Overheid Service
     */
    public function __construct(DeveloperOverheidService $service)
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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/DeveloperOverheidRepositoryToGatewayHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'DeveloperOverheidRepositoryToGatewayHandler',
            'description' => 'This is a action to create objects from the fetched repositories from the developer overheid source.',
            'required'    => [
                'source',
                'endpoint',
                'githubSource',
                'usercontentSource',
                'repositorySchema',
                'repositoryMapping',
                'organizationSchema',
                'organizationMapping',
                'componentSchema',
                'publiccodeMapping',
                'opencatalogiMapping',
                'applicationSchema',
            ],
            'properties'  => [
                'source'              => [
                    'type'        => 'string',
                    'description' => 'The source of the developer overheid.',
                    'example'     => 'https://opencatalogi.nl/source/oc.developerOverheid.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.developerOverheid.source.json',
                    'required'    => true,
                ],
                'endpoint'            => [
                    'type'        => 'string',
                    'description' => 'The endpoint of the source.',
                    'example'     => '/repositories',
                    'required'    => true,
                ],
                'githubSource'        => [
                    'type'        => 'string',
                    'description' => 'The source of the github api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubAPI.source.json',
                    'required'    => true,
                ],
                'usercontentSource'   => [
                    'type'        => 'string',
                    'description' => 'The source of the raw user content.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitHubusercontent.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitHubusercontent.source.json',
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
                'organizationSchema'  => [
                    'type'        => 'string',
                    'description' => 'The organization schema.',
                    'example'     => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.organisation.schema.json',
                    'required'    => true,
                ],
                'organizationMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for github organization to oc organization.',
                    'example'     => 'https://api.github.com/oc.githubOrganization.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubOrganization.mapping.json',
                    'required'    => true,
                ],
                'componentSchema'     => [
                    'type'        => 'string',
                    'description' => 'The component schema.',
                    'example'     => 'https://opencatalogi.nl/oc.component.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.component.schema.json',
                    'required'    => true,
                ],
                'publiccodeMapping'   => [
                    'type'        => 'string',
                    'description' => 'The mapping for publiccode file to oc component.',
                    'example'     => 'https://api.github.com/oc.githubPubliccodeComponent.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubPubliccodeComponent.mapping.json',
                    'required'    => true,
                ],
                'opencatalogiMapping' => [
                    'type'        => 'string',
                    'description' => 'The mapping for opencatalogi file to oc organization.',
                    'example'     => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
                    'reference'   => 'https://api.github.com/oc.githubOpenCatalogiYamlToOrg.mapping.json',
                    'required'    => true,
                ],
                'applicationSchema'   => [
                    'type'        => 'string',
                    'description' => 'The application schema.',
                    'example'     => 'https://opencatalogi.nl/oc.application.schema.json',
                    'reference'   => 'https://opencatalogi.nl/oc.application.schema.json',
                    'required'    => true,
                ],
            ],
        ];

    }//end getConfiguration()


    /**
     * This function runs the developer overheid repositories to gateway service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     * @throws \Exception
     */
    public function run(array $data, array $configuration): array
    {
        return $this->service->getRepositories($data, $configuration);

    }//end run()


}//end class
