# OpenCatalogiBundle [![Codacy Badge](https://app.codacy.com/project/badge/Grade/62464eebd6984848ba8642c3e0eaa809)](https://app.codacy.com/gh/OpenCatalogi/OpenCatalogiBundle/dashboard?utm_source=gh\&utm_medium=referral\&utm_content=\&utm_campaign=Badge_grade)

## Concept

OpenCatalogi vormt een index over meerdere bronnen, en maakt informatie hieruit inzichtelijk en doorzoekbaar voor gebruikers. Het focust hierbij in eerste instantie op software componenten aan de hand van repositories, maar kan ook APIs, datasets of processen indexeren.

## Waar leeft metadata?

OpenCatalogi indexeerd metadata uit verschillende bronnen, maar vanuit een 'data bij de bron'-principe prefereren we het als een (open)source codebase die zichzelf beschrijft.

zie ook: https://archive.fosdem.org/2022/schedule/event/publiccodeyml/

## Welke bronnen indexeerd OpenCatalogi naast GitHub?

OpenCatalogi kijkt standaard mee op:

* https://developer.overheid.nl
* https://data.overheid.nl
* https://componentencatalogus.commonground.nl

## Overige documenten

* [Publiccode](docs/Publiccode.md), bevat meer text en uitleg over het schrijven en toevoegen van publiccode bestanden
* [Publiorganisation](docs/Publicorganisation.md), bevat meer text en uitleg over het schrijven en toevoegen van publicorganisation bestanden
* [Installatie](docs/Installatie.md), hoe installeer je OpenCatalogi?
* [API specificatie](https://redocly.github.io/redoc/?url=https://raw.githubusercontent.com/OpenCatalogi/OpenCatalogiBundle/main/docs/oas.yaml\&nocors), voor het headless implementeren van open catalogi.
* [Security](docs/Security.md), voor onze veiligheids maatregelen
* [Architectuur](docs/Architectuur.md), de architectuur achter OpenCatalogi
* [Codacy](https://app.codacy.com/gh/OpenCatalogi/OpenCatalogiBundle/dashboard?utm_source=gh\&utm_medium=referral\&utm_content=\&utm_campaign=Badge_grade), en onafhankenlijke controle op de kwaliteit van de code

## Licentie

Deze bundle is beschikbaar onder [EUPL](https://eupl.eu/1.2/nl/) licentie.

## Backend Installation Instructions

The OpenCatalogi backend codebase utilizes the Common Gateway as an open-source installation framework. This means that the OpenCatalogi library, in its core form, functions as a plugin on this Framework. To learn more about the Common Gateway, you can refer to the documentation [here](https://commongateway.readthedocs.io/en/latest/).

Please note that the OpenCatalogi frontend codebase is a separate docker container.

To install the backend, follow the steps below:

### Gateway Installation

1. If you do not have the Common Gateway installed, you can follow the installation guide provided [here](https://github.com/ConductionNL/commonground-gateway#readme). The Common Gateway installation is required for the backend setup. You can choose any installation method for the gateway, such as Haven, Kubernetes, Linux, or Azure, and any database option like MySQL, PostgreSQL, Oracle, or MsSQL. The gateway framework handles this abstraction.

### OpenCatalogiBundle Installation - Admin-UI

1. After successfully installing the Gateway, access the admin-ui and log in.
2. In the left menu, navigate to "Plugins" to view a list of installed plugins. If you don't find the "OpenCatalogi" plugin listed here, you can search for it by clicking on "Search" in the upper-right corner and typing "OpenCatalogi" in the search bar.
3. Click on the "OpenCatalogi" card and then click on the "Install" button to install the plugin.
4. While the admin-ui allows you to install, upgrade, or remove bundles, to load all the required data (schemas, endpoints, sources), you need to execute the initialization command in a terminal.

### OpenCatalogiBundle Installation - Terminal

1. Open a terminal and run the following command to install the OpenCatalogi bundle:
   `docker-compose exec php composer require open-catalogi/open-catalogi-bundle`

## Frontend Installation Instructions

For instructions on setting up the frontend, please refer to the [Frontend Repository README](https://github.com/OpenCatalogi/web-app#readme) for detailed steps on how to run the frontend.

## Admin UI Configuration Instructions

1. GitHub API Source Configuration:

* Add your personal [GitHub token](https://github.com/settings/personal-access-tokens/new) as the API key:
  Bearer {{ here\_a\_github\_token }}

Please replace placeholders like `{{ here_a_github_token }}` with your actual GitHub token.
