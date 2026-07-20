#include "pch.h"

#define IBM 1
#define XPLM200 1
#define XPLM210 1
#define XPLM300 1
#define XPLM301 1

#include "XPLMPlugin.h"
#include "XPLMUtilities.h"
#include "XPLMDataAccess.h"
#include "XPLMProcessing.h"
#include "XPLMMenus.h"
#include "XPLMDisplay.h"
#include "XPLMGraphics.h"

#include "XPWidgets.h"
#include "XPStandardWidgets.h"
#include "XPWidgetUtils.h"

#include <algorithm>
#include <cctype>
#include <cmath>
#include <cstdio>
#include <ctime>
#include <fstream>
#include <string>
#include <sstream>
#include <map>
#include <vector>
#include <atomic>
#include <mutex>
#include <thread>
#include <windows.h>
#include <winhttp.h>
#include <gl/GL.h>

#pragma comment(lib, "winhttp.lib")
#pragma comment(lib, "opengl32.lib")

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

static const std::string gPilotsUrl =
gServerAddress + "/execute/get_pilots.php";

static const std::string gChatSendUrl =
gServerAddress + "/execute/chat_send.php";

static const std::string gChatPollUrl =
gServerAddress + "/execute/chat_poll.php";

static bool gLoggedIn = false;

static std::string gCurrentUsername = "";
static std::string gCurrentCallsign = "";
static std::string gAuthToken = "";

static bool gCanUseInvisible = false;
static bool gIsInvisible = false;

static bool gCloseFlightplanAfterSend = false;
static int gPositionUpdateFailureCount = 0;
static float gPositionUpdateFirstFailureTime = -1.0f;
static std::atomic<bool> gPositionUpdateInProgress(false);
static std::atomic<bool> gPositionUpdateResultReady(false);
static std::atomic<bool> gPositionUpdateLastSuccess(true);
static std::mutex gPositionUpdateResultMutex;
static std::string gPositionUpdateLastResponse = "";
static std::thread gPositionUpdateThread;

static const int gHttpResolveTimeoutMs = 1500;
static const int gHttpConnectTimeoutMs = 1500;
static const int gHttpSendTimeoutMs = 1500;
static const int gHttpReceiveTimeoutMs = 5000;
static const int gMaxPositionUpdateFailures = 20;
static const float gMinPositionUpdateFailureSeconds = 60.0f;

static int gSelectedFlightRulesIndex = 0;
static int gSelectedFlightTypeIndex = 2;

static XPLMMenuID gMenuId = nullptr;
static int gLoginMenuItem = 0;
static int gFlightplanMenuItem = 0;

static XPWidgetID gLoginWindow = nullptr;
static XPLMWindowID gCustomLoginWindow = nullptr;
static XPLMWindowID gCompactWindow = nullptr;
static XPLMWindowID gLogoutConfirmWindow = nullptr;
static bool gCustomLoginDragging = false;
static bool gCustomLoginPoppedOut = false;
static int gCustomLoginDragOffsetX = 0;
static int gCustomLoginDragOffsetY = 0;
static bool gCompactWindowDragging = false;
static int gCompactWindowDragOffsetX = 0;
static int gCompactWindowDragOffsetY = 0;
static bool gWindowsChatMouseDown = false;
static char gLastCompactKey = 0;
static char gLastCompactVirtualKey = 0;
static float gLastCompactKeyTime = -1.0f;
static bool gWindowsChatKeyDown[256] = {};
static char gLastLoginKey = 0;
static char gLastLoginVirtualKey = 0;
static float gLastLoginKeyTime = -1.0f;

static XPWidgetID gUsernameLabel = nullptr;
static XPWidgetID gPasswordLabel = nullptr;
static XPWidgetID gCallsignLabel = nullptr;
static XPWidgetID gLoginBrandLabel = nullptr;
static XPWidgetID gLoginSubtitleLabel = nullptr;
static XPWidgetID gLoginSectionLabel = nullptr;
static XPWidgetID gLoginNetworkLabel = nullptr;
static XPWidgetID gLoginPilotsLabel = nullptr;
static XPWidgetID gLoginAtcLabel = nullptr;

static XPWidgetID gUsernameField = nullptr;
static XPWidgetID gPasswordField = nullptr;
static XPWidgetID gCallsignField = nullptr;

static XPWidgetID gRememberLoginButton = nullptr;
static bool gRememberLogin = false;

static XPWidgetID gStatusCaption = nullptr;

static XPWidgetID gConnectButton = nullptr;
static XPWidgetID gLogoutButton = nullptr;
static XPWidgetID gInvisibleButton = nullptr;

static std::string gLoginUsernameText = "";
static std::string gLoginPasswordText = "";
static std::string gLoginCallsignText = "";
static std::string gCustomLoginStatusText = "";
static int gNetworkPilotsOnline = -1;
static int gNetworkAtcOnline = 0;
static float gNetworkStatusRefreshElapsed = 999.0f;
static std::atomic<bool> gNetworkStatusUpdateInProgress(false);
static std::atomic<bool> gNetworkStatusUpdateResultReady(false);
static std::mutex gNetworkStatusResultMutex;
static std::string gNetworkStatusLastResponse = "";
static std::thread gNetworkStatusThread;

struct ChatLine
{
    int id;
    std::string frequency;
    std::string timestamp;
    std::string sender;
    std::string type;
    std::string text;
};

static std::vector<ChatLine> gChatLines;
static std::string gChatInputText = "";
static bool gChatInputFocused = false;
static int gChatScrollOffset = 0;
static bool gChatSendButtonPressed = false;
static int gLastChatMessageId = 0;
static float gChatPollElapsed = 999.0f;
static std::atomic<bool> gChatPollInProgress(false);
static std::atomic<bool> gChatPollResultReady(false);
static std::mutex gChatPollResultMutex;
static std::string gChatPollLastResponse = "";
static std::thread gChatPollThread;
static std::atomic<bool> gChatSendInProgress(false);
static std::atomic<bool> gChatSendResultReady(false);
static std::mutex gChatSendResultMutex;
static std::string gChatSendLastResponse = "";
static std::thread gChatSendThread;
static std::string gCurrentPilotRatingCode = "FC0";
static std::string gCurrentPilotRatingName = "New Flight Cadet";
static std::string gCurrentAtcRatingCode = "TC0";
static std::string gCurrentAtcRatingName = "New ATC Cadet";

enum CustomLoginField
{
    CustomLoginFieldNone = 0,
    CustomLoginFieldUsername,
    CustomLoginFieldPassword,
    CustomLoginFieldCallsign
};

static CustomLoginField gCustomLoginFocusedField =
    CustomLoginFieldNone;

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

void UpdateLoginNetworkLabels();

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
static XPLMDataRef gTransponderMode = nullptr;
static XPLMCommandRef gTransponderIdentCommand = nullptr;
static float gTransponderIdentUntil = -1.0f;

static XPLMDataRef gOnGround = nullptr;

static XPLMDataRef gHasCrashedRef = nullptr;
static XPLMDataRef gFuelTotal = nullptr;
static XPLMDataRef gFuelCapacity = nullptr;
static XPLMDataRef gSunPitchDegrees = nullptr;
static XPLMDataRef gPausedRef = nullptr;
static XPLMDataRef gReplayModeRef = nullptr;

static bool gNightFlightActive = false;
static int gNightFlightSeconds = 0;
static int gTotalFlightSeconds = 0;
static double gNightFlightSecondAccumulator = 0.0;


void UpdateFlightplanWindowState();
void SetCustomLoginStatus(
    const std::string& value
);
bool HandleChatKeyInput(
    char inKey,
    XPLMKeyFlags inFlags,
    char inVirtualKey
);
int ChatKeySniffer(
    char inChar,
    XPLMKeyFlags inFlags,
    char inVirtualKey,
    void* inRefcon
);
std::string GetLocalizedChatText(
    const ChatLine& line
);

struct CustomRect
{
    int left;
    int top;
    int right;
    int bottom;
};

bool PointInRect(
    int x,
    int y,
    const CustomRect& rect
)
{
    return
        x >= rect.left &&
        x <= rect.right &&
        y <= rect.top &&
        y >= rect.bottom;
}


bool PointInWindowRect(
    int x,
    int y,
    const CustomRect& rect,
    int windowLeft,
    int windowTop,
    int windowBottom
)
{
    if (PointInRect(x, y, rect))
    {
        return true;
    }

    int localLeft =
        rect.left - windowLeft;
    int localRight =
        rect.right - windowLeft;
    int localBottomFromBottom =
        rect.bottom - windowBottom;
    int localTopFromBottom =
        rect.top - windowBottom;

    if (
        x >= localLeft &&
        x <= localRight &&
        y >= localBottomFromBottom &&
        y <= localTopFromBottom
    ) {
        return true;
    }

    int localTopFromTop =
        windowTop - rect.top;
    int localBottomFromTop =
        windowTop - rect.bottom;

    return
        x >= localLeft &&
        x <= localRight &&
        y >= localTopFromTop &&
        y <= localBottomFromTop;
}

void DrawFilledRect(
    const CustomRect& rect,
    float red,
    float green,
    float blue,
    float alpha
)
{
    glDisable(GL_TEXTURE_2D);
    if (alpha >= 1.0f)
    {
        glDisable(GL_BLEND);
    }
    else
    {
        glEnable(GL_BLEND);
        glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
    }

    glColor4f(red, green, blue, alpha);

    glBegin(GL_QUADS);
    glVertex2i(rect.left, rect.bottom);
    glVertex2i(rect.right, rect.bottom);
    glVertex2i(rect.right, rect.top);
    glVertex2i(rect.left, rect.top);
    glEnd();
}

void DrawRectOutline(
    const CustomRect& rect,
    float red,
    float green,
    float blue,
    float alpha
)
{
    glDisable(GL_TEXTURE_2D);
    glEnable(GL_BLEND);
    glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
    glColor4f(red, green, blue, alpha);
    glLineWidth(1.4f);

    glBegin(GL_LINE_LOOP);
    glVertex2i(rect.left, rect.bottom);
    glVertex2i(rect.right, rect.bottom);
    glVertex2i(rect.right, rect.top);
    glVertex2i(rect.left, rect.top);
    glEnd();
}

void DrawLine(
    int x1,
    int y1,
    int x2,
    int y2,
    float red,
    float green,
    float blue,
    float alpha
)
{
    glDisable(GL_TEXTURE_2D);
    glEnable(GL_BLEND);
    glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
    glColor4f(red, green, blue, alpha);
    glLineWidth(1.2f);

    glBegin(GL_LINES);
    glVertex2i(x1, y1);
    glVertex2i(x2, y2);
    glEnd();
}


void DrawCircleOutline(
    int centerX,
    int centerY,
    int radius,
    float red,
    float green,
    float blue,
    float alpha
)
{
    const float pi =
        3.1415926535f;

    glDisable(GL_TEXTURE_2D);
    glEnable(GL_BLEND);
    glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
    glColor4f(red, green, blue, alpha);
    glLineWidth(1.8f);

    glBegin(GL_LINE_LOOP);

    for (int i = 0; i < 40; i++)
    {
        float angle =
            (2.0f * pi * static_cast<float>(i)) / 40.0f;

        glVertex2i(
            centerX + static_cast<int>(std::cos(angle) * radius),
            centerY + static_cast<int>(std::sin(angle) * radius)
        );
    }

    glEnd();
}

void DrawText(
    int x,
    int y,
    const std::string& text,
    float red,
    float green,
    float blue
)
{
    float color[] =
    {
        red,
        green,
        blue
    };

    XPLMDrawString(
        color,
        x,
        y,
        text.c_str(),
        nullptr,
        xplmFont_Basic
    );
}

std::string MaskPassword(
    const std::string& password
)
{
    return std::string(
        password.size(),
        '*'
    );
}

std::string TruncateForField(
    const std::string& value,
    size_t maxLength
)
{
    if (value.size() <= maxLength)
    {
        return value;
    }

    return value.substr(
        value.size() - maxLength
    );
}


size_t EstimateTextCharsForWidth(
    int widthPixels
)
{
    if (widthPixels <= 0)
    {
        return 1;
    }

    return (std::max)(
        (size_t)1,
        (size_t)(widthPixels / 7)
    );
}


std::string TruncateForWidthFromStart(
    const std::string& value,
    int widthPixels
)
{
    size_t maxLength =
        EstimateTextCharsForWidth(widthPixels);

    if (value.size() <= maxLength)
    {
        return value;
    }

    if (maxLength <= 3)
    {
        return value.substr(0, maxLength);
    }

    return value.substr(0, maxLength - 3) + "...";
}


std::string TruncateForWidthFromEnd(
    const std::string& value,
    int widthPixels
)
{
    size_t maxLength =
        EstimateTextCharsForWidth(widthPixels);

    if (value.size() <= maxLength)
    {
        return value;
    }

    return value.substr(
        value.size() - maxLength
    );
}


std::string GetCurrentTimeHHmm()
{
    std::time_t now =
        std::time(nullptr);

    std::tm localTime = {};

    localtime_s(
        &localTime,
        &now
    );

    char buffer[6] = {};

    std::strftime(
        buffer,
        sizeof(buffer),
        "%H:%M",
        &localTime
    );

    return std::string(buffer);
}


std::vector<std::string> WrapTextForWidth(
    const std::string& value,
    int widthPixels
)
{
    size_t maxLength =
        EstimateTextCharsForWidth(widthPixels);

    std::vector<std::string> rows;

    if (value.empty())
    {
        rows.push_back("");
        return rows;
    }

    size_t position =
        0;

    while (position < value.size())
    {
        while (
            position < value.size() &&
            value[position] == ' '
        ) {
            position++;
        }

        if (position >= value.size())
        {
            break;
        }

        size_t remaining =
            value.size() - position;

        if (remaining <= maxLength)
        {
            rows.push_back(
                value.substr(position)
            );

            break;
        }

        size_t breakPosition =
            value.rfind(
                ' ',
                position + maxLength
            );

        if (
            breakPosition == std::string::npos ||
            breakPosition <= position
        ) {
            breakPosition =
                position + maxLength;
        }

        rows.push_back(
            value.substr(
                position,
                breakPosition - position
            )
        );

        position =
            breakPosition;
    }

    if (rows.empty())
    {
        rows.push_back("");
    }

    return rows;
}


