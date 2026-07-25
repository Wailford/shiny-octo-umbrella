<?php
$hash = '$2y$10$B50/P0ismjmvQIRnNgynFO5fYaHBSwa/TbKg5hO4kezypbb2y0CQW';
$passwords = [
    'Developer',
    'Developer123',
    'dev',
    'system',
    'system123',
    'systemdeveloper',
    'SystemDeveloper',
    'admin@school.com',
    'dev@system.com',
    '12345678',
    '123456789',
    'admin1234',
    'admin12345',
    'asante',
    'asantem1',
    'TechLaw',
    'TechLaw@143',
    'TechLaw@143143',
    'Sba_TechLaw',
    'developer',
    'developer123',
    'dev123',
    'admin123',
    'admin',
    'password',
    '123456',
    'sba',
    'sba123',
    'school123'
];

foreach ($passwords as $p) {
    if (password_verify($p, $hash)) {
        echo "FOUND: " . $p . "\n";
        exit;
    }
}
echo "NOT FOUND\n";
