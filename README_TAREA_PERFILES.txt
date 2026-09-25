GUIA DE TAREA: FILTRADO POR ASEGURADORA
=======================================

Proyecto: aseguradora-balamcore-backend
Fecha: 2026-09-25

OBJETIVO
--------
Evitar que un usuario pueda consultar, crear, modificar o eliminar datos que
pertenecen a otra aseguradora.

El filtro se obtiene del usuario autenticado:

    auth()->user()->id_aseguradora

Esto es mas seguro que confiar en un id_aseguradora enviado desde el frontend
o en un header que el cliente puede modificar.


ARCHIVOS MODIFICADOS
====================

1. app/Http/Controllers/BienesController.php

Se filtro por id_aseguradora en index, show, update, destroy,
getByRfcCliente y getByClienteId.

Al crear un bien:
- Se verifica que el cliente pertenezca a la aseguradora autenticada.
- Se ignora el id_aseguradora enviado por el frontend.
- Se asigna el id_aseguradora del usuario autenticado.

Al actualizar un bien:
- Solo se puede modificar un bien de la aseguradora actual.
- No se permite cambiar id_aseguradora ni id_cliente.


2. app/Http/Controllers/CoberturaController.php

Se filtro por id_aseguradora en index, update y destroy.

Al crear una cobertura:
- Se ignora el id_aseguradora enviado por el frontend.
- Se asigna el id_aseguradora del usuario autenticado.

Al actualizar una cobertura:
- Solo se puede modificar una cobertura de la aseguradora actual.
- No se permite cambiar id_aseguradora.


3. app/Http/Controllers/IndemnizacionesController.php

Las indemnizaciones no tienen id_aseguradora directo. El filtro se realiza
mediante el siniestro relacionado:

    indemnizaciones.id_siniestro -> siniestros.id ->
    siniestros.id_aseguradora

Se filtro por aseguradora en index, show, update y destroy.

Al crear una indemnizacion:
- Se verifica que el siniestro pertenezca a la aseguradora autenticada.
- Si pertenece a otra aseguradora, devuelve HTTP 404.

Al actualizar o eliminar:
- Solo se buscan indemnizaciones mediante siniestros de la aseguradora
  autenticada.


REGLAS DE SEGURIDAD
===================

1. Un usuario solo ve sus propios bienes.
2. Un usuario solo ve sus propias coberturas.
3. Un usuario solo ve sus propias indemnizaciones.
4. Un usuario no puede consultar registros externos usando otro ID.
5. Un usuario no puede modificar registros de otra aseguradora.
6. Un usuario no puede eliminar registros de otra aseguradora.
7. El frontend no puede cambiar la aseguradora enviando otro valor.
8. Los clientes utilizados para crear o consultar bienes tambien se validan
   contra la aseguradora autenticada.


RUTAS AFECTADAS
===============

Indemnizaciones:
GET    /api/indemnizaciones
GET    /api/indemnizaciones/{id}
POST   /api/indemnizaciones
PUT    /api/indemnizaciones/{id}
DELETE /api/indemnizaciones/{id}

Bienes:
GET    /api/bienes
GET    /api/bienes/{id}
GET    /api/bienes/cliente/{clienteId}
POST   /api/bienes
PUT    /api/bienes/{id}
DELETE /api/bienes/{id}

Coberturas:
GET    /api/coberturas
POST   /api/coberturas
PUT    /api/coberturas/{id}
DELETE /api/coberturas/{id}

Todas estas rutas estan dentro del middleware auth:sanctum, por lo que
auth()->user() debe existir.


EJEMPLO DEL FILTRO
==================

Para bienes:

    $bienes = Bienes::where(
        'id_aseguradora',
        auth()->user()->id_aseguradora
    )->get();

Para coberturas:

    $coberturas = Cobertura::where(
        'id_aseguradora',
        auth()->user()->id_aseguradora
    )->get();

Para indemnizaciones:

    $indemnizacion = Indemnizaciones::whereHas('siniestro', function ($query) {
        $query->where(
            'id_aseguradora',
            auth()->user()->id_aseguradora
        );
    })->findOrFail($id);


COMANDOS DE VALIDACION
======================

Ejecutar desde:

    cd /var/www/html/aseguradora-balamcore-backend

Revisar sintaxis:

    php -l app/Http/Controllers/BienesController.php
    php -l app/Http/Controllers/CoberturaController.php
    php -l app/Http/Controllers/IndemnizacionesController.php

Ejecutar pruebas:

    php artisan test

Resultado comprobado:
- 2 tests passed.
- git diff --check no reporta errores de formato.


PRUEBAS MANUALES
================

Caso 1: listado de bienes
- Iniciar sesion con un usuario de la aseguradora A.
- Consultar GET /api/bienes.
- Confirmar que no aparezcan bienes de la aseguradora B.

Caso 2: listado de coberturas
- Iniciar sesion con un usuario de la aseguradora A.
- Consultar GET /api/coberturas.
- Confirmar que solo aparezcan coberturas de A.

Caso 3: listado de indemnizaciones
- Iniciar sesion con un usuario de la aseguradora A.
- Consultar GET /api/indemnizaciones.
- Confirmar que solo aparezcan indemnizaciones cuyos siniestros pertenecen
  a A.

Caso 4: consulta, modificacion o eliminacion externa
- Intentar usar el ID de un registro de otra aseguradora.
- El registro no debe aparecer, modificarse ni eliminarse.
- La respuesta esperada es HTTP 404.

Caso 5: creacion con aseguradora falsa
- Enviar desde el frontend un id_aseguradora diferente.
- El backend debe ignorar ese valor.
- El registro debe guardarse con la aseguradora del usuario autenticado.


NOTA SOBRE EMPLEADOS
====================

EmpleadoController utiliza el header X-Usuario para obtener la aseguradora.
Los tres controladores modificados utilizan auth()->user() porque las rutas
estan protegidas con Sanctum. Asi el backend obtiene la identidad desde el
token autenticado y no desde un valor enviado por el cliente.


LISTA DE ENTREGA
================

[ ] Revisar BienesController.php.
[ ] Revisar CoberturaController.php.
[ ] Revisar IndemnizacionesController.php.
[ ] Ejecutar php -l en los tres controladores.
[ ] Ejecutar php artisan test.
[ ] Probar listados con dos aseguradoras diferentes.
[ ] Probar consulta por ID de otra aseguradora.
[ ] Probar actualizacion por ID de otra aseguradora.
[ ] Probar eliminacion por ID de otra aseguradora.
[ ] Probar envio de un id_aseguradora falso.


FIN DE LA GUIA
