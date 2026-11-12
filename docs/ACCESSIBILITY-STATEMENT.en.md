# Accessibility statement for AabenIntra

> **For the operator of an installation:** This is the template for the
> accessibility statement each customer (tenant) publishes for its own
> AabenIntra site. Danish public bodies must create the statement in the
> Danish Agency for Digital Government's statement tool at
> [was.digst.dk](https://www.was.digst.dk) and link to it from the site; use
> the content below as the source. Every field marked `[[LIKE THIS]]` **must
> be filled in** before publication, and "Non-accessible content" must be
> reconciled with the current list in
> [ACCESSIBILITY-KNOWN-ISSUES.md](ACCESSIBILITY-KNOWN-ISSUES.md).
> This file is documentation in the source repository; it is not a valid
> statement until completed and published by the public body.
> The Danish version, [ACCESSIBILITY-STATEMENT.da.md](ACCESSIBILITY-STATEMENT.da.md),
> is the one the Danish law requires; this English version is for
> bilingual sites and for international readers.

`[[TENANT_ORGANISATION]]` is committed to making its intranet accessible in
accordance with the Danish *Act on the accessibility of public sector bodies'
websites and mobile applications* (Act no. 692 of 8 June 2018, as amended),
which transposes Directive (EU) 2016/2102.

This accessibility statement applies to: `[[TENANT_SITE_URL]]` (AabenIntra,
version `[[AABENINTRA_VERSION]]`).

## Target and standard

AabenIntra is developed against **WCAG 2.2 level AA** for every page a regular
employee uses. This goes beyond the current legal requirement (EN 301 549,
which is based on WCAG 2.1 AA) and therefore also covers the success criteria
added in WCAG 2.2 (focus not obscured, minimum target size, alternatives to
dragging movements, consistent help, among others).

## Compliance status

This website is **partially compliant** with the requirements of the Act, due
to the non-compliances listed below.

`[[The public body selects the status that matches its own review: "fully
compliant", "partially compliant" or "not compliant". The product's starting
point is "partially" until the known deviations below are closed and a manual
review has been carried out on the specific installation.]]`

## Non-accessible content

The content listed below is non-accessible for the following reasons.

### a) Non-compliance with the Act

The current list of known deviations in the product itself is maintained in
[ACCESSIBILITY-KNOWN-ISSUES.md](ACCESSIBILITY-KNOWN-ISSUES.md). At the time of
this statement they are:

- The navigation drawer and the "Personalise" panel do not keep keyboard
  focus inside them while open, and focus does not always return to the
  button that opened them (WCAG 2.4.3 Focus Order). Tracked in GitHub issue
  #100.
- Rearranging dashboard tiles by dragging is pointer-only; pinning works with
  the keyboard, but there is no keyboard way to move a tile (WCAG 2.5.7
  Dragging Movements, 2.1.1 Keyboard). Tracked in GitHub issue #100.
- `[[Add deviations found during the public body's own review of the
  installation, including tenant-specific customisations.]]`

### b) Disproportionate burden

`[[List any content exempted under the Act's disproportionate-burden clause,
with the justification - or write "None".]]`

### c) Content outside the scope of the Act

- Office file formats (for example PDF, Word) published before 23 September
  2018 that are not needed for active administrative processes.
- Third-party content that the public body has neither funded, developed nor
  controls.
- `[[Add as needed.]]`

Editorial content (news, documents, images with alternative text) is the
public body's own responsibility. AabenIntra automatically checks that images
have an alternative text, not the quality of that text.

## Preparation of this accessibility statement

This statement was prepared on `[[DATE]]`.

The statement is based on:

- **Automated testing** of the product with axe-core (WCAG 2.2 A/AA rule set)
  on the login page, the dashboard, news, people directory, channels,
  recognition wall, activity feed, a story and a channel page, in both the
  English and the Danish interface. The test runs on every change to the
  product and weekly (see `docs/ACCESSIBILITY.md`). Serious and critical
  findings block a release.
- **Lighthouse** measurements of accessibility, performance and best
  practices on the same key pages.
- **Manual review** carried out by `[[NAME/UNIT]]` on `[[DATE]]` following the
  checklist in `docs/ACCESSIBILITY.md` (keyboard operation, visible focus,
  screen reader, 200 % and 400 % zoom, reduced motion, contrast in light and
  dark scheme). `[[Replace with "not yet carried out" if that is the case.]]`

The statement was last reviewed on `[[DATE]]`. It is reviewed at least once a
year and whenever the product changes substantially.

## Feedback and contact information

If you experience accessibility problems on the intranet, or need content in
another format, contact:

- `[[TENANT_CONTACT_UNIT]]`
- Email: `[[TENANT_CONTACT_EMAIL]]`
- Phone: `[[TENANT_CONTACT_PHONE]]`

We aim to respond within `[[X]]` working days. Defects in the AabenIntra
product itself are passed on to the supplier and recorded in the product's
public issue tracker (GitHub, label `a11y`).

## Enforcement procedure

The Danish Agency for Digital Government (Digitaliseringsstyrelsen) monitors
compliance with the Act. If you are not satisfied with our response to your
accessibility enquiry, you can contact the Agency via
[was.digst.dk](https://www.was.digst.dk).
`[[Verify the supervisory authority's current contact details at was.digst.dk
before publishing.]]`
