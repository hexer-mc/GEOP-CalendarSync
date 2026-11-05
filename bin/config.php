<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if ($argc !== 3) {
    throw new InvalidArgumentException('Uso: php ' . basename($argv[0]) . ' YYYY-MM-DD YYYY-MM-DD');
}
$local = is_file(__DIR__ . '/../config/local.php') ? require __DIR__ . '/../config/local.php' : [];
if (!is_array($local)) {
    throw new InvalidArgumentException('config/local.php deve restituire un array.');
}
$email = getenv('GEOP_EMAIL') !== false ? getenv('GEOP_EMAIL') : ($local['email'] ?? '');
$password = getenv('GEOP_PASSWORD') !== false ? getenv('GEOP_PASSWORD') : ($local['password'] ?? '');
$entity = getenv('GEOP_ENTITY_ID') !== false ? getenv('GEOP_ENTITY_ID') : ($local['entityId'] ?? 3);
$student = getenv('GEOP_STUDENT_ID') !== false ? getenv('GEOP_STUDENT_ID') : ($local['studentId'] ?? null);
$entityId = filter_var($entity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$studentId = $student === null ? null : filter_var($student, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_string($email) || !is_string($password) || $email === '' || $password === '' || $entityId === false || $studentId === false) {
    throw new InvalidArgumentException('Configurare email/password in config/local.php oppure GEOP_EMAIL e GEOP_PASSWORD nell’ambiente.');
}
return ['email' => $email, 'password' => $password, 'entityId' => $entityId, 'studentId' => $studentId, 'start' => $argv[1], 'end' => $argv[2]];
