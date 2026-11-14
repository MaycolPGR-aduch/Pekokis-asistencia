# Control de tareas — Sistema de asistencia Pekokis

Este archivo lleva lo hecho, lo pendiente y la bitácora del proyecto. Se actualiza cada vez que avanzamos.
El detalle técnico de cada punto está en [docs/plan-tecnico.md](docs/plan-tecnico.md).

**Estado actual:** el MVP funcional está completo en el WordPress local: perfiles, passkeys, marcas, pantalla QR, panel del día, revisión, correcciones, export mensual y respaldo. También están la app instalable (PWA), "Mis marcas del mes" y la revocación automática del celular anterior. También están el visor del historial, la pantalla de horarios y el aviso de IP nueva. Lo cubren 91 pruebas del núcleo y 98 de punta a punta. El despliegue está preparado (zip 0.5.0, guía paso a paso y diagnóstico en Ajustes). **Siguiente: Maycol monta el sitio en Hostinger** siguiendo [docs/despliegue-hostinger.md](docs/despliegue-hostinger.md), y después se hace el piloto con celulares reales. De calidad queda el respaldo en Google Drive (vía Make).

**Estrategia:** primero un MVP con las fases 0 y 1 del plan (registro de asistencia funcionando y conforme a la norma). Después, mejoras por etapas según lo que opine gerencia.

Leyenda: `[x]` hecho · `[ ]` pendiente · `[~]` en curso

---

## MVP

### 0. Preparación
- [x] Plan técnico — `docs/plan-tecnico.md`
- [x] Repositorio local y remoto `origin` en GitHub (primer push el 13-11)
- [x] Decidir el stack → **WordPress + plugin propio**, con la lógica de negocio en PHP puro
- [ ] Revisar la IP WAN del router del taller (¿CGNAT? ¿IP fija?)
- [ ] Revisar qué mide el 77 % de "Uso de recursos" del plan de Hostinger antes de agregar otro sitio
- [x] Preparar el despliegue:
  - `scripts/empaquetar.php` genera un zip con las librerías de producción (sin pruebas ni PHPUnit), que se sube desde wp-admin sin necesidad de SSH. Probado: el plugin empaquetado pasó la prueba de punta a punta;
  - guía paso a paso en `docs/despliegue-hostinger.md`;
  - "Estado del sistema" en Ajustes, que revisa 13 puntos.
- [~] **(Maycol)** Crear el sitio en Hostinger, aparte del staging. Pasos 1 a 9 de la guía
  - [x] WordPress instalado desde cero. Por ahora está en el dominio temporal `papayawhip-caribou-477306.hostingersite.com`
  - [ ] Conectar `asistencia.pekokis.com`: verificación en Hostinger, registro A en Cloudflare con nube gris, SSL
  - [ ] Pasos 3 a 9 de la guía (PHP, caché, plugins, wp-config, zip 0.5.0, cron, correo, usuarios)
- [ ] **(Maycol)** Subdominio `asistencia.pekokis.com` en Cloudflare en modo solo DNS, más SSL de Hostinger. Paso 2 de la guía
- [ ] **(Maycol)** En el taller: agregar la red del WiFi y activar la pantalla. Paso 10 de la guía
- [x] Entorno local con Laragon:
  - WordPress 7.1 en español en `C:\laragon\www\asistencia` (`https://asistencia.test`; las passkeys exigen HTTPS, así que hay que activar el SSL de Laragon para abrirlo en el navegador), con base de datos `pka_asistencia` y WP-CLI en `C:\laragon\bin\wp-cli\wp-cli.phar`.
  - El plugin está enlazado (junction) a esta carpeta, así que los cambios se ven al instante.
  - El usuario de prueba está en `.env.local`, que no se sube al repositorio.
- [ ] Conseguir la tablet para la pantalla QR y su soporte en la entrada

### 1. Base
- [x] Núcleo en PHP puro (`src/Nucleo`) con 59 pruebas automáticas: estados, validación de marcas, presencia por IP y QR, pase de presencia, cadena de hashes y día laboral
- [x] Esqueleto del plugin, tema vacío, instalador (13 tablas, roles `pka_jefe` y `pka_rrhh`, motivos y horario base) y endurecimiento básico. Probado en WordPress 7.1 local
- [x] Perfiles de trabajadores (Asistencia → Trabajadores):
  - alta y edición con motivo, todo registrado en la auditoría;
  - cese, que revoca el celular y la llave;
  - búsqueda y lista de activos y cesados.
