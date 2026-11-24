# Checklist del piloto · Asistencia Pekokis

_Actualizado: 24-11-2026. Copia del documento "Checklist del piloto · Asistencia Pekokis" en Claude Docs (para imprimir o exportar a PDF desde allá)._

Todo lo que hay que dejar listo y hacer el día del piloto, en orden. Marca cada casilla al terminarla. Sistema: asistencia.pekokis.com (versión 0.20). Modo: WiFi del taller **y** QR, el QR cambia cada 30 minutos.

## 1 · Antes del día (desde la oficina)

Se hace en la computadora, en `asistencia.pekokis.com/wp-admin`, uno o dos días antes.

- [ ] **Subir la versión 0.20.1** (Plugins → Añadir nuevo → Subir plugin → Reemplazar). Comprobar en Seguridad y reglas que diga *✔ todo en orden*.
- [ ] **Quitar los trabajadores de prueba**: Perfiles → *Cesar* a Julia Jefa (prueba), Beto Prueba Dos, Carla Prueba Tres y Ana Prueba Uno.
- [ ] **Borrar los usuarios de prueba**: Usuarios → carla.prueba, prueba.gerencia, prueba.jefe y prueba.rrhh.
- [ ] **Quitar la red de prueba**: Seguridad y reglas → Presencia → *Casa de Maycol* → Desactivar (hacerlo después de agregar la del taller, paso 2).
- [ ] **Dar de alta a cada persona del piloto** en Perfiles: DNI, nombres, cumpleaños, teléfono, horario y jefe que aprueba.
- [ ] **Honorario y tarifas de cada una** en Sueldos y tarifas (honorario mensual y hora extra, sábado, domingo y feriado). Brigitte hoy no tiene.
- [ ] **Revisar el horario base** (Horarios → Horarios base): 9:00–19:30, tolerancia 5 min, refrigerio 60 min.
- [ ] **Confirmar con el responsable** las reglas: comida descontada si no se marca (60 min), revisar jornadas de más de 16 h, tardanza desde la hora de entrada.
- [ ] **Aviso de datos personales** revisado por el abogado (Seguridad y reglas → Empresa y datos personales).
- [ ] **Cuentas de gerencia y RR.HH.** creadas con su rol y su contraseña (mientras no haya correo, la contraseña se pone a mano y se entrega en persona).
- [ ] **Cambiar `PKA_SECRETO`** en wp-config.php (lo hace Maycol).
- [ ] **Imprimir el manual** o tenerlo abierto: Manual de uso · Asistencia Pekokis.
- [ ] Opcional: **configurar el correo** (WP Mail SMTP) para que lleguen contraseñas, avisos y el respaldo mensual.

## 2 · En el taller, antes de que lleguen

Sin el WiFi registrado **y** la pantalla del QR encendida, nadie puede marcar. Hazlo 30 minutos antes de la hora de entrada.

- [ ] **Registrar el WiFi del taller**: con un celular o laptop **conectado al WiFi del taller**, entra a Seguridad y reglas → Presencia → *Agregar red* (el campo ya trae la IP). Nombre: "WiFi del taller".
- [ ] Si sale el aviso de **IP compartida (CGNAT)**, no la agregues y avisa a Maycol (habría que usar *Solo QR*).
- [ ] **Activar la tablet del QR**: en la tablet, entra a `wp-login.php` con un usuario administrador o de RR.HH., abre `asistencia.pekokis.com/pantalla/` y toca **Usar este equipo como pantalla**. Después se puede cerrar la sesión.
- [ ] **Dejar la tablet fija**: enchufada, brillo alto, sin bloqueo automático (iPad: Acceso guiado; Android: Fijar pantalla).
- [ ] Comprobar que la pantalla muestra el QR, "Se actualiza en N min", la fecha y la hora.
- [ ] En la computadora: Dashboard sin el aviso *"La pantalla no se conecta"*.
- [ ] **Prueba de marca** con un celular ya activado (por ejemplo el de Maycol): conectado al WiFi del taller → Escanear el QR → Marcar entrada. Debe aparecer en **Asistencia de hoy**.
- [ ] Desactivar la red de prueba *Casa de Maycol* y la pantalla de prueba vieja (Seguridad y reglas → Presencia).

## 3 · Con cada trabajador

Una persona a la vez, en el taller, con **su propio celular** (Android con Chrome o iPhone con Safari) y con el bloqueo de pantalla activado.

