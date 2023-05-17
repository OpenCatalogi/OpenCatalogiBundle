# OpenCatalogiBundle

## Hoe werkt Open Catalogi

Open catalogi vormt een index over meerdere bronnen, en maakt informatie hieruit inzichtenlijk en doorzoekbaar voor gebruikers. Het focused hierbij in eerste instantie op software componenten aan de hand van repositories maar kan ook API's, datasets of proccesen indexeren.

## Waar leeft metadata?

Open catalogi indexeerd meta data uitverschillende bronnen maar vanuit een data bij de bron princiepe prevereren we het als een (open)source codebade zichzelf beschrijft.

## Compont Publiceren

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
```

Pas dit voorbeeld aan op basis van de specificaties van jouw component. Een volledige beschrijving van de publiccode standaard vind je op [yml.publiccode.tools](https://yml.publiccode.tools/schema.core.html#top-level-keys-and-sections)

3.  Voeg eventuele aanvullende metadata toe die relevant kan zijn voor jouw component, zoals documentatie, afhankelijkheden, contactinformatie of onderhoudsinformatie.

4.  Commit en push het `publiccode.yaml` bestand naar je repository. Hou er rekening mee dat het de eerste keer tot 24 uur kan duren voordat OpenCatalogi je component indexeerd

> :note: Open Catalogi scant github elke nacht, als je een component sneller wilt aanmelden of bijwerken kan dat via (opencatalogi.nl)\[https://opencatalogi.nl/documentation/about] gaan en onder "documentatie->over" (hoofd menu)

## Zijn er mininmum eisen aan een publiccode?

Nee, de publiccode.yaml mag zelfs leeg zijn. Puur het plaatsen daarvan in een open toegankenlijke repository spreekt de intentie uit om een open source oplossing aan te bieden en is voldoende omt e worden mee genomen in de indexatie. In het geval bepaalde gegevens missen worden deze aangevuld vanuit de repository (naam, beschrijving, organisatie, url, licentie).

## Welke bronnen indexeerd open catalogi naast Github?

Open Catalogi kijkt mee op:

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
