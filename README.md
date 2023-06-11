# OpenCatalogiBundle

## Hoe werkt Open Catalogi

Open catalogi vormt een index over meerdere bronnen, en maakt informatie hieruit inzichtenlijk en doorzoekbaar voor gebruikers. Het focused hierbij in eerste instantie op software componenten aan de hand van repositories maar kan ook API's, datasets of proccesen indexeren.

## Waar leeft metadata?

Open catalogi indexeerd meta data uitverschillende bronnen maar vanuit een data bij de bron princiepe prevereren we het als een (open)source codebade zichzelf beschrijft.

zie ook: https://archive.fosdem.org/2022/schedule/event/publiccodeyml/


## Component (software) Publiceren

Het publiceren van een component op opencatalogi.nl gaat met behulp van een publiccode.yaml bestand in de root van je repository. Om je component te publiceren, dien je een publiccode.yaml bestand te maken dat metadata en informatie over je component bevat. Dit bestand helpt het platform om je component te indexeren en gemakkelijk te vinden voor andere gebruikers.

1.  Maak een `publiccode.yaml` bestand in de root van je repository met een teksteditor of een geïntegreerde ontwikkelomgeving (IDE).

2.  Voeg de vereiste metadata toe aan het `publiccode.yaml` bestand. Een voorbeeld van een basisstructuur:

```yaml
publiccodeYmlVersion: "0.2"
 
name: Medusa
url: "https://example.com/italia/medusa.git"
softwareVersion: "dev"    # Optional
releaseDate: "2017-04-15"
platforms:
  - web

categories:
  - financial-reporting

developmentStatus: development

softwareType: "standalone/desktop"

description:
  en:
    localisedName: medusa   # Optional
    shortDescription: >
          A rather short description which
          is probably useless

    longDescription: >
          Very long description of this software, also split
          on multiple rows. You should note what the software
          is and why one should need it. We can potentially
          have many pages of text here.

    features:
       - Just one feature

legal:
  license: AGPL-3.0-or-later

maintenance:
  type: "community"

  contacts:
    - name: Francesco Rossi

localisation:
  localisationReady: true
  availableLanguages:
    - en
# De Nederlandse uitbreiding op de commonground standaard
nl:
  countryExtensionVersion: "1.0"
  commonground:
  - layerType: "interface"
  - installationType: "helm"
  - intendedOrganisations: "https://github.com/Rotterdam"
  gemma:
    bedrijfsfuncties:
      - "sadsad"
      - "sadsad"
    bedrijfsservices:
      - "sadsad"
      - "sadsad"
    applicatiefunctie: "referentie component"
```

