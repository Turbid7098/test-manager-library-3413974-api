# test-manager-library-3413974-api

Backend de **Manager Library**, desarrollado con PHP y Laravel para la ficha SENA ADSO 3413974. Las reglas de negocio y el contrato funcional se basan en las especificaciones originales conservadas en el repositorio `docs`.

## Estado del desarrollo

El dominio y los casos de uso de P3 están implementados en `src/Domain` y `src/Application`. Sus pruebas con fakes no requieren PostgreSQL ni servidor HTTP.

La migración inicial, el endpoint `GET /api/v1/health` y el comando de aprovisionamiento del administrador están disponibles. Los adaptadores de persistencia, el inicio de sesión HTTP y las demás rutas todavía deben completarse antes de considerar P2/P4 terminadas.

## Pruebas del núcleo

Requisitos: Docker en ejecución, dependencias Composer disponibles en `vendor/` y la imagen `php:8.3-cli`.

Desde la raíz de este repositorio, en PowerShell:

```powershell
$api = (Resolve-Path .).Path
docker run --rm --mount "type=bind,source=$api,target=/app" --workdir /app php:8.3-cli php vendor/bin/phpunit tests/Unit/LendBooksServiceTest.php
```

La prueba de P3 usa fakes en memoria. No demuestra persistencia PostgreSQL, contrato HTTP ni concurrencia real; esas evidencias requieren sus adaptadores e integración.

## Migración y administrador inicial

La migración del esquema `library` vive en `database/migrations`. En Docker, desde el repositorio de infraestructura, ejecuta:

```powershell
docker compose exec api php artisan migrate --force
docker compose exec api php artisan app:create-admin
```

El comando de administrador obtiene `ADMIN_USERNAME` y `ADMIN_PASSWORD` del entorno, guarda únicamente el hash y puede ejecutarse de nuevo sin duplicar el usuario. No configures credenciales reales en archivos versionados.

El login HTTP aún no está implementado; por tanto, aprovisionar este usuario no significa que la autenticación por API esté lista.
