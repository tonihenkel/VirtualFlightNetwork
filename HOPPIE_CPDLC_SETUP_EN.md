# VFN Hoppie CPDLC gateway

VFN uses Hoppie's ACARS network to exchange CPDLC messages between compatible aircraft FMC/MCDU systems and the VFN ATC client. Pilots do not need a separate VFN CPDLC window.

## Server setup (OP level 5)

1. Obtain a Hoppie network logon code from <https://www.hoppie.nl/acars/system/register.html>.
2. Open **Administrator > Settings > CPDLC / Hoppie**.
3. Enter the logon code.
4. Keep the connect URL at `https://www.hoppie.nl/acars/system/connect.html`.
5. Select a polling interval between 45 and 75 seconds and enable the gateway.
6. Save the settings. The secret is stored in the Git-ignored `runtime-secrets.json` file and is masked in the settings form.
7. Open an active ATC position. The CPDLC panel displays `HOPPIE READY` or the most recent gateway error.

## Pilot setup and workflow

1. Configure the aircraft's Hoppie/ACARS provider with the pilot's personal Hoppie logon code. The exact procedure depends on the aircraft.
2. Enter the four-character VFN ATC station in the aircraft FMC/MCDU, such as `EDDP` or `EDMM`.
3. Request a CPDLC logon.
4. The request appears in the CPDLC window of the responsible VFN ATC client. The controller accepts or rejects it.
5. Controller uplinks appear in the aircraft FMC/MCDU. Aircraft downlinks appear in the VFN ATC transcript.
6. Log off from CPDLC when communication is no longer required.

Internal sector identifiers are mapped to a four-character Hoppie station. For example, `EDMM_MEI` uses the Hoppie station `EDMM`.

## Delivery time

Hoppie uses store-and-forward delivery. A message can take approximately one polling cycle to arrive. Voice communication remains the fallback if CPDLC is unavailable.

## X-Plane plugin

The aircraft communicates directly with Hoppie. This gateway does not require a new VFN XPL file and does not add a second CPDLC pilot window to the plugin.

## Live-test checklist

- The administration status reports that the gateway is enabled.
- An active controller position reports `HOPPIE READY`.
- The aircraft can request a logon to the displayed four-character station.
- The controller can accept and reject a logon request.
- An uplink arrives in the aircraft FMC/MCDU.
- A downlink arrives in the VFN ATC transcript.
- Logoff works on both sides.
- No private Hoppie logon code appears in logs, screenshots, source control, or bug reports.

## Requesting inclusion in Hoppie's software list

After a successful live test, email `hoppie@hoppie.nl` with the product name, website, platform, protocol, a short integration description, a contact address, and preferably a screenshot plus confirmation of a successful aircraft-to-controller test.

Suggested subject: `Software list request - Virtual Flight Network ATC Radar Client`

Never include the private Hoppie logon code in that email.
