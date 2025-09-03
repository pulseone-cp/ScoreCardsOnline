<?php
// Utility helpers

function random_room_hash(): string {
    return bin2hex(random_bytes(4)); // 8 hex chars
}

function random_mod_hash(): string {
    return bin2hex(random_bytes(8)); // 16 hex chars
}

function random_room_name(): string {
    $adjectives = ['Brave', 'Cosmic', 'Fuzzy', 'Mighty', 'Silent', 'Swift', 'Witty', 'Zany', 'Quirky', 'Lucky'];
    $nouns = ['Panda', 'Falcon', 'Otter', 'Tiger', 'Llama', 'Badger', 'Koala', 'Dragon', 'Phoenix', 'Narwhal'];
    return $adjectives[array_rand($adjectives)] . ' ' . $nouns[array_rand($nouns)];
}

function random_funny_name(): string {
    $cartoons = ['SpongeBob', 'DarthMaul', 'DarkKnight', 'RickSanchez', 'Morty', 'Bender', 'Homer', 'Marge', 'Bart', 'Lisa', 'Mickey', 'Goofy', 'BugsBunny', 'DaffyDuck', 'Scooby', 'Shaggy', 'Pikachu', 'AshKetchum', 'Goku', 'Vegeta'];
    $num = random_int(10, 999);
    return $cartoons[array_rand($cartoons)] . $num;
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