- [ ] Generar su **enlace de activación** (Perfiles → su ficha → Celular → Enlace de activación) y enviárselo por WhatsApp. Vence en 24 h y sirve una vez.
- [ ] En su celular: abrir el enlace → leer el aviso de datos → marcar la casilla → **Crear mi llave** (huella, rostro o PIN del celular).
- [ ] **Instalar la app**: Android → menú ⋮ → Instalar app; iPhone → Compartir → Agregar a inicio.
- [ ] **Primera marca**: conectado al WiFi del taller → Escanear el QR (en iPhone, escribir el código de 6 números) → **Marcar entrada** → huella.
- [ ] Ver que aparece en **Asistencia de hoy** con su hora.
- [ ] Enseñarle **Salir a comer**, **Volver de comer**, **Marcar salida** y **¿Olvidaste marcar algo?**.
- [ ] Opcional: que cree su **PIN de ingreso** en Perfil (sirve para entrar si la huella falla; para marcar sigue pidiendo la huella).

Si la persona prefiere registrarse sola: en el taller, **Es mi primera vez** → datos → Crear mi llave → *Registro enviado*, y gerencia lo aprueba en **Celulares**.

| Persona | Celular (Android / iPhone) | Activado | Primera marca |
| --- | --- | --- | --- |
|  |  | ☐ | ☐ |
|  |  | ☐ | ☐ |
|  |  | ☐ | ☐ |
|  |  | ☐ | ☐ |
|  |  | ☐ | ☐ |

## 4 · Gerencia durante el día

- [ ] Entrar con su usuario y contraseña en `asistencia.pekokis.com/wp-login.php`.
- [ ] En su celular o iPad: **Perfil → Activar huella o rostro en este equipo**. La próxima vez, **Entrar con huella o rostro**.
- [ ] Abrir la versión para celular: `asistencia.pekokis.com/gerencia/` (en el iPad sale la barra de íconos a la izquierda).
- [ ] Revisar a media mañana **Asistencia de hoy**: quién llegó, quién no, quién llegó tarde.
- [ ] Revisar **Requiere tu atención** en el Dashboard (o Inicio en el celular).
- [ ] Resolver las **Solicitudes** que entren: aprobar tal cual, con otra hora o rechazar (con respuesta).

## 5 · Al cierre del día

- [ ] Recordar a todos **Marcar salida** antes de irse (la jornada no se cierra sola).
- [ ] En **Asistencia de hoy**: nadie debe quedar "En el taller" o "En comida" después de irse.
- [ ] Resolver las **Solicitudes** pendientes y las **marcas por revisar** (emergencias, salidas personales).
- [ ] Al día siguiente, en **Registros y revisión**: corregir con *Editar* los días incompletos (pide motivo, queda en la auditoría).
- [ ] Anotar lo que la gente preguntó o no entendió, para el manual y la capacitación.

## 6 · Si algo falla

| Qué pasa | Qué hacer |
| --- | --- |
| *Para marcar tienes que estar en el taller* | Conectarse al WiFi del taller **y** escanear el QR (en iPhone, el código de 6 números) |
| La tablet dice *Sin conexión* | Revisar el WiFi o el cable de la tablet; nadie puede marcar hasta que vuelva |
| La tablet dice *Pantalla sin activar* | Volver a activarla (paso 2): entrar con un usuario administrador y abrir `/pantalla/` |
| No pide la huella o dice que el navegador no sirve | Abrir en Chrome (Android) o Safari (iPhone) y activar el bloqueo de pantalla del celular |
| El enlace de activación no funciona | Venció (24 h) o ya se usó: generar otro en su ficha |
| *Este celular ya está activado para otra persona* | Cada uno usa su propio celular. Si es un cambio, liberar el anterior en **Celulares** |
| Se olvidó de marcar | **¿Olvidaste marcar algo?** en la app, y gerencia lo aprueba en Solicitudes |
| Cambió o perdió el celular | En el taller: **Cambié de celular**, o un enlace de activación nuevo |
| Gerencia no puede entrar | Esperar 15 min tras 5 intentos fallidos; si olvidó la contraseña, el administrador la cambia en Usuarios |
| Algo raro o un error en pantalla | Tomar captura y enviarla a Maycol |

## Datos del piloto (llenar a mano)

| Dato | Valor |
| --- | --- |
| Fecha de inicio |  |
| Responsable en el taller |  |
| Contacto técnico | Maycol |
| Red WiFi del taller (nombre) |  |
| Tablet del QR (modelo y lugar) |  |
| Personas del piloto |  |
| Hora de entrada / salida | 9:00 / 19:30 |
| Fecha de revisión del piloto |  |
