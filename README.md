# OpenCatalogiBundle

An example repository for creating php symfony flex bundles

### Using this bundle

To execute the following command you will need [Composer](https://getcomposer.org/download/).

U can install this plugin by installing with command:
`composer require common-gateway/pet-store-plugin:dev-main`
in the directory where your composer.json lives.

### Creating your own bundle

##### Requirements

*   [GitHub](https://github.com/login) account or organization
*   [Packagist](https://packagist.org/login/) account
*   [Composer](https://getcomposer.org/download/) or if your project is dockerized, [Docker Desktop](https://www.docker.com/products/docker-desktop/)

#### Using this template

To create your own symfony bundle. You can copy this repository for a fast start.

1.  Login on [GitHub](https://github.com)
2.  Use [this template](https://github.com/OpenCatalogi/OpenCatalogiBundle/generate)
3.  Name your bundle (CamelCase)
4.  Press the green button `Create repository from template`
5.  Update file names and namespace to your fitting
    *   Open composer.json, and change the name to your fitting. The first word should be the namespace and the second the name of your bundle. Check the autoload field to be set accordingly. Note: this is kebab-case. Also read: [naming your package](https://packagist.org/about#naming-your-package)
    *   Open OpenCatalogiBundle.php and change the Bundle name and namespace. The namespace should be the same as your package name in composer.json but in CamelCase. So common-gateway/pet-store-bundle becomes OpenCatalogi/OpenCatalogiBundle
    *   Rename the /Service and /ActionHandler accordingly (or delete if not used).
    *   Rename the /DependencyInjection/OpenCatalogiExtension.php to your BundleNameExtension.php
    *   Rename the /Resources/config/services.yaml namespaces

##### Adding schemas

If you use your bundel with the common-gateway, you can add your own schemas that will be loaded as Entities.
These Entities will be loaded into the database so you can work with your own objects/data.

These schemas are [json schema's](https://json-schema.org/learn/getting-started-step-by-step.html#starting-the-schema).

You can add existing schemas or create your own and add them in /Schema. There is also an example as /Schema/example.json.

Make sure your schema's have a `version` property so you can update your schema's!

Also make sure they have a unique `$id` so that they can be related to other schema's.

If you have added schema's you can also add objects/data for them, in /Data is an example you can use.

#### Upload to packagist

Before we can use our bundle and code, we must have it online on packagist so we can install with composer.

1.  Upload to packagist
    *   Go to [Packagist](https://packagist.org)
    *   Press `submit` in the top menu bar and paste your bundle's github repository link, the name from you composer.json will be used as package name.
    *   If valid press `Submit`
2.  Auto-update package
    *   Go to your [packagist profile](https://packagist.org/profile/).
    *   Press `Show API Token` and copy
    *   Go to your new bundle's github repository
    *   Go to your repository settings
    *   Go to webhooks, and press `Add webhook`
    *   As Payload URL, set https://packagist.org/api/github?username=yourPackagistUsername
    *   Keep SSL verification enabled
    *   As secret, paste the copied API token from packagist
    *   Set event on `Just push the event`
    *   Press `Add webhook`
    *   If you push new code it will now push to packagis. On packagist you should not see the auto-update warning anymore. If its still there check if your username and secret are set properly on the github webhook.

#### Using your code

To use the code in your library we first have to install it with composer.

Note: for docker add `docker-compose exec php` before all commands

1.  Navigate with a command line to where your composer.json lives in the project you want to use this bundle.
    *   Execute `composer require {full package name}:dev-main`
    *   Docker users: restart your containers so symfony can recognize the new Bundle's namespace
2.  Open a php file where you want to use a class.
    *   Add the correct use statement (example `use CommonGateway\OpenCatalogiBundle\Service\OpenCatalogiService;`)
    *   U can now use your class!

In the common gateway, if you want to use your code when triggered by an event with a action, make sure the class of the action object is set as the handler name including the namespace. For example if I want to use the OpenCatalogiService I can set the OpenCatalogiHandler as `CommonGateway\OpenCatalogiBundle\ActionHandler\OpenCatalogiHandler`.
