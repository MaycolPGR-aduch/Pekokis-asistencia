# Despliegue en Hostinger — paso a paso

Objetivo: tener `https://asistencia.pekokis.com` funcionando para el piloto con celulares reales.

Al terminar, **Asistencia → Ajustes → Estado del sistema** debe mostrar todo en verde. Esa lista sirve para comprobar cada paso.

Lo que tienes que hacer tú está marcado con ☐. Los nombres de los menús de hPanel pueden variar un poco según la versión.

---

## 0. Antes de empezar

- ☐ **Recursos del plan.** En hPanel, revisa qué mide el 77 % de "Uso de recursos". Este sitio consume poco, pero suma archivos (unos 3.000 de WordPress y 200 del plugin) y un cron cada 5 minutos.
- ☐ **El zip del plugin.** Ya está en `dist/pekokis-asistencia-0.16.1.zip`. Si cambia el código, se vuelve a generar con:
  ```bash
  php scripts/empaquetar.php
  ```
- ☐ **Una cuenta de correo para el sitio** (por ejemplo `asistencia@pekokis.com`), con sus datos SMTP. Se usa para el respaldo mensual.

## 1. Crear el sitio en Hostinger

1. ☐ hPanel → **Sitios web → Crear web → WordPress**. Usa el dominio **`asistencia.pekokis.com`** como dominio existente o subdominio, **aparte del staging**.
2. ☐ Usuario administrador: un nombre que **no** sea `admin` y una contraseña larga (guárdala en tu gestor de contraseñas).
3. ☐ Anota la **IP del servidor** que muestra hPanel; se usa en el paso 2.

## 2. DNS en Cloudflare (zona pekokis.com)

1. ☐ **DNS → Agregar registro**:
   - **Tipo:** `A`
   - **Nombre:** `asistencia`
   - **Contenido:** la IP de Hostinger
   - **Proxy:** **desactivado (nube gris, "Solo DNS")**

   La nube gris es importante: así el servidor ve la IP real de cada celular, y el control del WiFi del taller funciona.
2. ☐ Espera a que el dominio apunte al servidor. Normalmente son minutos.
3. ☐ hPanel → **Seguridad → SSL**: instala el certificado gratis para `asistencia.pekokis.com` y activa "forzar HTTPS".

## 3. PHP y caché

- ☐ hPanel → **Avanzado → Configuración de PHP**: elige **PHP 8.3** (sirve desde 8.2).
- ☐ hPanel → **Rendimiento / Caché**: **desactiva la caché del servidor y el CDN** para este sitio.

  Si se cachean las pantallas, un trabajador podría ver el estado de otro.

## 4. WordPress mínimo

1. ☐ **Plugins:** desactiva y borra todo lo que instaló Hostinger (LiteSpeed Cache, el plugin de Hostinger, etc.). Quedan solo los del paso 6 y 8.
2. ☐ **Ajustes → Generales:**
   - Dirección de WordPress y del sitio: `https://asistencia.pekokis.com`
   - Idioma: Español
   - Zona horaria: **Lima**
3. ☐ **Ajustes → Enlaces permanentes:** "Nombre de la entrada".

## 5. wp-config.php

Ábrelo en hPanel → **Administrador de archivos** (`public_html/wp-config.php`). Agrega estas líneas antes de `/* That's all, stop editing! */`:

```php
// Pekokis Asistencia
define( 'PKA_SECRETO', 'PEGA_AQUÍ_64_CARACTERES' ); // Firma los QR y los pases de presencia.
define( 'DISABLE_WP_CRON', true );                   // Usamos el cron real (paso 7).
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'DISALLOW_FILE_EDIT', true );                // Nadie edita código desde wp-admin.
define( 'WP_DEBUG', false );
```

☐ Genera el secreto en tu PC con:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

No lo compartas ni lo subas a git. Si algún día cambia, solo se invalidan los QR y pases vigentes en ese momento; las passkeys siguen funcionando.

## 6. Instalar el plugin y el tema

