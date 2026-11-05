<?php
declare(strict_types=1);

// Solo per CLI sul proprio computer/server: questo file conserva le credenziali in chiaro se compilato.
// Copiare in local.php, fuori dalla document root, con permessi 0600 su Linux.
return [
    'email' => '',
    'password' => '',
    'entityId' => 3,
    'studentId' => null, // Riconoscimento dalla home autenticata; impostare solo il proprio ID se necessario.
];