Pas dit voorbeeld aan op basis van de specificaties van jouw component. Een volledige beschrijving van de publiccode standaard vind je op [yml.publiccode.tools](https://yml.publiccode.tools/schema.core.html#top-level-keys-and-sections)

3.  Voeg eventuele aanvullende metadata toe die relevant kan zijn voor jouw component, zoals documentatie, afhankelijkheden, contactinformatie of onderhoudsinformatie.

4.  Commit en push het `publiccode.yaml` bestand naar je repository. Hou er rekening mee dat het de eerste keer tot 24 uur kan duren voordat OpenCatalogi je component indexeerd

> :note: Open Catalogi scant github elke nacht, als je een component sneller wilt aanmelden of bijwerken kan dat via (opencatalogi.nl)\[https://opencatalogi.nl/documentation/about] gaan en onder "documentatie->over" (hoofd menu)

## Zijn er mininmum eisen aan een publiccode?

Nee, de publiccode.yaml mag zelfs leeg zijn. Puur het plaatsen daarvan in een open toegankenlijke repository spreekt de intentie uit om een open source oplossing aan te bieden en is voldoende om te worden mee genomen in de indexatie. In het geval bepaalde gegevens missen worden deze aangevuld vanuit de repository (naam, beschrijving, organisatie, url, licentie).

## Welke velden kan ik verwachten in een publiccode?

In een publiccode.yaml bestand zijn er verschillende properties die gedefinieerd kunnen worden om verschillende aspecten van de software of het project te beschrijven. Deze properties variëren van het geven van basisinformatie zoals de naam van de software, tot meer specifieke informatie zoals de gebruikte licentie of de ontwikkelstatus van de software. De volgende tabel geeft een overzicht van de mogelijke properties, of ze verplicht zijn of niet, wat het verwachte type input is en een korte beschrijving van elk.

Hier is een voorbeeld van hoe de tabel eruit kan zien, gebaseerd op de standaard die wordt beschreven op [yml.publiccode.tools]() en uitgewerkt onder [top level formats](https://docs.italia.it/italia/developers-italia/publiccodeyml-en/en/master/schema.core.html#top-level-keys-and-sections) op docs.italia.it.:

| Property             | Verplicht | Verwachte Input | Default                                                            | Enum                                                                                                                                           | Voorbeeld                                 | Beschrijving                                                 |
|----------------------|-----------|-----------------|--------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------|--------------------------------------------------------------|
| publiccodeYmlVersion | Nee       | String<SEMVER>  | 0.2                                                                | Nee                                                                                                                                            | 0.2                                       |This key specifies the version to which the current publiccode.yml adheres to, for forward compatibility.                  |
| name                 | Nee       | String          | De naam ven de repository waarin de public code is gevonden        | Nee                                                                                                                                            | Medusa                                    | This key contains the name of the software. It contains the (short) public name of the product, which can be localised in the specific localisation section. It should be the name most people usually refer to the software. In case the software has both an internal “code” name and a commercial name, use the commercial name.                                  |
| applicationSuite     | Nee       | String     | n.v.t                                                              | Nee                                                                                                                                            | MegaProductivitySuite                     | This key contains the name of the “suite” to which the software belongs.|
| url                  | Nee       | String<URL>     | De url van de repository waarin de public code is gevonden         | Nee                                                                                                                                            | https://example.com/italia/medusa.git     | A unique identifier for this software. This string must be a URL to the source code repository (git, svn, …) in which the software is published. If the repository is available under multiple protocols, prefer HTTP/HTTPS URLs which don’t require user authentication.Forks created for the purpose of contributing upstream should not modify this file; this helps software parsing publiccode.yml to immediately skip technical forks. On the contrary, a complete fork that is meant to be maintained separately from the original software should modify this line, to give themselves the status of a different project.                |
| landingURL           | Nee       | String<URL>     | De url onder repository settings (indien opgegeven)                | Nee                                                                                                                                            | https://example.com/italia/medusa         | If the url parameter does not serve a human readable or browsable page, but only serves source code to a source control client, with this key you have an option to specify a landing page. This page, ideally, is where your users will land when they will click a button labeled something like “Go to the application source code”. In case the product provides an automated graphical installer, this URL can point to a page which contains a reference to the source code but also offers the download of such an installer.|
| isBasedOn            | Nee       | String<URL>     | N.v.t.                                                             | Nee                                                                                                                                            | https://example.com/italia/medusa.gi      | In case this software is a variant or a fork of another software, which might or might not contain a publiccode.yml file, this key will contain the url of the original project(s).The existence of this key identifies the fork as a software variant, descending from the specified repositories.. |
| softwareVersion      | Nee       | String<SEMVER>  | N.v.t.                                                             | Nee                                                                                                                                            | 1.0                                       | This key contains the latest stable version number of the software. The version number is a string that is not meant to be interpreted and parsed but just displayed; parsers should not assume semantic versioning or any other specific version format.The key can be omitted if the software is currently in initial development and has never been released yet.              |
| logo                 | Nee       | String          | De afbeedling van de repository (indien opgegeven)                 | Nee                                                                                                                                            | img/logo.svg                              | This key contains the path to the logo of the software. Logos should be in vector format; raster formats are only allowed as a fallback. In this case, they should be transparent PNGs, minimum 1000px of width. The key value can be the relative path to the file starting from the root of the repository, or it can be an absolute URL pointing to the logo in raw version. In both cases, the file must reside inside the same repository where the publiccode.yml file is stored.                 |
| monochromeLogo       | Nee       | String          | N.v.t.                  | Nee                                                                                                                                            | img/logo-mono.svg                         | A monochromatic (black) logo. The logo should be in vector format; raster formats are only allowed as a fallback. In this case, they should be transparent PNGs, minimum 1000px of width. The key value can be the relative path to the file starting from the root of the repository, or it can be an absolute URL pointing to the logo in raw version. In both cases, the file must reside inside the same repository where the publiccode.yml file is stored.           |
| platforms            | Nee       | Lijst           | N.v.t.                                                             | web, windows, mac, linux, ios, android, haven,kubernetes,azure,aws                                                                             | 0.2                                       | This key specifies which platform the software runs on. It is meant to describe the platforms that users will use to access and operate the software, rather than the platform the software itself runs on.Use the predefined values if possible. If the software runs on a platform for which a predefined value is not available, a different value can be used.             |
| releaseDate          | Nee       | String<DATE>    | De creatie datum van de repository (indien opgegeven)              | Nee                                                                                                                                            | 2023-01-01                                | This key contains the date at which the latest version was released. This date is mandatory if the software has been released at least once and thus the version number is present.           |
| categories           | Nee       | Lijst           | N.v.t.                                                             | Any of [the catagories list](https://docs.italia.it/italia/developers-italia/publiccodeyml-en/en/master/categories-list.html#categories-list). | 0.2                                       | A list of words that can be used to describe the software and can help building catalogs of open software.              |
| developmentStatus    | Nee       | String          | N.v.t.                                                             | concept, development, beta, stable, obsolete                                                                                                                                             | stable                                       | De huidige ontwikkelstatus van de software.               |
| softwareType         | Nee       | String          | N.v.t.                                                             | "standalone/mobile", "standalone/iot", "standalone/desktop", "standalone/web", "standalone/backend", "standalone/other", "addon", "library", "configurationFiles"                                                                                                                                             | 0.2                                       | Het type software (e.g., standalone, library, etc.).      |
| description          | Nee       | Object          | De beschrijving van de repository waarind e publiccode is gevonden | Nee                                                                                                                                            | 0.2                                       | Bevat gelokaliseerde namen en beschrijvingen van de software.|
| legal                | Nee       | Object          | De licentie van de repository (indien opgegeven)                   | Nee                                                                                                                                            | 0.2                                       | Bevat de licentie onder welke de software is vrijgegeven. |
| maintenance          | Nee       | Object          | N.v.t.                                                             | Nee                                                                                                                                            | 0.2                                       | Bevat onderhoudsinformatie voor de software.              |
| localisation         | Nee       | Object          | N.v.t.                                                             | Nee                                                                                                                                            | 0.2                                       |Bevat informatie over de beschikbare talen van de software. |
| roadmap              | Nee       | String<URL>     | N.v.t.                                                             | Nee                                                                                                                                            | https://example.com/italia/medusa/roadmap | A link to a public roadmap of the software. |
| inputTypes           | Nee       | array<String>   | N.v.t.                                                             | as per RFC 6838                                                                                                                                | text/plain                                |A list of Media Types (MIME Types) as mandated in RFC 6838 which the application can handle as input.In case the software does not support any input, you can skip this field or use application/x.empty. |
| outputTypes          | Nee       | array<String>   | N.v.t.                                                             | as per RFC 6838                                                                                                                                | text/plain                                |A list of Media Types (MIME Types) as mandated in RFC 6838 which the application can handle as output.In case the software does not support any output, you can skip this field or use application/x.empty. |
| nl                   | Nee       | object          | N.v.t.                                                             | Nee                                                                                                                                            | n.v.t.                                    | A link to a public roadmap of the software. |

Dat laats dus een aantal mogenlijke subobjecten

### description

### legal

### maintenance

### localisation

### nl
Een (concept) Nederlande uitbreiding op de publiccode standaard in lijn met de [mogenlijkheid tot regionale uitbreidingen](https://docs.italia.it/italia/developers-italia/publiccodeyml-en/en/master/country.html#italy).

| Property                | Verplicht | Verwachte Input | Default  | Enum | Beschrijving                                                                                                                                                                                                                                                                                                                                                                |
|-------------------------|-----------|-----------------|----------|------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| countryExtensionVersion | Ja        | String<SEMVER>  | N.v.t.   | N.v.t.  | This key specifies the version to which the current extension schema adheres to, for forward compatibility.Please note how the value of this key is independent from the top-level publiccodeYmlVersion one (see The Standard (core)). In such a way, the extensions schema versioning is independent both from the core version of the schema and from every other Country. |
| commonground            | Nee       | String          | N.v.t.   |N.v.t.| An object describing the commonground attributes of this software, look bellow for the object definitions.                                                                                                                                                                                                                                                                  |
| gemma                   | Nee       | String<URL>     | N.v.t.   | N.v.t.  | An object describing the GEMMA attributes of this software, look bellow for the object definitions.                                                                                                                                                                                                                                                                                                                  |
| upl                     | Nee       | array<String>   | N.v.t.   | N.v.t.  | One or more from the [UPL list](https://standaarden.overheid.nl/upl), defines products provided by this sotware                                                                                                                                                                                                                                                             |                                                                                                                                                                                                                                                             |                                                                                                                                                                                                                                                                                                                                                    |

Dit leid tot de volgende subobjecten
#### Commonground
| Property             | Verplicht | Verwachte Input | Default | Enum                                           | Beschrijving                                                                                                                                                                                                                                                                                                                                                                 |
|----------------------|-----------|----------------|---------|------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| intendedOrganisations | Nee       | Array          | n.v.t   | n.v.t.                                         | This key specifies the version to which the current extension schema adheres to, for forward compatibility.Please note how the value of this key is independent from the top-level publiccodeYmlVersion one (see The Standard (core)). In such a way, the extensions schema versioning is independent both from the core version of the schema and from every other Country. |
| installationType                 | Nee       | String         | n.v.t.  | self, helm, provision                          | Defines how the software should be installed                                                                                                                                                                                                                                                                                                                                 |
| layerType                  | Nee       | String     | n.v.t   | interface, integration, data, service, process | An extension to public code based on the componentencatalogus. Refers to the layer on wich the component oprates.                                                                                                                                                                                                                                                            |

#### Gemma
| Property             | Verplicht | Verwachte Input | Default | Enum                                   | Beschrijving                                                                                                                                   |
|----------------------|-----------|-----------------|---------|----------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| bedrijfsfuncties | Nee       | Array<STRING>   | n.v.t   | n.v.t                                  | Een of meerdere [bedrijfsfuncties](https://www.gemmaonline.nl/index.php/GEMMA_Bedrijfsfuncties)                                                |
| bedrijfsservices                 | Nee       | Array<STRING>            | n.v.t.  | n.v.t                                  | Een of meerdere [bedrijfsservices]                                                                                                             |
| applicatiefunctie                  | Nee       | String          | n.v.t   | n.v.t                                  | Een van [de mogenlijke applicatie functies](https://www.gemmaonline.nl/index.php/GEMMAkennismodel/1.0/id-35825388-05d9-45aa-98f4-86dfb82337f5) |
| model                  | Nee       | String          | n.v.t   | semantic, conceptual,logical, physical | Het soort model (mag alleen worden gebruikt als het type schema is).                                                                           |

In theorie zijn er meer mogenlijke nederlandse utibreidingen te bedenken maar voor fase 1 hebben we ons bewust tot bovenstaande beperkt.

## Zijn er uitbreidingen op en afwijkingen van de publiccode standaard?
We hebben op verschillende pleken afgeweken en uitgebreid op de publiccode standaard, met namen omdat deze te beperkend bleek. We hebben er overal voor gekozen om aan te vullen of eisen te verlagen. Dat betekend dat een (volgens de standaard) geldige publiccode.yaml ook voor OC werkt maar dat je aanvullende informatie zou kunnen opnemen. 

Bij het veld softwareType ondersteunen we extra mogenlijkheden

| Software Type         | Beschrijving                                                                                       |
|-----------------------|---------------------------------------------------------------------------------------------------|
| standalone/mobile     | The software is a standalone, self-contained. The software is a native mobile app.                |
| standalone/iot        | The software is suitable for an IoT context.                                                      |
| standalone/desktop    | The software is typically installed and run in a a desktop operating system environment.          |
| standalone/web        | The software represents a web application usable by means of a browser.                           |
| standalone/backend    | The software is a backend application.                                                            |
| standalone/other      | The software has a different nature from the ones listed above.                                   |
| softwareAddon         | The software is an addon, such as a plugin or a theme, for a more complex software.               |
| library               | The software contains a library or an SDK to make it easier to third party developers.            |
| configurationFiles    | The software does not contain executable script but a set of configuration files.                 |
| api                   | The repository/folder doesn't contain software but an OAS api description.                        |
| schema                | The repository/folder doesn't contain software but a schema.json object description.              |
| data                  | The repository/folder doesn't contain software but a public data file (e.g. csv, xml etc).        |
| process               | The repository/folder doesn't contain software but an executable process (e.g. bpmn2, camunda).   |
| model                 | The repository/folder doesn't contain software but a model (e.g. uml).                            |

Bij het veld platforms ondersteunen we extra opties "haven","kubernetes","azure","aws"

Daarnaast zijn in de normale versie van de standaard de velden "publiccodeYmlVersion","name","url" verplicht en kent public code vanuit de standaard geen default values (die wij ontrekken aan de repository)

Bij logo laten we naast een realtief pad ook een absolute url naar het logo toe.

## API


## Welke bronnen indexeerd open catalogi naast Github?

Open Catalogi kijkt standaard mee op:

*   https://developer.overheid.nl
*   https://data.overheid.nl
*   https://componentencatalogus.commonground.nl


## Hoe werkt federalisatie?

Iedere installatie (Catalogus) van Open Catalogi heeft een directory waarin alle installaties van Open Catalogi zijn opgenomen. Bij het opkomen van een nieuwe catalogus moet deze connectie maken met ten minimale één bestaande catalogus (bij default is dat opencatalogi.nl) voor het ophalen van de directory.

Vervolgens meld de catalogus zich bij de overige catalogusen aan als nieuwe mogenlijke bron. De catalogusen hanteren onderling zowel periodieke pull requests op hun directories als cloudevent gebaseerd berichten verkeer om elkar op de hoogte te houden van nieuwe catalogi en eventueele endpoints binnen die catalogi.

De bestaande catalogi hebben vervolgens de mogenlijkheid om de niewe catlogus mee te nemen in hun zoekopdrachten.

> :note:
>
> *   Bronnen worden pas gebruikt door een catalogus als de beheerder hiervoor akkoord heeft gegeven
> *   Bronnen kunnen zelf voorwaarde stellen aan het gebruikt (bijvoorbeeld alleen met PKI certificaat, of aan de hand van API sleutel)

## Licentie

Deze bundle is beschickbaar onder [EUPL](https://eupl.eu/1.2/nl/) licentie.