1. ☐ **Plugins → Añadir nuevo → Subir plugin:** sube `pekokis-asistencia-0.16.1.zip` y actívalo. Al activarse crea sus tablas, roles, motivos y el horario base.
2. ☐ **Apariencia → Temas:** activa **"Pekokis Asistencia (vacío)"** y borra los demás temas, salvo uno por defecto como respaldo.
3. ☐ Revisa **Asistencia → Ajustes → Estado del sistema.**

**Para actualizar más adelante:** genera un zip nuevo y súbelo igual. WordPress pregunta si reemplazar la versión instalada: acepta. Los datos se conservan y las tablas se actualizan solas.

## 7. Cron real

☐ hPanel → **Avanzado → Cron Jobs**: crea una tarea **"Personalizado"**, **cada 5 minutos**, con este comando (la ruta exacta aparece en hPanel):

```
/opt/alt/php83/usr/bin/php /home/TU_USUARIO/domains/asistencia.pekokis.com/public_html/wp-cron.php
```

**Ojo:** la opción "PHP" de hPanel usa `/usr/bin/php`, que en este servidor es **PHP 8.1**, y el plugin pide 8.2 o más. Con ese PHP el cron no ejecuta nada. Desde la 0.15.1, Estado del sistema lo avisa. Para comprobarlo, "Ver resultado" debe salir vacío. Si dice "No such file", prueba con `/usr/bin/php8.3`.

Es igual que en producción: **nunca** se llama a `wp-cron.php` por URL.

## 8. Correo (respaldo mensual)

1. ☐ Instala **WP Mail SMTP** (o el plugin SMTP que prefieras) y configúralo con la cuenta del paso 0.
2. ☐ **Asistencia → Exportar:** escribe el correo de RR.HH. en "Enviar a" y pulsa **"Enviar respaldo"** con el mes actual, para probar que llega.

## 9. Usuarios del panel

- ☐ **Usuarios → Añadir nuevo:** crea las cuentas de RR.HH. (rol **"RR.HH. (asistencia)"**) y de los jefes (rol **"Jefe de área (asistencia)"**).

  Los trabajadores **no** llevan usuario: entran con su passkey.
- ☐ Recomendado: instala el plugin **Two Factor** y exige un segundo factor a administradores y RR.HH.

  El plugin ya bloquea 15 minutos una IP después de 5 contraseñas equivocadas.

## 10. Configuración desde el taller

1. ☐ **Red del WiFi:** con un celular o laptop **conectado al WiFi del taller**, entra a wp-admin → Asistencia → Ajustes → "Agregar red" (el campo ya trae tu IP).
   - Si aparece el aviso de **IP compartida (CGNAT)**, no la agregues: elige el modo **"Solo QR"**.
2. ☐ **Pantalla:** en la tablet de la entrada, inicia sesión en wp-admin, abre `https://asistencia.pekokis.com/pantalla/` y pulsa **"Usar este equipo como pantalla"**.
   - Puedes cerrar la sesión después: la pantalla sigue funcionando.
   - Déjala enchufada y con el brillo alto.
