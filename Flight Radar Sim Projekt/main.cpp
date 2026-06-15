#include "pch.h"

#define IBM 1

#include "XPLMPlugin.h"
#include "XPLMUtilities.h"
#include "XPLMDataAccess.h"
#include "XPLMProcessing.h"
#include "XPLMMenus.h"

#include "XPWidgets.h"
#include "XPStandardWidgets.h"
#include "XPWidgetUtils.h"

#include <cstdio>
#include <fstream>
#include <string>
#include <sstream>
#include <map>
#include <windows.h>
#include <winhttp.h>

#pragma comment(lib, "winhttp.lib")

static std::string gPluginDirectory;
static std::string gConfigPath;
static std::string gLanguageDirectory;
static std::string gCurrentLanguage = "en";

static std::map<std::string, std::string> gText;

static bool gDebugEnabled = false;


/*
static const std::string gServerAddress =
"https://virtualflightnetwork.com";
*/


static const std::string gServerAddress =
"http://127.0.0.1";



static const std::string gLoginUrl =
gServerAddress + "/execute/login.php";

static const std::string gLogoutUrl =
gServerAddress + "/execute/logout.php";

static const std::string gPositionUrl =
gServerAddress + "/execute/position_update.php";

static const std::string gFlightplanUrl =
gServerAddress + "/execute/flightplan_update.php";

static const std::string gSetInvisibleUrl =
gServerAddress + "/execute/set_invisible.php";

static bool gLoggedIn = false;

static std::string gCurrentUsername = "";
static std::string gCurrentCallsign = "";
static std::string gAuthToken = "";

static bool gCanUseInvisible = false;
static bool gIsInvisible = false;

static bool gCloseFlightplanAfterSend = false;

static int gSelectedFlightRulesIndex = 0;
static int gSelectedFlightTypeIndex = 2;

static XPLMMenuID gMenuId = nullptr;
static int gLoginMenuItem = 0;
static int gFlightplanMenuItem = 0;

static XPWidgetID gLoginWindow = nullptr;

static XPWidgetID gUsernameLabel = nullptr;
static XPWidgetID gPasswordLabel = nullptr;
static XPWidgetID gCallsignLabel = nullptr;

static XPWidgetID gUsernameField = nullptr;
static XPWidgetID gPasswordField = nullptr;
static XPWidgetID gCallsignField = nullptr;

static XPWidgetID gRememberLoginButton = nullptr;
static bool gRememberLogin = false;

static XPWidgetID gStatusCaption = nullptr;

static XPWidgetID gConnectButton = nullptr;
static XPWidgetID gLogoutButton = nullptr;
static XPWidgetID gInvisibleButton = nullptr;

static XPWidgetID gFlightplanWindow = nullptr;

static XPWidgetID gFlightRulesLabel = nullptr;
static XPWidgetID gFlightTypeLabel = nullptr;
static XPWidgetID gDepartureTimeLabel = nullptr;
static XPWidgetID gDepartureAirportLabel = nullptr;
static XPWidgetID gArrivalAirportLabel = nullptr;
static XPWidgetID gAlternate1AirportLabel = nullptr;
static XPWidgetID gAlternate2AirportLabel = nullptr;
static XPWidgetID gRouteLabel = nullptr;
static XPWidgetID gCruisingLevelLabel = nullptr;
static XPWidgetID gCruisingSpeedLabel = nullptr;
static XPWidgetID gRemarksLabel = nullptr;

static XPWidgetID gFlightRulesField = nullptr;
static XPWidgetID gFlightTypeField = nullptr;
static XPWidgetID gDepartureTimeField = nullptr;
static XPWidgetID gDepartureAirportField = nullptr;
static XPWidgetID gArrivalAirportField = nullptr;
static XPWidgetID gAlternate1AirportField = nullptr;
static XPWidgetID gAlternate2AirportField = nullptr;
static XPWidgetID gRouteField = nullptr;
static XPWidgetID gPasteRouteButton = nullptr;
static XPWidgetID gClearRouteButton = nullptr;
static XPWidgetID gCruisingLevelField = nullptr;
static XPWidgetID gCruisingSpeedField = nullptr;
static XPWidgetID gRemarksField = nullptr;

static XPWidgetID gCloseAfterSendButton = nullptr;

static XPWidgetID gSendFlightplanButton = nullptr;
static XPWidgetID gFlightplanStatusCaption = nullptr;

static XPLMDataRef gLatitude = nullptr;
static XPLMDataRef gLongitude = nullptr;
static XPLMDataRef gAltitude = nullptr;
static XPLMDataRef gHeading = nullptr;
static XPLMDataRef gAirspeed = nullptr;
static XPLMDataRef gPitch = nullptr;
static XPLMDataRef gRoll = nullptr;
static XPLMDataRef gVerticalSpeed = nullptr;

static XPLMDataRef gCom1 = nullptr;
static XPLMDataRef gCom2 = nullptr;
static XPLMDataRef gCom3 = nullptr;

static XPLMDataRef gTransponder = nullptr;

static XPLMDataRef gOnGround = nullptr;


void UpdateFlightplanWindowState();


std::string TrimString(const std::string& value)
{
    size_t start =
        value.find_first_not_of(" \t\r\n");

    if (start == std::string::npos)
    {
        return "";
    }

    size_t end =
        value.find_last_not_of(" \t\r\n");

    return value.substr(
        start,
        end - start + 1
    );
}


std::string ToLowerString(
    const std::string& value
)
{
    std::string result =
        value;

    for (size_t i = 0; i < result.size(); i++)
    {
        if (result[i] >= 'A' && result[i] <= 'Z')
        {
            result[i] =
                result[i] + 32;
        }
    }

    return result;
}


std::string ToUpperString(
    const std::string& value
)
{
    std::string result =
        value;

    for (size_t i = 0; i < result.size(); i++)
    {
        if (result[i] >= 'a' && result[i] <= 'z')
        {
            result[i] =
                result[i] - 32;
        }
    }

    return result;
}


const char* T(
    const std::string& key
)
{
    auto it =
        gText.find(key);

    if (it != gText.end())
    {
        return it->second.c_str();
    }

    return key.c_str();
}


void LoadInternalEnglishLanguage()
{
    gText.clear();

    gText["window.login.title"] = "Flight Radar Login";
    gText["window.flightplan.title"] = "Flightplan";

    gText["label.username"] = "Username:";
    gText["label.password"] = "Password:";
    gText["label.callsign"] = "Callsign:";
    gText["checkbox.remember_login.off"] = "[ ] Remember login";
    gText["checkbox.remember_login.on"] = "[X] Remember login";

    gText["button.connect"] = "Connect";
    gText["button.logout"] = "Logout";
    gText["button.send_flightplan"] = "Send Flightplan";
    gText["button.paste_route"] = "Paste Route";
    gText["button.clear_route"] = "Clear Route";

    gText["checkbox.invisible.off"] = "[ ] Invisible";
    gText["checkbox.invisible.on"] = "[X] Invisible";
    gText["status.invisible_enabled"] = "Invisible Mode enabled.";
    gText["status.invisible_disabled"] = "Invisible Mode disabled.";

    gText["menu.title"] = "Flight Radar Sim Project";
    gText["menu.login"] = "Open / Close Login Window";
    gText["menu.flightplan"] = "Open / Close Flightplan Window";

    gText["status.not_connected"] = "Not connected.";
    gText["status.connected_as"] = "Connected as";
    gText["status.logout_sending"] = "Sending logout...";
    gText["status.logout_success"] = "Logout successful.";
    gText["status.local_logout_server"] = "Logged out locally. Server: ";
    gText["status.already_connected"] = "You are already connected.";
    gText["status.login_missing"] = "Please enter username, password and callsign.";
    gText["status.connecting"] = "Connecting to server...";
    gText["status.login_success_no_token"] = "Login successful, but no token received.";
    gText["status.login_failed_log"] = "Flight Radar Plugin: Login failed.\n";
    gText["status.login_first"] = "Please login first.";

    gText["label.flight_rules"] = "Flight Rules:";
    gText["label.flight_type"] = "Flight Type:";
    gText["label.departure_time"] = "Departure Time:";
    gText["label.departure_airport"] = "Departure ICAO:";
    gText["label.arrival_airport"] = "Arrival ICAO:";
    gText["label.alternate1_airport"] = "Alternate 1 ICAO:";
    gText["label.alternate2_airport"] = "Alternate 2 ICAO:";
    gText["label.route"] = "Route:";
    gText["label.cruising_level"] = "Cruising Level:";
    gText["label.cruising_speed"] = "Cruising Speed:";
    gText["label.remarks"] = "Additional Info:";

    gText["option.flight_rules.ifr"] = "IFR";
    gText["option.flight_rules.vfr"] = "VFR";
    gText["option.flight_rules.ifr_vfr"] = "IFR then VFR";
    gText["option.flight_rules.vfr_ifr"] = "VFR then IFR";

    gText["option.flight_type.scheduled"] = "Scheduled Airline";
    gText["option.flight_type.non_scheduled"] = "Non-Scheduled";
    gText["option.flight_type.general_aviation"] = "General Aviation";
    gText["option.flight_type.military"] = "Military";
    gText["option.flight_type.other"] = "Other";

    gText["checkbox.close_after_send.off"] = "[ ] Close after send";
    gText["checkbox.close_after_send.on"] = "[X] Close after send";

    gText["flightplan.ready"] = "Flightplan ready.";
    gText["flightplan.sending"] = "Sending flightplan...";
    gText["flightplan.saved"] = "Flightplan saved.";
    gText["flightplan.error"] = "Flightplan error: ";
    gText["flightplan.saved_log"] = "Flight Radar Plugin: Flightplan saved.\n";
    gText["flightplan.failed_log"] = "Flight Radar Plugin: Flightplan could not be saved.\n";

    gText["debug.plugin_path"] = "Flight Radar Plugin: Plugin path detected:\n";
    gText["debug.config_created"] = "Flight Radar Plugin: config.txt created.\n";
    gText["debug.config_create_failed"] = "Flight Radar Plugin: config.txt could NOT be created.\n";
    gText["debug.config_load_failed"] = "Flight Radar Plugin: config.txt could not be loaded.\n";
    gText["debug.debug_enabled"] = "Flight Radar Plugin: Debug enabled.\n";
    gText["debug.debug_disabled"] = "Flight Radar Plugin: Debug disabled.\n";
    gText["debug.server_address"] = "Flight Radar Plugin: Server address:\n";
    gText["debug.plugin_loaded"] = "Flight Radar Plugin loaded.\n";
    gText["debug.plugin_stopped"] = "Flight Radar Plugin stopped.\n";
    gText["debug.plugin_disabled"] = "Flight Radar Plugin disabled.\n";
    gText["debug.plugin_enabled"] = "Flight Radar Plugin enabled.\n";
    gText["debug.login_success"] = "Flight Radar Plugin: Login successful.\n";
    gText["debug.token_saved"] = "Flight Radar Plugin: Auth token saved.\n";
    gText["debug.logout_success"] = "Flight Radar Plugin: Logout successful.\n";
    gText["debug.logout_local_error"] = "Flight Radar Plugin: Logged out locally, server response invalid.\n";
    gText["debug.position_failed"] = "Flight Radar Plugin: Position update failed: ";
}


