<?php

namespace OpenCatalogi\OpenCatalogiBundle\ActionHandler;

use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;
use OpenCatalogi\OpenCatalogiBundle\Service\GitlabApiService;

/**
 * Haalt alle repositories op die een opencatalogi en/of publiccode file hebben.
 */
class GitlabApiHandler implements ActionHandlerInterface
{

    /**
     * @var GithubApiService
     */
    private GitlabApiService $service;


    /**
     * @param GitlabApiService $service The  GitlabApiService
     */
    public function __construct(GitlabApiService $service)
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
            '$id'         => 'https://opencatalogi.nl/ActionHandler/GithubApiHandler.ActionHandler.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'GitlabApiHandler',
            'description' => 'This is a action to create objects from the fetched applications from the componenten catalogus.',
            'required'    => [
                'gitlabSource',
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
                'gitlabSource'        => [
                    'type'        => 'string',
                    'description' => 'The source of the gitlab api.',
                    'example'     => 'https://opencatalogi.nl/source/oc.GitlabAPI.source.json',
                    'reference'   => 'https://opencatalogi.nl/source/oc.GitlabAPI.source.json',
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
     * This function runs the application to gateway service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @return array
     * @throws \Exception
     */
    public function run(array $data, array $configuration): array
    {
        return $this->service->getGitlabRepository($data, $configuration);

    }//end run()


}//end class