int CountWrappedChatRows(
    const CustomRect& chatRect
)
{
    int messageTextLeft =
        chatRect.left + 82;
    int messageTextWidth =
        chatRect.right - messageTextLeft - 20;
    int rowCount =
        0;

    for (const ChatLine& line : gChatLines)
    {
        rowCount +=
            (int)WrapTextForWidth(
                GetLocalizedChatText(line),
                messageTextWidth
            ).size();
    }

    return rowCount;
}


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
    gText["button.send"] = "Send";
    gText["button.send_flightplan"] = "Send Flightplan";
    gText["button.paste_route"] = "Paste Route";
    gText["button.clear_route"] = "Clear Route";

    gText["checkbox.invisible.off"] = "[ ] Invisible";
    gText["checkbox.invisible.on"] = "[X] Invisible";
    gText["status.invisible_enabled"] = "Invisible Mode enabled.";
    gText["status.invisible_disabled"] = "Invisible Mode disabled.";

    gText["menu.title"] = "Flight Radar Sim Project";
    gText["menu.login"] = "Open / Close Main Window";
    gText["menu.main"] = "Open / Close Main Window";
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
    gText["status.connection_lost_auto_logout"] = "Connection lost. Logged out locally.";
    gText["chat.connected"] = "Connected to VFN Network.";
    gText["chat.rank_status"] = "Pilot Rank: {pilot} / ATC Rank: {atc}";
    gText["chat.ready"] = "Ready for network operations.";

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

    gText["chat.award_unlocked"] = "Award unlocked";
    gText["award_first_flight"] = "First Flight";
    gText["award_first_landing"] = "First Landing";
    gText["award_crash_pilot"] = "Crash Pilot";
    gText["award_hard_landing"] = "Hard Landing";
    gText["award_butter_landing"] = "Butter Landing";
    gText["award_fuel_gambler"] = "Fuel Gambler";
    gText["award_world_traveler"] = "World Traveler";
    gText["award_global_explorer"] = "Global Explorer";
    gText["award_international_ace"] = "International Ace";
    gText["award_globe_master"] = "Globe Master";
    gText["award_night_owl"] = "Night Owl";
    gText["award_moon_walker"] = "Moon Walker";
    gText["award_master_of_night"] = "Master of Night";
    gText["award_founder_home"] = "Founder's House";

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