void WriteDefaultLanguageFilesIfMissing()
{
    if (gLanguageDirectory.empty())
    {
        return;
    }

    CreateDirectoryA(
        gLanguageDirectory.c_str(),
        nullptr
    );

    std::string enPath =
        gLanguageDirectory + "\\en.txt";

    std::string dePath =
        gLanguageDirectory + "\\de.txt";

    std::ifstream enCheck(
        enPath.c_str()
    );

    if (!enCheck.good())
    {
        std::ofstream enFile(
            enPath.c_str()
        );

        if (enFile.is_open())
        {
            enFile << "window.login.title=Flight Radar Login\n";
            enFile << "window.flightplan.title=Flightplan\n";
            enFile << "label.username=Username:\n";
            enFile << "label.password=Password:\n";
            enFile << "label.callsign=Callsign:\n";
            enFile << "checkbox.remember_login.off=[ ] Remember login\n";
            enFile << "checkbox.remember_login.on=[X] Remember login\n";
            enFile << "button.connect=Connect\n";
            enFile << "button.logout=Logout\n";
            enFile << "button.send_flightplan=Send Flightplan\n";
            enFile << "button.paste_route=Paste Route\n";
            enFile << "button.clear_route=Clear Route\n";
            enFile << "checkbox.invisible.off=[ ] Invisible\n";
            enFile << "checkbox.invisible.on=[X] Invisible\n";
            enFile << "status.invisible_enabled=Invisible Mode enabled.\n";
            enFile << "status.invisible_disabled=Invisible Mode disabled.\n";
            enFile << "menu.title=Flight Radar Sim Project\n";
            enFile << "menu.login=Open / Close Login Window\n";
            enFile << "menu.flightplan=Open / Close Flightplan Window\n";
            enFile << "status.not_connected=Not connected.\n";
            enFile << "status.connected_as=Connected as\n";
            enFile << "status.logout_sending=Sending logout...\n";
            enFile << "status.logout_success=Logout successful.\n";
            enFile << "status.local_logout_server=Logged out locally. Server: \n";
            enFile << "status.already_connected=You are already connected.\n";
            enFile << "status.login_missing=Please enter username, password and callsign.\n";
            enFile << "status.connecting=Connecting to server...\n";
            enFile << "status.login_success_no_token=Login successful, but no token received.\n";
            enFile << "status.login_failed_log=Flight Radar Plugin: Login failed.\\n\n";
            enFile << "status.login_first=Please login first.\n";
            enFile << "label.flight_rules=Flight Rules:\n";
            enFile << "label.flight_type=Flight Type:\n";
            enFile << "label.departure_time=Departure Time:\n";
            enFile << "label.departure_airport=Departure ICAO:\n";
            enFile << "label.arrival_airport=Arrival ICAO:\n";
            enFile << "label.alternate1_airport=Alternate 1 ICAO:\n";
            enFile << "label.alternate2_airport=Alternate 2 ICAO:\n";
            enFile << "label.route=Route:\n";
            enFile << "label.cruising_level=Cruising Level:\n";
            enFile << "label.cruising_speed=Cruising Speed:\n";
            enFile << "label.remarks=Additional Info:\n";
            enFile << "option.flight_rules.ifr=IFR\n";
            enFile << "option.flight_rules.vfr=VFR\n";
            enFile << "option.flight_rules.ifr_vfr=IFR then VFR\n";
            enFile << "option.flight_rules.vfr_ifr=VFR then IFR\n";
            enFile << "option.flight_type.scheduled=Scheduled Airline\n";
            enFile << "option.flight_type.non_scheduled=Non-Scheduled\n";
            enFile << "option.flight_type.general_aviation=General Aviation\n";
            enFile << "option.flight_type.military=Military\n";
            enFile << "option.flight_type.other=Other\n";
            enFile << "checkbox.close_after_send.off=[ ] Close after send\n";
            enFile << "checkbox.close_after_send.on=[X] Close after send\n";
            enFile << "flightplan.ready=Flightplan ready.\n";
            enFile << "flightplan.sending=Sending flightplan...\n";
            enFile << "flightplan.saved=Flightplan saved.\n";
            enFile << "flightplan.error=Flightplan error: \n";
            enFile << "flightplan.saved_log=Flight Radar Plugin: Flightplan saved.\\n\n";
            enFile << "flightplan.failed_log=Flight Radar Plugin: Flightplan could not be saved.\\n\n";
            enFile << "debug.plugin_path=Flight Radar Plugin: Plugin path detected:\\n\n";
            enFile << "debug.config_created=Flight Radar Plugin: config.txt created.\\n\n";
            enFile << "debug.config_create_failed=Flight Radar Plugin: config.txt could NOT be created.\\n\n";
            enFile << "debug.config_load_failed=Flight Radar Plugin: config.txt could not be loaded.\\n\n";
            enFile << "debug.debug_enabled=Flight Radar Plugin: Debug enabled.\\n\n";
            enFile << "debug.debug_disabled=Flight Radar Plugin: Debug disabled.\\n\n";
            enFile << "debug.server_address=Flight Radar Plugin: Server address:\\n\n";
            enFile << "debug.plugin_loaded=Flight Radar Plugin loaded.\\n\n";
            enFile << "debug.plugin_stopped=Flight Radar Plugin stopped.\\n\n";
            enFile << "debug.plugin_disabled=Flight Radar Plugin disabled.\\n\n";
            enFile << "debug.plugin_enabled=Flight Radar Plugin enabled.\\n\n";
            enFile << "debug.login_success=Flight Radar Plugin: Login successful.\\n\n";
            enFile << "debug.token_saved=Flight Radar Plugin: Auth token saved.\\n\n";
            enFile << "debug.logout_success=Flight Radar Plugin: Logout successful.\\n\n";
            enFile << "debug.logout_local_error=Flight Radar Plugin: Logged out locally, server response invalid.\\n\n";
            enFile << "debug.position_failed=Flight Radar Plugin: Position update failed: \n";
            enFile.close();
        }
    }

    enCheck.close();

    std::ifstream deCheck(
        dePath.c_str()
    );

    if (!deCheck.good())
    {
        std::ofstream deFile(
            dePath.c_str()
        );

        if (deFile.is_open())
        {
            deFile << "window.login.title=Flight Radar Login\n";
            deFile << "window.flightplan.title=Flugplan\n";
            deFile << "label.username=Benutzer:\n";
            deFile << "label.password=Passwort:\n";
            deFile << "label.callsign=Callsign:\n";
            deFile << "checkbox.remember_login.off=[ ] Login speichern\n";
            deFile << "checkbox.remember_login.on=[X] Login speichern\n";
            deFile << "button.connect=Verbinden\n";
            deFile << "button.logout=Logout\n";
            deFile << "button.send_flightplan=Flugplan senden\n";
            deFile << "button.paste_route=Route einfuegen\n";
            deFile << "button.clear_route=Route leeren\n";
            deFile << "checkbox.invisible.off=[ ] Unsichtbar\n";
            deFile << "checkbox.invisible.on=[X] Unsichtbar\n";
            deFile << "status.invisible_enabled=Unsichtbarer Modus aktiviert.\n";
            deFile << "status.invisible_disabled=Unsichtbarer Modus deaktiviert.\n";
            deFile << "menu.title=Flight Radar Sim Project\n";
            deFile << "menu.login=Login-Fenster öffnen / schließen\n";
            deFile << "menu.flightplan=Flugplan-Fenster öffnen / schließen\n";
            deFile << "status.not_connected=Nicht verbunden.\n";
            deFile << "status.connected_as=Verbunden als\n";
            deFile << "status.logout_sending=Logout wird gesendet...\n";
            deFile << "status.logout_success=Logout erfolgreich.\n";
            deFile << "status.local_logout_server=Lokal ausgeloggt. Server: \n";
            deFile << "status.already_connected=Du bist bereits verbunden.\n";
            deFile << "status.login_missing=Bitte Benutzername, Passwort und Callsign eintragen.\n";
            deFile << "status.connecting=Verbinde mit Server...\n";
            deFile << "status.login_success_no_token=Login erfolgreich, aber kein Token erhalten.\n";
            deFile << "status.login_failed_log=Flight Radar Plugin: Login fehlgeschlagen.\\n\n";
            deFile << "status.login_first=Bitte zuerst einloggen.\n";
            deFile << "label.flight_rules=Flugregeln:\n";
            deFile << "label.flight_type=Flugart:\n";
            deFile << "label.departure_time=Abflugzeit:\n";
            deFile << "label.departure_airport=Abflug ICAO:\n";
            deFile << "label.arrival_airport=Ziel ICAO:\n";
            deFile << "label.alternate1_airport=Ausweich 1 ICAO:\n";
            deFile << "label.alternate2_airport=Ausweich 2 ICAO:\n";
            deFile << "label.route=Route:\n";
            deFile << "label.cruising_level=Flugflaeche:\n";
            deFile << "label.cruising_speed=Reisegeschwindigkeit:\n";
            deFile << "label.remarks=Weitere Infos:\n";
            deFile << "option.flight_rules.ifr=IFR\n";
            deFile << "option.flight_rules.vfr=VFR\n";
            deFile << "option.flight_rules.ifr_vfr=IFR dann VFR\n";
            deFile << "option.flight_rules.vfr_ifr=VFR dann IFR\n";
            deFile << "option.flight_type.scheduled=Linienflug\n";
            deFile << "option.flight_type.non_scheduled=Charter / Non-Scheduled\n";
            deFile << "option.flight_type.general_aviation=General Aviation\n";
            deFile << "option.flight_type.military=Militär\n";
            deFile << "option.flight_type.other=Sonstige\n";
            deFile << "checkbox.close_after_send.off=[ ] Nach Senden schließen\n";
            deFile << "checkbox.close_after_send.on=[X] Nach Senden schließen\n";
            deFile << "flightplan.ready=Flugplan bereit.\n";
            deFile << "flightplan.sending=Flugplan wird gesendet...\n";
            deFile << "flightplan.saved=Flugplan gespeichert.\n";
            deFile << "flightplan.error=Flugplan Fehler: \n";
            deFile << "flightplan.saved_log=Flight Radar Plugin: Flugplan gespeichert.\\n\n";
            deFile << "flightplan.failed_log=Flight Radar Plugin: Flugplan konnte nicht gespeichert werden.\\n\n";
            deFile << "debug.plugin_path=Flight Radar Plugin: Plugin Pfad erkannt:\\n\n";
            deFile << "debug.config_created=Flight Radar Plugin: config.txt wurde erstellt.\\n\n";
            deFile << "debug.config_create_failed=Flight Radar Plugin: config.txt konnte NICHT erstellt werden.\\n\n";
            deFile << "debug.config_load_failed=Flight Radar Plugin: config.txt konnte nicht geladen werden.\\n\n";
            deFile << "debug.debug_enabled=Flight Radar Plugin: Debug aktiviert.\\n\n";
            deFile << "debug.debug_disabled=Flight Radar Plugin: Debug deaktiviert.\\n\n";
            deFile << "debug.server_address=Flight Radar Plugin: Server Adresse:\\n\n";
            deFile << "debug.plugin_loaded=Flight Radar Plugin geladen.\\n\n";
            deFile << "debug.plugin_stopped=Flight Radar Plugin gestoppt.\\n\n";
            deFile << "debug.plugin_disabled=Flight Radar Plugin deaktiviert.\\n\n";
            deFile << "debug.plugin_enabled=Flight Radar Plugin aktiviert.\\n\n";
            deFile << "debug.login_success=Flight Radar Plugin: Login erfolgreich.\\n\n";
            deFile << "debug.token_saved=Flight Radar Plugin: Auth Token gespeichert.\\n\n";
            deFile << "debug.logout_success=Flight Radar Plugin: Logout erfolgreich.\\n\n";
            deFile << "debug.logout_local_error=Flight Radar Plugin: Lokal ausgeloggt, Serverantwort fehlerhaft.\\n\n";
            deFile << "debug.position_failed=Flight Radar Plugin: Position Update fehlgeschlagen: \n";
            deFile.close();
        }
    }

    deCheck.close();
}


