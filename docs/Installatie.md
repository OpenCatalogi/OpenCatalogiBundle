# Installatie

Open Catalogi is een common ground applicatie die is opgebouwd uit losse componenten, om deze componenten optioneel te maken zijn ze ondergebracht in losse [kubernetes containers](https://kubernetes.io/docs/concepts/containers/). Dat betekend dat voor een totaal installatie van Open Catalogi een aantal Containers nodig zijn. Welke containers dat zijn kan je terugvinden onder [architectuur]().

Momenteel zijn er twee beproefde methode om open catalogi te installeren. De primaire route is door middel van een [helm](https://helm.sh/) installatie op kubernetes. Daarvoor bieden we ook een voor gedefinieerde helm repository aan.

De voor gedefinieerde repository kan worden binnengehaald via

```cli
$ helm repo add open-catalogi https://raw.githubusercontent.com/OpenCatalogi/web-app/development/helm/index.yaml
```

En vervolgens geïnstalleerd via

```cli
$ helm install [my-opencatalogi] open-catalogi/opencatalogi 
```

Meer installatie over installeren via helm kan worden gevonden op de  [helm](https://helm.sh/), meer informatie over installatie opties kan worden gevonden op [artifact hub](https://artifacthub.io/packages/helm/opencatalogi/commonground-gateway?modal=values).

> :note:
>
> Bij helm ligt de moeilijkheid vaak in het vinden van alle mogelijke configuratie opties. Om dit te vergemakkelijken hebben we alle opties opgenomen in een zogenoemd values bestand, deze kan je [hier](https://artifacthub.io/packages/helm/opencatalogi/opencatalogi?modal=values) vinden.

## Alternatieve installatie route
In sommige gevallen is er meer behoefte aan controle over de installatie (bijvoorbeeld omdat er geen kubernetes omgeving beschikbaar is) in dat geval kan gebruik worden gemaakt van een ‘kale’ common gateway instalatie, zie voor meer informatie over het installeren van de Common Gateway de [Common Gateway installatie handleiding](https://github.com/ConductionNL/commonground-gateway).

Omdat Open Catalogi een Common Gateway plugin is kan je vervolgens simpelweg in de common gateway naar plugins navigeren zoeken naar Open Catalogi en op installeren klikken.

## Bijwerken naar nieuwere versies
Er worden regelmatig nieuwe updates van Open Catalogi gepubliceerd, deze kunnen via de Common Gateway Admin ui worden geïnstalleerd door  naar plugins te navigeren, Open Catalogi te selecteren en op Update te drukken.