void ApplyInternalGermanLanguageFallbacks()
{
    gText["chat.connected"] = "Mit dem VFN Netzwerk verbunden.";
    gText["chat.rank_status"] = "Pilotenrang: {pilot} / ATC-Rang: {atc}";
    gText["chat.ready"] = "Bereit fuer den Netzwerkbetrieb.";
    gText["button.send"] = "Senden";
    gText["chat.award_unlocked"] = "Award freigeschaltet";
    gText["award_first_flight"] = "Erster Flug";
    gText["award_first_landing"] = "Erste Landung";
    gText["award_crash_pilot"] = "Crash Pilot";
    gText["award_hard_landing"] = "Harte Landung";
    gText["award_butter_landing"] = "Butterweiche Landung";
    gText["award_fuel_gambler"] = "Fuel Gambler";
    gText["award_world_traveler"] = "World Traveler";
    gText["award_global_explorer"] = "Global Explorer";
    gText["award_international_ace"] = "International Ace";
    gText["award_globe_master"] = "Globe Master";
    gText["award_night_owl"] = "Night Owl";
    gText["award_moon_walker"] = "Moon Walker";
    gText["award_master_of_night"] = "Master of Night";
    gText["award_founder_home"] = "Haus des Gruenders";
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
            enFile << "button.send=Send\n";
            enFile << "button.send_flightplan=Send Flightplan\n";
            enFile << "button.paste_route=Paste Route\n";
            enFile << "button.clear_route=Clear Route\n";
            enFile << "checkbox.invisible.off=[ ] Invisible\n";
            enFile << "checkbox.invisible.on=[X] Invisible\n";
            enFile << "status.invisible_enabled=Invisible Mode enabled.\n";
            enFile << "status.invisible_disabled=Invisible Mode disabled.\n";
            enFile << "menu.title=Flight Radar Sim Project\n";
            enFile << "menu.login=Open / Close Main Window\n";
            enFile << "menu.main=Open / Close Main Window\n";
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
            enFile << "status.connection_lost_auto_logout=Connection lost. Logged out locally.\n";
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
            enFile << "chat.connected=Connected to VFN Network.\n";
            enFile << "chat.rank_status=Pilot Rank: {pilot} / ATC Rank: {atc}\n";
            enFile << "chat.ready=Ready for network operations.\n";
            enFile << "chat.award_unlocked=Award unlocked\n";
            enFile << "award_first_flight=First Flight\n";
            enFile << "award_first_landing=First Landing\n";
            enFile << "award_crash_pilot=Crash Pilot\n";
            enFile << "award_hard_landing=Hard Landing\n";
            enFile << "award_butter_landing=Butter Landing\n";
            enFile << "award_fuel_gambler=Fuel Gambler\n";
            enFile << "award_world_traveler=World Traveler\n";
            enFile << "award_global_explorer=Global Explorer\n";
            enFile << "award_international_ace=International Ace\n";
            enFile << "award_globe_master=Globe Master\n";
            enFile << "award_night_owl=Night Owl\n";
            enFile << "award_moon_walker=Moon Walker\n";
            enFile << "award_master_of_night=Master of Night\n";
            enFile << "award_founder_home=Founder's House\n";
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
            deFile << "button.send=Senden\n";
            deFile << "button.send_flightplan=Flugplan senden\n";
            deFile << "button.paste_route=Route einfuegen\n";
            deFile << "button.clear_route=Route leeren\n";
            deFile << "checkbox.invisible.off=[ ] Unsichtbar\n";
            deFile << "checkbox.invisible.on=[X] Unsichtbar\n";
            deFile << "status.invisible_enabled=Unsichtbarer Modus aktiviert.\n";
            deFile << "status.invisible_disabled=Unsichtbarer Modus deaktiviert.\n";
            deFile << "menu.title=Flight Radar Sim Project\n";
            deFile << "menu.main=Hauptfenster oeffnen / schliessen\n";
            deFile << "menu.login=Hauptfenster oeffnen / schliessen\n";
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
            deFile << "status.connection_lost_auto_logout=Verbindung verloren. Lokal ausgeloggt.\n";
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
            deFile << "chat.connected=Mit dem VFN Netzwerk verbunden.\n";
            deFile << "chat.rank_status=Pilotenrang: {pilot} / ATC-Rang: {atc}\n";
            deFile << "chat.ready=Bereit fuer den Netzwerkbetrieb.\n";
            deFile << "chat.award_unlocked=Award freigeschaltet\n";
            deFile << "award_first_flight=Erster Flug\n";
            deFile << "award_first_landing=Erste Landung\n";
            deFile << "award_crash_pilot=Crash Pilot\n";
            deFile << "award_hard_landing=Harte Landung\n";
            deFile << "award_butter_landing=Butterweiche Landung\n";
            deFile << "award_fuel_gambler=Fuel Gambler\n";
            deFile << "award_world_traveler=World Traveler\n";
            deFile << "award_global_explorer=Global Explorer\n";
            deFile << "award_international_ace=International Ace\n";
            deFile << "award_globe_master=Globe Master\n";
            deFile << "award_night_owl=Night Owl\n";
            deFile << "award_moon_walker=Moon Walker\n";
            deFile << "award_master_of_night=Master of Night\n";
            deFile << "award_founder_home=Haus des Gruenders\n";
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

    if (gCurrentLanguage == "de")
    {
        ApplyInternalGermanLanguageFallbacks();
    }

    if (
        gCurrentLanguage == "de" &&
        gText["menu.main"] == "Open / Close Main Window"
    )
    {
        gText["menu.main"] =
            "Hauptfenster oeffnen / schliessen";
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
        gLoginUsernameText = username;
        gLoginPasswordText = password;
        gLoginCallsignText = callsign;

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


float GetFuelRemainingPercent()
{
    if (gFuelTotal == nullptr || gFuelCapacity == nullptr)
    {
        return -1.0f;
    }

    float fuelTotal =
        XPLMGetDataf(gFuelTotal);

    float fuelCapacity =
        XPLMGetDataf(gFuelCapacity);

    if (fuelCapacity <= 0.0f)
    {
        return -1.0f;
    }

    float fuelPercent =
        (fuelTotal / fuelCapacity) * 100.0f;

    if (fuelPercent < 0.0f)
    {
        fuelPercent = 0.0f;
    }

    if (fuelPercent > 100.0f)
    {
        fuelPercent = 100.0f;
    }

    return fuelPercent;
}


void ResetNightFlightTracking()
{
    gNightFlightActive = false;
    gNightFlightSeconds = 0;
    gTotalFlightSeconds = 0;
    gNightFlightSecondAccumulator = 0.0;
}


bool IsSimulatorPaused()
{
    return gPausedRef != nullptr && XPLMGetDatai(gPausedRef) != 0;
}


bool IsReplayActive()
{
    return gReplayModeRef != nullptr && XPLMGetDatai(gReplayModeRef) != 0;
}


bool IsNightInSimulator()
{
    if (gSunPitchDegrees == nullptr)
    {
        return false;
    }

    return XPLMGetDataf(gSunPitchDegrees) < -6.0f;
}


void UpdateNightFlightTracking(
    float elapsedSeconds
)
{
    if (!gLoggedIn || gAuthToken.empty())
    {
        return;
    }

    int onGround =
        gOnGround ? XPLMGetDatai(gOnGround) : 1;

    float airspeed =
        gAirspeed ? XPLMGetDataf(gAirspeed) : 0.0f;

    bool isAirborne =
        onGround == 0 && airspeed >= 40.0f;

    if (!isAirborne)
    {
        return;
    }

    if (!gNightFlightActive)
    {
        ResetNightFlightTracking();
        gNightFlightActive = true;
    }

    if (
        IsSimulatorPaused()
        || IsReplayActive()
    ) {
        return;
    }

    if (
        elapsedSeconds < 0.0f
        || elapsedSeconds > 5.0f
    ) {
        elapsedSeconds = 1.0f;
    }

    gNightFlightSecondAccumulator += elapsedSeconds;

    bool isNight =
        IsNightInSimulator();

    while (gNightFlightSecondAccumulator >= 1.0)
    {
        gTotalFlightSeconds++;

        if (isNight)
        {
            gNightFlightSeconds++;
        }

        gNightFlightSecondAccumulator -= 1.0;
    }
}


void CompleteNightFlightTrackingIfLanded()
{
    if (!gNightFlightActive)
    {
        return;
    }

    int onGround =
        gOnGround ? XPLMGetDatai(gOnGround) : 1;

    if (onGround == 1)
    {
        ResetNightFlightTracking();
    }
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


std::string GetPrimaryChatFrequency()
{
    int com1 =
        gCom1 ? XPLMGetDatai(gCom1) : 0;

    std::string frequency =
        FormatComFrequency(com1);

    if (frequency != "0.000")
    {
        return frequency;
    }

    int com2 =
        gCom2 ? XPLMGetDatai(gCom2) : 0;

    return FormatComFrequency(com2);
}


std::string GetActiveChatFrequencies()
{
    std::vector<std::string> frequencies;

    int com1 =
        gCom1 ? XPLMGetDatai(gCom1) : 0;

    int com2 =
        gCom2 ? XPLMGetDatai(gCom2) : 0;

    std::string com1Frequency =
        FormatComFrequency(com1);

    std::string com2Frequency =
        FormatComFrequency(com2);

    if (com1Frequency != "0.000")
    {
        frequencies.push_back(com1Frequency);
    }

    if (
        com2Frequency != "0.000" &&
        com2Frequency != com1Frequency
    ) {
        frequencies.push_back(com2Frequency);
    }

    std::string value;

    for (size_t i = 0; i < frequencies.size(); i++)
    {
        if (i > 0)
        {
            value += ",";
        }

        value += frequencies[i];
    }

    return value;
}


std::vector<std::string> SplitString(
    const std::string& value,
    char delimiter
)
{
    std::vector<std::string> parts;
    std::stringstream stream(value);
    std::string part;

    while (std::getline(stream, part, delimiter))
    {
        parts.push_back(part);
    }

    return parts;
}


void AddChatLine(
    const ChatLine& line,
    bool notify
)
{
    ChatLine storedLine =
        line;

    if (storedLine.timestamp.empty())
    {
        storedLine.timestamp =
            GetCurrentTimeHHmm();
    }

    gChatLines.push_back(storedLine);

    while (gChatLines.size() > 200)
    {
        gChatLines.erase(gChatLines.begin());
    }

    gChatScrollOffset = 0;

    if (line.id > gLastChatMessageId)
    {
        gLastChatMessageId =
            line.id;
    }

    if (notify)
    {
        MessageBeep(MB_ICONASTERISK);
    }
}


std::string ReplaceAll(
    std::string value,
    const std::string& search,
    const std::string& replacement
)
{
    size_t position =
        0;

    while (
        (position = value.find(search, position)) != std::string::npos
    ) {
        value.replace(
            position,
            search.size(),
            replacement
        );

        position +=
            replacement.size();
    }

    return value;
}


void AddLoginChatSummary()
{
    std::string pilotRating =
        gCurrentPilotRatingCode;

    if (!gCurrentPilotRatingName.empty())
    {
        pilotRating +=
            " - " + gCurrentPilotRatingName;
    }

    std::string atcRating =
        gCurrentAtcRatingCode;

    if (!gCurrentAtcRatingName.empty())
    {
        atcRating +=
            " - " + gCurrentAtcRatingName;
    }

    std::string rankText =
        T("chat.rank_status");

    rankText =
        ReplaceAll(rankText, "{pilot}", pilotRating);

    rankText =
        ReplaceAll(rankText, "{atc}", atcRating);

    AddChatLine(
        { 0, "", "", "SYSTEM", "system", T("chat.connected") },
        false
    );

    AddChatLine(
        { 0, "", "", "SYSTEM", "system", rankText },
        false
    );

    AddChatLine(
        { 0, "", "", "VFN", "system", T("chat.ready") },
        false
    );
}


std::string GetLocalizedChatText(
    const ChatLine& line
)
{
    if (line.type != "award")
    {
        return line.text;
    }

    std::string awardKey =
        line.text;

    const std::string keyPrefix =
        "award:";

    const std::string oldPrefix =
        "Award unlocked: ";

    if (awardKey.rfind(keyPrefix, 0) == 0)
    {
        awardKey =
            awardKey.substr(keyPrefix.size());
    }
    else if (awardKey.rfind(oldPrefix, 0) == 0)
    {
        awardKey =
            TrimString(awardKey.substr(oldPrefix.size()));
    }

    return std::string(T("chat.award_unlocked")) + ": " + T(awardKey);
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

    WinHttpSetTimeouts(
        hSession,
        gHttpResolveTimeoutMs,
        gHttpConnectTimeoutMs,
        gHttpSendTimeoutMs,
        gHttpReceiveTimeoutMs
    );

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


std::string HttpGet(
    const std::string& url
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

    WinHttpSetTimeouts(
        hSession,
        gHttpResolveTimeoutMs,
        gHttpConnectTimeoutMs,
        gHttpSendTimeoutMs,
        gHttpReceiveTimeoutMs
    );

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
            L"GET",
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

    BOOL result =
        WinHttpSendRequest(
            hRequest,
            WINHTTP_NO_ADDITIONAL_HEADERS,
            0,
            WINHTTP_NO_REQUEST_DATA,
            0,
            0,
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


int ExtractJsonIntValue(
    const std::string& response,
    const std::string& keyName,
    int fallbackValue
)
{
    std::string key =
        "\"" + keyName + "\"";

    size_t keyPos =
        response.find(key);

    if (keyPos == std::string::npos)
    {
        return fallbackValue;
    }

    size_t colonPos =
        response.find(":", keyPos);

    if (colonPos == std::string::npos)
    {
        return fallbackValue;
    }

    size_t valuePos =
        colonPos + 1;

    while (
        valuePos < response.size() &&
        std::isspace(
            static_cast<unsigned char>(response[valuePos])
        )
    )
    {
        valuePos++;
    }

    bool negative =
        false;

    if (
        valuePos < response.size() &&
        response[valuePos] == '-'
    )
    {
        negative = true;
        valuePos++;
    }

    int value = 0;
    bool foundDigit = false;

    while (
        valuePos < response.size() &&
        std::isdigit(
            static_cast<unsigned char>(response[valuePos])
        )
    )
    {
        foundDigit = true;
        value =
            (value * 10) +
            (response[valuePos] - '0');
        valuePos++;
    }

    if (!foundDigit)
    {
        return fallbackValue;
    }

    return negative ? -value : value;
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

    UpdateLoginNetworkLabels();

    if (gLoggedIn)
    {
        SetCustomLoginStatus(
            std::string(T("status.connected_as")) + " " +
            gCurrentCallsign
        );

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
        SetCustomLoginStatus(
            T("status.not_connected")
        );

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
    gPositionUpdateFailureCount = 0;
    gPositionUpdateFirstFailureTime = -1.0f;
    gPositionUpdateResultReady.store(false);
    ResetNightFlightTracking();
    gChatLines.clear();
    gChatInputText = "";
    gChatInputFocused = false;
    gChatScrollOffset = 0;
    gLastChatMessageId = 0;
    gChatPollElapsed = 999.0f;
    gCurrentPilotRatingCode = "FC0";
    gCurrentPilotRatingName = "New Flight Cadet";
    gCurrentAtcRatingCode = "TC0";
    gCurrentAtcRatingName = "New ATC Cadet";

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

        gLoginUsernameText = "";
        gLoginPasswordText = "";
        gLoginCallsignText = "";
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

    if (gCompactWindow != nullptr)
    {
        XPLMSetWindowIsVisible(
            gCompactWindow,
            0
        );
    }

    if (gLogoutConfirmWindow != nullptr)
    {
        XPLMSetWindowIsVisible(
            gLogoutConfirmWindow,
            0
        );
    }

    if (gCustomLoginWindow != nullptr)
    {
        XPLMSetWindowIsVisible(
            gCustomLoginWindow,
            1
        );

        XPLMBringWindowToFront(
            gCustomLoginWindow
        );
    }
}


void ForceLocalLogoutAfterConnectionFailures(
    const std::string& reason
)
{
    if (!gLoggedIn)
    {
        return;
    }

    gLoggedIn = false;
    gCurrentUsername = "";
    gCurrentCallsign = "";
    gAuthToken = "";
    gCanUseInvisible = false;
    gIsInvisible = false;
    gPositionUpdateFailureCount = 0;
    gPositionUpdateFirstFailureTime = -1.0f;
    gPositionUpdateResultReady.store(false);
    ResetNightFlightTracking();
    gChatLines.clear();
    gChatInputText = "";
    gChatInputFocused = false;
    gChatScrollOffset = 0;
    gLastChatMessageId = 0;
    gChatPollElapsed = 999.0f;
    gCurrentPilotRatingCode = "FC0";
    gCurrentPilotRatingName = "New Flight Cadet";
    gCurrentAtcRatingCode = "TC0";
    gCurrentAtcRatingName = "New ATC Cadet";

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

        gLoginUsernameText = "";
        gLoginPasswordText = "";
        gLoginCallsignText = "";
    }

    UpdateLoginWindowState();
    UpdateFlightplanWindowState();

    if (gCompactWindow != nullptr)
    {
        XPLMSetWindowIsVisible(
            gCompactWindow,
            0
        );
    }

    if (gLogoutConfirmWindow != nullptr)
    {
        XPLMSetWindowIsVisible(
            gLogoutConfirmWindow,
            0
        );
    }

    if (gCustomLoginWindow != nullptr)
    {
        XPLMSetWindowIsVisible(
            gCustomLoginWindow,
            1
        );

        XPLMBringWindowToFront(
            gCustomLoginWindow
        );
    }

    XPSetWidgetDescriptor(
        gStatusCaption,
        T("status.connection_lost_auto_logout")
    );

    XPLMDebugString(
        "Flight Radar Plugin: Auto logout after repeated position update failures: "
    );

    XPLMDebugString(
        reason.c_str()
    );

    XPLMDebugString("\n");
}


void StartPositionUpdateWorker(
    const std::string& postData
)
{
    if (gPositionUpdateInProgress.exchange(true))
    {
        return;
    }

    if (gPositionUpdateThread.joinable())
    {
        gPositionUpdateThread.join();
    }

    gPositionUpdateThread =
        std::thread(
        [postData]()
        {
            std::string response =
                HttpPost(
                    gPositionUrl,
                    postData
                );

            bool success =
                ResponseIsSuccess(response);

            {
                std::lock_guard<std::mutex> lock(
                    gPositionUpdateResultMutex
                );

                gPositionUpdateLastResponse =
                    response;
            }

            gPositionUpdateLastSuccess.store(
                success
            );

            gPositionUpdateResultReady.store(
                true
            );

            gPositionUpdateInProgress.store(
                false
            );
        }
    );
}


void ProcessPositionUpdateResult()
{
    if (!gPositionUpdateResultReady.exchange(false))
    {
        return;
    }

    bool success =
        gPositionUpdateLastSuccess.load();

    std::string response;

    {
        std::lock_guard<std::mutex> lock(
            gPositionUpdateResultMutex
        );

        response =
            gPositionUpdateLastResponse;
    }

    if (gDebugEnabled)
    {
        XPLMDebugString("POSITION RESPONSE: ");
        XPLMDebugString(response.c_str());
        XPLMDebugString("\n");
    }

    if (
        !gPositionUpdateInProgress.load()
        && gPositionUpdateThread.joinable()
    ) {
        gPositionUpdateThread.join();
    }

    if (success)
    {
        gPositionUpdateFailureCount = 0;
        gPositionUpdateFirstFailureTime = -1.0f;
        return;
    }

    std::string message =
        ExtractMessageFromResponse(response);

    gPositionUpdateFailureCount++;

    if (gPositionUpdateFirstFailureTime < 0.0f)
    {
        gPositionUpdateFirstFailureTime =
            XPLMGetElapsedTime();
    }

    XPLMDebugString(
        T("debug.position_failed")
    );

    XPLMDebugString(
        message.c_str()
    );

    XPLMDebugString("\n");

    float failureSeconds =
        XPLMGetElapsedTime() -
        gPositionUpdateFirstFailureTime;

    if (
        gPositionUpdateFailureCount >= gMaxPositionUpdateFailures &&
        failureSeconds >= gMinPositionUpdateFailureSeconds
    )
    {
        ForceLocalLogoutAfterConnectionFailures(
            message
        );
    }
}


void StartChatPollWorker()
{
    if (!gLoggedIn || gAuthToken.empty())
    {
        return;
    }

    if (gChatPollInProgress.exchange(true))
    {
        return;
    }

    if (gChatPollThread.joinable())
    {
        gChatPollThread.join();
    }

    std::string postData =
        "token=" + UrlEncode(gAuthToken) +
        "&since_id=" + UrlEncode(IntToString(gLastChatMessageId)) +
        "&frequencies=" + UrlEncode(GetActiveChatFrequencies());

    gChatPollThread =
        std::thread(
        [postData]()
        {
            std::string response =
                HttpPost(
                    gChatPollUrl,
                    postData
                );

            {
                std::lock_guard<std::mutex> lock(
                    gChatPollResultMutex
                );

                gChatPollLastResponse =
                    response;
            }

            gChatPollResultReady.store(true);
            gChatPollInProgress.store(false);
        }
    );
}


void ProcessChatPollResult()
{
    if (!gChatPollResultReady.exchange(false))
    {
        return;
    }

    std::string response;

    {
        std::lock_guard<std::mutex> lock(
            gChatPollResultMutex
        );

        response =
            gChatPollLastResponse;
    }

    if (
        !gChatPollInProgress.load() &&
        gChatPollThread.joinable()
    ) {
        gChatPollThread.join();
    }

    if (response.rfind("OK", 0) != 0)
    {
        return;
    }

    std::stringstream stream(response);
    std::string line;
    bool firstLine = true;
    bool gotNewLine = false;

    while (std::getline(stream, line))
    {
        if (firstLine)
        {
            firstLine = false;
            continue;
        }

        if (line.empty())
        {
            continue;
        }

        if (line.rfind("LAST|", 0) == 0)
        {
            gLastChatMessageId =
                atoi(line.substr(5).c_str());

            continue;
        }

        std::vector<std::string> parts =
            SplitString(line, '|');

        if (parts.size() < 5)
        {
            continue;
        }

        ChatLine chatLine;
        chatLine.id = atoi(parts[0].c_str());
        chatLine.frequency = parts[1];

        if (parts.size() >= 6)
        {
            chatLine.timestamp = parts[2];
            chatLine.sender = parts[3];
            chatLine.type = parts[4];
            chatLine.text = parts[5];
        }
        else
        {
            chatLine.timestamp = "";
            chatLine.sender = parts[2];
            chatLine.type = parts[3];
            chatLine.text = parts[4];
        }

        bool isOwnPilotMessage =
            chatLine.type == "pilot" &&
            chatLine.sender == gCurrentCallsign;

        AddChatLine(
            chatLine,
            !isOwnPilotMessage
        );

        if (!isOwnPilotMessage)
        {
            gotNewLine = true;
        }
    }

    if (gotNewLine && gDebugEnabled)
    {
        XPLMDebugString("Flight Radar Plugin: New chat message received.\n");
    }
}


void UpdateChatPolling(
    float elapsedSeconds
)
{
    if (!gLoggedIn || gAuthToken.empty())
    {
        return;
    }

    gChatPollElapsed += elapsedSeconds;

    if (gChatPollElapsed < 2.0f)
    {
        return;
    }

    gChatPollElapsed =
        0.0f;

    StartChatPollWorker();
}


void StartChatSendWorker(
    const std::string& postData
)
{
    if (gChatSendInProgress.exchange(true))
    {
        return;
    }

    if (gChatSendThread.joinable())
    {
        gChatSendThread.join();
    }

    gChatSendThread =
        std::thread(
        [postData]()
        {
            std::string response =
                HttpPost(
                    gChatSendUrl,
                    postData
                );

            {
                std::lock_guard<std::mutex> lock(
                    gChatSendResultMutex
                );

                gChatSendLastResponse =
                    response;
            }

            gChatSendResultReady.store(true);
            gChatSendInProgress.store(false);
        }
    );
}


void ProcessChatSendResult()
{
    if (!gChatSendResultReady.exchange(false))
    {
        return;
    }

    if (
        !gChatSendInProgress.load() &&
        gChatSendThread.joinable()
    ) {
        gChatSendThread.join();
    }
}


void SendChatMessage()
{
    if (!gLoggedIn || gAuthToken.empty())
    {
        XPLMDebugString(
            "Flight Radar Plugin: Chat send ignored, not logged in or missing token.\n"
        );

        return;
    }

    std::string message =
        TrimString(gChatInputText);

    if (message.empty())
    {
        XPLMDebugString(
            "Flight Radar Plugin: Chat send ignored, message is empty.\n"
        );

        return;
    }

    std::string frequency =
        GetPrimaryChatFrequency();

    if (frequency == "0.000")
    {
        XPLMDebugString(
            "Flight Radar Plugin: Chat send ignored, no active chat frequency.\n"
        );

        return;
    }

    XPLMDebugString(
        "Flight Radar Plugin: Chat send started.\n"
    );

    std::string postData =
        "token=" + UrlEncode(gAuthToken) +
        "&callsign=" + UrlEncode(gCurrentCallsign) +
        "&frequency=" + UrlEncode(frequency) +
        "&message=" + UrlEncode(message);

    gChatInputText = "";

    StartChatSendWorker(
        postData
    );
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

    float fuelRemainingPercent =
        GetFuelRemainingPercent();

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

    int hasCrashed = 0;

    if (gHasCrashedRef)
    {
        hasCrashed =
            XPLMGetDatai(gHasCrashedRef);
    }

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
        "&transponder=" + UrlEncode(IntToString(transponder)) +
        "&fuel_remaining_percent=" + UrlEncode(FloatToString(fuelRemainingPercent)) +
        "&night_flight_seconds=" + UrlEncode(IntToString(gNightFlightSeconds)) +
        "&total_flight_seconds=" + UrlEncode(IntToString(gTotalFlightSeconds)) +
        "&has_crashed=" + UrlEncode(IntToString(hasCrashed));

    StartPositionUpdateWorker(
        postData
    );
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


CustomRect GetCustomLoginUsernameRect(int left, int top)
{
    return { left + 28, top - 142, left + 332, top - 170 };
}


CustomRect GetCustomLoginPasswordRect(int left, int top)
{
    return { left + 28, top - 194, left + 332, top - 222 };
}


CustomRect GetCustomLoginCallsignRect(int left, int top)
{
    return { left + 28, top - 246, left + 332, top - 274 };
}


CustomRect GetCustomLoginRememberRect(int left, int top)
{
    return { left + 28, top - 286, left + 150, top - 306 };
}


CustomRect GetCustomLoginButtonRect(int left, int top)
{
    return { left + 28, top - 322, left + 332, top - 356 };
}


CustomRect GetCustomLoginLogoutRect(int left, int top)
{
    return { left + 28, top - 322, left + 174, top - 356 };
}


CustomRect GetCustomLoginInvisibleRect(int left, int top)
{
    return { left + 186, top - 322, left + 332, top - 356 };
}


CustomRect GetCustomLoginCloseRect(int left, int top, int right)
{
    return { right - 36, top - 32, right - 6, top - 4 };
}


CustomRect GetCustomLoginPopoutRect(int left, int top, int right)
{
    return { right - 126, top - 32, right - 42, top - 4 };
}


void SetCustomLoginStatus(
    const std::string& value
)
{
    gCustomLoginStatusText =
        value;
}


std::string FormatNetworkCount(
    int value
)
{
    if (value < 0)
    {
        return "--";
    }

    return std::to_string(value);
}


void UpdateLoginNetworkLabels()
{
    if (gLoginPilotsLabel != nullptr)
    {
        std::string pilotsText =
            "Pilots Online: " +
            FormatNetworkCount(gNetworkPilotsOnline);

        XPSetWidgetDescriptor(
            gLoginPilotsLabel,
            pilotsText.c_str()
        );
    }

    if (gLoginAtcLabel != nullptr)
    {
        std::string atcText =
            "ATC Online: " +
            FormatNetworkCount(gNetworkAtcOnline);

        XPSetWidgetDescriptor(
            gLoginAtcLabel,
            atcText.c_str()
        );
    }
}


void StartNetworkStatusUpdateWorker()
{
    if (gNetworkStatusUpdateInProgress.exchange(true))
    {
        return;
    }

    if (gNetworkStatusThread.joinable())
    {
        gNetworkStatusThread.join();
    }

    gNetworkStatusThread =
        std::thread(
        []()
        {
            std::string response =
                HttpGet(
                    gPilotsUrl
                );

            {
                std::lock_guard<std::mutex> lock(
                    gNetworkStatusResultMutex
                );

                gNetworkStatusLastResponse =
                    response;
            }

            gNetworkStatusUpdateResultReady.store(
                true
            );

            gNetworkStatusUpdateInProgress.store(
                false
            );
        }
    );
}


void ProcessNetworkStatusUpdateResult()
{
    if (!gNetworkStatusUpdateResultReady.exchange(false))
    {
        return;
    }

    std::string response;

    {
        std::lock_guard<std::mutex> lock(
            gNetworkStatusResultMutex
        );

        response =
            gNetworkStatusLastResponse;
    }

    if (!ResponseIsSuccess(response))
    {
        return;
    }

    int pilotCount =
        ExtractJsonIntValue(
            response,
            "visible_count",
            -1
        );

    if (pilotCount < 0)
    {
        pilotCount =
            ExtractJsonIntValue(
                response,
                "count",
                -1
            );
    }

    gNetworkPilotsOnline =
        pilotCount;

    gNetworkAtcOnline =
        0;

    UpdateLoginNetworkLabels();
}


void UpdateNetworkStatusIfNeeded(
    float elapsedSeconds
)
{
    gNetworkStatusRefreshElapsed +=
        elapsedSeconds;

    if (gNetworkStatusRefreshElapsed < 10.0f)
    {
        return;
    }

    if (
        gCustomLoginWindow != nullptr &&
        !XPLMGetWindowIsVisible(gCustomLoginWindow)
    )
    {
        return;
    }

    gNetworkStatusRefreshElapsed =
        0.0f;

    StartNetworkStatusUpdateWorker();
}


void PerformCustomLogin()
{
    if (gLoggedIn)
    {
        SetCustomLoginStatus(
            T("status.already_connected")
        );

        UpdateLoginWindowState();
        return;
    }

    std::string username =
        TrimString(gLoginUsernameText);

    std::string password =
        gLoginPasswordText;

    std::string callsign =
        TrimString(gLoginCallsignText);

    if (
        username.empty() ||
        password.empty() ||
        callsign.empty()
    ) {
        SetCustomLoginStatus(
            T("status.login_missing")
        );

        return;
    }

    SetCustomLoginStatus(
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
        gPositionUpdateFailureCount = 0;
        ResetNightFlightTracking();

        gAuthToken =
            ExtractJsonStringValue(
                response,
                "token"
            );

        gCurrentPilotRatingCode =
            ExtractJsonStringValue(
                response,
                "pilot_rating_code"
            );

        gCurrentPilotRatingName =
            ExtractJsonStringValue(
                response,
                "pilot_rating_name"
            );

        gCurrentAtcRatingCode =
            ExtractJsonStringValue(
                response,
                "atc_rating_code"
            );

        gCurrentAtcRatingName =
            ExtractJsonStringValue(
                response,
                "atc_rating_name"
            );

        if (gCurrentPilotRatingCode.empty())
        {
            gCurrentPilotRatingCode = "FC0";
        }

        if (gCurrentPilotRatingName.empty())
        {
            gCurrentPilotRatingName = "New Flight Cadet";
        }

        if (gCurrentAtcRatingCode.empty())
        {
            gCurrentAtcRatingCode = "TC0";
        }

        if (gCurrentAtcRatingName.empty())
        {
            gCurrentAtcRatingName = "New ATC Cadet";
        }

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

            SetCustomLoginStatus(
                T("status.login_success_no_token")
            );

            return;
        }

        gChatLines.clear();
        gChatInputText = "";
        gChatInputFocused = false;
        gChatSendButtonPressed = false;
        gChatScrollOffset = 0;
        gLastChatMessageId = 0;
        gChatPollElapsed = 999.0f;

        AddLoginChatSummary();

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

        if (gCustomLoginWindow != nullptr)
        {
            XPLMSetWindowIsVisible(
                gCustomLoginWindow,
                0
            );
        }

        if (gLoginWindow != nullptr)
        {
            XPHideWidget(
                gLoginWindow
            );
        }

        if (gCompactWindow != nullptr)
        {
            XPLMSetWindowIsVisible(
                gCompactWindow,
                1
            );

            XPLMBringWindowToFront(
                gCompactWindow
            );

            XPLMTakeKeyboardFocus(
                gCompactWindow
            );
        }

        return;
    }

    gLoggedIn = false;
    gCurrentUsername = "";
    gCurrentCallsign = "";
    gAuthToken = "";
    gCanUseInvisible = false;
    gIsInvisible = false;

    SetCustomLoginStatus(
        ExtractMessageFromResponse(response)
    );

    XPLMDebugString(
        T("status.login_failed_log")
    );
}


void ToggleCustomInvisible()
{
    if (!gLoggedIn || gAuthToken.empty())
    {
        return;
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

    if (ResponseIsSuccess(response))
    {
        gIsInvisible =
            newInvisibleState;

        SetCustomLoginStatus(
            gIsInvisible
            ? T("status.invisible_enabled")
            : T("status.invisible_disabled")
        );
    }
    else
    {
        SetCustomLoginStatus(
            ExtractMessageFromResponse(response)
        );
    }
}


void ToggleCustomLoginPopout()
{
    if (gCustomLoginWindow == nullptr)
    {
        return;
    }

    bool isCurrentlyPoppedOut =
        XPLMWindowIsPoppedOut(
            gCustomLoginWindow
        ) != 0;

    if (isCurrentlyPoppedOut)
    {
        XPLMSetWindowPositioningMode(
            gCustomLoginWindow,
            xplm_WindowPositionFree,
            -1
        );

        XPLMSetWindowGeometry(
            gCustomLoginWindow,
            80,
            700,
            440,
            230
        );

        gCustomLoginPoppedOut = false;

        XPLMSetWindowIsVisible(
            gCustomLoginWindow,
            1
        );

        XPLMBringWindowToFront(
            gCustomLoginWindow
        );

        return;
    }

    XPLMSetWindowIsVisible(
        gCustomLoginWindow,
        1
    );

    XPLMSetWindowPositioningMode(
        gCustomLoginWindow,
        xplm_WindowPopOut,
        -1
    );

    XPLMSetWindowGeometryOS(
        gCustomLoginWindow,
        120,
        120,
        480,
        590
    );

    XPLMBringWindowToFront(
        gCustomLoginWindow
    );

    gCustomLoginPoppedOut = true;
}


void DrawCustomLoginInput(
    const CustomRect& rect,
    const std::string& label,
    const std::string& value,
    CustomLoginField field,
    bool password
)
{
    bool focused =
        gCustomLoginFocusedField == field;

    DrawText(
        rect.left,
        rect.top + 9,
        label,
        0.82f,
        0.88f,
        0.95f
    );

    DrawFilledRect(
        rect,
        0.025f,
        0.080f,
        0.115f,
        0.98f
    );

    DrawRectOutline(
        rect,
        focused ? 0.14f : 0.22f,
        focused ? 0.60f : 0.36f,
        focused ? 1.00f : 0.46f,
        focused ? 1.00f : 0.95f
    );

    std::string displayValue =
        password
        ? MaskPassword(value)
        : value;

    displayValue =
        TruncateForField(
            displayValue,
            31
        );

    if (displayValue.empty())
    {
        std::string placeholder =
            field == CustomLoginFieldUsername
            ? "Enter your VFN username"
            : (
                field == CustomLoginFieldPassword
                ? "Enter your password"
                : "Enter your callsign"
            );

        DrawText(
            rect.left + 12,
            rect.bottom + 9,
            placeholder,
            0.46f,
            0.54f,
            0.62f
        );
    }
    else
    {
        DrawText(
            rect.left + 12,
            rect.bottom + 9,
            displayValue,
            0.86f,
            0.91f,
            0.96f
        );
    }
}


void DrawCustomLoginButton(
    const CustomRect& rect,
    const std::string& label,
    bool primary
)
{
    if (primary)
    {
        DrawFilledRect(
            rect,
            0.04f,
            0.30f,
            0.72f,
            1.00f
        );

        DrawRectOutline(
            rect,
            0.13f,
            0.50f,
            0.95f,
            1.00f
        );
    }
    else
    {
        DrawFilledRect(
            rect,
            0.04f,
            0.10f,
            0.15f,
            0.98f
        );

        DrawRectOutline(
            rect,
            0.16f,
            0.28f,
            0.38f,
            0.96f
        );
    }

    int textX =
        rect.left + ((rect.right - rect.left) / 2) - ((int)label.size() * 3);
    int textY =
        rect.bottom + ((rect.top - rect.bottom) / 2) - 5;

    DrawText(
        textX,
        textY,
        label,
        0.92f,
        0.96f,
        1.00f
    );
}


void DrawCustomLoginWindow(
    XPLMWindowID inWindowID,
    void* inRefcon
)
{
    int left;
    int top;
    int right;
    int bottom;

    XPLMGetWindowGeometry(
        inWindowID,
        &left,
        &top,
        &right,
        &bottom
    );

    gCustomLoginPoppedOut =
        XPLMWindowIsPoppedOut(
            inWindowID
        ) != 0;

    XPLMSetGraphicsState(
        0,
        0,
        0,
        0,
        1,
        0,
        0
    );

    CustomRect windowRect =
    {
        left,
        top,
        right,
        bottom
    };

    XPLMDrawTranslucentDarkBox(
        left,
        top,
        right,
        bottom
    );

    DrawFilledRect(
        windowRect,
        0.015f,
        0.040f,
        0.065f,
        1.00f
    );

    DrawRectOutline(
        windowRect,
        0.36f,
        0.55f,
        0.66f,
        0.98f
    );

    DrawRectOutline(
        { left + 2, top - 2, right - 2, bottom + 2 },
        0.06f,
        0.17f,
        0.25f,
        1.00f
    );

    DrawFilledRect(
        { left + 1, top - 34, right - 1, top - 1 },
        0.018f,
        0.075f,
        0.115f,
        1.00f
    );

    DrawFilledRect(
        { left + 3, top - 36, right - 3, top - 34 },
        0.10f,
        0.45f,
        0.85f,
        0.80f
    );

    DrawFilledRect(
        { left + 17, top - 24, left + 23, top - 9 },
        0.00f,
        0.32f,
        0.72f,
        1.00f
    );

    DrawFilledRect(
        { left + 25, top - 24, left + 30, top - 9 },
        0.04f,
        0.52f,
        1.00f,
        1.00f
    );

    DrawText(
        left + 36,
        top - 18,
        "VFN",
        0.76f,
        0.90f,
        1.00f
    );

    DrawText(
        left + 78,
        top - 18,
        "Network Pilot Client",
        0.94f,
        0.97f,
        1.00f
    );

    DrawRectOutline(
        GetCustomLoginCloseRect(left, top, right),
        0.18f,
        0.38f,
        0.52f,
        0.85f
    );

    DrawText(
        right - 21,
        top - 22,
        "X",
        0.72f,
        0.80f,
        0.88f
    );

    DrawLine(
        left + 22,
        top - 102,
        right - 26,
        top - 102,
        0.15f,
        0.30f,
        0.40f,
        0.84f
    );

    DrawFilledRect(
        { left + 92, top - 82, left + 103, top - 51 },
        0.00f,
        0.32f,
        0.72f,
        1.00f
    );

    DrawFilledRect(
        { left + 107, top - 82, left + 116, top - 51 },
        0.04f,
        0.52f,
        1.00f,
        1.00f
    );

    DrawText(
        left + 124,
        top - 61,
        "VFN",
        0.94f,
        0.98f,
        1.00f
    );

    DrawText(
        left + 122,
        top - 82,
        "NETWORK",
        0.82f,
        0.88f,
        0.96f
    );

    DrawText(
        left + 28,
        top - 113,
        "LOGIN",
        0.13f,
        0.58f,
        1.00f
    );

    DrawLine(
        left + 82,
        top - 108,
        right - 28,
        top - 108,
        0.15f,
        0.30f,
        0.40f,
        0.84f
    );

    DrawCustomLoginInput(
        GetCustomLoginUsernameRect(left, top),
        "Username",
        gLoginUsernameText,
        CustomLoginFieldUsername,
        false
    );

    DrawCustomLoginInput(
        GetCustomLoginPasswordRect(left, top),
        "Password",
        gLoginPasswordText,
        CustomLoginFieldPassword,
        true
    );

    DrawCustomLoginInput(
        GetCustomLoginCallsignRect(left, top),
        "Callsign",
        gLoginCallsignText,
        CustomLoginFieldCallsign,
        false
    );

    CustomRect rememberRect =
        GetCustomLoginRememberRect(left, top);

    DrawFilledRect(
        { rememberRect.left, rememberRect.top - 14, rememberRect.left + 13, rememberRect.top - 1 },
        gRememberLogin ? 0.05f : 0.03f,
        gRememberLogin ? 0.36f : 0.10f,
        gRememberLogin ? 0.82f : 0.16f,
        0.94f
    );

    DrawRectOutline(
        { rememberRect.left, rememberRect.top - 14, rememberRect.left + 13, rememberRect.top - 1 },
        0.13f,
        0.50f,
        0.95f,
        0.90f
    );

    if (gRememberLogin)
    {
        DrawText(
            rememberRect.left + 2,
            rememberRect.top - 13,
            "X",
            0.90f,
            0.96f,
            1.00f
        );
    }

    DrawText(
        rememberRect.left + 22,
        rememberRect.top - 12,
        "Remember me",
        0.82f,
        0.88f,
        0.95f
    );

    if (gLoggedIn)
    {
        DrawCustomLoginButton(
            GetCustomLoginLogoutRect(left, top),
            "LOGOUT",
            false
        );

        DrawCustomLoginButton(
            GetCustomLoginInvisibleRect(left, top),
            gIsInvisible ? "VISIBLE" : "INVISIBLE",
            true
        );
    }
    else
    {
        DrawCustomLoginButton(
            GetCustomLoginButtonRect(left, top),
            "LOGIN",
            true
        );
    }

    if (!gCustomLoginStatusText.empty())
    {
        DrawText(
            left + 28,
            top - 376,
            TruncateForField(gCustomLoginStatusText, 36),
            gLoggedIn ? 0.22f : 0.78f,
            gLoggedIn ? 0.92f : 0.72f,
            gLoggedIn ? 0.25f : 0.72f
        );
    }

}


CustomRect GetCompactCloseRect(int left, int top, int right)
{
    return { right - 62, top - 36, right - 4, top - 2 };
}


CustomRect GetCompactTabRect(int left, int top, int index)
{
    int tabWidth = 120;
    int tabLeft =
        left + 12 + (index * (tabWidth + 8));

    return { tabLeft, top - 322, tabLeft + tabWidth, top - 358 };
}


CustomRect GetCompactChatInputRect(const CustomRect& chatRect)
{
    return {
        chatRect.left + 8,
        chatRect.bottom + 10,
        chatRect.right - 126,
        chatRect.bottom + 48
    };
}


CustomRect GetCompactChatSendRect(const CustomRect& chatRect)
{
    return {
        chatRect.right - 116,
        chatRect.bottom + 14,
        chatRect.right - 38,
        chatRect.bottom + 44
    };
}


CustomRect GetCompactChatSendHitRect(const CustomRect& chatRect)
{
    return {
        chatRect.right - 130,
        chatRect.bottom,
        chatRect.right,
        chatRect.bottom + 66
    };
}


bool PointInCompactChatSendArea(
    int x,
    int y,
    int windowLeft,
    int windowTop,
    int windowRight,
    int windowBottom
)
{
    CustomRect chatRect =
        { windowLeft + 270, windowTop - 50, windowRight - 12, windowTop - 300 };
    CustomRect sendRect =
        GetCompactChatSendHitRect(chatRect);

    if (
        PointInRect(x, y, sendRect) ||
        PointInWindowRect(x, y, sendRect, windowLeft, windowTop, windowBottom)
    ) {
        return true;
    }

    int width =
        windowRight - windowLeft;
    int height =
        windowTop - windowBottom;

    if (
        width <= 0 ||
        height <= 0
    ) {
        return false;
    }

    int localX =
        x;

    if (
        x >= windowLeft &&
        x <= windowRight
    ) {
        localX =
            x - windowLeft;
    }

    int localYFromTop =
        y;

    if (
        y <= windowTop &&
        y >= windowBottom
    ) {
        localYFromTop =
            windowTop - y;
    }

    bool inButtonColumn =
        localX >= width - 230 &&
        localX <= width - 4;
    bool inInputRow =
        localYFromTop >= height - 112 &&
        localYFromTop <= height - 12;

    return
        inButtonColumn &&
        inInputRow;
}


CustomRect GetCompactChatFocusRect(const CustomRect& chatRect)
{
    return {
        chatRect.left,
        chatRect.bottom,
        chatRect.right,
        chatRect.top - 28
    };
}


std::string FormatTransponderCode(int value)
{
    char buffer[8];

    sprintf_s(
        buffer,
        "%04d",
        value
    );

    return std::string(buffer);
}


std::string GetCompactComSubLabel(
    const std::string& frequency
)
{
    if (frequency == "122.800")
    {
        return "UNICOM";
    }

    return "";
}


void DrawCompactHeaderLogo(int left, int top)
{
    DrawFilledRect(
        { left + 14, top - 23, left + 20, top - 8 },
        0.00f,
        0.32f,
        0.72f,
        1.00f
    );

    DrawFilledRect(
        { left + 22, top - 23, left + 27, top - 8 },
        0.04f,
        0.52f,
        1.00f,
        1.00f
    );

    DrawText(
        left + 34,
        top - 17,
        "VFN",
        0.76f,
        0.90f,
        1.00f
    );
}


void DrawCompactTab(
    const CustomRect& rect,
    const std::string& label,
    bool active
)
{
    DrawFilledRect(
        rect,
        active ? 0.06f : 0.035f,
        active ? 0.22f : 0.070f,
        active ? 0.50f : 0.095f,
        0.96f
    );

    DrawRectOutline(
        rect,
        0.13f,
        0.32f,
        0.48f,
        0.88f
    );

    DrawText(
        rect.left + 31,
        rect.top - 22,
        label,
        0.88f,
        0.94f,
        1.00f
    );
}


void DrawCompactGreenButton(
    const CustomRect& rect,
    const std::string& label
)
{
    DrawFilledRect(
        rect,
        0.05f,
        0.34f,
        0.09f,
        0.96f
    );

    DrawRectOutline(
        rect,
        0.13f,
        0.42f,
        0.18f,
        0.96f
    );

    DrawText(
        rect.left + 9,
        rect.top - 17,
        label,
        0.90f,
        0.98f,
        0.90f
    );
}


void DrawCompactRadioPanel(
    const CustomRect& rect,
    const std::string& label,
    const std::string& value,
    const std::string& subLabel
)
{
    DrawFilledRect(
        rect,
        0.015f,
        0.040f,
        0.065f,
        1.00f
    );

    DrawRectOutline(
        rect,
        0.14f,
        0.28f,
        0.38f,
        0.84f
    );

    DrawText(
        rect.left + 14,
        rect.top - 18,
        label,
        0.78f,
        0.86f,
        0.94f
    );

    DrawText(
        rect.left + 14,
        rect.top - 47,
        value,
        0.06f,
        0.55f,
        1.00f
    );

    if (!subLabel.empty())
    {
        DrawText(
            rect.left + 14,
            rect.top - 68,
            subLabel,
            0.90f,
            0.95f,
            1.00f
        );
    }

    int knobX =
        rect.right - 78;

    int knobY =
        rect.top - 48;

    DrawCircleOutline(
        knobX,
        knobY,
        18,
        0.82f,
        0.88f,
        0.92f,
        0.94f
    );

    DrawCircleOutline(
        knobX,
        knobY,
        15,
        0.05f,
        0.09f,
        0.12f,
        0.82f
    );

    DrawLine(
        knobX + 8,
        knobY - 10,
        knobX + 13,
        knobY - 15,
        0.82f,
        0.88f,
        0.92f,
        0.92f
    );

    DrawCompactGreenButton(
        { rect.right - 47, rect.top - 24, rect.right - 14, rect.top - 49 },
        "RX"
    );

    DrawCompactGreenButton(
        { rect.right - 47, rect.top - 54, rect.right - 14, rect.top - 79 },
        "TX"
    );
}


std::string GetTransponderModeLabel(int mode)
{
    if (mode == 1)
    {
        return "STBY";
    }

    if (mode == 2 || mode == 3)
    {
        return "ON";
    }

    if (mode == 4)
    {
        return "IDENT";
    }

    return "OFF";
}


int TransponderIdentCommandHandler(
    XPLMCommandRef inCommand,
    XPLMCommandPhase inPhase,
    void* inRefcon
)
{
    if (inPhase == xplm_CommandBegin)
    {
        gTransponderIdentUntil =
            XPLMGetElapsedTime() + 8.0f;
    }

    return 1;
}


void DrawCompactTransponderMode(
    const CustomRect& rect,
    const std::string& label,
    bool active
)
{
    DrawFilledRect(
        rect,
        0.035f,
        active ? 0.18f : 0.07f,
        active ? 0.42f : 0.09f,
        active ? 0.98f : 0.72f
    );

    DrawRectOutline(
        rect,
        active ? 0.16f : 0.13f,
        active ? 0.48f : 0.27f,
        active ? 0.92f : 0.38f,
        0.88f
    );

    DrawText(
        rect.left + 8,
        rect.top - 15,
        label,
        active ? 0.94f : 0.74f,
        active ? 0.98f : 0.84f,
        1.00f
    );
}


void DrawCompactTransponderPanel(
    const CustomRect& rect,
    int code,
    int mode
)
{
    DrawFilledRect(
        rect,
        0.015f,
        0.040f,
        0.065f,
        1.00f
    );

    DrawRectOutline(
        rect,
        0.14f,
        0.28f,
        0.38f,
        0.84f
    );

    DrawText(
        rect.left + 14,
        rect.top - 18,
        "XPDR",
        0.78f,
        0.86f,
        0.94f
    );

    DrawText(
        rect.left + 14,
        rect.top - 48,
        FormatTransponderCode(code),
        0.06f,
        0.55f,
        1.00f
    );

    std::string activeMode =
        GetTransponderModeLabel(mode);

    if (XPLMGetElapsedTime() < gTransponderIdentUntil)
    {
        activeMode =
            "IDENT";
    }

    int modeTop =
        rect.bottom + 32;

    DrawCompactTransponderMode(
        { rect.left + 14, modeTop, rect.left + 61, modeTop - 22 },
        "STBY",
        activeMode == "STBY"
    );

    DrawCompactTransponderMode(
        { rect.left + 70, modeTop, rect.left + 109, modeTop - 22 },
        "ON",
        activeMode == "ON"
    );

    DrawCompactTransponderMode(
        { rect.left + 118, modeTop, rect.left + 176, modeTop - 22 },
        "IDENT",
        activeMode == "IDENT"
    );
}


void DrawCompactWindow(
    XPLMWindowID inWindowID,
    void* inRefcon
)
{
    int left;
    int top;
    int right;
    int bottom;

    XPLMGetWindowGeometry(
        inWindowID,
        &left,
        &top,
        &right,
        &bottom
    );

    XPLMSetGraphicsState(
        0,
        0,
        0,
        0,
        1,
        0,
        0
    );

    XPLMDrawTranslucentDarkBox(
        left,
        top,
        right,
        bottom
    );

    DrawFilledRect({ left, top, right, bottom }, 0.015f, 0.040f, 0.065f, 1.00f);
    DrawRectOutline({ left, top, right, bottom }, 0.28f, 0.48f, 0.60f, 0.95f);
    DrawFilledRect({ left + 1, top - 36, right - 1, top - 1 }, 0.018f, 0.075f, 0.115f, 1.00f);
    DrawFilledRect({ left + 3, top - 38, right - 3, top - 36 }, 0.10f, 0.45f, 0.85f, 0.86f);

    DrawCompactHeaderLogo(left, top);

    DrawText(left + 76, top - 18, "Network Pilot Client", 0.94f, 0.97f, 1.00f);
    DrawText(right - 234, top - 18, gCurrentCallsign.empty() ? "VFN" : gCurrentCallsign, 0.94f, 0.97f, 1.00f);
    DrawText(right - 104, top - 18, "ONLINE", 0.24f, 0.92f, 0.25f);

    DrawRectOutline(GetCompactCloseRect(left, top, right), 0.18f, 0.38f, 0.52f, 0.85f);
    DrawText(right - 22, top - 21, "X", 0.72f, 0.80f, 0.88f);

    int com1 = gCom1 ? XPLMGetDatai(gCom1) : 0;
    int com2 = gCom2 ? XPLMGetDatai(gCom2) : 0;
    int transponder = gTransponder ? XPLMGetDatai(gTransponder) : 0;
    int transponderMode = gTransponderMode ? XPLMGetDatai(gTransponderMode) : 0;

    std::string com1Frequency =
        FormatComFrequency(com1);

    std::string com2Frequency =
        FormatComFrequency(com2);

    DrawCompactRadioPanel({ left + 12, top - 50, left + 255, top - 132 }, "COM 1", com1Frequency, GetCompactComSubLabel(com1Frequency));
    DrawCompactRadioPanel({ left + 12, top - 140, left + 255, top - 222 }, "COM 2", com2Frequency, GetCompactComSubLabel(com2Frequency));
    DrawCompactTransponderPanel({ left + 12, top - 230, left + 255, top - 300 }, transponder, transponderMode);

    CustomRect chatRect = { left + 270, top - 50, right - 12, top - 300 };
    DrawFilledRect(chatRect, 0.015f, 0.040f, 0.065f, 1.00f);
    DrawRectOutline(chatRect, 0.14f, 0.28f, 0.38f, 0.84f);
    DrawText(chatRect.left + 14, chatRect.top - 20, "CHAT", 0.88f, 0.94f, 1.00f);

    struct ChatDisplayRow
    {
        const ChatLine* line;
        std::string timeText;
        std::string senderText;
        std::string messageText;
        bool firstRow;
    };

    std::vector<ChatDisplayRow> displayRows;
    int timeTextLeft =
        chatRect.left + 14;
    int senderTextLeft =
        chatRect.left + 58;
    int messageTextLeft =
        chatRect.left + 116;
    int messageTextWidth =
        chatRect.right - messageTextLeft - 20;

    for (const ChatLine& line : gChatLines)
    {
        std::vector<std::string> wrappedRows =
            WrapTextForWidth(
                GetLocalizedChatText(line),
                messageTextWidth
            );

        for (size_t rowIndex = 0; rowIndex < wrappedRows.size(); rowIndex++)
        {
            displayRows.push_back(
                {
                    &line,
                    rowIndex == 0 ? line.timestamp : "",
                    rowIndex == 0 ? TruncateForField(line.sender + ":", 11) : "",
                    wrappedRows[rowIndex],
                    rowIndex == 0
                }
            );
        }
    }

    const int chatLineHeight = 18;
    const int visibleChatLines =
        (std::max)(1, (chatRect.top - chatRect.bottom - 110) / chatLineHeight);
    const int totalChatRows =
        (int)displayRows.size();
    const int maxChatScrollOffset =
        (std::max)(0, totalChatRows - visibleChatLines);

    gChatScrollOffset =
        (std::max)(0, (std::min)(gChatScrollOffset, maxChatScrollOffset));

    int visibleEnd =
        (std::max)(0, totalChatRows - gChatScrollOffset);
    int visibleStart =
        (std::max)(0, visibleEnd - visibleChatLines);
    int messageY =
        chatRect.top - 52;

    for (int rowIndex = visibleStart; rowIndex < visibleEnd; ++rowIndex)
    {
        const ChatDisplayRow& row =
            displayRows[rowIndex];
        const ChatLine& line =
            *row.line;

        float senderRed = 0.05f;
        float senderGreen = 0.50f;
        float senderBlue = 1.00f;

        if (line.type == "award")
        {
            senderRed = 1.00f;
            senderGreen = 0.78f;
            senderBlue = 0.16f;
        }
        else if (line.type == "landing")
        {
            senderRed = 0.24f;
            senderGreen = 0.92f;
            senderBlue = 0.25f;
        }
        else if (line.sender == gCurrentCallsign)
        {
            senderRed = 0.55f;
            senderGreen = 0.78f;
            senderBlue = 1.00f;
        }

        DrawText(
            timeTextLeft,
            messageY,
            row.timeText,
            0.46f,
            0.58f,
            0.68f
        );

        DrawText(
            senderTextLeft,
            messageY,
            row.senderText,
            senderRed,
            senderGreen,
            senderBlue
        );

        DrawText(
            messageTextLeft,
            messageY,
            row.messageText,
            0.72f,
            0.80f,
            0.88f
        );

        messageY -= chatLineHeight;
    }

    if (maxChatScrollOffset > 0)
    {
        CustomRect scrollTrack =
            { chatRect.right - 10, chatRect.bottom + 58, chatRect.right - 6, chatRect.top - 36 };

        DrawFilledRect(scrollTrack, 0.05f, 0.10f, 0.14f, 0.92f);

        float visibleRatio =
            (float)visibleChatLines / (float)totalChatRows;
        int trackHeight =
            scrollTrack.top - scrollTrack.bottom;
        int thumbHeight =
            (std::max)(18, (int)(trackHeight * visibleRatio));
        int scrollableTrack =
            (std::max)(1, trackHeight - thumbHeight);
        int thumbTop =
            scrollTrack.top - (int)((float)gChatScrollOffset / (float)maxChatScrollOffset * scrollableTrack);

        CustomRect scrollThumb =
            { scrollTrack.left, thumbTop - thumbHeight, scrollTrack.right, thumbTop };

        DrawFilledRect(scrollThumb, 0.10f, 0.45f, 0.85f, 0.95f);

        if (gChatScrollOffset > 0)
        {
            DrawText(chatRect.right - 56, chatRect.top - 20, "OLDER", 0.45f, 0.66f, 0.82f);
        }
    }

    CustomRect inputRect =
        GetCompactChatInputRect(chatRect);

    DrawFilledRect(inputRect, 0.090f, 0.105f, 0.122f, 0.98f);
    DrawRectOutline(
        inputRect,
        gChatInputFocused ? 0.05f : 0.13f,
        gChatInputFocused ? 0.50f : 0.27f,
        gChatInputFocused ? 1.00f : 0.38f,
        0.84f
    );

    DrawText(
        inputRect.left + 12,
        inputRect.bottom + ((inputRect.top - inputRect.bottom) / 2) - 5,
        gChatInputText.empty()
            ? (gChatInputFocused ? "|" : "Type your message...")
            : TruncateForWidthFromEnd(
                gChatInputText + (gChatInputFocused ? "|" : ""),
                inputRect.right - inputRect.left - 24
            ),
        gChatInputText.empty() ? 0.45f : 0.86f,
        gChatInputText.empty() ? 0.56f : 0.92f,
        gChatInputText.empty() ? 0.66f : 1.00f
    );

    DrawCustomLoginButton(GetCompactChatSendRect(chatRect), T("button.send"), true);

    DrawCompactTab(GetCompactTabRect(left, top, 0), "ATC", false);
    DrawCompactTab(GetCompactTabRect(left, top, 1), "MSG", false);
    DrawCompactTab(GetCompactTabRect(left, top, 2), "FP", false);
    DrawCompactTab(GetCompactTabRect(left, top, 3), "D-ATIS", false);
    DrawCompactTab(GetCompactTabRect(left, top, 4), "SETTINGS", false);
}


CustomRect GetLogoutConfirmYesRect(int left, int top)
{
    return { left + 28, top - 122, left + 126, top - 156 };
}


CustomRect GetLogoutConfirmNoRect(int left, int top)
{
    return { left + 142, top - 122, left + 240, top - 156 };
}


void DrawLogoutConfirmWindow(
    XPLMWindowID inWindowID,
    void* inRefcon
)
{
    int left;
    int top;
    int right;
    int bottom;

    XPLMGetWindowGeometry(
        inWindowID,
        &left,
        &top,
        &right,
        &bottom
    );

    XPLMSetGraphicsState(
        0,
        0,
        0,
        0,
        1,
        0,
        0
    );

    XPLMDrawTranslucentDarkBox(
        left,
        top,
        right,
        bottom
    );

    DrawFilledRect(
        { left, top, right, bottom },
        0.015f,
        0.040f,
        0.065f,
        1.00f
    );

    DrawRectOutline(
        { left, top, right, bottom },
        0.28f,
        0.48f,
        0.60f,
        0.95f
    );

    DrawFilledRect(
        { left + 1, top - 34, right - 1, top - 1 },
        0.018f,
        0.075f,
        0.115f,
        1.00f
    );

    DrawText(
        left + 22,
        top - 20,
        "Logout",
        0.94f,
        0.97f,
        1.00f
    );

    DrawText(
        left + 28,
        top - 70,
        "Really logout from VFN?",
        0.82f,
        0.88f,
        0.95f
    );

    DrawText(
        left + 28,
        top - 94,
        gCurrentCallsign.empty() ? "" : gCurrentCallsign,
        0.24f,
        0.92f,
        0.25f
    );

    DrawCustomLoginButton(
        GetLogoutConfirmYesRect(left, top),
        "YES",
        true
    );

    DrawCustomLoginButton(
        GetLogoutConfirmNoRect(left, top),
        "NO",
        false
    );
}


int LogoutConfirmHandleMouse(
    XPLMWindowID inWindowID,
    int x,
    int y,
    XPLMMouseStatus inMouse,
    void* inRefcon
)
{
    if (inMouse != xplm_MouseDown)
    {
        return 1;
    }

    int left;
    int top;
    int right;
    int bottom;

    XPLMGetWindowGeometry(
        inWindowID,
        &left,
        &top,
        &right,
        &bottom
    );

    if (PointInRect(x, y, GetLogoutConfirmYesRect(left, top)))
    {
        XPLMSetWindowIsVisible(
            inWindowID,
            0
        );

        DoLogout();
        return 1;
    }

    if (PointInRect(x, y, GetLogoutConfirmNoRect(left, top)))
    {
        XPLMSetWindowIsVisible(
            inWindowID,
            0
        );

        return 1;
    }

    return 1;
}


int LogoutConfirmHandleCursor(
    XPLMWindowID inWindowID,
    int x,
    int y,
    void* inRefcon
)
{
    return xplm_CursorDefault;
}


int LogoutConfirmHandleMouseWheel(
    XPLMWindowID inWindowID,
    int x,
    int y,
    int wheel,
    int clicks,
    void* inRefcon
)
{
    return 0;
}


void ShowLogoutConfirmWindow()
{
    int confirmLeft = 300;
    int confirmTop = 620;
    int confirmWidth = 268;
    int confirmHeight = 170;

    if (gCompactWindow != nullptr)
    {
        int compactLeft;
        int compactTop;
        int compactRight;
        int compactBottom;

        XPLMGetWindowGeometry(
            gCompactWindow,
            &compactLeft,
            &compactTop,
            &compactRight,
            &compactBottom
        );

        confirmLeft =
            compactLeft +
            ((compactRight - compactLeft - confirmWidth) / 2);

        confirmTop =
            compactTop - 70;
    }

    if (gLogoutConfirmWindow == nullptr)
    {
        XPLMCreateWindow_t params = {};
        params.structSize = sizeof(params);
        params.left = confirmLeft;
        params.top = confirmTop;
        params.right = confirmLeft + confirmWidth;
        params.bottom = confirmTop - confirmHeight;
        params.visible = 0;
        params.drawWindowFunc = DrawLogoutConfirmWindow;
        params.handleMouseClickFunc = LogoutConfirmHandleMouse;
        params.handleKeyFunc = nullptr;
        params.handleCursorFunc = LogoutConfirmHandleCursor;
        params.handleMouseWheelFunc = LogoutConfirmHandleMouseWheel;
        params.refcon = nullptr;
        params.decorateAsFloatingWindow =
            xplm_WindowDecorationRoundRectangle;
        params.layer =
            xplm_WindowLayerFloatingWindows;
        params.handleRightClickFunc = LogoutConfirmHandleMouse;

        gLogoutConfirmWindow =
            XPLMCreateWindowEx(
                &params
            );

        if (gLogoutConfirmWindow != nullptr)
        {
            XPLMSetWindowTitle(
                gLogoutConfirmWindow,
                "Confirm Logout"
            );

            XPLMSetWindowResizingLimits(
                gLogoutConfirmWindow,
                268,
                170,
                268,
                170
            );
        }
    }

    if (gLogoutConfirmWindow == nullptr)
    {
        DoLogout();
        return;
    }

    XPLMSetWindowGeometry(
        gLogoutConfirmWindow,
        confirmLeft,
        confirmTop,
        confirmLeft + confirmWidth,
        confirmTop - confirmHeight
    );

    XPLMSetWindowIsVisible(
        gLogoutConfirmWindow,
        1
    );

    XPLMBringWindowToFront(
        gLogoutConfirmWindow
    );
}


void CompactHandleKey(
    XPLMWindowID inWindowID,
    char inKey,
    XPLMKeyFlags inFlags,
    char inVirtualKey,
    void* inRefcon,
    int losingFocus
)
{
    if (losingFocus)
    {
        gChatInputFocused = false;
        return;
    }

    HandleChatKeyInput(
        inKey,
        inFlags,
        inVirtualKey
    );
}


bool HandleChatKeyInput(
    char inKey,
    XPLMKeyFlags inFlags,
    char inVirtualKey
)
{
    if (!gChatInputFocused)
    {
        return false;
    }

    if ((inFlags & xplm_UpFlag) != 0)
    {
        return false;
    }

    float now =
        XPLMGetElapsedTime();

    bool repeatedKeyEvent =
        inKey == gLastCompactKey &&
        inVirtualKey == gLastCompactVirtualKey &&
        now - gLastCompactKeyTime < 0.06f;

    if (repeatedKeyEvent)
    {
        return true;
    }

    gLastCompactKey =
        inKey;

    gLastCompactVirtualKey =
        inVirtualKey;

    gLastCompactKeyTime =
        now;

    if (inVirtualKey == 8 || inKey == 8)
    {
        if (!gChatInputText.empty())
        {
            gChatInputText.pop_back();
        }

        return true;
    }

    if (inVirtualKey == 13 || inKey == 13)
    {
        SendChatMessage();
        return true;
    }

    if (inVirtualKey == 27 || inKey == 27)
    {
        gChatInputFocused = false;
        XPLMTakeKeyboardFocus(
            nullptr
        );
        return true;
    }

    if (
        inKey >= 32 &&
        inKey <= 126 &&
        gChatInputText.size() < 180
    ) {
        gChatInputText.push_back(inKey);
        return true;
    }

    return false;
}


int ChatKeySniffer(
    char inChar,
    XPLMKeyFlags inFlags,
    char inVirtualKey,
    void* inRefcon
)
{
    if (
        !gChatInputFocused ||
        gCompactWindow == nullptr ||
        !XPLMGetWindowIsVisible(gCompactWindow)
    ) {
        return 1;
    }

    return HandleChatKeyInput(
        inChar,
        inFlags,
        inVirtualKey
    ) ? 0 : 1;
}


char GetWindowsChatCharacter(int virtualKey)
{
    bool shiftDown =
        (GetAsyncKeyState(VK_SHIFT) & 0x8000) != 0;

    if (virtualKey >= 'A' && virtualKey <= 'Z')
    {
        char value =
            static_cast<char>(virtualKey);

        return shiftDown
            ? value
            : static_cast<char>(std::tolower(value));
    }

    if (virtualKey >= '0' && virtualKey <= '9')
    {
        if (!shiftDown)
        {
            return static_cast<char>(virtualKey);
        }

        const char shiftedDigits[] =
            ")!@#$%^&*(";

        return shiftedDigits[virtualKey - '0'];
    }

    switch (virtualKey)
    {
    case VK_SPACE:
        return ' ';

    case VK_OEM_PERIOD:
        return shiftDown ? '>' : '.';

    case VK_OEM_COMMA:
        return shiftDown ? '<' : ',';

    case VK_OEM_MINUS:
        return shiftDown ? '_' : '-';

    case VK_OEM_PLUS:
        return shiftDown ? '+' : '=';

    case VK_OEM_1:
        return shiftDown ? ':' : ';';

    case VK_OEM_2:
        return shiftDown ? '?' : '/';

    case VK_OEM_3:
        return shiftDown ? '~' : '`';

    case VK_OEM_4:
        return shiftDown ? '{' : '[';

    case VK_OEM_5:
        return shiftDown ? '|' : '\\';

    case VK_OEM_6:
        return shiftDown ? '}' : ']';

    case VK_OEM_7:
        return shiftDown ? '"' : '\'';

    default:
        return 0;
    }
}


void PollWindowsChatKeyboard()
{
    if (
        !gLoggedIn ||
        !gChatInputFocused ||
        gCompactWindow == nullptr ||
        !XPLMGetWindowIsVisible(gCompactWindow)
    ) {
        return;
    }

    for (int virtualKey = 0; virtualKey < 256; virtualKey++)
    {
        bool isDown =
            (GetAsyncKeyState(virtualKey) & 0x8000) != 0;

        if (!isDown)
        {
            gWindowsChatKeyDown[virtualKey] = false;
            continue;
        }

        if (gWindowsChatKeyDown[virtualKey])
        {
            continue;
        }

        gWindowsChatKeyDown[virtualKey] = true;

        if (virtualKey == VK_BACK)
        {
            if (!gChatInputText.empty())
            {
                gChatInputText.pop_back();
            }

            continue;
        }

        if (virtualKey == VK_RETURN)
        {
            SendChatMessage();
            continue;
        }

        if (virtualKey == VK_ESCAPE)
        {
            gChatInputFocused = false;
            XPLMTakeKeyboardFocus(
                nullptr
            );
            continue;
        }

        char character =
            GetWindowsChatCharacter(virtualKey);

        if (
            character >= 32 &&
            character <= 126 &&
            gChatInputText.size() < 180
        ) {
            gChatInputText.push_back(character);
        }
    }
}


void PollCompactChatMouseFocus()
{
    if (
        !gLoggedIn ||
        gCompactWindow == nullptr ||
        !XPLMGetWindowIsVisible(gCompactWindow)
    ) {
        gWindowsChatMouseDown = false;
        return;
    }

    bool mouseDown =
        (GetAsyncKeyState(VK_LBUTTON) & 0x8000) != 0;

    bool mousePressed =
        mouseDown && !gWindowsChatMouseDown;

    bool mouseReleased =
        !mouseDown && gWindowsChatMouseDown;

    int left = 0;
    int top = 0;
    int right = 0;
    int bottom = 0;

    int mouseX = 0;
    int mouseY = 0;
    bool mouseInCompactWindow = false;
    bool mouseInSendOsRect = false;

    XPLMGetWindowGeometry(
        gCompactWindow,
        &left,
        &top,
        &right,
        &bottom
    );

    if (XPLMWindowIsPoppedOut(gCompactWindow))
    {
        POINT cursorPoint = {};

        GetCursorPos(
            &cursorPoint
        );

        int osLeft = 0;
        int osTop = 0;
        int osRight = 0;
        int osBottom = 0;

        XPLMGetWindowGeometryOS(
            gCompactWindow,
            &osLeft,
            &osTop,
            &osRight,
            &osBottom
        );

        int osMinX =
            (std::min)(osLeft, osRight);
        int osMaxX =
            (std::max)(osLeft, osRight);
        int osMinY =
            (std::min)(osTop, osBottom);
        int osMaxY =
            (std::max)(osTop, osBottom);

        int osWidth =
            (std::max)(1, osMaxX - osMinX);
        int osHeight =
            (std::max)(1, osMaxY - osMinY);

        mouseInCompactWindow =
            cursorPoint.x >= osMinX &&
            cursorPoint.x <= osMaxX &&
            cursorPoint.y >= osMinY &&
            cursorPoint.y <= osMaxY;

        int windowWidth =
            (std::max)(1, right - left);
        int windowHeight =
            (std::max)(1, top - bottom);
        CustomRect chatRectForOs =
            { left + 270, top - 50, right - 12, top - 300 };
        CustomRect sendRectForOs =
            GetCompactChatSendRect(chatRectForOs);

        int sendLocalLeft =
            sendRectForOs.left - left;
        int sendLocalRight =
            sendRectForOs.right - left;
        int sendLocalTop =
            top - sendRectForOs.top;
        int sendLocalBottom =
            top - sendRectForOs.bottom;

        int sendOsLeft =
            osMinX + (sendLocalLeft * osWidth / windowWidth) - 20;
        int sendOsRight =
            osMinX + (sendLocalRight * osWidth / windowWidth) + 20;
        int sendOsTop =
            osMinY + (sendLocalTop * osHeight / windowHeight) - 18;
        int sendOsBottom =
            osMinY + (sendLocalBottom * osHeight / windowHeight) + 18;

        mouseInSendOsRect =
            (
                cursorPoint.x >= sendOsLeft &&
                cursorPoint.x <= sendOsRight &&
                cursorPoint.y >= sendOsTop &&
                cursorPoint.y <= sendOsBottom
            ) ||
            (
                cursorPoint.x >= osMaxX - 250 &&
                cursorPoint.x <= osMaxX - 10 &&
                cursorPoint.y >= osMaxY - 170 &&
                cursorPoint.y <= osMaxY - 45
            );

        float relativeX =
            (float)(cursorPoint.x - osMinX) / (float)osWidth;
        float relativeYFromTop =
            (float)(cursorPoint.y - osMinY) / (float)osHeight;

        relativeX =
            (std::max)(0.0f, (std::min)(1.0f, relativeX));
        relativeYFromTop =
            (std::max)(0.0f, (std::min)(1.0f, relativeYFromTop));

        mouseX =
            left + (int)(relativeX * (float)(right - left));

        mouseY =
            top - (int)(relativeYFromTop * (float)(top - bottom));
    }
    else
    {
        XPLMGetMouseLocationGlobal(
            &mouseX,
            &mouseY
        );

        mouseInCompactWindow =
            PointInRect(mouseX, mouseY, { left, top, right, bottom });
    }

    CustomRect chatRect =
        { left + 270, top - 50, right - 12, top - 300 };
    CustomRect chatFocusRect =
        GetCompactChatFocusRect(chatRect);

    bool mouseInChatFocus =
        PointInRect(mouseX, mouseY, chatFocusRect);
    bool mouseInSend =
        mouseInSendOsRect ||
        PointInCompactChatSendArea(mouseX, mouseY, left, top, right, bottom);

    if (mouseDown)
    {
        if (!mouseInCompactWindow)
        {
            if (gChatInputFocused)
            {
                gChatInputFocused = false;
                gChatSendButtonPressed = false;

                XPLMTakeKeyboardFocus(
                    nullptr
                );
            }

            gWindowsChatMouseDown =
                mouseDown;

            return;
        }

        if (mouseInSend && mousePressed)
        {
            gChatSendButtonPressed = false;

            XPLMDebugString(
                "Flight Radar Plugin: Chat send button clicked by mouse poll.\n"
            );

            XPLMBringWindowToFront(
                gCompactWindow
            );

            SendChatMessage();

            XPLMTakeKeyboardFocus(
                nullptr
            );

            gWindowsChatMouseDown =
                mouseDown;

            return;
        }

        if (mouseInChatFocus || mouseInCompactWindow)
        {
            gChatInputFocused = true;
            gChatSendButtonPressed = false;

            XPLMBringWindowToFront(
                gCompactWindow
            );

            XPLMTakeKeyboardFocus(
                gCompactWindow
            );

            gWindowsChatMouseDown =
                mouseDown;

            return;
        }

        if (gChatInputFocused)
        {
            gChatInputFocused = false;
            gChatSendButtonPressed = false;

            XPLMTakeKeyboardFocus(
                nullptr
            );
        }

        return;
    }

    if (mouseReleased)
    {
        gChatSendButtonPressed = false;
    }

    gWindowsChatMouseDown =
        mouseDown;
}


int CompactHandleMouse(
    XPLMWindowID inWindowID,
    int x,
    int y,
    XPLMMouseStatus inMouse,
    void* inRefcon
)
{
    int left;
    int top;
    int right;
    int bottom;

    XPLMGetWindowGeometry(
        inWindowID,
        &left,
        &top,
        &right,
        &bottom
    );

    if (inMouse == xplm_MouseDown)
    {
        if (
            PointInWindowRect(x, y, GetCompactCloseRect(left, top, right), left, top, bottom) ||
            (
                x >= right - 82 &&
                x <= right &&
                y <= top &&
                y >= top - 46
            )
        )
        {
            ShowLogoutConfirmWindow();
            return 1;
        }

        CustomRect chatRect =
            { left + 270, top - 50, right - 12, top - 300 };
        CustomRect chatSendRect =
            GetCompactChatSendRect(chatRect);
        CustomRect chatSendHitRect =
            {
                chatSendRect.left - 8,
                chatSendRect.top + 8,
                chatSendRect.right + 8,
                chatSendRect.bottom - 8
            };

        if (gFlightplanWindow != nullptr && PointInWindowRect(x, y, GetCompactTabRect(left, top, 2), left, top, bottom))
        {
            gChatInputFocused = false;
            gChatSendButtonPressed = false;

            XPLMTakeKeyboardFocus(
                nullptr
            );

            if (XPIsWidgetVisible(gFlightplanWindow))
            {
                XPHideWidget(gFlightplanWindow);
            }
            else
            {
                XPShowWidget(gFlightplanWindow);
                XPBringRootWidgetToFront(gFlightplanWindow);
                UpdateFlightplanWindowState();
            }

            return 1;
        }

        if (
            PointInRect(x, y, chatSendRect) ||
            PointInRect(x, y, chatSendHitRect) ||
            PointInWindowRect(x, y, chatSendRect, left, top, bottom) ||
            PointInWindowRect(x, y, chatSendHitRect, left, top, bottom) ||
            PointInCompactChatSendArea(x, y, left, top, right, bottom)
        )
        {
            gChatSendButtonPressed = false;

            XPLMDebugString(
                "Flight Radar Plugin: Chat send button clicked.\n"
            );

            XPLMBringWindowToFront(
                inWindowID
            );

            SendChatMessage();

            XPLMTakeKeyboardFocus(
                nullptr
            );

            return 1;
        }

        gChatInputFocused = true;
        gChatSendButtonPressed = false;

        XPLMBringWindowToFront(
            inWindowID
        );

        XPLMTakeKeyboardFocus(
            inWindowID
        );

        if (gDebugEnabled)
        {
            XPLMDebugString(
                "Flight Radar Plugin: Compact window focused chat input.\n"
            );
        }

        if (y >= top - 38)
        {
            gCompactWindowDragging = true;
            gCompactWindowDragOffsetX = x - left;
            gCompactWindowDragOffsetY = top - y;
            return 1;
        }
    }
    else if (inMouse == xplm_MouseDrag && gCompactWindowDragging)
    {
        int width = right - left;
        int height = top - bottom;
        int newLeft = x - gCompactWindowDragOffsetX;
        int newTop = y + gCompactWindowDragOffsetY;

        XPLMSetWindowGeometry(
            inWindowID,
            newLeft,
            newTop,
            newLeft + width,
            newTop - height
        );

        return 1;
    }
    else if (inMouse == xplm_MouseUp)
    {
        CustomRect chatRect =
            { left + 270, top - 50, right - 12, top - 300 };

        gCompactWindowDragging = false;
        gChatSendButtonPressed = false;
        return 1;
    }

    return 1;
}


int CompactHandleCursor(
    XPLMWindowID inWindowID,
    int x,
    int y,
    void* inRefcon
)
{
    return xplm_CursorDefault;
}


int CompactHandleMouseWheel(
    XPLMWindowID inWindowID,
    int x,
    int y,
    int wheel,
    int clicks,
    void* inRefcon
)
{
    int left;
    int top;
    int right;
    int bottom;

    XPLMGetWindowGeometry(
        inWindowID,
        &left,
        &top,
        &right,
        &bottom
    );

    CustomRect chatRect =
        { left + 270, top - 50, right - 12, top - 300 };

    if (!PointInWindowRect(x, y, chatRect, left, top, bottom))
    {
        return 0;
    }

    const int chatLineHeight =
        18;
    const int visibleChatLines =
        (std::max)(1, (chatRect.top - chatRect.bottom - 110) / chatLineHeight);
    const int maxChatScrollOffset =
        (std::max)(0, CountWrappedChatRows(chatRect) - visibleChatLines);

    if (maxChatScrollOffset <= 0)
    {
        return 1;
    }

    gChatScrollOffset =
        (std::max)(
            0,
            (std::min)(
                maxChatScrollOffset,
                gChatScrollOffset + clicks
            )
        );

    return 1;
}


void AppendToFocusedLoginField(
    char value
)
{
    std::string* target =
        nullptr;

    size_t maxLength =
        64;

    if (gCustomLoginFocusedField == CustomLoginFieldUsername)
    {
        target =
            &gLoginUsernameText;
    }
    else if (gCustomLoginFocusedField == CustomLoginFieldPassword)
    {
        target =
            &gLoginPasswordText;
    }
    else if (gCustomLoginFocusedField == CustomLoginFieldCallsign)
    {
        target =
            &gLoginCallsignText;
        maxLength =
            16;
    }

    if (target == nullptr)
    {
        return;
    }

    if (target->size() >= maxLength)
    {
        return;
    }

    target->push_back(value);
}


void BackspaceFocusedLoginField()
{
    std::string* target =
        nullptr;

    if (gCustomLoginFocusedField == CustomLoginFieldUsername)
    {
        target =
            &gLoginUsernameText;
    }
    else if (gCustomLoginFocusedField == CustomLoginFieldPassword)
    {
        target =
            &gLoginPasswordText;
    }
    else if (gCustomLoginFocusedField == CustomLoginFieldCallsign)
    {
        target =
            &gLoginCallsignText;
    }

    if (target == nullptr || target->empty())
    {
        return;
    }

    target->pop_back();
}


void FocusNextCustomLoginField()
{
    if (gCustomLoginFocusedField == CustomLoginFieldUsername)
    {
        gCustomLoginFocusedField =
            CustomLoginFieldPassword;
    }
    else if (gCustomLoginFocusedField == CustomLoginFieldPassword)
    {
        gCustomLoginFocusedField =
            CustomLoginFieldCallsign;
    }
    else
    {
        gCustomLoginFocusedField =
            CustomLoginFieldUsername;
    }
}


void CustomLoginHandleKey(
    XPLMWindowID inWindowID,
    char inKey,
    XPLMKeyFlags inFlags,
    char inVirtualKey,
    void* inRefcon,
    int losingFocus
)
{
    if (losingFocus)
    {
        return;
    }

    if ((inFlags & xplm_UpFlag) != 0)
    {
        return;
    }

    float now =
        XPLMGetElapsedTime();

    bool repeatedKeyEvent =
        inKey == gLastLoginKey &&
        inVirtualKey == gLastLoginVirtualKey &&
        now - gLastLoginKeyTime < 0.06f;

    if (repeatedKeyEvent)
    {
        return;
    }

    gLastLoginKey =
        inKey;

    gLastLoginVirtualKey =
        inVirtualKey;

    gLastLoginKeyTime =
        now;

    if (inVirtualKey == 8 || inKey == 8)
    {
        BackspaceFocusedLoginField();
        return;
    }

    if (inVirtualKey == 9 || inKey == 9)
    {
        FocusNextCustomLoginField();
        return;
    }

    if (inVirtualKey == 13 || inKey == 13)
    {
        PerformCustomLogin();
        return;
    }

    if (inKey >= 32 && inKey <= 126)
    {
        AppendToFocusedLoginField(
            inKey
        );
    }
}


int CustomLoginHandleMouse(
    XPLMWindowID inWindowID,
    int x,
    int y,
    XPLMMouseStatus inMouse,
    void* inRefcon
)
{
    int left;
    int top;
    int right;
    int bottom;

    XPLMGetWindowGeometry(
        inWindowID,
        &left,
        &top,
        &right,
        &bottom
    );

    if (inMouse == xplm_MouseDown)
    {
        if (PointInRect(x, y, GetCustomLoginCloseRect(left, top, right)))
        {
            XPLMSetWindowIsVisible(
                inWindowID,
                0
            );

            return 1;
        }

        if (PointInRect(x, y, GetCustomLoginUsernameRect(left, top)))
        {
            gCustomLoginFocusedField =
                CustomLoginFieldUsername;

            XPLMTakeKeyboardFocus(
                inWindowID
            );

            return 1;
        }

        if (PointInRect(x, y, GetCustomLoginPasswordRect(left, top)))
        {
            gCustomLoginFocusedField =
                CustomLoginFieldPassword;

            XPLMTakeKeyboardFocus(
                inWindowID
            );

            return 1;
        }

        if (PointInRect(x, y, GetCustomLoginCallsignRect(left, top)))
        {
            gCustomLoginFocusedField =
                CustomLoginFieldCallsign;

            XPLMTakeKeyboardFocus(
                inWindowID
            );

            return 1;
        }

        if (PointInRect(x, y, GetCustomLoginRememberRect(left, top)))
        {
            gRememberLogin =
                !gRememberLogin;

            if (!gRememberLogin)
            {
                DeleteSavedLoginData();
            }

            return 1;
        }

        if (
            !gLoggedIn &&
            PointInRect(x, y, GetCustomLoginButtonRect(left, top))
        ) {
            PerformCustomLogin();
            return 1;
        }

        if (
            gLoggedIn &&
            PointInRect(x, y, GetCustomLoginLogoutRect(left, top))
        ) {
            DoLogout();
            return 1;
        }

        if (
            gLoggedIn &&
            PointInRect(x, y, GetCustomLoginInvisibleRect(left, top))
        ) {
            ToggleCustomInvisible();
            return 1;
        }

        if (y >= top - 34)
        {
            gCustomLoginDragging = true;
            gCustomLoginDragOffsetX = x - left;
            gCustomLoginDragOffsetY = top - y;
            return 1;
        }
    }
    else if (inMouse == xplm_MouseDrag && gCustomLoginDragging)
    {
        int width =
            right - left;

        int height =
            top - bottom;

        int newLeft =
            x - gCustomLoginDragOffsetX;

        int newTop =
            y + gCustomLoginDragOffsetY;

        XPLMSetWindowGeometry(
            inWindowID,
            newLeft,
            newTop,
            newLeft + width,
            newTop - height
        );

        return 1;
    }
    else if (inMouse == xplm_MouseUp)
    {
        gCustomLoginDragging = false;
        return 1;
    }

    return 1;
}


int CustomLoginHandleCursor(
    XPLMWindowID inWindowID,
    int x,
    int y,
    void* inRefcon
)
{
    return 0;
}


int CustomLoginHandleMouseWheel(
    XPLMWindowID inWindowID,
    int x,
    int y,
    int wheel,
    int clicks,
    void* inRefcon
)
{
    return 1;
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
                gPositionUpdateFailureCount = 0;
                ResetNightFlightTracking();

                gAuthToken =
                    ExtractJsonStringValue(
                        response,
                        "token"
                    );

                gCurrentPilotRatingCode =
                    ExtractJsonStringValue(
                        response,
                        "pilot_rating_code"
                    );

                gCurrentPilotRatingName =
                    ExtractJsonStringValue(
                        response,
                        "pilot_rating_name"
                    );

                gCurrentAtcRatingCode =
                    ExtractJsonStringValue(
                        response,
                        "atc_rating_code"
                    );

                gCurrentAtcRatingName =
                    ExtractJsonStringValue(
                        response,
                        "atc_rating_name"
                    );

                if (gCurrentPilotRatingCode.empty())
                {
                    gCurrentPilotRatingCode = "FC0";
                }

                if (gCurrentPilotRatingName.empty())
                {
                    gCurrentPilotRatingName = "New Flight Cadet";
                }

                if (gCurrentAtcRatingCode.empty())
                {
                    gCurrentAtcRatingCode = "TC0";
                }

                if (gCurrentAtcRatingName.empty())
                {
                    gCurrentAtcRatingName = "New ATC Cadet";
                }

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

                gChatLines.clear();
                gChatInputText = "";
                gChatInputFocused = false;
                gChatSendButtonPressed = false;
                gChatScrollOffset = 0;
                gLastChatMessageId = 0;
                gChatPollElapsed = 999.0f;

                AddLoginChatSummary();

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

                if (gCustomLoginWindow != nullptr)
                {
                    XPLMSetWindowIsVisible(
                        gCustomLoginWindow,
                        0
                    );
                }

                if (gLoginWindow != nullptr)
                {
                    XPHideWidget(
                        gLoginWindow
                    );
                }

                if (gCompactWindow != nullptr)
                {
                    XPLMSetWindowIsVisible(
                        gCompactWindow,
                        1
                    );

                    XPLMBringWindowToFront(
                        gCompactWindow
                    );
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
    int top = 760;
    int right = 455;
    int bottom = 230;

    gLoginWindow =
        XPCreateWidget(
            left,
            top,
            right,
            bottom,
            1,
            "VFN Network Pilot Client",
            1,
            nullptr,
            xpWidgetClass_MainWindow
        );

    XPSetWidgetProperty(
        gLoginWindow,
        xpProperty_MainWindowType,
        xpMainWindowStyle_Translucent
    );

    XPSetWidgetProperty(
        gLoginWindow,
        xpProperty_MainWindowHasCloseBoxes,
        1
    );

    gLoginBrandLabel =
        XPCreateWidget(
            left + 30,
            top - 45,
            right - 30,
            top - 75,
            1,
            "VFN NETWORK",
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gLoginSubtitleLabel =
        XPCreateWidget(
            left + 30,
            top - 72,
            right - 30,
            top - 95,
            1,
            "Pilot Client Login",
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gLoginSectionLabel =
        XPCreateWidget(
            left + 30,
            top - 115,
            right - 30,
            top - 135,
            1,
            "LOGIN",
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gUsernameLabel =
        XPCreateWidget(
            left + 30,
            top - 150,
            right - 30,
            top - 170,
            1,
            T("label.username"),
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gUsernameField =
        XPCreateWidget(
            left + 30,
            top - 170,
            right - 30,
            top - 200,
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
            top - 210,
            right - 30,
            top - 230,
            1,
            T("label.password"),
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gPasswordField =
        XPCreateWidget(
            left + 30,
            top - 230,
            right - 30,
            top - 260,
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
            top - 270,
            right - 30,
            top - 290,
            1,
            T("label.callsign"),
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gCallsignField =
        XPCreateWidget(
            left + 30,
            top - 290,
            right - 30,
            top - 320,
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
            left + 30,
            top - 330,
            right - 30,
            top - 360,
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
            left + 30,
            top - 370,
            right - 30,
            top - 405,
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
            left + 30,
            top - 370,
            left + 185,
            top - 405,
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
            left + 195,
            top - 370,
            right - 30,
            top - 405,
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

    gLoginNetworkLabel =
        XPCreateWidget(
            left + 30,
            top - 415,
            right - 30,
            top - 435,
            1,
            "Network Status",
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gStatusCaption =
        XPCreateWidget(
            left + 30,
            top - 438,
            right - 30,
            top - 460,
            1,
            T("status.not_connected"),
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gLoginPilotsLabel =
        XPCreateWidget(
            left + 30,
            top - 465,
            right - 30,
            top - 485,
            1,
            "Pilots Online: --",
            0,
            gLoginWindow,
            xpWidgetClass_Caption
        );

    gLoginAtcLabel =
        XPCreateWidget(
            left + 30,
            top - 490,
            right - 30,
            top - 510,
            1,
            "ATC Online: --",
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

    XPLMCreateWindow_t customLoginParams = {};
    customLoginParams.structSize = sizeof(customLoginParams);
    customLoginParams.left = 80;
    customLoginParams.top = 700;
    customLoginParams.right = 440;
    customLoginParams.bottom = 310;
    customLoginParams.visible = 0;
    customLoginParams.drawWindowFunc = DrawCustomLoginWindow;
    customLoginParams.handleMouseClickFunc = CustomLoginHandleMouse;
    customLoginParams.handleKeyFunc = CustomLoginHandleKey;
    customLoginParams.handleCursorFunc = CustomLoginHandleCursor;
    customLoginParams.handleMouseWheelFunc = CustomLoginHandleMouseWheel;
    customLoginParams.refcon = nullptr;
    customLoginParams.decorateAsFloatingWindow =
        xplm_WindowDecorationRoundRectangle;
    customLoginParams.layer =
        xplm_WindowLayerFloatingWindows;
    customLoginParams.handleRightClickFunc = CustomLoginHandleMouse;

    gCustomLoginWindow =
        XPLMCreateWindowEx(
            &customLoginParams
        );

    if (gCustomLoginWindow != nullptr)
    {
        XPLMSetWindowTitle(
            gCustomLoginWindow,
            "VFN Network Pilot Client"
        );

        XPLMSetWindowResizingLimits(
            gCustomLoginWindow,
            360,
            390,
            360,
            390
        );
    }

    XPLMCreateWindow_t compactParams = {};
    compactParams.structSize = sizeof(compactParams);
    compactParams.left = 80;
    compactParams.top = 700;
    compactParams.right = 700;
    compactParams.bottom = 320;
    compactParams.visible = 0;
    compactParams.drawWindowFunc = DrawCompactWindow;
    compactParams.handleMouseClickFunc = CompactHandleMouse;
    compactParams.handleKeyFunc = CompactHandleKey;
    compactParams.handleCursorFunc = CompactHandleCursor;
    compactParams.handleMouseWheelFunc = CompactHandleMouseWheel;
    compactParams.refcon = nullptr;
    compactParams.decorateAsFloatingWindow =
        xplm_WindowDecorationRoundRectangle;
    compactParams.layer =
        xplm_WindowLayerFloatingWindows;
    compactParams.handleRightClickFunc = CompactHandleMouse;

    gCompactWindow =
        XPLMCreateWindowEx(
            &compactParams
        );

    if (gCompactWindow != nullptr)
    {
        XPLMSetWindowTitle(
            gCompactWindow,
            "VFN Network Pilot Client"
        );

        XPLMSetWindowResizingLimits(
            gCompactWindow,
            620,
            380,
            620,
            380
        );
    }

    if (gLoginWindow != nullptr)
    {
        XPHideWidget(
            gLoginWindow
        );
    }

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
        XPLMWindowID targetWindow =
            gLoggedIn ? gCompactWindow : gCustomLoginWindow;

        if (targetWindow == nullptr)
        {
            return;
        }

        if (XPLMGetWindowIsVisible(targetWindow))
        {
            XPLMSetWindowIsVisible(
                targetWindow,
                0
            );
        }
        else
        {
            XPLMSetWindowIsVisible(
                targetWindow,
                1
            );

            XPLMBringWindowToFront(
                targetWindow
            );

            gChatInputFocused = false;
            gChatSendButtonPressed = false;

            if (!gLoggedIn)
            {
                UpdateLoginWindowState();
                StartNetworkStatusUpdateWorker();
            }
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
            if (gCustomLoginWindow != nullptr)
            {
                XPLMSetWindowIsVisible(
                    gCustomLoginWindow,
                    1
                );

                XPLMBringWindowToFront(
                    gCustomLoginWindow
                );
            }

            SetCustomLoginStatus(
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
            T("menu.main"),
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
    ProcessPositionUpdateResult();
    ProcessChatPollResult();
    ProcessChatSendResult();
    ProcessNetworkStatusUpdateResult();

    PollCompactChatMouseFocus();
    PollWindowsChatKeyboard();

    UpdateNetworkStatusIfNeeded(
        inElapsedSinceLastCall
    );
    UpdateChatPolling(
        inElapsedSinceLastCall
    );

    UpdateNightFlightTracking(
        inElapsedSinceLastCall
    );

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
    CompleteNightFlightTrackingIfLanded();

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

    gHasCrashedRef =
        XPLMFindDataRef(
            "sim/flightmodel2/misc/has_crashed"
        );

    gFuelTotal =
        XPLMFindDataRef(
            "sim/flightmodel/weight/m_fuel_total"
        );

    gFuelCapacity =
        XPLMFindDataRef(
            "sim/aircraft/weight/acf_m_fuel_tot"
        );

    gSunPitchDegrees =
        XPLMFindDataRef(
            "sim/graphics/scenery/sun_pitch_degrees"
        );

    gPausedRef =
        XPLMFindDataRef(
            "sim/time/paused"
        );

    gReplayModeRef =
        XPLMFindDataRef(
            "sim/operation/prefs/replay_mode"
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

    gTransponderMode =
        XPLMFindDataRef(
            "sim/cockpit2/radios/actuators/transponder_mode"
        );

    if (gTransponderMode == nullptr)
    {
        gTransponderMode =
            XPLMFindDataRef(
                "sim/cockpit/radios/transponder_mode"
            );
    }

    gTransponderIdentCommand =
        XPLMFindCommand(
            "sim/transponder/transponder_ident"
        );

    if (gTransponderIdentCommand != nullptr)
    {
        XPLMRegisterCommandHandler(
            gTransponderIdentCommand,
            TransponderIdentCommandHandler,
            0,
            nullptr
        );
    }

    XPLMRegisterFlightLoopCallback(
        FlightLoopCallback,
        1.0f,
        nullptr
    );

    XPLMRegisterKeySniffer(
        ChatKeySniffer,
        1,
        nullptr
    );

    return 1;
}


PLUGIN_API void XPluginStop(void)
{
    if (gTransponderIdentCommand != nullptr)
    {
        XPLMUnregisterCommandHandler(
            gTransponderIdentCommand,
            TransponderIdentCommandHandler,
            0,
            nullptr
        );
    }

    XPLMUnregisterFlightLoopCallback(
        FlightLoopCallback,
        nullptr
    );

    XPLMUnregisterKeySniffer(
        ChatKeySniffer,
        1,
        nullptr
    );

    if (gPositionUpdateThread.joinable())
    {
        gPositionUpdateThread.join();
    }

    if (gNetworkStatusThread.joinable())
    {
        gNetworkStatusThread.join();
    }

    if (gChatPollThread.joinable())
    {
        gChatPollThread.join();
    }

    if (gChatSendThread.joinable())
    {
        gChatSendThread.join();
    }

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
        ResetNightFlightTracking();
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

    if (gCustomLoginWindow != nullptr)
    {
        XPLMDestroyWindow(
            gCustomLoginWindow
        );

        gCustomLoginWindow = nullptr;
    }

    if (gCompactWindow != nullptr)
    {
        XPLMDestroyWindow(
            gCompactWindow
        );

        gCompactWindow = nullptr;
    }

    if (gLogoutConfirmWindow != nullptr)
    {
        XPLMDestroyWindow(
            gLogoutConfirmWindow
        );

        gLogoutConfirmWindow = nullptr;
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
