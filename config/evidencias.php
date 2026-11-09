<?php

/*
 * Disco donde se guardan los archivos de evidencias.
 *
 *  'local' (por defecto) = storage/app/private: nadie lo abre por URL, solo la API con sesión.
 *  Para S3 / R2 / Spaces: define un disco en config/filesystems.php y pon su nombre en
 *  EVIDENCIAS_DISK. No toques código.
 *
 *  Nunca 'public': esa carpeta se sirve sin sesión.
 */
return [
    'disk' => env('EVIDENCIAS_DISK', 'local'),
];