std::string ReadXPlaneLanguage()
{
    const char* dataRefs[] =
    {
        "sim/operation/prefs/language",
        "sim/operation/prefs/misc/language"
    };

    for (int i = 0; i < 2; i++)
    {
        XPLMDataRef ref =
            XPLMFindDataRef(
                dataRefs[i]
            );

        if (ref == nullptr)
        {
            continue;
        }

        int types =
            XPLMGetDataRefTypes(ref);

        if (types & xplmType_Data)
        {
            char buffer[256] = { 0 };

            XPLMGetDatab(
                ref,
                buffer,
                0,
                sizeof(buffer)
            );

            std::string value =
                ToLowerString(
                    std::string(buffer)
                );

            if (
                value.find("de") != std::string::npos ||
                value.find("german") != std::string::npos ||
                value.find("deutsch") != std::string::npos
                )
            {
                return "de";
            }

            if (
                value.find("en") != std::string::npos ||
                value.find("english") != std::string::npos
                )
            {
                return "en";
            }
        }
    }

    return "en";
}


bool LoadLanguageFile(
    const std::string& languageCode
)
{
    std::string filePath =
        gLanguageDirectory + "\\" + languageCode + ".txt";

    std::ifstream file(
        filePath.c_str()
    );

    if (!file.is_open())
    {
        return false;
    }

    std::string line;

    while (std::getline(file, line))
    {
        line =
            TrimString(line);

        if (line.empty())
        {
            continue;
        }

        if (line[0] == '#')
        {
            continue;
        }

        size_t pos =
            line.find("=");

        if (pos == std::string::npos)
        {
            continue;
        }

        std::string key =
            TrimString(
                line.substr(0, pos)
            );

        std::string value =
            line.substr(pos + 1);

        size_t newlinePos =
            0;

        while (
            (newlinePos = value.find("\\n", newlinePos)) != std::string::npos
            )
        {
            value.replace(
                newlinePos,
                2,
                "\n"
            );

            newlinePos++;
        }

        if (!key.empty())
        {
            gText[key] = value;
        }
    }

    file.close();

    return true;
}


void LoadLanguage()
{
    LoadInternalEnglishLanguage();

    WriteDefaultLanguageFilesIfMissing();

    std::string detectedLanguage =
        ReadXPlaneLanguage();

    if (
        detectedLanguage != "de" &&
        detectedLanguage != "en"
        )
    {
        detectedLanguage = "en";
    }

    gCurrentLanguage =
        detectedLanguage;

    if (!LoadLanguageFile(gCurrentLanguage))
    {
        gCurrentLanguage = "en";

        LoadLanguageFile("en");
    }

    XPLMDebugString("Flight Radar Plugin: Language loaded: ");
    XPLMDebugString(gCurrentLanguage.c_str());
    XPLMDebugString("\n");
}


void InitializePluginPaths()
{
    char pluginPath[1024] = { 0 };

    XPLMGetPluginInfo(
        XPLMGetMyID(),
        nullptr,
        pluginPath,
        nullptr,
        nullptr
    );

    std::string fullPath = pluginPath;

    size_t lastSlash =
        fullPath.find_last_of("\\/");

    if (lastSlash != std::string::npos)
    {
        gPluginDirectory =
            fullPath.substr(0, lastSlash);
    }
    else
    {
        gPluginDirectory = ".";
    }

    gConfigPath =
        gPluginDirectory + "\\config.txt";

    gLanguageDirectory =
        gPluginDirectory + "\\languages";

    XPLMDebugString(T("debug.plugin_path"));
    XPLMDebugString(gPluginDirectory.c_str());
    XPLMDebugString("\n");
}


void CreateDefaultConfigIfMissing()
{
    std::ifstream checkFile(
        gConfigPath.c_str()
    );

    if (checkFile.good())
    {
        checkFile.close();
        return;
    }

    checkFile.close();

    std::ofstream configFile(
        gConfigPath.c_str()
    );

    if (!configFile.is_open())
    {
        XPLMDebugString(T("debug.config_create_failed"));
        return;
    }

    configFile << "# Flight Radar Plugin Config\n";
    configFile << "debug=false\n";

    configFile.close();

    XPLMDebugString(T("debug.config_created"));
}


void LoadConfig()
{
    gDebugEnabled = false;

    CreateDefaultConfigIfMissing();

    std::ifstream configFile(
        gConfigPath.c_str()
    );

    if (!configFile.is_open())
    {
        XPLMDebugString(T("debug.config_load_failed"));
        return;
    }

    std::string line;

    while (std::getline(configFile, line))
    {
        if (line == "debug=true")
        {
            gDebugEnabled = true;
        }
        else if (line == "debug=false")
        {
            gDebugEnabled = false;
        }
    }

    configFile.close();

    if (gDebugEnabled)
    {
        XPLMDebugString(T("debug.debug_enabled"));
    }
    else
    {
        XPLMDebugString(T("debug.debug_disabled"));
    }

    XPLMDebugString(T("debug.server_address"));
    XPLMDebugString(gServerAddress.c_str());
    XPLMDebugString("\n");
}


std::string GetLoginDataPath()
{
    return gPluginDirectory + "\\login.txt";
}


void UpdateRememberLoginButtonCaption()
{
    if (gRememberLoginButton == nullptr)
    {
        return;
    }

    if (gRememberLogin)
    {
        XPSetWidgetDescriptor(
            gRememberLoginButton,
            T("checkbox.remember_login.on")
        );
    }
    else
    {
        XPSetWidgetDescriptor(
            gRememberLoginButton,
            T("checkbox.remember_login.off")
        );
    }
}


void SaveLoginData(
    const std::string& username,
    const std::string& password,
    const std::string& callsign
)
{
    std::ofstream file(
        GetLoginDataPath().c_str()
    );

    if (!file.is_open())
    {
        return;
    }

    /*
        Hinweis:
        Das Passwort wird lokal in login.txt gespeichert.
        Das ist bequem, aber nicht verschluesselt.
        Fuer eine spaetere oeffentliche Version sollte Windows DPAPI genutzt werden.
    */

    file << "remember=true\n";
    file << "username=" << username << "\n";
    file << "password=" << password << "\n";
    file << "callsign=" << callsign << "\n";

    file.close();
}


void DeleteSavedLoginData()
{
    DeleteFileA(
        GetLoginDataPath().c_str()
    );
}


