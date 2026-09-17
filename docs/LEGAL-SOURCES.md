# Legal sources

The rules the module follows, with where they come from and when they were
read. Amounts and deadlines are checked against the primary source again before
the feature that uses them is built. **Primary** means the law, the ministry or
the government's business service portal was read; **secondary** means an
association federation or tax advisor, to be confirmed.

Research date: 16 September 2026.

## Austria

| Topic | Rule | Source | Kind |
| --- | --- | --- | --- |
| Officers | Report new officers and a new address for service to the association authority within four weeks | [§ 14 VerG](https://www.jusline.at/gesetz/verg/paragraf/14) | primary |
| ZVR number | Must be used in dealings with others: letters, invoices, website | [§ 18 VerG](https://www.jusline.at/gesetz/verg/paragraf/18) | secondary |
| Accounts | Income and expenditure statement and statement of assets within five months; auditors check within four months | [§ 21 VerG](https://www.jusline.at/gesetz/verg/paragraf/21) | primary |
| Larger associations | Annual financial statements above EUR 1 million income or expenditure in two consecutive years; statutory audit above EUR 3 million or EUR 1 million donations | [§ 22 VerG](https://www.jusline.at/gesetz/verg/paragraf/22) | primary |
| Membership fees | Genuine membership fees, donations and subsidies without consideration are outside the scope of VAT | [USP: Umsatzsteuer für Vereine](https://www.usp.gv.at/themen/steuern-finanzen/umsatzsteuer-ueberblick/weitere-informationen-zur-umsatzsteuer/weitere-steuertatbestaende-und-befreiungen/umsatzsteuer-fuer-vereine.html) | primary |
| Small businesses | VAT exemption up to EUR 55,000 turnover since 2025, a gross amount; exceeded by at most 10 % the exemption lasts until the end of the year, beyond that it ends with the exceeding turnover | [§ 6 (1) no. 27 UStG](https://www.jusline.at/gesetz/ustg/paragraf/6), [USP: Kleinunternehmen](https://www.usp.gv.at/themen/steuern-finanzen/umsatzsteuer-ueberblick/weitere-informationen-zur-umsatzsteuer/weitere-steuertatbestaende-und-befreiungen/kleinunternehmen.html) (updated 1 January 2026) | primary |
| Spheres | Business of a non-profit body: dispensable auxiliary business (§ 45 (1)), indispensable auxiliary business with three conditions (§ 45 (2)), otherwise harmful (§ 45 (3), § 44) | [§ 45 BAO](https://www.jusline.at/gesetz/bao/paragraf/45) | primary |
| Small association festival | Social event carried by the association, organised mainly by members, outsiders only unpaid, artists at most EUR 1,000 an hour, at most 72 hours a year in total | [§ 45 (1a) BAO](https://www.jusline.at/gesetz/bao/paragraf/45), in force since 2 August 2016 | primary |
| VAT without consideration | Genuine membership fees, donations and subsidies without direct consideration are not subject to VAT | [USP: Umsatzsteuer für Vereine](https://www.usp.gv.at/themen/steuern-finanzen/umsatzsteuer-ueberblick/weitere-informationen-zur-umsatzsteuer/weitere-steuertatbestaende-und-befreiungen/umsatzsteuer-fuer-vereine.html) (updated 1 January 2026) | primary |
| Examples per area | Genuine fees, donations, free talks (idealistic); interest and letting (assets); theatre association's performance, museum admission, sports lessons (indispensable); balls, festivals, flea markets (dispensable); own canteen, large festival, sale of goods (harmful). VAT: Liebhaberei presumed for indispensable and dispensable auxiliary businesses, not for asset management; income from auxiliary businesses does not count towards the small business limit | [BMF: Vereine und Steuern](https://www.wko.at/oe/wirtschaftsrecht/bmf-br-st-vereine-und-steuern-201608-12.pdf), legal state August 2016, sections 4.1 to 4.5 and FAQ; amounts in it are outdated (EUR 40,000 is now EUR 100,000 under § 45a BAO) | primary |
| Liebhaberei | Without lasting profit an association's activity is Liebhaberei and not subject to VAT; presumed for indispensable and dispensable auxiliary businesses, which may still be treated as business activity | USP as above; Vereinsrichtlinien Rz 463 and 464 as cited by [sport-steuer.at](https://www.sport-steuer.at/umsatzsteuer_verein.php) | primary / secondary |
| 10 % rate | Supplies of non-profit bodies; not for a commercial or agricultural business or a business under § 45 (3) BAO, not for letting parking spaces and some other supplies | [§ 10 (2) no. 4 UStG](https://www.jusline.at/gesetz/ustg/paragraf/10) | primary |
| 13 % rate | Among others admission to sports events (no. 12), theatre, music and museums (no. 6), youth and education services (no. 10), swimming pools (no. 5) | [§ 10 (3) UStG](https://www.jusline.at/gesetz/ustg/paragraf/10) | primary |
| Sports exemption | Supplies of non-profit associations whose statutory purpose is practising or promoting physical sport; not within a business under § 45 (3) BAO | [§ 6 (1) no. 14 UStG](https://www.jusline.at/gesetz/ustg/paragraf/6) | primary |
| Invoice note | An invoice for an exempt supply must say that an exemption applies | [§ 11 (1) no. 3 lit. e UStG](https://www.jusline.at/gesetz/ustg/paragraf/11) | primary |
| Dolibarr VAT rates | Dolibarr 22, 23 and 24 ship 0, 10 and 20 % for Austria, no 13 %; the column `einvoice_vatex` exists from 24 | `htdocs/install/mysql/data/llx_c_tva.sql` of each branch | primary |
| Harmful business | Non-profit status kept without an exemption notice when the turnover under § 1 (1) no. 1 and 2 UStG of all businesses under § 45 (3) BAO does not exceed EUR 100,000 in the assessment period; in force since 1 January 2024. The law does not say gross or net; the module compares gross amounts to warn early | [§ 45a BAO](https://www.jusline.at/gesetz/bao/paragraf/45a) | primary |
| Harmful business until 2023 | EUR 40,000; confirmed for 2016 by the ministry's brochure, the module uses it from 2016 to 2023 | [BMF: Vereine und Steuern](https://www.wko.at/oe/wirtschaftsrecht/bmf-br-st-vereine-und-steuern-201608-12.pdf), section 4.3.4 | primary |
| Small businesses until 2024 | EUR 35,000 net; the module uses it from 2020 to 2024 | [USP: Kleinunternehmerregelung ab 2025](https://www.usp.gv.at/aktuelles/newsliste/kleinunternehmerregelung-ab-2025.html) | primary |
| Small business limit, what counts | Income from indispensable and dispensable auxiliary businesses need not be counted; turnover exempt under § 6 (1) no. 14 UStG is left out by § 6 (1) no. 27 UStG | [BMF: Vereine und Steuern](https://www.wko.at/oe/wirtschaftsrecht/bmf-br-st-vereine-und-steuern-201608-12.pdf) FAQ, [§ 6 UStG](https://www.jusline.at/gesetz/ustg/paragraf/6) | primary |
| Cash register | Required from EUR 15,000 turnover per business when cash turnover exceeds EUR 7,500, from the fourth month after the period in which both were first exceeded | [§ 131b BAO](https://www.jusline.at/gesetz/bao/paragraf/131b) | primary |
| Cash register for associations | Indispensable auxiliary businesses and small association festivals are exempt. The original Barumsatzverordnung 2015 named 48 hours of social events; § 45 (1a) BAO names 72 since 2016. The BMF brochure of August 2016 already applies § 3 (2) BarUV 2015 with 72 hours. Read the current wording (last amended BGBl. II Nr. 321/2025) before issue #40 is built | [Sport Austria](https://www.sportaustria.at/de/service-center/recht-und-finanzen/registrierkassenpflicht), [RIS: BarUV 2015](https://www.ris.bka.gv.at/GeltendeFassung.wxe?Abfrage=Bundesnormen&Gesetzesnummer=20009267) | secondary, to confirm |
| Donation reporting | Yearly total per donor with vbPK SA to the tax office by the end of February; first, change and cancellation transmissions | [BMF for software makers](https://www.bmf.gv.at/services/finanzonline/informationen-fuer-softwarehersteller/softewarehersteller-sonderausgaben-datenuebermittlung.html), schema of 24 July 2024, description of 19 January 2026 | primary |
| Volunteer allowance | Small EUR 30 a day and EUR 1,000 a year; large EUR 50 a day and EUR 3,000 a year; excess reported with form E 29 by the end of February | [BDO](https://www.bdo.at/de-at/blog/tax-news/tax-news/update-gemeinnuetzigkeit), [LBG](https://www.lbg.at/servicecenter/lbg_steuertipps_praxis/vereine_meldepflicht_des_freiwilligenpauschales_bis_ende_februar_beachten/index_ger.html) | secondary |
| Sports travel allowance | EUR 120 per day of activity, EUR 720 a month; form L 19 through ELDA by the end of February | [BMF](https://www.bmf.gv.at/themen/steuern/spenden-gemeinnuetzigkeit/haeufig-gestellte-fragen-zu-vereinen-gemeinn%C3%BCtzigkeit-und-registrierkassenpflicht/abgabe-der-meldung-pauschale-reiseaufwandsentschaedigung-(prae).html), [Sport Austria](https://www.sportaustria.at/de/interessenvertretung-und-sportpolitik/pauschale-reiseaufwandsentschaedigung) | primary / secondary |
| Retention | Seven years | § 132 BAO | secondary |

## Germany

To be confirmed before the German profile (version 1.1) is built.

| Topic | Rule | Source | Kind |
| --- | --- | --- | --- |
| Tax exemption limit | EUR 50,000 income of the taxable business from 2026 (before EUR 45,000) | § 64 (3) AO, Steueränderungsgesetz 2025 ([Paritätischer](https://www.der-paritaetische.de/alle-meldungen/steueraenderungsgesetz-2025)) | secondary |
| Timely use of funds | Not required up to EUR 100,000 income from 2026 | § 55 (1) no. 5 AO | secondary |
| Allowances | Trainer allowance EUR 3,300, volunteer allowance EUR 960 from 2026 | § 3 no. 26, 26a EStG | secondary |
| E-sports | Promotion of e-sports becomes a charitable purpose from 2026 | Steueränderungsgesetz 2025 | secondary |
| Small businesses | EUR 25,000 previous year, EUR 100,000 current year; invoice note required | § 19 UStG | secondary |
| Donation receipts | Binding official templates; up to EUR 300 a payment record suffices | § 50 EStDV, [BMF templates](https://ao.bundesfinanzministerium.de/esth/2025/B-Anhaenge/Anhang-37/I/inhalt.html) | primary |
| VAT on membership fees | Fees can be consideration for services; administration not yet adapted | BFH V R 4/23 of 13 November 2025 ([Grant Thornton](https://www.grantthornton.de/themen/2026/umsatzsteuerliche-behandlung-von-mitgliedsbeitraegen-bei-vereinen/)) | secondary |
| Retention | Booking records eight years since 2025, books and financial statements ten | § 147 AO, BEG IV | secondary |