- [x] Horarios (Asistencia → Horarios):
  - crear y editar: días laborables, entrada y salida por día, tolerancia y refrigerio (mínimo legal de 45 min, o 0);
  - muestra las horas por semana (el máximo legal es 48) y cuántos trabajadores usa cada horario;
  - editar exige un motivo y queda en el historial como "Sábado: 09:00–13:00 → 08:00–13:00";
  - no se puede desactivar un horario en uso;
  - avisa que editar cambia también los días anteriores; para un cambio "desde una fecha", conviene crear otro horario.
- [x] Motivos de salida por defecto (catálogo en el núcleo; se cargan al instalar)

### 2. Identidad (passkeys)
- [x] Enlace de activación de un solo uso: vence en 24 h y el token va después de `#`. Con él se registra la passkey, con verificación del usuario obligatoria y attestation "none"
- [x] Identificación con huella y ticket de marca (2 min, un solo uso). Límite de 10 fallos por IP cada 15 min
- [x] Un celular por trabajador. Al cesar se revocan el celular y la passkey; para cambiar de celular se genera un enlace nuevo
- [x] Al activar un celular nuevo se revocan solos la llave y el celular anteriores; queda en la auditoría cuántas llaves se revocaron

### 3. Presencia
- [x] Redes autorizadas y modo de presencia (Asistencia → Ajustes): muestra tu IP, avisa si es CGNAT, permite agregar, activar y desactivar redes, y elegir uno de los 4 modos
- [x] Pantalla QR en `/pantalla/`:
  - se activa desde la misma tablet, con un administrador con sesión iniciada;
  - muestra el QR (SVG generado en el servidor con `chillerlan/php-qrcode`), el código de 6 dígitos, la cuenta regresiva y la hora;
  - mantiene la pantalla encendida (Wake Lock);
  - las pantallas se pueden desactivar en Ajustes.
- [x] Pase de presencia: `/m?q=…` (el QR escaneado) o el código de 6 dígitos que se escribe en la app cuando no está en el WiFi; luego se reintenta la marca sin volver a poner la huella. Límite de 5 códigos fallidos por IP cada 15 min
- [x] Alertas en el panel: pantalla sin conexión (más de 5 min) e **IP nueva**:
  - si hoy llegan 3 o más marcas por QR de 2 o más personas desde una red no autorizada, "Hoy" lo avisa;
  - Ajustes ofrece el botón "Sí, es la nueva IP del taller" (o desaconseja agregarla si es CGNAT);
  - en IPv6 agrupa por prefijo /64;
  - nunca la agrega sola.

### 4. Marcas
- [x] Máquina de estados, validación en el servidor, antirrebote y cadena de hashes. Usa un candado por trabajador y otro para la cadena en MySQL. API: `/identificar`, `/marcas` y `/presencia`
- [~] App del trabajador en `/` y `/activar/`: estado, botones válidos, motivos, nota, marcas de hoy, aviso de presencia y código de la pantalla. Ya se vio en el navegador (pantallas de inicio y de la tablet). Falta probar la huella real
- [x] "Mis marcas del mes" en la app:
  - días con marcas, horas en el taller, tardanzas con sus minutos, días sin salida y solicitudes pendientes;
  - se ve el mes actual y el anterior;
  - no gasta el ticket, así que después se puede marcar sin volver a poner la huella.
- [~] App instalable (PWA):
  - el manifest está en `/manifest.webmanifest`, con íconos de 192 y 512 px, incluido el adaptable de Android, y el ícono de iPhone;
  - el service worker en `/sw.js` solo guarda en caché los archivos estáticos, nunca la API ni las páginas.

  Falta comprobar en Chrome y en el celular que se instala: el navegador integrado de Claude no admite service workers, ni siquiera uno de prueba de una línea
- [x] Salida por emergencia sin presencia, marcada para revisión

### 5. Revisión y auditoría
- [x] Panel del día (Asistencia → Hoy):
  - muestra ausentes, quién llegó tarde (con los minutos), en refrigerio (avisa si se pasó del tiempo), fuera con motivo, presentes, por llegar y quién terminó;
  - se actualiza cada minuto;
  - el jefe ve solo a su equipo y RR.HH. ve a todos.
- [x] Revisión de marcas: aprobar o rechazar emergencias y salidas personales o de salud. Para rechazar hay que escribir una nota; queda en la auditoría
- [x] Solicitud "olvidé marcar":
  - el trabajador la pide desde la app (hoy o ayer, hora, marca y qué pasó), y la app le avisa si ayer quedó sin salida;
  - el jefe la aprueba o la rechaza con nota en "Hoy".