void LoadSavedLoginData()
{
    std::ifstream file(
        GetLoginDataPath().c_str()
    );

    if (!file.is_open())
    {
        gRememberLogin = false;
        UpdateRememberLoginButtonCaption();
        return;
    }

    std::string line;
    std::string username;
    std::string password;
    std::string callsign;

    while (std::getline(file, line))
    {
        line =
            TrimString(line);

        size_t pos =
            line.find("=");

        if (pos == std::string::npos)
        {
            continue;
        }

        std::string key =
            TrimString(
                line.substr(0, pos)
            );

        std::string value =
            line.substr(pos + 1);

        if (key == "remember")
        {
            gRememberLogin =
                (value == "true" || value == "1");
        }
        else if (key == "username")
        {
            username = value;
        }
        else if (key == "password")
        {
            password = value;
        }
        else if (key == "callsign")
        {
            callsign = value;
        }
    }

    file.close();

    if (gRememberLogin)
    {
        if (gUsernameField != nullptr)
        {
            XPSetWidgetDescriptor(
                gUsernameField,
                username.c_str()
            );
        }

        if (gPasswordField != nullptr)
        {
            XPSetWidgetDescriptor(
                gPasswordField,
                password.c_str()
            );
        }

        if (gCallsignField != nullptr)
        {
            XPSetWidgetDescriptor(
                gCallsignField,
                callsign.c_str()
            );
        }
    }

    UpdateRememberLoginButtonCaption();
}


std::wstring StringToWString(const std::string& value)
{
    if (value.empty())
    {
        return std::wstring();
    }

    int sizeNeeded = MultiByteToWideChar(
        CP_UTF8,
        0,
        value.c_str(),
        -1,
        nullptr,
        0
    );

    std::wstring result(sizeNeeded, 0);

    MultiByteToWideChar(
        CP_UTF8,
        0,
        value.c_str(),
        -1,
        &result[0],
        sizeNeeded
    );

    if (!result.empty() && result.back() == L'\0')
    {
        result.pop_back();
    }

    return result;
}


std::string GetClipboardText()
{
    if (!OpenClipboard(nullptr))
    {
        return "";
    }

    HANDLE hData =
        GetClipboardData(CF_TEXT);

    if (hData == nullptr)
    {
        CloseClipboard();
        return "";
    }

    char* textPointer =
        static_cast<char*>(
            GlobalLock(hData)
            );

    if (textPointer == nullptr)
    {
        CloseClipboard();
        return "";
    }

    std::string result =
        textPointer;

    GlobalUnlock(hData);

    CloseClipboard();

    result =
        TrimString(result);

    return result;
}


std::string UrlEncode(const std::string& value)
{
    std::ostringstream escaped;

    for (unsigned char c : value)
    {
        if (
            (c >= 'a' && c <= 'z') ||
            (c >= 'A' && c <= 'Z') ||
            (c >= '0' && c <= '9') ||
            c == '-' ||
            c == '_' ||
            c == '.' ||
            c == '~'
            )
        {
            escaped << c;
        }
        else
        {
            char hex[4];
            sprintf_s(hex, "%%%02X", c);
            escaped << hex;
        }
    }

    return escaped.str();
}


std::string DoubleToString(double value)
{
    char buffer[128];
    sprintf_s(buffer, "%.8f", value);
    return std::string(buffer);
}


std::string FloatToString(float value)
{
    char buffer[128];
    sprintf_s(buffer, "%.4f", value);
    return std::string(buffer);
}


std::string IntToString(int value)
{
    char buffer[64];
    sprintf_s(buffer, "%d", value);
    return std::string(buffer);
}


std::string NormalizeAirportCode(
    const std::string& value
)
{
    std::string result =
        ToUpperString(value);

    if (result.empty())
    {
        return "ZZZZ";
    }

    return result;
}


std::string FormatComFrequency(int value)
{
    if (value <= 0)
    {
        return "0.000";
    }

    /*
        X-Plane kann COM-Frequenzen je nach DataRef/Flugzeug unterschiedlich liefern.

        Beispiele:
        - 122000000  -> echte Hz      -> 122.000 MHz
        - 122000     -> kHz-Wert      -> 122.000 MHz
        - 12200      -> Legacy-Wert   -> 122.000 MHz
        - 12245      -> Legacy-Wert   -> 122.450 MHz

        Wichtig:
        Nicht mehr "khz * 5" verwenden.
        Das war der Grund, warum die Frequenz in der Map nicht zur G1000-Anzeige passte.
    */

    double frequencyMhz = 0.0;

    if (value >= 100000000)
    {
        frequencyMhz =
            (double)value / 1000000.0;
    }
    else if (value >= 100000)
    {
        frequencyMhz =
            (double)value / 1000.0;
    }
    else
    {
        frequencyMhz =
            (double)value / 100.0;
    }

    char buffer[64];

    sprintf_s(
        buffer,
        "%.3f",
        frequencyMhz
    );

    return std::string(buffer);
}


std::string HttpPost(
    const std::string& url,
    const std::string& postData
)
{
    std::wstring wideUrl =
        StringToWString(url);

    URL_COMPONENTS urlComp;
    ZeroMemory(&urlComp, sizeof(urlComp));
    urlComp.dwStructSize =
        sizeof(urlComp);

    wchar_t hostName[256];
    wchar_t urlPath[2048];

    ZeroMemory(hostName, sizeof(hostName));
    ZeroMemory(urlPath, sizeof(urlPath));

    urlComp.lpszHostName =
        hostName;

    urlComp.dwHostNameLength =
        256;

    urlComp.lpszUrlPath =
        urlPath;

    urlComp.dwUrlPathLength =
        2048;

    if (!WinHttpCrackUrl(
        wideUrl.c_str(),
        0,
        0,
        &urlComp
    ))
    {
        return "{\"success\":false,\"message\":\"URL konnte nicht gelesen werden.\"}";
    }

    bool useHttps =
        urlComp.nScheme == INTERNET_SCHEME_HTTPS;

    HINTERNET hSession =
        WinHttpOpen(
            L"FlightRadarPlugin/1.0",
            WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
            WINHTTP_NO_PROXY_NAME,
            WINHTTP_NO_PROXY_BYPASS,
            0
        );

    if (!hSession)
    {
        return "{\"success\":false,\"message\":\"WinHTTP Session Fehler.\"}";
    }

    HINTERNET hConnect =
        WinHttpConnect(
            hSession,
            hostName,
            urlComp.nPort,
            0
        );

    if (!hConnect)
    {
        WinHttpCloseHandle(hSession);
        return "{\"success\":false,\"message\":\"Server Verbindung fehlgeschlagen.\"}";
    }

    DWORD flags =
        useHttps ? WINHTTP_FLAG_SECURE : 0;

    HINTERNET hRequest =
        WinHttpOpenRequest(
            hConnect,
            L"POST",
            urlPath,
            nullptr,
            WINHTTP_NO_REFERER,
            WINHTTP_DEFAULT_ACCEPT_TYPES,
            flags
        );

    if (!hRequest)
    {
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        return "{\"success\":false,\"message\":\"HTTP Request konnte nicht erstellt werden.\"}";
    }

    std::wstring headers =
        L"Content-Type: application/x-www-form-urlencoded\r\n";

    BOOL result =
        WinHttpSendRequest(
            hRequest,
            headers.c_str(),
            (DWORD)-1L,
            (LPVOID)postData.c_str(),
            (DWORD)postData.length(),
            (DWORD)postData.length(),
            0
        );

    if (!result)
    {
        WinHttpCloseHandle(hRequest);
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        return "{\"success\":false,\"message\":\"HTTP Request senden fehlgeschlagen.\"}";
    }

    result =
        WinHttpReceiveResponse(
            hRequest,
            nullptr
        );

    if (!result)
    {
        WinHttpCloseHandle(hRequest);
        WinHttpCloseHandle(hConnect);
        WinHttpCloseHandle(hSession);
        return "{\"success\":false,\"message\":\"Keine Serverantwort erhalten.\"}";
    }

    std::string response;
    DWORD size = 0;

    do
    {
        DWORD downloaded = 0;

        if (!WinHttpQueryDataAvailable(
            hRequest,
            &size
        ))
        {
            break;
        }

        if (size == 0)
        {
            break;
        }

        std::string buffer(size, 0);

        if (!WinHttpReadData(
            hRequest,
            &buffer[0],
            size,
            &downloaded
        ))
        {
            break;
        }

        buffer.resize(downloaded);
        response += buffer;

    } while (size > 0);

    WinHttpCloseHandle(hRequest);
    WinHttpCloseHandle(hConnect);
    WinHttpCloseHandle(hSession);

    if (response.empty())
    {
        return "{\"success\":false,\"message\":\"Leere Serverantwort.\"}";
    }

    return response;
}


bool ResponseIsSuccess(
    const std::string& response
)
{
    if (
        response.find("\"success\":true") != std::string::npos ||
        response.find("\"success\": true") != std::string::npos
        )
    {
        return true;
    }

    return false;
}


std::string ExtractJsonStringValue(
    const std::string& response,
    const std::string& keyName
)
{
    std::string key =
        "\"" + keyName + "\"";

    size_t keyPos =
        response.find(key);

    if (keyPos == std::string::npos)
    {
        return "";
    }

    size_t colonPos =
        response.find(":", keyPos);

    if (colonPos == std::string::npos)
    {
        return "";
    }

    size_t firstQuote =
        response.find("\"", colonPos + 1);

    if (firstQuote == std::string::npos)
    {
        return "";
    }

    size_t secondQuote =
        response.find("\"", firstQuote + 1);

    if (secondQuote == std::string::npos)
    {
        return "";
    }

    return response.substr(
        firstQuote + 1,
        secondQuote - firstQuote - 1
    );
}


std::string ExtractMessageFromResponse(
    const std::string& response
)
{
    std::string message =
        ExtractJsonStringValue(
            response,
            "message"
        );

    if (!message.empty())
    {
        return message;
    }

    return response;
}