3. ☐ **Calendario:** en Asistencia → Calendario, revisa la lista sugerida de feriados del año (y del siguiente) contra el calendario oficial y pulsa "Cargar los feriados". Agrega los días no laborables propios de la empresa.
4. ☐ **Aviso de datos personales:** en Asistencia → Ajustes, al final, está el borrador. **Revísalo con el abogado** y guárdalo antes de activar a nadie: cada trabajador lo acepta al activar su celular. Confirma también la razón social, el RUC y el domicilio.
5. ☐ **Trabajadores:** en Asistencia → Trabajadores, agrega a las 2 o 3 personas del piloto y genera su **enlace de activación**. Cada una lo abre en su propio celular. Si un jefe o alguien de RR.HH. también marca, enlaza su perfil con su usuario de wp-admin ("Usuario de wp-admin"): así no puede aprobar sus propias marcas.
6. ☐ **Gerencia (opcional):** crea su usuario con el rol **"Gerencia (asistencia, solo lectura)"**: ve el panel, el historial y descarga el Excel, pero no corrige nada.
7. ☐ **Copia en la nube:** el escenario de Make **"Respaldo asistencia → Google Drive"** ya está creado y activo (equipo de Pekokis en Make). Guarda cada Excel en la carpeta **"Respaldos asistencia Pekokis"** del Drive de admin1@pekokis.com. Copia la URL de su webhook (Make → Webhooks → "Respaldo asistencia Pekokis"), pégala en Asistencia → Exportar y pulsa "Enviar respaldo" para probar. La URL es un secreto: no la compartas ni la subas a git.
8. ☐ **Honorarios y tarifas (para la planilla de pagos):**
   - En la ficha de cada trabajador, sección **Honorario y tarifas**, registra su honorario mensual y sus 4 tarifas por hora (extra, sábado, domingo y feriado), y desde cuándo rigen. Solo lo ve quien puede ver sueldos.
   - En Asistencia → Ajustes → "Reglas de horas y pagos", confirma cuántos minutos de comida se descuentan si no la marcan (60 por defecto) y desde cuándo cuenta la tardanza.
   - Las horas extra son automáticas: de lunes a viernes, lo que pase de la jornada efectiva (9 h 30 min con el horario base). No se autorizan.

## 11. Checklist del piloto