- [x] Marca manual del jefe y anulación de marcas, desde el detalle del día de cada trabajador:
  - antes de aplicar, se revisa que el día quede lógico (no dos entradas seguidas, no salidas sin entrada);
  - no se aceptan horas futuras ni de hace más de un mes;
  - la marca anulada queda en la tabla, tachada.
- [x] Visor del historial (Asistencia → Historial, rol RR.HH. o administrador):
  - quién hizo qué, cuándo, sobre quién, qué cambió ("antes → después", con horas de Lima y nombres en lugar de claves) y el motivo;
  - filtros por fechas, tipo, trabajador, quién lo hizo y texto, con páginas de 50 registros;
  - avisa si la cadena del historial fue alterada;
  - descarga en CSV para Excel (UTF-8, separado por punto y coma), y la descarga también queda registrada;
  - enlaces desde la lista de Trabajadores y desde el detalle del día.
- [x] ~~Cierre del día por cron~~ → se reemplazó por "Días anteriores por revisar" en Hoy (últimos 7 días, sin salida o laborables sin marcas). Se calcula al momento, así que nunca queda desactualizado cuando se aprueba una corrección

### 6. Exportes y respaldo
- [x] Export mensual del registro (Asistencia → Exportar), en Excel con tres hojas:
  - "Registro": empleador, RUC, ingreso y salida por día, refrigerio, salidas y tiempo en el taller;
  - "Marcas": todas, incluidas las anuladas, con prueba, origen, revisión y hash;
  - "Integridad": estado de la cadena y último hash.

  Cada descarga queda en la auditoría con el SHA-256 del archivo.
- [~] Respaldo mensual: el día 1 a las 06:00 se envía por correo el Excel del mes anterior (destino configurable), y también se puede enviar a mano. En Hostinger hace falta que el correo del sitio funcione (SMTP) y el cron real. Falta Google Drive vía Make

### 7. Calidad y puesta en marcha
- [x] Pruebas automáticas:
  - 91 del núcleo (`composer test`);
  - 98 de todo el flujo contra el WordPress local, con un autenticador simulado (`tests/integracion/flujo-marcas.php`);
  - `tests/integracion/datos-demo.php` carga 5 trabajadores de ejemplo, con casos de hoy y de ayer, para ver el panel.
- [ ] Pruebas en celulares reales: Android (Chrome) e iPhone (Safari)
- [ ] Aviso de datos personales para los trabajadores (revisado por el abogado)
- [ ] Piloto: una semana con 3 o 4 trabajadores y una semana con todos, en paralelo con el método actual

---

## Después del MVP (según lo que pida gerencia)

- [ ] **Fase 2 — Horas y sueldos:** varios horarios, calendario y feriados, cálculo del día, horas extra autorizadas, sábados, domingos y feriados, sueldos con historial, valor hora, permisos y sustentos, pre-planilla
- [ ] **Fase 3 — Dashboard e informes:** dashboard general, informe por trabajador, Excel y PDF
- [ ] **Fase 4 — Notificaciones:** correo y luego WhatsApp

---

## Decisiones pendientes

Vienen de la sección 16 del plan; se resuelven con gerencia y el contador.

- [ ] Jornada: ¿lunes a viernes? ¿Sábados? ¿Cuánto dura el refrigerio y en qué franja?
- [ ] Tolerancia y desde cuándo se cuenta la tardanza
- [ ] Qué salidas cuentan como tiempo trabajado
- [ ] Fórmula del valor hora y quién cobra por hora
- [ ] Horas extra: quién las autoriza; ¿pago o descanso?
- [ ] Qué jefe aprueba las solicitudes de quién
- [ ] ¿Todos tienen smartphone con bloqueo de pantalla?
- [ ] Texto del aviso de datos personales
- [ ] Método actual de asistencia y fecha del piloto

---

## Bitácora

### 14-11-2026
- **Las tres mejoras para el piloto (versión 0.3.0)**:
  - **Cambio de celular:** al activar uno nuevo se revocan la llave y el celular anteriores (`Dispositivos::revocarOtros`). Probado: la llave vieja recibe 401.
  - **"Mis marcas del mes":** `POST /yo/mes` y una pantalla en la app con el resumen y la lista por día. Se puede ver el mes actual o el anterior, y no gasta el ticket. En el núcleo se separó `ResumenDia::minutosTardanza` para reutilizarlo.
  - **PWA:** `/manifest.webmanifest`, `/sw.js` (solo guarda estáticos) e íconos generados con `scripts/generar-iconos.php` (reloj blanco a las 9:00 sobre rosa Pekokis).
