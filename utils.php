<?php
// Utility helpers

function random_room_hash(): string {
    return bin2hex(random_bytes(4)); // 8 hex chars
}

function random_mod_hash(): string {
    return bin2hex(random_bytes(8)); // 16 hex chars
}

function random_room_name(): string {
    // Themed by comic/cartoon/superhero worlds and locations
    // Adjectives act as world/franchise/location qualifiers, nouns are landmarks/places
    $adjectives = [
        // Batman/DC
        'Gotham', 'Arkham', 'Wayne', 'Bat', 'Metropolis', 'Krypton', 'DailyPlanet',
        // Marvel
        'Avengers', 'Stark', 'Asgardian', 'Wakandan', 'Xavier', 'Mutant', 'Spider', 'SHIELD', 'Hydra',
        // SpongeBob
        'BikiniBottom', 'Krusty', 'Chum', 'RockBottom', 'Jellyfish', 'Pineapple',
        // General/other toons
        'Marvel', 'DC', 'Toon', 'Cartoon', 'Cosmic', 'Galactic'
    ];
    $nouns = [
        // Generic places
        'Alley', 'Cave', 'Mansion', 'Tower', 'Sanctum', 'Lab', 'Bridge', 'Docks', 'Pier', 'Street', 'Boulevard', 'Hideout', 'HQ', 'Fortress', 'Castle',
        // Batman/DC spots
        'Batcave', 'WayneManor', 'HallOfJustice', 'DailyPlanet',
        // Marvel spots
        'AvengersTower', 'SanctumSanctorum', 'Helicarrier', 'Asgard', 'Wakanda', 'XavierSchool', 'DailyBugle',
        // SpongeBob spots
        'Pineapple', 'KrustyKrab', 'ChumBucket', 'Lagoon', 'GloveWorld', 'JellyfishFields'
    ];
    return $adjectives[array_rand($adjectives)] . ' ' . $nouns[array_rand($nouns)];
}

function random_funny_name(): string {
    // Character names aligned with the themed room franchises (Batman/DC, Marvel, SpongeBob)
    $characters = [
        // Batman/DC
        'Batman','Robin','Nightwing','Batgirl','Alfred','CommissionerGordon','Joker','HarleyQuinn','Catwoman','Riddler','Penguin','TwoFace','Bane','PoisonIvy','Scarecrow',
        'Superman','WonderWoman','Flash','Aquaman','GreenLantern','Cyborg',
        // Marvel (Avengers, X-Men, Guardians)
        'IronMan','CaptainAmerica','Thor','Hulk','BlackWidow','Hawkeye','SpiderMan','BlackPanther','DoctorStrange','ScarletWitch','Vision','AntMan','Wasp','Falcon','WinterSoldier',
        'StarLord','Gamora','Drax','Rocket','Groot','Loki','NickFury','Deadpool','Wolverine','Storm','Cyclops','ProfessorX','Rogue','Beast','Magneto',
        // SpongeBob
        'SpongeBob','Patrick','Squidward','SandyCheeks','MrKrabs','Plankton','GarySnail','Pearl','MrsPuff','LarryLobster','MermaidMan','BarnacleBoy'
    ];
    $num = random_int(10, 999);
    return $characters[array_rand($characters)] . $num;
}

function fixed_card_colors(): array {
    // 10 distinct, friendly colors in a fixed order
    return [
        '#e6194b', // 0 red
        '#3cb44b', // 1 green
        '#ffe119', // 2 yellow
        '#4363d8', // 3 blue
        '#f58231', // 4 orange
        '#911eb4', // 5 purple
        '#46f0f0', // 6 cyan
        '#f032e6', // 7 magenta
        '#bcf60c', // 8 lime
        '#fabebe'  // 9 pink
    ];
}

function qr_url(string $data, int $size=200): string {
    $encoded = urlencode($data);
    return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encoded}";
}

function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

?>