# Control de tareas — Sistema de asistencia Pekokis

Este archivo lleva lo hecho, lo pendiente y la bitácora del proyecto. Se actualiza cada vez que avanzamos.
El detalle técnico de cada punto está en [docs/plan-tecnico.md](docs/plan-tecnico.md).

**Estado actual:** MVP en marcha. El núcleo y el instalador están hechos y probados en un WordPress local (Laragon). Lo siguiente: perfiles de trabajadores, passkeys y la API de marcas.

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
- [ ] Crear el sitio en Hostinger, aparte del staging
- [ ] Subdominio `asistencia.pekokis.com` en Cloudflare en modo solo DNS, más SSL de Hostinger
- [x] Entorno local con Laragon:
  - WordPress 7.1 en español en `C:\laragon\www\asistencia` (`http://asistencia.test`), con base de datos `pka_asistencia` y WP-CLI en `C:\laragon\bin\wp-cli\wp-cli.phar`.
  - El plugin está enlazado (junction) a esta carpeta, así que los cambios se ven al instante.
  - El usuario de prueba está en `.env.local`, que no se sube al repositorio.
- [ ] Conseguir la tablet para la pantalla QR y su soporte en la entrada

### 1. Base
- [x] Núcleo en PHP puro (`src/Nucleo`) con 59 pruebas automáticas: estados, validación de marcas, presencia por IP y QR, pase de presencia, cadena de hashes y día laboral
- [x] Esqueleto del plugin, tema vacío, instalador (13 tablas, roles `pka_jefe` y `pka_rrhh`, motivos y horario base) y endurecimiento básico. Probado en WordPress 7.1 local
- [ ] Perfiles de trabajadores: alta, edición con auditoría y cese
- [~] Horario base 9:00–19:30 con 5 min de tolerancia y 60 min de refrigerio: se crea al instalar, pero falta su pantalla
- [x] Motivos de salida por defecto (catálogo en el núcleo; se cargan al instalar)

### 2. Identidad (passkeys)
- [ ] Enlace de activación de un solo uso y registro de la passkey
- [ ] Identificación con huella y ticket de marca (2 min, un uso)
- [ ] Un celular por trabajador; revocar passkey y celular

### 3. Presencia
- [~] Redes autorizadas y modo de presencia: el núcleo está hecho (IP exacta, rangos, prefijo IPv6, detección de CGNAT y los 4 modos); falta la pantalla de administración
- [~] Pantalla QR: los tokens y el código de 6 dígitos cada 30 s están hechos; falta la página de la tablet y su activación
- [~] Pase de presencia por QR o código: el pase firmado de 3 min está hecho; falta la API y el límite de intentos
- [ ] Alertas en el panel: pantalla sin conexión e IP nueva

### 4. Marcas
- [~] Máquina de estados, validación en el servidor, antirrebote y cadena de hashes: el núcleo está hecho y probado; falta guardar las marcas en la base de datos (con bloqueo para la cadena) y la API
- [ ] App del trabajador (PWA): estado, botones válidos, marcar y "mis marcas"
- [ ] Salida por emergencia sin presencia, marcada para revisión

### 5. Revisión y auditoría
- [ ] Panel del día: presentes, tarde, ausentes, en refrigerio y fuera
- [ ] Solicitud "olvidé marcar" y su aprobación
- [ ] Marca manual del jefe con motivo
- [ ] Visor de auditoría
- [ ] Cierre del día por cron: días incompletos y faltas a revisar

### 6. Exportes y respaldo
- [ ] Export mensual del registro de asistencia (Excel + hash)
- [ ] Respaldo mensual fuera de Hostinger (correo a RR.HH. y Google Drive)

### 7. Calidad y puesta en marcha
- [x] Pruebas automáticas de validación de marcas (núcleo: `composer test`)
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

### 12-11-2026
- Se definió el enfoque:
  - marca desde el celular propio con passkey;
  - presencia por WiFi del taller o por QR que cambia cada 30 s en una pantalla;
  - salidas con motivo; las correcciones nunca editan la marca original.
- Se eligió Hostinger con PHP y MySQL; Supabase y Neon quedaron descartados.
- Se descartaron el enlace que rota cada 30 minutos, el GPS como bloqueo y el reconocimiento facial.