- **Pruebas:**
  - 81 del núcleo y 75 de punta a punta (7 nuevas), todas en verde, con `debug.log` vacío;
  - el manifest, el service worker y los 3 íconos responden bien.
- **Sin probar:**
  - la instalación de la PWA, porque el navegador integrado de Claude no admite service workers (falla incluso con uno de una línea);
  - la pantalla "Mis marcas" vista con una huella real.

  Las dos cosas quedan en el checklist del piloto (filas 2, 11 y 12).
- Zip nuevo: `dist/pekokis-asistencia-0.3.0.zip` (203 archivos). Se borró el 0.2.0 y la guía apunta al nuevo.

- **Visor del historial (versión 0.4.0)**:
  - Clases `Historial` (consulta y texto legible) y `Admin/PantallaHistorial` (pantalla y CSV).
  - El filtro por trabajador incluye lo que hizo él mismo, lo que se hizo sobre su perfil y sobre sus marcas y solicitudes.
  - **Bug encontrado por la prueba:** lo que el trabajador hace desde su celular quedaba a nombre del usuario de wp-admin si en ese navegador había una sesión abierta. Ahora siempre queda a nombre del trabajador.
  - Pruebas: 86 de punta a punta (11 nuevas, del historial), 81 del núcleo y `debug.log` vacío.
  - En el navegador se revisaron la pantalla, el filtro por trabajador y el CSV, que trae BOM para que Excel lea bien las tildes.
  - Zip `dist/pekokis-asistencia-0.4.0.zip`; se borró el 0.3.0.

- **IP nueva y horarios (versión 0.5.0)**:
  - En el núcleo: `DeteccionRedNueva` y `ValidacionHorario` (10 pruebas). `RedAutorizada` ahora guarda los rangos como dirección de red (`190.42.10.77/24` → `190.42.10.0/24`).
  - En WordPress: `Presencia::redesCandidatas`, `Horarios` (listar, completo, guardar, cambiarEstado) y `Admin/PantallaHorarios`. El historial reconoce `horario.*`.
  - Pruebas: 91 del núcleo y 98 de punta a punta (12 nuevas), con `debug.log` vacío. La prueba de punta a punta ahora borra el horario que crea.
  - En el navegador se revisaron el aviso en Hoy, el botón en Ajustes (agrega la red) y la lista y el formulario de Horarios.
  - Zip `dist/pekokis-asistencia-0.5.0.zip`; se borró el 0.4.0.

- **Hostinger: el sitio ya existe**:
  - Al instalar WordPress, Hostinger respondió "You cannot create any more databases": el plan permite **2 bases de datos** y estaban ocupadas.
    - `u412994707_dObeD`: la del staging pekokis.com.mx; no se tocó.
    - `u412994707_SotTt` (889 MB): no estaba asignada a ningún sitio y era de la landing antigua **cuponesvueltaalcole.com**. Maycol la borró.
  - Con esa base libre, WordPress se instaló desde cero. Por ahora responde en el dominio temporal de Hostinger.
  - Con esto el plan queda con sus 2 bases ocupadas (staging y asistencia): un sitio más con base de datos exigiría liberar otra o subir de plan.
  - **Las passkeys se atan al dominio:** no se activa ningún celular hasta tener `asistencia.pekokis.com` con SSL.