std::string GetAircraftICAO()
{
    char aircraftICAO[64] = { 0 };

    XPLMDataRef aircraftRef =
        XPLMFindDataRef(
            "sim/aircraft/view/acf_ICAO"
        );

    if (aircraftRef != nullptr)
    {
        XPLMGetDatab(
            aircraftRef,
            aircraftICAO,
            0,
            sizeof(aircraftICAO)
        );
    }

    return std::string(aircraftICAO);
}


std::string GetWidgetText(
    XPWidgetID widget
)
{
    char buffer[4096] = { 0 };

    XPGetWidgetDescriptor(
        widget,
        buffer,
        sizeof(buffer)
    );

    return std::string(buffer);
}


void UpdateCloseAfterSendButtonCaption()
{
    if (gCloseAfterSendButton == nullptr)
    {
        return;
    }

    if (gCloseFlightplanAfterSend)
    {
        XPSetWidgetDescriptor(
            gCloseAfterSendButton,
            T("checkbox.close_after_send.on")
        );
    }
    else
    {
        XPSetWidgetDescriptor(
            gCloseAfterSendButton,
            T("checkbox.close_after_send.off")
        );
    }
}


std::string GetSelectedFlightRulesCode()
{
    switch (gSelectedFlightRulesIndex)
    {
    case 0:
        return "I";

    case 1:
        return "V";

    case 2:
        return "Y";

    case 3:
        return "Z";

    default:
        return "I";
    }
}


std::string GetSelectedFlightTypeCode()
{
    switch (gSelectedFlightTypeIndex)
    {
    case 0:
        return "S";

    case 1:
        return "N";

    case 2:
        return "G";

    case 3:
        return "M";

    case 4:
        return "X";

    default:
        return "G";
    }
}


std::string GetSelectedFlightRulesCaption()
{
    switch (gSelectedFlightRulesIndex)
    {
    case 0:
        return std::string(T("option.flight_rules.ifr")) + "  v";

    case 1:
        return std::string(T("option.flight_rules.vfr")) + "  v";

    case 2:
        return std::string(T("option.flight_rules.ifr_vfr")) + "  v";

    case 3:
        return std::string(T("option.flight_rules.vfr_ifr")) + "  v";

    default:
        return std::string(T("option.flight_rules.ifr")) + "  v";
    }
}


std::string GetSelectedFlightTypeCaption()
{
    switch (gSelectedFlightTypeIndex)
    {
    case 0:
        return std::string(T("option.flight_type.scheduled")) + "  v";

    case 1:
        return std::string(T("option.flight_type.non_scheduled")) + "  v";

    case 2:
        return std::string(T("option.flight_type.general_aviation")) + "  v";

    case 3:
        return std::string(T("option.flight_type.military")) + "  v";

    case 4:
        return std::string(T("option.flight_type.other")) + "  v";

    default:
        return std::string(T("option.flight_type.general_aviation")) + "  v";
    }
}


void UpdateFlightplanSelectionButtonCaptions()
{
    if (gFlightRulesField != nullptr)
    {
        std::string caption =
            GetSelectedFlightRulesCaption();

        XPSetWidgetDescriptor(
            gFlightRulesField,
            caption.c_str()
        );
    }

    if (gFlightTypeField != nullptr)
    {
        std::string caption =
            GetSelectedFlightTypeCaption();

        XPSetWidgetDescriptor(
            gFlightTypeField,
            caption.c_str()
        );
    }
}


void UpdateInvisibleButtonCaption()
{
    if (gInvisibleButton == nullptr)
    {
        return;
    }

    if (gIsInvisible)
    {
        XPSetWidgetDescriptor(
            gInvisibleButton,
            T("checkbox.invisible.on")
        );
    }
    else
    {
        XPSetWidgetDescriptor(
            gInvisibleButton,
            T("checkbox.invisible.off")
        );
    }
}


void UpdateLoginWindowState()
{
    if (gLoginWindow == nullptr)
    {
        return;
    }

    if (gLoggedIn)
    {
        XPHideWidget(gUsernameLabel);
        XPHideWidget(gPasswordLabel);
        XPHideWidget(gCallsignLabel);

        XPHideWidget(gUsernameField);
        XPHideWidget(gPasswordField);
        XPHideWidget(gCallsignField);
        XPHideWidget(gRememberLoginButton);

        XPHideWidget(gConnectButton);

        XPShowWidget(gLogoutButton);

        if (gCanUseInvisible)
        {
            XPShowWidget(gInvisibleButton);
            UpdateInvisibleButtonCaption();
        }
        else
        {
            XPHideWidget(gInvisibleButton);
        }

        std::string status =
            std::string(T("status.connected_as")) + " " +
            gCurrentCallsign +
            " [" +
            gCurrentUsername +
            "]";

        XPSetWidgetDescriptor(
            gStatusCaption,
            status.c_str()
        );
    }
    else
    {
        XPShowWidget(gUsernameLabel);
        XPShowWidget(gPasswordLabel);
        XPShowWidget(gCallsignLabel);

        XPShowWidget(gUsernameField);
        XPShowWidget(gPasswordField);
        XPShowWidget(gCallsignField);
        XPShowWidget(gRememberLoginButton);

        XPShowWidget(gConnectButton);

        UpdateRememberLoginButtonCaption();

        XPHideWidget(gLogoutButton);
        XPHideWidget(gInvisibleButton);

        XPSetWidgetDescriptor(
            gStatusCaption,
            T("status.not_connected")
        );
    }

    UpdateFlightplanWindowState();
}


void UpdateFlightplanWindowState()
{
    if (gFlightplanWindow == nullptr)
    {
        return;
    }

    if (gLoggedIn)
    {
        XPShowWidget(gFlightRulesLabel);
        XPShowWidget(gFlightTypeLabel);
        XPShowWidget(gDepartureTimeLabel);
        XPShowWidget(gDepartureAirportLabel);
        XPShowWidget(gArrivalAirportLabel);
        XPShowWidget(gAlternate1AirportLabel);
        XPShowWidget(gAlternate2AirportLabel);
        XPShowWidget(gRouteLabel);
        XPShowWidget(gCruisingLevelLabel);
        XPShowWidget(gCruisingSpeedLabel);
        XPShowWidget(gRemarksLabel);

        XPShowWidget(gFlightRulesField);
        XPShowWidget(gFlightTypeField);
        XPShowWidget(gDepartureTimeField);
        XPShowWidget(gDepartureAirportField);
        XPShowWidget(gArrivalAirportField);
        XPShowWidget(gAlternate1AirportField);
        XPShowWidget(gAlternate2AirportField);
        XPShowWidget(gRouteField);
        XPShowWidget(gPasteRouteButton);
        XPShowWidget(gClearRouteButton);
        XPShowWidget(gCruisingLevelField);
        XPShowWidget(gCruisingSpeedField);
        XPShowWidget(gRemarksField);

        XPShowWidget(gCloseAfterSendButton);
        XPShowWidget(gSendFlightplanButton);

        UpdateCloseAfterSendButtonCaption();
        UpdateFlightplanSelectionButtonCaptions();

        XPSetWidgetDescriptor(
            gFlightplanStatusCaption,
            T("flightplan.ready")
        );
    }
    else
    {
        XPHideWidget(gFlightplanWindow);
    }
}


void DoLogout()
{
    if (!gLoggedIn)
    {
        return;
    }

    XPSetWidgetDescriptor(
        gStatusCaption,
        T("status.logout_sending")
    );

    std::string postData =
        "token=" + UrlEncode(gAuthToken);

    std::string response =
        HttpPost(
            gLogoutUrl,
            postData
        );

    if (gDebugEnabled)
    {
        XPLMDebugString("LOGOUT RESPONSE: ");
        XPLMDebugString(response.c_str());
        XPLMDebugString("\n");
    }

    gLoggedIn = false;
    gCurrentUsername = "";
    gCurrentCallsign = "";
    gAuthToken = "";
    gCanUseInvisible = false;
    gIsInvisible = false;

    if (gRememberLogin)
    {
        LoadSavedLoginData();
    }
    else
    {
        XPSetWidgetDescriptor(
            gUsernameField,
            ""
        );

        XPSetWidgetDescriptor(
            gPasswordField,
            ""
        );

        XPSetWidgetDescriptor(
            gCallsignField,
            ""
        );
    }

    if (ResponseIsSuccess(response))
    {
        XPSetWidgetDescriptor(
            gStatusCaption,
            T("status.logout_success")
        );

        XPLMDebugString(
            T("debug.logout_success")
        );
    }
    else
    {
        std::string message =
            ExtractMessageFromResponse(response);

        std::string status =
            std::string(T("status.local_logout_server")) + message;

        XPSetWidgetDescriptor(
            gStatusCaption,
            status.c_str()
        );

        XPLMDebugString(
            T("debug.logout_local_error")
        );
    }

    UpdateLoginWindowState();
    UpdateFlightplanWindowState();
}


