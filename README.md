# Swift Invoice

**Swift Invoice** es una aplicación web para la gestión integral de ventas y facturación, enfocada en la administración eficiente y digital de clientes (personas y empresas), productos y comprobantes fiscales.  
Funciona en cualquier servidor local o web con PHP y utiliza MySQL para el almacenamiento seguro de la información.

---

## 🧩 **Características**

- Administración de clientes: personas físicas y empresas, con información fiscal.
- Registro y edición de ventas, asignando cliente y productos.
- Generación de facturas o comprobantes en PDF con diseño profesional.
- Control y configuración de impuestos por producto o venta.
- Catálogo de productos y servicios con precios y descripciones.
- Historial y búsqueda de ventas, clientes y productos.
- Reportes básicos para consulta y análisis.
- Enfoque **paperless**: reduce uso de papel y facilita la digitalización de procesos.
- Estructura modular, fácil de mantener y expandir.
- Compatible con cualquier entorno PHP/MySQL (XAMPP, WAMP, servidores en la nube).

---

## ⚙️ **Requisitos**

- PHP 7.4 o superior
- MySQL o MariaDB
- Servidor web (Apache, Nginx, etc.)

---

## 🚀 **Instalación**

1. Clona el repositorio o descarga el proyecto.
2. Crea una base de datos MySQL. Para desarrollo local, la conexión usa por defecto `localhost:3306`, la base `swift_invoice_db` y el usuario `root` sin contraseña.
3. Importa `script.sql`; contiene el esquema y los catálogos, no cuentas ni transacciones de ejemplo.
4. Accede desde tu navegador a la ruta donde instalaste Swift Invoice.
5. ¡Listo! Comienza a administrar tus ventas y clientes de manera digital.

## API REST

La especificación OpenAPI está en [`openapi.yaml`](./openapi.yaml). En el XAMPP local con el proyecto en `htdocs\Proyecto-Swifty` y `mod_rewrite` activo, la API se sirve bajo `/Proyecto-Swifty/api/v1`; sustituye `Proyecto-Swifty` por el nombre/ruta pública usada al desplegar:

- `GET /auth/csrf` entrega un token CSRF y establece la cookie de sesión.
- `POST /auth/login` inicia sesión; envía JSON y el token en `X-CSRF-Token`.
- `POST /auth/logout` cierra la sesión; requiere cookie y token CSRF.
- `/clients`, `/companies` y `/products` tienen operaciones de lectura y CRUD protegidas por sesión; las mutaciones requieren CSRF.
- `/sales` permite listar, consultar, crear y eliminar ventas. Los precios se obtienen del catálogo del servidor.
- `/invoices` permite listar y consultar facturas.
- `/catalogs/business-types` y `/catalogs/tax-regimes` exponen catálogos de solo lectura.

Las listas aceptan `limit` (1–100, predeterminado 20) y `offset` (predeterminado 0). La paginación se informa en `X-Total-Count`, `X-Limit` y `X-Offset`. Los errores usan una respuesta JSON común y códigos HTTP específicos.

La autenticación de la API utiliza la sesión PHP de la aplicación; no es una API de tokens para clientes externos. No se habilita CORS. Las peticiones de escritura deben conservar la cookie y enviar el token CSRF recibido para esa sesión. Las sesiones vencen tras 30 minutos de inactividad y los cuerpos JSON están limitados a 1 MiB.

## Configuración de producción y seguridad

- Configura `APP_ENV=production`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD` en el entorno de Apache/PHP. En producción, la aplicación no acepta las credenciales locales predeterminadas.
- Usa un usuario MySQL dedicado con los permisos mínimos necesarios; no uses `root`.
- Publica el sitio solo por HTTPS. Configura PHP para `display_errors=Off` y `log_errors=On`, y protege los archivos de log.
- Las cookies de sesión usan `HttpOnly` y `SameSite=Lax`; se marcan `Secure` y se envía HSTS cuando PHP detecta una conexión HTTPS.
- El registro público se desactiva por defecto cuando `APP_ENV=production`. Para crear la primera cuenta, habilita `ALLOW_REGISTRATION=true` solo durante una ventana controlada y vuelve a desactivarlo al terminar.
- El límite de intentos de inicio de sesión usa la tabla `auth_login_attempts`. En instalaciones existentes ejecuta una vez [`20261104_auth_login_attempts.sql`](./database/migrations/20261104_auth_login_attempts.sql); las instalaciones nuevas la crean al importar `script.sql`.
- Los archivos `.htaccess` protegen rutas y archivos en Apache. Si se usa Nginx u otro servidor, aplica reglas equivalentes y habilita el enrutamiento de `/api/v1`.
- Los usuarios autenticados comparten los mismos permisos; el esquema actual no implementa roles. Antes de ofrecer acceso a varios perfiles, agrega autorización por rol y propiedad de datos.
- El volcado ya no incluye registros de usuarios, clientes o transacciones, pero borrar esos registros del archivo actual no los elimina de commits anteriores. Si el repositorio estuvo compartido, limpia el historial de forma coordinada y gestiona las credenciales/datos expuestos.

Estas medidas reducen riesgos comunes, pero no permiten garantizar que una aplicación sea “inhackeable”. Mantén PHP, dependencias y servidor actualizados, y realiza pruebas de seguridad antes de publicar el sistema.
