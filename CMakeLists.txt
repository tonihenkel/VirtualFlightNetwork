cmake_minimum_required(VERSION 3.16)
project(VFNPlugin VERSION 0.1.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_POSITION_INDEPENDENT_CODE ON)

if(NOT XPlaneSDK_DIR)
    set(XPlaneSDK_DIR "${CMAKE_CURRENT_SOURCE_DIR}/../SDK")
endif()

add_subdirectory(third_party/XPMP2)

find_package(CURL REQUIRED)
find_package(OpenGL REQUIRED)
find_package(PNG REQUIRED)
find_package(PkgConfig REQUIRED)
pkg_check_modules(OPENAL REQUIRED openal)
pkg_check_modules(LWS REQUIRED libwebsockets)

add_library(VFNPlugin MODULE
    "Flight Radar Sim Projekt/main.cpp"
)

target_include_directories(VFNPlugin PRIVATE
    "Flight Radar Sim Projekt"
    "${XPlaneSDK_DIR}/CHeaders/Wrappers"
    "${XPlaneSDK_DIR}/CHeaders/Widgets"
    "${XPlaneSDK_DIR}/CHeaders/XPLM"
    "third_party/XPMP2/inc"
    "third_party/XPMP2/src"
    ${OPENAL_INCLUDE_DIRS}
    ${LWS_INCLUDE_DIRS}
)

target_compile_definitions(VFNPlugin PRIVATE
    APL=0 IBM=0 LIN=1
    XPLM200=1 XPLM210=1 XPLM300=1 XPLM301=1 XPLM303=1 XPLM400=1
)
target_compile_options(VFNPlugin PRIVATE -O2 -fvisibility=hidden -Wall -Wextra)
target_link_options(VFNPlugin PRIVATE -Wl,--unresolved-symbols=ignore-all)
target_link_libraries(VFNPlugin PRIVATE XPMP2 CURL::libcurl OpenGL::GL PNG::PNG ${OPENAL_LIBRARIES} ${LWS_LIBRARIES} dl pthread)

set_target_properties(VFNPlugin PROPERTIES PREFIX "" SUFFIX ".xpl" OUTPUT_NAME "lin")