void SendPositionUpdate()
{
    if (!gLoggedIn || gAuthToken.empty())
    {
        return;
    }

    double latitude =
        XPLMGetDatad(gLatitude);

    double longitude =
        XPLMGetDatad(gLongitude);

    float altitude =
        XPLMGetDataf(gAltitude);

    float heading =
        XPLMGetDataf(gHeading);

    float airspeed =
        XPLMGetDataf(gAirspeed);

    float pitch =
        XPLMGetDataf(gPitch);

    float roll =
        XPLMGetDataf(gRoll);

    float verticalSpeed =
        XPLMGetDataf(gVerticalSpeed);

    int onGround =
        gOnGround ? XPLMGetDatai(gOnGround) : 0;

    int com1 =
        gCom1 ? XPLMGetDatai(gCom1) : 0;

    int com2 =
        gCom2 ? XPLMGetDatai(gCom2) : 0;

    int com3 =
        gCom3 ? XPLMGetDatai(gCom3) : 0;

    int transponder =
        gTransponder ? XPLMGetDatai(gTransponder) : 0;

    std::string aircraftICAO =
        GetAircraftICAO();

    std::string postData =
        "token=" + UrlEncode(gAuthToken) +
        "&callsign=" + UrlEncode(gCurrentCallsign) +
        "&aircraft_icao=" + UrlEncode(aircraftICAO) +
        "&latitude=" + UrlEncode(DoubleToString(latitude)) +
        "&longitude=" + UrlEncode(DoubleToString(longitude)) +
        "&altitude=" + UrlEncode(FloatToString(altitude)) +
        "&heading=" + UrlEncode(FloatToString(heading)) +
        "&airspeed=" + UrlEncode(FloatToString(airspeed)) +
        "&pitch=" + UrlEncode(FloatToString(pitch)) +
        "&roll=" + UrlEncode(FloatToString(roll)) +
        "&vertical_speed=" + UrlEncode(FloatToString(verticalSpeed)) +
        "&on_ground=" + UrlEncode(IntToString(onGround)) +
        "&com1=" + UrlEncode(FormatComFrequency(com1)) +
        "&com2=" + UrlEncode(FormatComFrequency(com2)) +
        "&com3=" + UrlEncode(FormatComFrequency(com3)) +
        "&transponder=" + UrlEncode(IntToString(transponder));

    std::string response =
        HttpPost(
            gPositionUrl,
            postData
        );

    if (gDebugEnabled)
    {
        XPLMDebugString("POSITION RESPONSE: ");
        XPLMDebugString(response.c_str());
        XPLMDebugString("\n");
    }

    if (!ResponseIsSuccess(response))
    {
        std::string message =
            ExtractMessageFromResponse(response);

        XPLMDebugString(
            T("debug.position_failed")
        );

        XPLMDebugString(
            message.c_str()
        );

        XPLMDebugString("\n");
    }
}


void SendFlightplan()
{
    if (!gLoggedIn || gAuthToken.empty())
    {
        XPSetWidgetDescriptor(
            gFlightplanStatusCaption,
            T("status.login_first")
        );

        return;
    }

    std::string flightRules =
        GetSelectedFlightRulesCode();

    std::string flightType =
        GetSelectedFlightTypeCode();

    std::string departureTime =
        GetWidgetText(gDepartureTimeField);

    std::string departureAirport =
        NormalizeAirportCode(
            GetWidgetText(gDepartureAirportField)
        );

    std::string arrivalAirport =
        NormalizeAirportCode(
            GetWidgetText(gArrivalAirportField)
        );

    std::string alternate1Airport =
        NormalizeAirportCode(
            GetWidgetText(gAlternate1AirportField)
        );

    std::string alternate2Airport =
        NormalizeAirportCode(
            GetWidgetText(gAlternate2AirportField)
        );

    std::string routeText =
        ToUpperString(
            GetWidgetText(gRouteField)
        );

    std::string cruisingLevel =
        ToUpperString(
            GetWidgetText(gCruisingLevelField)
        );

    std::string cruisingSpeed =
        ToUpperString(
            GetWidgetText(gCruisingSpeedField)
        );

    std::string remarks =
        GetWidgetText(gRemarksField);

    XPSetWidgetDescriptor(
        gFlightplanStatusCaption,
        T("flightplan.sending")
    );

    std::string postData =
        "token=" + UrlEncode(gAuthToken) +
        "&callsign=" + UrlEncode(gCurrentCallsign) +
        "&flight_rules=" + UrlEncode(flightRules) +
        "&flight_type=" + UrlEncode(flightType) +
        "&departure_time=" + UrlEncode(departureTime) +
        "&departure_airport=" + UrlEncode(departureAirport) +
        "&arrival_airport=" + UrlEncode(arrivalAirport) +
        "&alternate1_airport=" + UrlEncode(alternate1Airport) +
        "&alternate2_airport=" + UrlEncode(alternate2Airport) +
        "&route_text=" + UrlEncode(routeText) +
        "&cruising_level=" + UrlEncode(cruisingLevel) +
        "&cruising_speed=" + UrlEncode(cruisingSpeed) +
        "&remarks=" + UrlEncode(remarks);

    std::string response =
        HttpPost(
            gFlightplanUrl,
            postData
        );

    if (gDebugEnabled)
    {
        XPLMDebugString("FLIGHTPLAN RESPONSE: ");
        XPLMDebugString(response.c_str());
        XPLMDebugString("\n");
    }

    if (ResponseIsSuccess(response))
    {
        XPSetWidgetDescriptor(
            gFlightplanStatusCaption,
            T("flightplan.saved")
        );

        XPLMDebugString(
            T("flightplan.saved_log")
        );

        if (gCloseFlightplanAfterSend)
        {
            XPHideWidget(
                gFlightplanWindow
            );
        }
    }
    else
    {
        std::string message =
            ExtractMessageFromResponse(response);

        std::string status =
            std::string(T("flightplan.error")) + message;

        XPSetWidgetDescriptor(
            gFlightplanStatusCaption,
            status.c_str()
        );

        XPLMDebugString(
            T("flightplan.failed_log")
        );
    }
}


int LoginWindowHandler(
    XPWidgetMessage inMessage,
    XPWidgetID inWidget,
    intptr_t inParam1,
    intptr_t inParam2
)
{
    if (inMessage == xpMessage_CloseButtonPushed)
    {
        XPHideWidget(gLoginWindow);
        return 1;
    }

    if (inMessage == xpMsg_PushButtonPressed)
    {
        if ((XPWidgetID)inParam1 == gRememberLoginButton)
        {
            gRememberLogin =
                !gRememberLogin;

            UpdateRememberLoginButtonCaption();

            if (!gRememberLogin)
            {
                DeleteSavedLoginData();
            }

            return 1;
        }

        if ((XPWidgetID)inParam1 == gConnectButton)
        {
            if (gLoggedIn)
            {
                XPSetWidgetDescriptor(
                    gStatusCaption,
                    T("status.already_connected")
                );

                UpdateLoginWindowState();

                return 1;
            }

            std::string username =
                GetWidgetText(gUsernameField);

            std::string password =
                GetWidgetText(gPasswordField);

            std::string callsign =
                GetWidgetText(gCallsignField);

            if (
                username.empty() ||
                password.empty() ||
                callsign.empty()
                )
            {
                XPSetWidgetDescriptor(
                    gStatusCaption,
                    T("status.login_missing")
                );

                return 1;
            }

            XPSetWidgetDescriptor(
                gStatusCaption,
                T("status.connecting")
            );

            std::string postData =
                "username=" + UrlEncode(username) +
                "&password=" + UrlEncode(password) +
                "&callsign=" + UrlEncode(callsign);

            std::string response =
                HttpPost(
                    gLoginUrl,
                    postData
                );

            if (gDebugEnabled)
            {
                XPLMDebugString("LOGIN RESPONSE: ");
                XPLMDebugString(response.c_str());
                XPLMDebugString("\n");
            }

            if (ResponseIsSuccess(response))
            {
                if (gRememberLogin)
                {
                    SaveLoginData(
                        username,
                        password,
                        callsign
                    );
                }
                else
                {
                    DeleteSavedLoginData();
                }

                gLoggedIn = true;
                gCurrentUsername = username;
                gCurrentCallsign = callsign;

                gAuthToken =
                    ExtractJsonStringValue(
                        response,
                        "token"
                    );

                gCanUseInvisible =
                    (
                        response.find("\"can_use_invisible\":true")
                        != std::string::npos ||
                        response.find("\"can_use_invisible\": true")
                        != std::string::npos
                        );

                gIsInvisible = false;

                if (gAuthToken.empty())
                {
                    gLoggedIn = false;
                    gCurrentUsername = "";
                    gCurrentCallsign = "";

                    XPSetWidgetDescriptor(
                        gStatusCaption,
                        T("status.login_success_no_token")
                    );

                    return 1;
                }

                XPLMDebugString(
                    T("debug.login_success")
                );

                if (gDebugEnabled)
                {
                    XPLMDebugString(
                        T("debug.token_saved")
                    );
                }

                UpdateLoginWindowState();

                if (gFlightplanWindow != nullptr)
                {
                    XPShowWidget(gFlightplanWindow);

                    XPBringRootWidgetToFront(
                        gFlightplanWindow
                    );

                    UpdateFlightplanWindowState();
                }
            }
            else
            {
                gLoggedIn = false;
                gCurrentUsername = "";
                gCurrentCallsign = "";
                gAuthToken = "";
                gCanUseInvisible = false;
                gIsInvisible = false;

                std::string message =
                    ExtractMessageFromResponse(response);

                XPSetWidgetDescriptor(
                    gStatusCaption,
                    message.c_str()
                );

                XPLMDebugString(
                    T("status.login_failed_log")
                );
            }

            return 1;
        }

        if ((XPWidgetID)inParam1 == gLogoutButton)
        {
            DoLogout();
            return 1;
        }

        if ((XPWidgetID)inParam1 == gInvisibleButton)
        {
            if (!gLoggedIn || gAuthToken.empty())
            {
                return 1;
            }

            bool newInvisibleState =
                !gIsInvisible;

            std::string postData =
                "token=" + UrlEncode(gAuthToken) +
                "&is_invisible=" +
                UrlEncode(
                    newInvisibleState ? "1" : "0"
                );

            std::string response =
                HttpPost(
                    gSetInvisibleUrl,
                    postData
                );

            if (gDebugEnabled)
            {
                XPLMDebugString("INVISIBLE RESPONSE: ");
                XPLMDebugString(response.c_str());
                XPLMDebugString("\n");
            }

            if (ResponseIsSuccess(response))
            {
                gIsInvisible =
                    newInvisibleState;

                UpdateInvisibleButtonCaption();

                XPSetWidgetDescriptor(
                    gStatusCaption,
                    gIsInvisible
                    ? T("status.invisible_enabled")
                    : T("status.invisible_disabled")
                );
            }
            else
            {
                std::string message =
                    ExtractMessageFromResponse(response);

                XPSetWidgetDescriptor(
                    gStatusCaption,
                    message.c_str()
                );
            }

            return 1;
        }
    }

    return 0;
}


