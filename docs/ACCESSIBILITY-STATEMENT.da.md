# Tilgængelighedserklæring for AabenIntra

> **Til leverandøren/driftsansvarlige for installationen:** Denne tekst er
> skabelonen for den tilgængelighedserklæring, som hver kunde (hvert "tenant")
> skal offentliggøre for sin egen AabenIntra-installation. Offentlige
> myndigheder skal oprette erklæringen i Digitaliseringsstyrelsens
> erklæringsværktøj på [was.digst.dk](https://www.was.digst.dk) og linke til
> den fra webstedet; brug indholdet herunder som grundlag. Alle felter markeret
> `[[SÅDAN]]` **skal udfyldes** før offentliggørelse, og afsnittet
> "Ikke-tilgængeligt indhold" skal afstemmes med den aktuelle liste i
> [ACCESSIBILITY-KNOWN-ISSUES.md](ACCESSIBILITY-KNOWN-ISSUES.md).
> Denne fil er dokumentation i kildekoden; den er ikke selv en gyldig
> erklæring, før den er udfyldt og offentliggjort af myndigheden.

`[[TENANT_ORGANISATION]]` er forpligtet til at gøre sit intranet tilgængeligt i
overensstemmelse med *Lov om tilgængelighed af offentlige organers websteder og
mobilapplikationer* (lov nr. 692 af 8. juni 2018 med senere ændringer).

Denne tilgængelighedserklæring gælder for: `[[TENANT_SITE_URL]]` (AabenIntra,
version `[[AABENINTRA_VERSION]]`).

## Mål og standard

AabenIntra udvikles med **WCAG 2.2 niveau AA** som mål for alle sider, en
almindelig medarbejder møder. Det ligger ud over lovens aktuelle krav
(EN 301 549, som bygger på WCAG 2.1 AA) og dækker dermed også de
succeskriterier, der er tilføjet i WCAG 2.2 (bl.a. fokus synligt og ikke
tildækket, minimumsstørrelse på klikmål, alternativer til træk-bevægelser og
konsistent hjælp).

## Overholdelsesstatus

Webstedet er **delvist i overensstemmelse** med lovens krav, jf. afsnittet
"Ikke-tilgængeligt indhold".

`[[Myndigheden vælger den status, der passer efter egen gennemgang: "fuldt i
overensstemmelse", "delvist i overensstemmelse" eller "ikke i
overensstemmelse". Produktets udgangspunkt er "delvist", indtil de kendte
afvigelser nedenfor er lukket og en manuel gennemgang er udført på den
konkrete installation.]]`

## Ikke-tilgængeligt indhold

Det indhold, der er anført nedenfor, er ikke tilgængeligt af følgende årsager.

### a) Manglende overholdelse af loven

Den aktuelle liste over kendte afvigelser i selve produktet vedligeholdes i
[ACCESSIBILITY-KNOWN-ISSUES.md](ACCESSIBILITY-KNOWN-ISSUES.md). På tidspunktet
for denne erklæring drejer det sig om:

- Navigationspanelet og panelet "Personliggør" fastholder ikke tastaturfokus,
  mens de er åbne, og fokus vender ikke altid tilbage til den knap, der åbnede
  dem (WCAG 2.4.3 Fokusrækkefølge, 2.1.2 Ingen tastaturfælde i omvendt
  forstand). Rettes i GitHub-sag #100.
- Omplacering af fliser på forsiden ved at trække kan kun udføres med mus
  eller touch; fastgørelse ("pin") kan udføres med tastatur, men der findes
  ingen tastaturbaseret flytning (WCAG 2.5.7 Trækbevægelser, 2.1.1 Tastatur).
  Rettes i GitHub-sag #100.
- `[[Tilføj afvigelser fundet ved myndighedens egen gennemgang af
  installationen, herunder tenant-specifikke tilpasninger.]]`

### b) Uforholdsmæssig stor byrde

`[[Angiv eventuelt indhold, der er undtaget med henvisning til lovens § 3,
stk. 2 om uforholdsmæssig stor byrde, med begrundelse - eller skriv "Ingen".]]`

### c) Indholdet er ikke omfattet af loven

- Dokumenter (fx PDF, Word) offentliggjort før den 23. september 2018, som
  ikke er nødvendige for aktive administrative processer.
- Indhold leveret af tredjepart, som myndigheden hverken har finansieret,
  udviklet eller har kontrol over.
- `[[Suppler efter behov.]]`

Redaktionelt indhold (nyheder, dokumenter, billeder med alternativ tekst) er
myndighedens eget ansvar. AabenIntra kontrollerer automatisk, at billeder har
en alternativ tekst, men ikke tekstens kvalitet.

## Udarbejdelse af denne tilgængelighedserklæring

Denne erklæring blev udarbejdet den `[[DATO]]`.

Erklæringen er baseret på:

- **Automatiseret test** af produktet med axe-core (WCAG 2.2 A/AA-regelsæt)
  på login-siden, forsiden/dashboard, nyheder, medarbejderoversigt, kanaler,
  anerkendelser, aktivitet, en historie og en kanalside, på både engelsk og
  dansk brugerflade. Testen kører automatisk ved hver ændring af produktet og
  ugentligt (se `docs/ACCESSIBILITY.md`). Alvorlige og kritiske fejl blokerer
  for udgivelse.
- **Lighthouse**-måling af tilgængelighed, ydeevne og god praksis på de samme
  centrale sider.
- **Manuel gennemgang** udført af `[[NAVN/ENHED]]` den `[[DATO]]` efter
  tjeklisten i `docs/ACCESSIBILITY.md` (tastaturbetjening, synligt fokus,
  skærmlæser, 200 % og 400 % zoom, reduceret bevægelse, kontrast i lyst og
  mørkt tema). `[[Erstat med "endnu ikke udført", hvis det er tilfældet.]]`

Erklæringen blev senest gennemgået den `[[DATO]]`. Den gennemgås mindst én
gang årligt og ved væsentlige ændringer af produktet.

## Feedback og kontaktoplysninger

Oplever du problemer med tilgængeligheden på intranettet, eller har du brug for
indhold i et andet format, så kontakt:

- `[[TENANT_KONTAKTENHED]]`
- E-mail: `[[TENANT_KONTAKT_EMAIL]]`
- Telefon: `[[TENANT_KONTAKT_TELEFON]]`

Vi bestræber os på at svare inden for `[[X]]` arbejdsdage. Fejl i selve
AabenIntra-produktet videregives til leverandøren og registreres i produktets
offentlige sagsliste (GitHub, mærkatet `a11y`).

## Håndhævelsesprocedure

Digitaliseringsstyrelsen fører tilsyn med, at loven overholdes. Hvis du ikke er
tilfreds med vores svar på din henvendelse om tilgængelighed, kan du kontakte
Digitaliseringsstyrelsen via [was.digst.dk](https://www.was.digst.dk).
`[[Kontroller de aktuelle kontaktoplysninger for tilsynet på was.digst.dk, før
erklæringen offentliggøres.]]`
