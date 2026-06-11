<?php

function getAircraftCategory(string $icao): string
{
    $icao = strtoupper(trim($icao));

    if ($icao === "") {
        return "unknown";
    }

    $smallAircraft = [
        "C150", "C152", "C162", "C170", "C172", "C175", "C177", "C180", "C182", "C185", "C206", "C207", "C208", "C210",
        "P28A", "PA18", "PA28", "PA32", "PA34", "PA38", "PA44", "P46T",
        "SR20", "SR22", "DA20", "DA40", "DA42", "DR40", "DV20",
        "BE35", "BE36", "BE55", "BE58", "BE9L", "B58T",
        "B350", "B300", "BE20", "C90", "C90B",
        "PC12", "TBM7", "TBM8", "TBM9", "P180",
        "L5", "L5S", "RV10", "RV6", "RV7", "RV8", "RV9",
        "LC40", "EVOT", "LANCAIR",
        "DHC2", "DHC3", "DHC6", "AN2",
        "AERO", "A103", "ULAC", "ALIA", "ALIA250",
        "GLID", "ASK21", "AS21", "ASW2", "DG80", "DISC"
    ];

    $mediumAircraft = [
        "A318", "A319", "A19N", "A320", "A20N", "A321", "A21N",
        "B712", "B721", "B722", "B731", "B732", "B733", "B734", "B735", "B736", "B737", "B738", "B739",
        "B37M", "B38M", "B39M",
        "E135", "E145", "E170", "E175", "E190", "E195", "E290", "E295",
        "CRJ1", "CRJ2", "CRJ7", "CRJ9", "CRJX",
        "DH8A", "DH8B", "DH8C", "DH8D",
        "AT43", "AT45", "AT46", "AT72", "AT75", "AT76",
        "F50", "F70", "F100", "RJ85", "RJ1H",
        "MD80", "MD81", "MD82", "MD83", "MD87", "MD88", "MD90",
        "B190", "JS32", "JS41", "SF34", "SB20", "D328", "E120",
        "C25A", "C25B", "C25C", "C510", "C525", "C550", "C560", "C680", "C700", "C750", "E50P", "E55P", "SF50"
    ];

    $largeAircraft = [
        "A300", "A306", "A30B", "A310",
        "A332", "A333", "A338", "A339",
        "A342", "A343", "A345", "A346",
        "A358", "A359", "A35K",
        "B741", "B742", "B743", "B744", "BLCF",
        "B752", "B753", "B762", "B763", "B764",
        "B772", "B773", "B77L", "B77W", "B778", "B779",
        "B788", "B789", "B78X",
        "DC10", "MD11", "IL76", "IL96", "A124", "AN12", "AN22"
    ];

    $superAircraft = [
        "A388", "A380", "A225", "AN225", "B748", "B74R"
    ];

    $helicopters = [
        "A109", "A119", "A139", "A169", "A189",
        "AS50", "AS55", "AS65", "AS32", "AS35",
        "B06", "B407", "B412", "B429",
        "EC20", "EC30", "EC35", "EC45", "EC55",
        "H120", "H125", "H130", "H135", "H145", "H160", "H175", "H215", "H225",
        "R22", "R44", "R66",
        "S76", "S76C", "S92", "MD50", "MD52", "UH1", "UH60", "CH47", "CH53"
    ];

    $military = [
        "A10", "A29",
        "B1", "B2", "B52",
        "C130", "C17", "C5M",
        "E3CF", "E3TF",
        "KC10", "KC30", "KC35", "KC46", "K35R",

        "F4", "F4E", "F4F", "F14", "F14A", "F14B", "F14D",
        "F15", "F15C", "F15E",
        "F16", "F16C", "F16D",
        "F18", "F18E", "F18F",
        "F22", "F35", "F117",

        "EUFI", "TYPH", "RAFA", "GRIP", "TOR",
        "MIR2", "MIR4",
        "MIG21", "MIG23", "MIG25", "MIG29", "MIG31",
        "SU24", "SU25", "SU27", "SU30", "SU33", "SU34", "SU35", "SU57",
        "TU22", "TU95", "TU160",
        "L39", "T38", "T6", "PC21"
    ];

    $drones = [
        "UAV", "DRON", "DRONE", "RPAS", "MQ1", "MQ9", "RQ4", "RQ7", "TB2", "PRED", "GLBL"
    ];

    $balloons = [
        "BALL", "BALLON", "HOTAIR", "HBAL"
    ];

    $groundvehicle = [
        "CHEVY", "OPS", "FOLLOW", "PUSH", "FIRE", "TUG", "FUEL", "BUS", "CAR", "VAN", "GPU", "CATER", "BAG", "DEICE", "MARSHAL", "VITO"
    ];

    if (in_array($icao, $smallAircraft, true)) return "small";
    if (in_array($icao, $mediumAircraft, true)) return "medium";
    if (in_array($icao, $largeAircraft, true)) return "large";
    if (in_array($icao, $superAircraft, true)) return "super";
    if (in_array($icao, $helicopters, true)) return "helicopter";
    if (in_array($icao, $military, true)) return "military";
    if (in_array($icao, $drones, true)) return "drone";
    if (in_array($icao, $balloons, true)) return "balloon";
    if (in_array($icao, $groundvehicle, true)) return "groundvehicle";

    return "unknown";
}