int FlightplanWindowHandler(
    XPWidgetMessage inMessage,
    XPWidgetID inWidget,
    intptr_t inParam1,
    intptr_t inParam2
)
{
    if (inMessage == xpMessage_CloseButtonPushed)
    {
        XPHideWidget(gFlightplanWindow);
        return 1;
    }

    if (inMessage == xpMsg_PushButtonPressed)
    {
        if ((XPWidgetID)inParam1 == gPasteRouteButton)
        {
            std::string clipboardText =
                GetClipboardText();

            if (!clipboardText.empty())
            {
                XPSetWidgetDescriptor(
                    gRouteField,
                    clipboardText.c_str()
                );

                XPSetWidgetDescriptor(
                    gFlightplanStatusCaption,
                    T("flightplan.ready")
                );
            }

            return 1;
        }

        if ((XPWidgetID)inParam1 == gClearRouteButton)
        {
            XPSetWidgetDescriptor(
                gRouteField,
                ""
            );

            XPSetWidgetDescriptor(
                gFlightplanStatusCaption,
                T("flightplan.ready")
            );

            return 1;
        }

        if ((XPWidgetID)inParam1 == gSendFlightplanButton)
        {
            SendFlightplan();
            return 1;
        }

        if ((XPWidgetID)inParam1 == gFlightRulesField)
        {
            gSelectedFlightRulesIndex++;

            if (gSelectedFlightRulesIndex > 3)
            {
                gSelectedFlightRulesIndex = 0;
            }

            UpdateFlightplanSelectionButtonCaptions();

            return 1;
        }

        if ((XPWidgetID)inParam1 == gFlightTypeField)
        {
            gSelectedFlightTypeIndex++;

            if (gSelectedFlightTypeIndex > 4)
            {
                gSelectedFlightTypeIndex = 0;
            }

            UpdateFlightplanSelectionButtonCaptions();

            return 1;
        }

        if ((XPWidgetID)inParam1 == gCloseAfterSendButton)
        {
            gCloseFlightplanAfterSend =
                !gCloseFlightplanAfterSend;

            UpdateCloseAfterSendButtonCaption();

            return 1;
        }
    }

    return 0;
}