### 13-11-2026
- Plan técnico escrito en `docs/plan-tecnico.md`: 7 módulos, 4 fases, modelo de datos, API, seguridad y aspectos legales.
- Se decidió hacer primero un MVP (fases 0 y 1) y mejorar después según gerencia.
- Se creó el repositorio con el remoto `origin` (https://github.com/MaycolPGR-aduch/Pekokis-asistencia.git). Todavía no tiene commits.
- Se comparó WordPress + plugin con PHP sin WordPress. La propuesta es WordPress con la lógica en PHP puro; falta la confirmación de Maycol.
- Aclaraciones sobre el stack:
  - Con WordPress basta el plugin. El tema es uno vacío de 2 archivos, solo porque WordPress exige tener uno activo.
  - Laravel funciona en Hostinger con la opción "Sitio web PHP/HTML" (SSH + Composer). Node.js no está incluido en el plan, así que los assets se compilan en local.
- El panel de Hostinger muestra 77 % de "Uso de recursos". Hay que revisarlo antes de crear el sitio.
- **Stack confirmado: WordPress + plugin.** Empezó el MVP:
  - Estructura: `src/Nucleo` (PHP puro) y `src/WordPress` (capa de WordPress), con PHP 8.2 como mínimo.
  - El tema vacío vive dentro del plugin (`temas/pka-vacio`) y se registra con `register_theme_directory`, así todo se despliega como una sola pieza.
  - Núcleo hecho: estados y botones, validación de marcas (4 modos de presencia, antirrebote de 2 min, emergencia sin presencia), redes autorizadas con IPv6 y CGNAT, código QR y de 6 dígitos cada 30 s, pase de presencia de 3 min, cadena de hashes y día laboral con corte a las 04:00.
  - 59 pruebas automáticas en verde.
  - Instalador escrito: 13 tablas, roles, motivos, horario base, ajustes (razón social y RUC tomados del Libro de Reclamaciones) y secreto. Falta probarlo en WordPress.
  - El esquema se actualiza solo cuando cambia la versión de la base de datos, porque el despliegue con `git pull` no ejecuta el hook de activación.
- Decisión de diseño: los trabajadores no son usuarios de WordPress, se identifican solo con su passkey. Se actualizó el plan (secciones 3, 4, 6.1 y 7).
- Primer commit `e842da8` subido a GitHub (`origin/main`).
- Entorno local con Laragon:
  - MySQL 8.4 arranca bien y se creó la base `pka_asistencia`.
  - La descarga de WordPress y WP-CLI desde internet quedó bloqueada por los permisos de Claude Code. Maycol dio permiso y se instaló WordPress 7.1 en español.
- **Instalador probado en WordPress local**:
  - El plugin se activa sin errores ni avisos en `debug.log`.
  - Están las 13 tablas `wp_pka_*` con sus índices.
  - Los 7 motivos tienen sus reglas, y el horario base es de lunes a viernes, de 9:00 a 19:30, con 5 min de tolerancia y 60 min de refrigerio.
  - Los roles tienen sus capacidades, y el administrador las tiene todas.
  - Los ajustes están cargados, y el secreto tiene 64 caracteres y no se autocarga.
  - Volver a ejecutar el instalador (al desactivar y activar, o al cambiar la versión) no duplica nada.
  - El tema vacío se activa desde la carpeta del plugin.
  - Endurecimiento: XML-RPC y las contraseñas de aplicación están apagados, `/wp/v2/users` devuelve 404 a los anónimos y `blog_public` vale 0.
- Detalle: `stylesheet_root` guarda la ruta real del plugin. En local apunta a la carpeta de Downloads por el junction; en Hostinger el plugin estará dentro de `wp-content`.
- Notas del entorno:
  - `wp db query` no funciona en esta PC porque `mysql` no está en el PATH; se usa el cliente de Laragon.
  - MySQL se arrancó desde Claude con `mysqld --standalone`; también se puede arrancar desde Laragon.
- **Perfiles, passkeys y marcas**:
  - Se agregó la librería `lbuchs/webauthn` 2.2 (Composer).
  - Clases nuevas en `src/WordPress`: `Trabajadores`, `Activaciones`, `Dispositivos`, `Passkeys`, `Marcas`, `Motivos`, `Presencia`, `Auditoria`, `InsercionEncadenada`, `Temporales`, `Peticion`, `Api` y `Rutas`. En `Admin/` van las pantallas Trabajadores y Ajustes.
  - En el núcleo, `ValidacionTrabajador` valida los datos del perfil, y `Base64Url` ahora también decodifica.
  - App del trabajador sin dependencias (`assets/app.js` y `app.css`), con CSP estricta.
- **Bug encontrado por la prueba de punta a punta**: el reto de la passkey es binario y se perdía al guardarlo en un transient (columna de texto utf8mb4). Ahora se guarda en base64url. Sin la prueba habría fallado en producción.
- El sitio local ahora es `https://asistencia.test`. Para abrirlo en el navegador hay que activar el SSL de Laragon (Menú → Apache → SSL).
- **Sin probar todavía**:
  - la app y las pantallas de wp-admin en un navegador real (la lógica ya se probó por la API);
  - una passkey real con huella, en Windows Hello o en un celular.
- Decisión: el refrigerio y el fin de jornada no piden elegir motivo, porque cada uno tiene uno solo.
- **Pantalla QR, panel del día y revisión**:
  - Se agregó la librería `chillerlan/php-qrcode` 6.0.
  - Clases nuevas: `Pantallas`, `Horarios`, `Revisiones` y `Admin/PantallaHoy`. En el núcleo, `HorarioDelDia` y `ResumenDia` (9 pruebas).
  - El menú Asistencia ahora abre en "Hoy"; Trabajadores pasó a `page=pka-trabajadores`.
  - Rutas nuevas: `/pantalla/`, `/m`, `POST /presencia/codigo`, `GET /pantalla/codigo` y `POST /pantalla/activar` (solo administradores, con nonce).
- **Revisión en el navegador** (WordPress local servido con `php -S` en `http://localhost:8080`, con un ajuste solo local en `wp-config.php`):
  - Hoy, Trabajadores, Ajustes, la app y la pantalla se ven bien.
  - La pantalla rota el código cada 30 s.
  - **Bug encontrado:** Ajustes daba error fatal porque `checked()` no acepta enums de PHP. Corregido.
- Pendiente de decidir: la tardanza se cuenta desde las 9:00 cuando se pasa la tolerancia; está por confirmar con gerencia.
- **Correcciones, export y respaldo**:
  - Se agregó la librería `shuchkin/simplexlsxgen` 1.5.
  - Clases nuevas: `Correcciones`, `Seguimiento`, `Exportes`, `Respaldo`, `Admin/PantallaDia` y `Admin/PantallaExportar`. En el núcleo, `TotalesDia` y `MaquinaEstados::problemaEnSecuencia` (7 pruebas).
  - Rutas nuevas: `POST /correcciones`. La app tiene "Olvidé marcar" y el aviso de que ayer quedó sin salida.
  - Ajustes: nuevo `correo_rrhh`. Cron diario `pka_diario`, que se desprograma al desactivar el plugin.
- **Probado en el navegador**: aprobar una solicitud y anular una marca desde los formularios, el detalle del día, la página Exportar y la descarga del Excel (7 KB, con SHA-256 en la cabecera).
- **Sin probar todavía**: el formulario "Olvidé marcar" de la app en pantalla, porque hace falta una passkey real (por la API sí está probado), y el envío real de correo (se probó interceptando `wp_mail`).
- **Preparación del despliegue (versión 0.2.0)**:
  - `Diagnostico`: "Estado del sistema" en Ajustes revisa PHP, extensiones, librerías, HTTPS, secreto en wp-config, enlaces permanentes, tema, plugins de caché, errores visibles, cron, cron real, correo y presencia.
  - Límite de acceso a wp-admin: 5 contraseñas equivocadas desde una IP la bloquean 15 minutos; desde otra IP se sigue entrando. Probado.
  - Si falta `vendor/`, el plugin muestra un aviso en lugar de un error fatal.
  - `scripts/empaquetar.php` arma `dist/pekokis-asistencia-0.2.0.zip` (200 archivos, 1,3 MB). Se probó instalando el paquete en el WordPress local: pasó la prueba de punta a punta sin PHPUnit.
  - Guía `docs/despliegue-hostinger.md`: 11 pasos, del sitio en hPanel al checklist del piloto en Android e iPhone.
- **Corrección de un dato:** la prueba de punta a punta tiene **68** comprobaciones, no 70 como se anotó antes. Se contaron directamente en el archivo.
- **Bug del entorno local (lo encontró Maycol):** `http://localhost:8080/wp-admin/` salía en blanco.
  - Causa: el router del servidor de PHP no buscaba el `index.php` de las carpetas, así que mandaba esa dirección a la parte pública, donde el tema vacío responde con una página vacía. En las pruebas siempre se había entrado por `/wp-admin/admin.php?...`, por eso no se notó.
  - Corrección: router nuevo dentro del proyecto, en `scripts/servidor-local.php` (antes estaba en una carpeta temporal). Ahora `/wp-admin` redirige a `/wp-admin/` y sirve su `index.php`.
  - Se comprobaron 9 rutas: inicio, wp-admin con y sin barra, login, activar, pantalla, API, CSS y estilos del panel.
  - No afecta a Hostinger, donde Apache o LiteSpeed resuelven las carpetas solos.

### 12-11-2026
- Se definió el enfoque:
  - marca desde el celular propio con passkey;
  - presencia por WiFi del taller o por QR que cambia cada 30 s en una pantalla;
  - salidas con motivo; las correcciones nunca editan la marca original.
- Se eligió Hostinger con PHP y MySQL; Supabase y Neon quedaron descartados.
- Se descartaron el enlace que rota cada 30 minutos, el GPS como bloqueo y el reconocimiento facial.
