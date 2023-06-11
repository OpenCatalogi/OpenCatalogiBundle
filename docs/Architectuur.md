# Architectuur

## Hoe werkt federalisatie?

Iedere installatie (Catalogus) van Open Catalogi heeft een directory waarin alle installaties van Open Catalogi zijn opgenomen. Bij het opkomen van een nieuwe catalogus moet deze connectie maken met ten minimale één bestaande catalogus (bij default is dat opencatalogi.nl) voor het ophalen van de directory.

Vervolgens meld de catalogus zich bij de overige catalogusen aan als nieuwe mogenlijke bron. De catalogusen hanteren onderling zowel periodieke pull requests op hun directories als cloudevent gebaseerd berichten verkeer om elkar op de hoogte te houden van nieuwe catalogi en eventueele endpoints binnen die catalogi.

De bestaande catalogi hebben vervolgens de mogenlijkheid om de niewe catlogus mee te nemen in hun zoekopdrachten.

> :note:
>
> *   Bronnen worden pas gebruikt door een catalogus als de beheerder hiervoor akkoord heeft gegeven
> *   Bronnen kunnen zelf voorwaarde stellen aan het gebruikt (bijvoorbeeld alleen met PKI certificaat, of aan de hand van API sleutel)
