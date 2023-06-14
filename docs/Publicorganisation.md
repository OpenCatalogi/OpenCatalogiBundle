# Specificatie YAML voor Openbare Organisaties

Het bestand openCatalogi.yaml is een voorgestande standaard voor het identificeren en verstrekken van informatie over openbare organisaties op GitHub. Dit bestand moet worden geplaatst in de .github-map van het repository van een GitHub-organisatie. Het dient als een verklaring van de identiteit van de organisatie, inclusief de naam, website, contactgegevens en de software die het gebruikt en ondersteunt.

Door dit bestand te implementeren, kunnen openbare organisaties effectiever communiceren over hun softwareportfolio en bijdragen aan een transparant ecosysteem voor open-source. Het openCatalogi.yaml-bestand van elke organisatie biedt essentiële informatie die kan worden gebruikt door ontwikkelaars, burgers en andere belanghebbenden om de activiteiten en toewijding van de organisatie in het open-source domein te begrijpen.

Een belangrijk aspect van deze toewijding aan open-source is de ondersteuning die een organisatie biedt voor bepaalde software. Dit omvat zowel software die eigendom is van de organisatie zelf als software die eigendom is van andere organisaties. De geboden ondersteuning kan verschillende vormen aannemen, zoals onderhoud, hosting, software als een dienst (SaaS) of andere services. Deze details worden vastgelegd in een onderhoudsobject onder het veld softwareSupported.

Het onderhoudsobject vertegenwoordigt het type en niveau van ondersteuning dat de organisatie biedt voor een bepaalde software. Het bevat details zoals het type onderhoud en contactgegevens voor onderhoudsvragen.

Hier is een voorbeeld van een openCatalogi.yaml-bestand:

```yaml
openCatalogiYmlVersion: "0.2"

# The official name of the public organization
name: 'Public Organization Name'

# The official website of the public organization
website: 'https://www.publicorganizationwebsite.com'

# The contact details of the public organization
contact:
  email: 'contact@publicorganization.com' # Public contact email
  phone: '+1234567890' # Public contact phone number

# List of software the public organization owns. keep in mind that owns dosn't automaticly means maintained
# Each item refers to the repository URL of the software
softwareOwned:
  - 'https://github.com/organization/software1'
  - 'https://github.com/organization/software2'
  
# List of software the public organization uses
# Each item refers to the repository URL of the software
softwareUsed:
  - 'https://github.com/organization/software1'
  - 'https://github.com/organization/software2'

# List of software the public organization supports
# Each item is a maintenance object representing the support provided for a software
softwareSupported:
  - software: 'https://github.com/organization/software2'
    type: 'maintenance' # The type of support provided
    contact:
      email: 'contact@publicorganization.com' # Public contact email
      phone: '+1234567890' # Public contact phone number
  - software: 'https://github.com/organization/software2'
    type: 'saas' # The type of support provided
    contact:
      email: 'contact@publicorganization.com' # Public contact email
      phone: '+1234567890' # Public contact phone number
```

Here is a table presenting all the properties in the openCatalogi.yaml or openCatalogi.yml file:

|Key| 	Type                    | 	Description                                                                                                                        |
|---|--------------------------|-------------------------------------------------------------------------------------------------------------------------------------|
|name| 	String                  | 	The official name of the public organization                                                                                       |
|website| 	String (URL)            | 	The official website of the public organization                                                                                    |
|logo| 	String                  | 	The image of the organization\
|catalogusAPI| 	String                  | 	The catalogus api of the organization\
|contact| 	Object                  | 	The contact details of the public organization                                                                                     |
|contact.email| 	String                  | 	The public contact email of the public organization                                                                                |
|contact.phone| 	String                  | 	The public contact phone number of the public organization                                                                         |
|softwareUsed| 	Array of Strings (URLs) | 	List of software the public organization uses, represented by their repository URLs                                                |
|softwareSupported|	Array of Objects (maintenance)| 	List of software the public organization supports, represented by their maintenance objects\
|softwareSupported\[].software|	String| 	The software that the organization supports\
|softwareSupported\[].type|	String| 	The type of support provided for the software, one of "Hosting","SAAS","Support","Maintenance","Training","Consultancy","Purchase"
|softwareSupported\[].contact|	Object| 	The contact details of the support                                                                                                 |
|softwareSupported\[].contact.email| 	String                  | 	The public contact email of the public organization                                                                                |
|softwareSupported\[].contact.phone| 	String                  | 	The public contact phone number of the public organization                                                                         |