void CreateLoginWindow()
{
    int left = 100;
    int top = 700;
    int right = 520;
    int bottom = 360;

    gLoginWindow =
        XPCreateWidget(
            left,
            top,
            right,
            bottom,
            1,
            T("window.login.title"),
            1,
            nullptr,
            xpWidgetClass_MainWindow
        );

    XPSetWidgetProperty(
        gLoginWindow,
        xpProperty_MainWindowHasCloseBoxes,
        1
    );

    gUsernameLabel =
        XPCreateWidget(
            left + 30,
            top - 50,
            left + 150,
            top - 70,
            1,
            T("label.username"),
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gUsernameField =
        XPCreateWidget(
            left + 160,
            top - 45,
            right - 30,
            top - 75,
            1,
            "",
            0,
            gLoginWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gUsernameField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gPasswordLabel =
        XPCreateWidget(
            left + 30,
            top - 95,
            left + 150,
            top - 115,
            1,
            T("label.password"),
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gPasswordField =
        XPCreateWidget(
            left + 160,
            top - 90,
            right - 30,
            top - 120,
            1,
            "",
            0,
            gLoginWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gPasswordField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gCallsignLabel =
        XPCreateWidget(
            left + 30,
            top - 140,
            left + 150,
            top - 160,
            1,
            T("label.callsign"),
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gCallsignField =
        XPCreateWidget(
            left + 160,
            top - 135,
            right - 30,
            top - 165,
            1,
            "",
            0,
            gLoginWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gCallsignField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gRememberLoginButton =
        XPCreateWidget(
            left + 160,
            top - 175,
            right - 30,
            top - 205,
            1,
            T("checkbox.remember_login.off"),
            0,
            gLoginWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gRememberLoginButton,
        xpProperty_ButtonType,
        xpPushButton
    );

    gConnectButton =
        XPCreateWidget(
            left + 160,
            top - 230,
            left + 300,
            top - 265,
            1,
            T("button.connect"),
            0,
            gLoginWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gConnectButton,
        xpProperty_ButtonType,
        xpPushButton
    );

    gLogoutButton =
        XPCreateWidget(
            left + 160,
            top - 230,
            left + 300,
            top - 265,
            1,
            T("button.logout"),
            0,
            gLoginWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gLogoutButton,
        xpProperty_ButtonType,
        xpPushButton
    );

    gInvisibleButton =
        XPCreateWidget(
            left + 320,
            top - 230,
            right - 30,
            top - 265,
            1,
            T("checkbox.invisible.off"),
            0,
            gLoginWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gInvisibleButton,
        xpProperty_ButtonType,
        xpPushButton
    );

    gStatusCaption =
        XPCreateWidget(
            left + 30,
            top - 295,
            right - 30,
            top - 320,
            1,
            T("status.not_connected"),
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    XPAddWidgetCallback(
        gLoginWindow,
        LoginWindowHandler
    );

    UpdateLoginWindowState();

    XPHideWidget(gLoginWindow);
}


void CreateFlightplanWindow()
{
    int left = 560;
    int top = 760;
    int right = 1130;
    int bottom = 115;

    gFlightplanWindow =
        XPCreateWidget(
            left,
            top,
            right,
            bottom,
            1,
            T("window.flightplan.title"),
            1,
            nullptr,
            xpWidgetClass_MainWindow
        );

    XPSetWidgetProperty(
        gFlightplanWindow,
        xpProperty_MainWindowHasCloseBoxes,
        1
    );

    gFlightRulesLabel =
        XPCreateWidget(
            left + 30,
            top - 45,
            left + 180,
            top - 65,
            1,
            T("label.flight_rules"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gFlightRulesField =
        XPCreateWidget(
            left + 190,
            top - 40,
            left + 280,
            top - 70,
            1,
            "IFR  v",
            0,
            gFlightplanWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gFlightRulesField,
        xpProperty_ButtonType,
        xpPushButton
    );

    gFlightTypeLabel =
        XPCreateWidget(
            left + 300,
            top - 45,
            left + 430,
            top - 65,
            1,
            T("label.flight_type"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gFlightTypeField =
        XPCreateWidget(
            left + 430,
            top - 40,
            right - 30,
            top - 70,
            1,
            "General Aviation  v",
            0,
            gFlightplanWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gFlightTypeField,
        xpProperty_ButtonType,
        xpPushButton
    );

    gDepartureTimeLabel =
        XPCreateWidget(
            left + 30,
            top - 90,
            left + 180,
            top - 110,
            1,
            T("label.departure_time"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gDepartureTimeField =
        XPCreateWidget(
            left + 190,
            top - 85,
            right - 30,
            top - 115,
            1,
            "",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gDepartureTimeField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gDepartureAirportLabel =
        XPCreateWidget(
            left + 30,
            top - 135,
            left + 180,
            top - 155,
            1,
            T("label.departure_airport"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gDepartureAirportField =
        XPCreateWidget(
            left + 190,
            top - 130,
            left + 280,
            top - 160,
            1,
            "",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gDepartureAirportField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gArrivalAirportLabel =
        XPCreateWidget(
            left + 300,
            top - 135,
            left + 430,
            top - 155,
            1,
            T("label.arrival_airport"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gArrivalAirportField =
        XPCreateWidget(
            left + 430,
            top - 130,
            right - 30,
            top - 160,
            1,
            "ZZZZ",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gArrivalAirportField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gAlternate1AirportLabel =
        XPCreateWidget(
            left + 30,
            top - 180,
            left + 180,
            top - 200,
            1,
            T("label.alternate1_airport"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gAlternate1AirportField =
        XPCreateWidget(
            left + 190,
            top - 175,
            left + 280,
            top - 205,
            1,
            "ZZZZ",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gAlternate1AirportField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gAlternate2AirportLabel =
        XPCreateWidget(
            left + 300,
            top - 180,
            left + 430,
            top - 200,
            1,
            T("label.alternate2_airport"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gAlternate2AirportField =
        XPCreateWidget(
            left + 430,
            top - 175,
            right - 30,
            top - 205,
            1,
            "ZZZZ",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gAlternate2AirportField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gRouteLabel =
        XPCreateWidget(
            left + 30,
            top - 225,
            left + 180,
            top - 245,
            1,
            T("label.route"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gRouteField =
        XPCreateWidget(
            left + 190,
            top - 220,
            right - 30,
            top - 295,
            1,
            "",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gRouteField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gPasteRouteButton =
        XPCreateWidget(
            left + 30,
            top - 255,
            left + 180,
            top - 285,
            1,
            T("button.paste_route"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gPasteRouteButton,
        xpProperty_ButtonType,
        xpPushButton
    );

    gClearRouteButton =
        XPCreateWidget(
            left + 30,
            top - 290,
            left + 180,
            top - 320,
            1,
            T("button.clear_route"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gClearRouteButton,
        xpProperty_ButtonType,
        xpPushButton
    ); 

    XPSetWidgetProperty(
        gPasteRouteButton,
        xpProperty_ButtonType,
        xpPushButton
    );

    gCruisingLevelLabel =
        XPCreateWidget(
            left + 30,
            top - 320,
            left + 180,
            top - 340,
            1,
            T("label.cruising_level"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gCruisingLevelField =
        XPCreateWidget(
            left + 190,
            top - 315,
            left + 280,
            top - 345,
            1,
            "FL350",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gCruisingLevelField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gCruisingSpeedLabel =
        XPCreateWidget(
            left + 300,
            top - 320,
            left + 430,
            top - 340,
            1,
            T("label.cruising_speed"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gCruisingSpeedField =
        XPCreateWidget(
            left + 430,
            top - 315,
            right - 30,
            top - 345,
            1,
            "",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gCruisingSpeedField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gRemarksLabel =
        XPCreateWidget(
            left + 30,
            top - 370,
            left + 180,
            top - 390,
            1,
            T("label.remarks"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    gRemarksField =
        XPCreateWidget(
            left + 190,
            top - 365,
            right - 30,
            top - 435,
            1,
            "",
            0,
            gFlightplanWindow,
            xpWidgetClass_TextField
        );

    XPSetWidgetProperty(
        gRemarksField,
        xpProperty_TextFieldType,
        xpTextEntryField
    );

    gCloseAfterSendButton =
        XPCreateWidget(
            left + 190,
            top - 455,
            left + 430,
            top - 485,
            1,
            T("checkbox.close_after_send.off"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gCloseAfterSendButton,
        xpProperty_ButtonType,
        xpPushButton
    );

    gSendFlightplanButton =
        XPCreateWidget(
            left + 190,
            top - 500,
            left + 360,
            top - 535,
            1,
            T("button.send_flightplan"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Button
        );

    XPSetWidgetProperty(
        gSendFlightplanButton,
        xpProperty_ButtonType,
        xpPushButton
    );

    gFlightplanStatusCaption =
        XPCreateWidget(
            left + 30,
            top - 565,
            right - 30,
            top - 590,
            1,
            T("status.login_first"),
            0,
            gFlightplanWindow,
            xpWidgetClass_Caption
        );

    UpdateFlightplanSelectionButtonCaptions();

    XPAddWidgetCallback(
        gFlightplanWindow,
        FlightplanWindowHandler
    );

    XPHideWidget(gFlightplanWindow);
}


void MenuHandler(
    void* inMenuRef,
    void* inItemRef
)
{
    intptr_t item =
        (intptr_t)inItemRef;

    if (item == 1)
    {
        if (gLoginWindow == nullptr)
        {
            return;
        }

        if (XPIsWidgetVisible(gLoginWindow))
        {
            XPHideWidget(gLoginWindow);
        }
        else
        {
            XPShowWidget(gLoginWindow);

            XPBringRootWidgetToFront(
                gLoginWindow
            );

            UpdateLoginWindowState();
        }

        return;
    }

    if (item == 2)
    {
        if (gFlightplanWindow == nullptr)
        {
            return;
        }

        if (!gLoggedIn)
        {
            XPShowWidget(gLoginWindow);

            XPBringRootWidgetToFront(
                gLoginWindow
            );

            XPSetWidgetDescriptor(
                gStatusCaption,
                T("status.login_first")
            );

            return;
        }

        if (XPIsWidgetVisible(gFlightplanWindow))
        {
            XPHideWidget(gFlightplanWindow);
        }
        else
        {
            XPShowWidget(gFlightplanWindow);

            XPBringRootWidgetToFront(
                gFlightplanWindow
            );

            UpdateFlightplanWindowState();
        }

        return;
    }
}


void CreatePluginMenu()
{
    int pluginMenuIndex =
        XPLMAppendMenuItem(
            XPLMFindPluginsMenu(),
            T("menu.title"),
            nullptr,
            1
        );

    gMenuId =
        XPLMCreateMenu(
            T("menu.title"),
            XPLMFindPluginsMenu(),
            pluginMenuIndex,
            MenuHandler,
            nullptr
        );

    gLoginMenuItem =
        XPLMAppendMenuItem(
            gMenuId,
            T("menu.login"),
            (void*)1,
            1
        );

    gFlightplanMenuItem =
        XPLMAppendMenuItem(
            gMenuId,
            T("menu.flightplan"),
            (void*)2,
            1
        );
}


float FlightLoopCallback(
    float inElapsedSinceLastCall,
    float inElapsedTimeSinceLastFlightLoop,
    int inCounter,
    void* inRefcon
)
{
    double latitude =
        XPLMGetDatad(gLatitude);

    double longitude =
        XPLMGetDatad(gLongitude);

    float altitude =
        XPLMGetDataf(gAltitude);

    float heading =
        XPLMGetDataf(gHeading);

    float airspeed =
        XPLMGetDataf(gAirspeed);

    float pitch =
        XPLMGetDataf(gPitch);

    float roll =
        XPLMGetDataf(gRoll);

    float verticalSpeed =
        XPLMGetDataf(gVerticalSpeed);

    int com1 =
        gCom1 ? XPLMGetDatai(gCom1) : 0;

    int com2 =
        gCom2 ? XPLMGetDatai(gCom2) : 0;

    int com3 =
        gCom3 ? XPLMGetDatai(gCom3) : 0;

    int transponder =
        gTransponder ? XPLMGetDatai(gTransponder) : 0;

    std::string aircraftICAO =
        GetAircraftICAO();

    if (gDebugEnabled)
    {
        char buffer[1200];

        sprintf_s(
            buffer,
            "FLIGHT DATA | LOGGED_IN: %d | USER: %s | CALLSIGN: %s | TOKEN_SET: %d | ICAO: %s | LAT: %.6f | LON: %.6f | ALT: %.2f | HDG: %.2f | SPEED: %.2f | XPDR: %04d | COM1: %s | COM2: %s | COM3: %s | PITCH: %.2f | ROLL: %.2f | VS: %.2f\n",
            gLoggedIn ? 1 : 0,
            gCurrentUsername.c_str(),
            gCurrentCallsign.c_str(),
            gAuthToken.empty() ? 0 : 1,
            aircraftICAO.c_str(),
            latitude,
            longitude,
            altitude,
            heading,
            airspeed,
            transponder,
            FormatComFrequency(com1).c_str(),
            FormatComFrequency(com2).c_str(),
            FormatComFrequency(com3).c_str(),
            pitch,
            roll,
            verticalSpeed
        );

        XPLMDebugString(buffer);
    }

    SendPositionUpdate();

    return 1.0f;
}


PLUGIN_API int XPluginStart(
    char* outName,
    char* outSig,
    char* outDesc
)
{
    strcpy_s(
        outName,
        256,
        "Flight Radar Plugin"
    );

    strcpy_s(
        outSig,
        256,
        "toni.flightradar.plugin"
    );

    strcpy_s(
        outDesc,
        256,
        "Reads flight data from X-Plane."
    );

    LoadInternalEnglishLanguage();

    XPLMDebugString(
        T("debug.plugin_loaded")
    );

    InitializePluginPaths();

    LoadConfig();

    LoadLanguage();

    CreateLoginWindow();

    LoadSavedLoginData();

    CreateFlightplanWindow();

    CreatePluginMenu();

    gLatitude =
        XPLMFindDataRef(
            "sim/flightmodel/position/latitude"
        );

    gLongitude =
        XPLMFindDataRef(
            "sim/flightmodel/position/longitude"
        );

    gAltitude =
        XPLMFindDataRef(
            "sim/flightmodel/position/elevation"
        );

    gHeading =
        XPLMFindDataRef(
            "sim/flightmodel/position/psi"
        );

    gAirspeed =
        XPLMFindDataRef(
            "sim/flightmodel/position/indicated_airspeed"
        );

    gPitch =
        XPLMFindDataRef(
            "sim/flightmodel/position/theta"
        );

    gRoll =
        XPLMFindDataRef(
            "sim/flightmodel/position/phi"
        );

    gVerticalSpeed =
        XPLMFindDataRef(
            "sim/flightmodel/position/vh_ind_fpm"
        );

    gOnGround =
        XPLMFindDataRef(
            "sim/flightmodel/failures/onground_any"
        );

    /*
        Moderne cockpit2-DataRefs verwenden.
        Diese passen bei G1000-Flugzeugen besser zur tatsächlich sichtbaren aktiven Frequenz.
        Falls ein älteres Flugzeug die cockpit2-DataRefs nicht liefert, fallen wir auf die alten DataRefs zurück.
    */

    gCom1 =
        XPLMFindDataRef(
            "sim/cockpit2/radios/actuators/com1_frequency_hz"
        );

    if (gCom1 == nullptr)
    {
        gCom1 =
            XPLMFindDataRef(
                "sim/cockpit/radios/com1_freq_hz"
            );
    }

    gCom2 =
        XPLMFindDataRef(
            "sim/cockpit2/radios/actuators/com2_frequency_hz"
        );

    if (gCom2 == nullptr)
    {
        gCom2 =
            XPLMFindDataRef(
                "sim/cockpit/radios/com2_freq_hz"
            );
    }

    gCom3 =
        XPLMFindDataRef(
            "sim/cockpit2/radios/actuators/com3_frequency_hz"
        );

    if (gCom3 == nullptr)
    {
        gCom3 =
            XPLMFindDataRef(
                "sim/cockpit/radios/com3_freq_hz"
            );
    }

    gTransponder =
        XPLMFindDataRef(
            "sim/cockpit/radios/transponder_code"
        );

    XPLMRegisterFlightLoopCallback(
        FlightLoopCallback,
        1.0f,
        nullptr
    );

    return 1;
}


PLUGIN_API void XPluginStop(void)
{
    XPLMUnregisterFlightLoopCallback(
        FlightLoopCallback,
        nullptr
    );

    if (gLoggedIn && !gAuthToken.empty())
    {
        std::string postData =
            "token=" + UrlEncode(gAuthToken);

        HttpPost(
            gLogoutUrl,
            postData
        );

        gLoggedIn = false;
        gCurrentUsername = "";
        gCurrentCallsign = "";
        gAuthToken = "";
    }

    if (gMenuId != nullptr)
    {
        XPLMDestroyMenu(gMenuId);
        gMenuId = nullptr;
    }

    if (gFlightplanWindow != nullptr)
    {
        XPDestroyWidget(
            gFlightplanWindow,
            1
        );

        gFlightplanWindow = nullptr;
    }

    if (gLoginWindow != nullptr)
    {
        XPDestroyWidget(
            gLoginWindow,
            1
        );

        gLoginWindow = nullptr;
    }

    XPLMDebugString(
        T("debug.plugin_stopped")
    );
}


PLUGIN_API void XPluginDisable(void)
{
    XPLMDebugString(
        T("debug.plugin_disabled")
    );
}


PLUGIN_API int XPluginEnable(void)
{
    XPLMDebugString(
        T("debug.plugin_enabled")
    );

    return 1;
}


PLUGIN_API void XPluginReceiveMessage(
    XPLMPluginID inFromWho,
    int inMessage,
    void* inParam
)
{
}
