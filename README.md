# test-manager-library-3413974-api

Backend de **Manager Library**, desarrollado con PHP y Laravel para la ficha SENA ADSO 3413974. Las reglas de negocio y el contrato funcional se basan en las especificaciones originales conservadas en el repositorio `docs`.

## Estado del desarrollo

El dominio y los casos de uso de P3 están implementados en `src/Domain` y `src/Application`. Sus pruebas con fakes no requieren PostgreSQL ni servidor HTTP.

La migración inicial, `GET /api/v1/health`, el login HTTP/JWT, los adaptadores PostgreSQL para libros, socios, usuarios, categorías, préstamos y reportes, y el almacenamiento privado de portadas están implementados. Las rutas de negocio autenticadas están conectadas según el contrato, con permisos `admin`/`librarian`, respuestas JSON uniformes y CORS limitado a `FRONTEND_ORIGIN`. El checkout se verifica con dos procesos Laravel concurrentes contra PostgreSQL real; el adaptador de portadas valida las imágenes por contenido y permite hasta 2 MiB. P2 sigue parcial hasta verificar un arranque desde un volumen vacío.

## Pruebas del núcleo

Requisitos: Docker en ejecución, dependencias Composer disponibles en `vendor/` y la imagen `php:8.3-cli`.

Desde la raíz de este repositorio, en PowerShell:

```powershell
$api = (Resolve-Path .).Path
docker run --rm --mount "type=bind,source=$api,target=/app" --workdir /app php:8.3-cli php vendor/bin/phpunit tests/Unit/LendBooksServiceTest.php
```

Las pruebas de P3 usan fakes en memoria. Las pruebas de `tests/Integration` requieren el PostgreSQL real de Compose y revierten sus transacciones al terminar:

```powershell
docker compose exec api php artisan test --compact
```

El conjunto completo incluye pruebas unitarias, HTTP y de integración contra PostgreSQL real. El contrato HTTP verifica creación, consulta, actualización y baja de libros, miembros, préstamos, vencidos, reporte, portadas binarias, autenticación y permisos. La prueba concurrente de T-13 ejecuta procesos independientes y verifica que solo uno preste el último ejemplar.

## Migración y administrador inicial

La migración del esquema `library` vive en `database/migrations`. En Docker, desde el repositorio de infraestructura, ejecuta:

```powershell
docker compose exec api php artisan migrate --force
docker compose exec api php artisan app:create-admin
```

El comando de administrador obtiene `ADMIN_USERNAME` y `ADMIN_PASSWORD` del entorno, guarda únicamente el hash y puede ejecutarse de nuevo sin duplicar el usuario. No configures credenciales reales en archivos versionados.

En Compose, el servidor Laravel usa `--no-reload` para heredar las variables de entorno del servicio. Esto es necesario porque `artisan serve` filtra variables no incluidas en su lista permitida cuando encuentra un archivo `.env`.

La ruta `POST /api/v1/auth/login` devuelve un JWT Bearer firmado con vigencia de ocho horas. Configura un `JWT_SECRET` aleatorio de al menos 32 bytes, distinto de `APP_KEY`, mediante el entorno; no lo guardes en Git. La ruta health y login son públicas; las rutas operativas deben aplicar el middleware `jwt` y, cuando corresponda, limitar roles, por ejemplo `jwt:admin`.