| # | Prueba | Android (Chrome) | iPhone (Safari) |
|---|---|---|---|
| 1 | Activar el celular con el enlace (huella, rostro o PIN) | ☐ | ☐ |
| 2 | Instalar la app: Android → menú ⋮ → "Instalar app"; iPhone → Compartir → "Agregar a inicio". Debe abrir con el ícono rosa del reloj y sin barra del navegador | ☐ | ☐ |
| 3 | Marcar entrada conectado al WiFi del taller | ☐ | ☐ |
| 4 | Con datos móviles: escanear el QR con la cámara y marcar | ☐ | ☐ |
| 5 | Con datos móviles: escribir el código de 6 dígitos y marcar | ☐ | ☐ |
| 6 | Salir a refrigerio y volver | ☐ | ☐ |
| 7 | Salida temporal con motivo "Personal" (aparece para revisión) | ☐ | ☐ |
| 8 | "Olvidé marcar" y que el jefe lo apruebe en Hoy | ☐ | ☐ |
| 9 | Intentar activar a otra persona en el mismo celular (debe negarlo) | ☐ | ☐ |
| 10 | En wp-admin: Hoy muestra a todos bien; descargar el Excel del mes | ☐ | — |
| 11 | "Mis marcas del mes": ver el calendario de colores (hoy con borde rosa), los días, tardanzas y horas, y el mes anterior | ☐ | ☐ |
| 12 | Cambio de celular: generar un enlace nuevo, activarlo en otro celular y comprobar que el anterior ya no entra | ☐ | ☐ |
| 13 | Al activar: se ve el aviso de datos y "Crear mi llave" no funciona hasta marcar la casilla | ☐ | ☐ |
| 14 | Registrar vacaciones a alguien: en Hoy aparece como "Ausencia justificada" y en su app, en "Mis marcas del mes" | ☐ | — |
| 15 | Alguien se queda después de su hora: en el detalle del día salen sus horas extra; un sábado trabajado sale como "h de sábado" | ☐ | — |
| 16 | Descargar la planilla de pagos del mes y comprobar el total de una persona a mano (honorario + horas × tarifa) | ☐ | — |
| 17 | Asistencia → Resumen del mes: los totales cuadran con lo que se vio en Hoy durante la semana; un jefe solo ve a su equipo | ☐ | — |
| 18 | Asistencia → Informe: el calendario de un trabajador, "Descargar Excel" e "Imprimir o guardar PDF" | ☐ | — |
| 20 | Mi recibo (app): enviar un recibo con número, monto y PDF; anular el envío; en wp-admin → Recibos, abrir el PDF y marcarlo como recibido | ☐ | ☐ |
| 21 | Comprobar que el PDF **no** se abre con su dirección directa: `https://asistencia.pekokis.com/wp-content/uploads/pka-recibos/` debe dar 403 | ☐ | — |
| 22 | Asistencia → Pagos: "Ver cálculo", marcar un pago y bajar la "Planilla de las listas" | ☐ | — |
| 23 | App nueva: entrar con la huella, cerrar la app y volver a abrirla (sigue dentro); marcar entrada y salir a comer (cada marca pide la huella) | ☐ | ☐ |
| 24 | Fuera del WiFi: tocar "Escanear" (Android lee el QR; iPhone pide el código de 6 números) y marcar | ☐ | ☐ |
| 25 | Mis horas: tocar un día sin salida, "Pedir corrección", y verla en el panel; Perfil: "Ver" el DNI y "Cerrar sesión" | ☐ | ☐ |
| 26 | Perfil → PIN de ingreso → Crear; cerrar sesión y "Entrar con PIN" (un PIN equivocado dice cuántos intentos quedan) | ☐ | ☐ |
| 27 | En un celular sin registrar, en el taller: "Es mi primera vez" → datos → llave → "Registro enviado"; aprobarlo en Asistencia → Celulares y entrar | ☐ | ☐ |
| 28 | Cambio de celular: poner el cumpleaños en la ficha; en el celular nuevo "Cambié de celular" → buscar → DNI y cumpleaños → llave; el anterior sigue marcando hasta aprobar; después ya no | ☐ | ☐ |
| 30 | Panel: entrar como RR.HH. → abre el Dashboard; las cifras y "Requiere tu atención" cuadran con Hoy; en Solicitudes aprobar una con otra hora y verla como "Aprobada con corrección" en la app | ☐ | ☐ |
| 31 | Perfiles → una ficha: las 5 pestañas, "Mostrar" el DNI (queda en Auditoría); Delegar permisos a alguien con correo, que le llegue el correo y que entre solo a lo que se le dio | ☐ | — |
| 32 | Registros y revisión: los incompletos salen primero; "Editar" un día sin salida, poner la salida con motivo y verlo en Auditoría ("Día editado") y en la app del trabajador | ☐ | ☐ |
| 33 | Sueldos y tarifas: cambiar la tarifa de dos personas, ver "2 cambios sin guardar", guardar desde mañana y revisar el historial en la ficha | ☐ | — |
| 34 | Cerrar sesión y abrir `/wp-login.php`: la página con la marca. Entrar, ir a Perfil → "Activar Face ID o huella en este equipo" en el iPhone o iPad de gerencia, cerrar sesión y entrar con "Entrar con Face ID o huella" | ☐ | — |
| 35 | En el celular de gerencia, abrir `/gerencia/`: Inicio, Hoy (filtros), Solicitudes (aprobar con otra hora), Pagos ("Cómo se calculó"), Recibos y Más | ✔ (vistas, 18-11) | Falta aprobar una solicitud real desde el celular |
| 36 | Asistencia de hoy: los filtros por situación. Seguridad y reglas: las 6 pestañas y "N cambios sin guardar" | ✔ 18-11 | En angosto se veía "0 cambios": arreglado en 0.16.1 |
| 29 | Ajustes: QR a 30 min y la pantalla muestra la barra; "Avisos de campaña" con una fecha de hoy y ver el aviso en la app antes de marcar (y volver a dejarlo como estaba) | ☐ | ☐ |
| 19 | Ajustes → Avisos: "Enviarme un correo de prueba"; encender "No llegó", pedir un "olvidé marcar" y ver que el correo llega al jefe (revisa también spam) | ☐ | — |

Anota en [TAREAS.md](../TAREAS.md) lo que falle, con el celular y el navegador.

## Si algo falla

- **Errores de PHP:** pon `WP_DEBUG` en `true` y `WP_DEBUG_LOG` en `true` (nunca `WP_DEBUG_DISPLAY`), reproduce el problema y revisa `wp-content/debug.log`. Después vuelve a dejarlo en `false`.
- **"No reconocimos tu llave"**: confirma que la dirección del sitio sea exactamente `https://asistencia.pekokis.com`. La passkey queda atada a ese dominio.
- **Nadie puede marcar con WiFi:** compara la IP que muestra Ajustes estando en el taller con la red guardada. Si cambió, agrega la nueva.
