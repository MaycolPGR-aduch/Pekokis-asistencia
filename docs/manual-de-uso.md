# Manual de uso · Asistencia Pekokis

_Actualizado: 20-11-2026 · versión 0.16. Copia del documento "Manual de uso · Asistencia Pekokis" en Claude Docs; si se cambia allá, actualizar este archivo._

Cómo usar el sistema de asistencia del taller (asistencia.pekokis.com): qué hace cada persona, paso a paso, desde marcar la entrada hasta pagar el mes. Versión del sistema: 0.16.

## Quién usa qué

Hay dos lugares: la **app del trabajador**, en el celular, y el **panel de gerencia**, en la computadora o el celular. Los trabajadores no tienen usuario ni contraseña: entran con la huella, el rostro o el bloqueo de su propio celular.

| Quién | Dónde entra | Qué puede hacer |
| --- | --- | --- |
| Trabajador | `asistencia.pekokis.com` en su celular (Android con Chrome, iPhone con Safari) | Marcar, ver sus horas, pedir correcciones, enviar su recibo por honorarios |
| Jefe de área | Panel: `asistencia.pekokis.com/wp-admin` o `/gerencia/` en el celular | Ver a su equipo, aprobar o rechazar sus solicitudes y marcas |
| RR.HH. | Panel | Todo lo del jefe, para todos, más personal, sueldos, pagos, recibos, reglas, exportar y auditoría |
| Gerencia (solo lectura) | Panel | Ve todo, el historial y descarga el Excel; no corrige nada |
| Supervisor delegado | Panel | Solo lo que RR.HH. le delegue (ver *Delegar permisos*) |
| Administrador | Panel | Lo mismo que RR.HH. y, además, WordPress (usuarios, plugins) |

Nada se borra ni se edita a escondidas: cada marca queda guardada tal como se hizo, y cada corrección o aprobación queda en la **Auditoría** con el nombre de quien la hizo.

## Trabajador · la primera vez

Cada persona registra **su propio celular, una sola vez**. Necesita Chrome (Android) o Safari (iPhone) y el celular con bloqueo de pantalla (huella, rostro o PIN). Un celular sirve para una sola persona.

**Camino A · con enlace de activación** (gerencia ya creó su perfil)

1. RR.HH. entra a **Asistencia → Perfiles**, abre a la persona → pestaña **Celular** → **Enlace de activación**.
2. Copia el enlace y se lo envía por WhatsApp. Vence en **24 horas**, sirve **una sola vez** y no se vuelve a mostrar.
3. La persona lo abre **en su propio celular**, lee el aviso de datos personales y marca la casilla.
4. Toca **Crear mi llave**: el celular pide la huella, el rostro o su PIN. Listo, ya está dentro.

**Camino B · registro propio** (persona nueva; se hace **dentro del taller**)

1. En el celular abre `asistencia.pekokis.com` → **Es mi primera vez**.
2. Llena nombre, apellido, celular o WhatsApp, DNI y cumpleaños, y acepta el aviso de datos.
3. Toca **Crear mi llave** → sale **Registro enviado**.
4. Gerencia lo aprueba en **Asistencia → Celulares y llaves** (*Esperando aprobación* → **Aprobar**). Desde ese momento puede entrar.

Si la persona ya estaba registrada pero cambió de celular, usa **Cambié de celular** en el taller: busca su perfil, confirma DNI y cumpleaños y crea la llave nueva. El celular anterior sigue marcando hasta que gerencia apruebe el cambio; después ya no entra.

**Instalar la app** (recomendado, para tener el ícono en la pantalla):

- Android: en Chrome, menú ⋮ → **Instalar app**.
- iPhone: en Safari, **Compartir → Agregar a inicio**.

## Trabajador · marcar cada día

Para marcar hay que **estar en el taller** y poner la huella o el rostro en **cada marca**. La app dice arriba si se puede: *Estás en el WiFi del taller · Puedes marcar*.

1. Abre la app → **Entrar con mi huella** (o **Entrar con PIN**, si lo creó; el PIN solo abre la app, para marcar igual pide la huella).
2. En la pestaña **Marcar**, el botón grande muestra lo que toca: **Marcar entrada**.
3. Al almorzar: **Salir a comer** y, al regresar, **Volver de comer**.
4. Si sale por otra cosa: **Otra salida (encargo, salud…)** → elige el motivo y, si quiere, una nota → **Marcar con mi huella**. Al regresar: **Volver al taller**.
5. Al terminar el día: **Marcar salida**. Si vuelve a trabajar después, aparece **Volví a trabajar: marcar entrada**.

Debajo, **Tu día** muestra los pasos (Entrada, Inicio de comida, Fin de comida, Salida) con su hora, y cuánto trabajó y cuánto falta.

**Cómo se comprueba que está en el taller** (lo elige gerencia en *Seguridad y reglas*):

- **WiFi del taller:** conectado al WiFi del taller, marca directo.
- **QR:** sin WiFi, toca **Escanear el QR** y apunta a la pantalla de la entrada. En iPhone se escribe el **código de 6 números** que sale debajo del QR.
- Con datos móviles y sin QR, **no deja marcar**.

**Motivos de salida**

| Motivo | Cuenta como trabajado | Lo revisa gerencia |
| --- | --- | --- |
| Refrigerio | No | No |
| Encargo de Pekokis | Sí | No |
| Personal / trámite | No | Sí |
| Salud / cita médica | No | Sí |
| Lactancia | Sí | No |
| Emergencia | No | Sí (es la única que se puede marcar fuera del taller) |
| Fin de jornada | — | No |

**Si se olvidó de marcar**, toca **¿Olvidaste marcar algo?**, elige la marca que falta y la hora → **Enviar a mi jefe**. La marca cuenta recién cuando la aprueban. Si ayer no marcó la salida, la app lo avisa al día siguiente (**Salí ayer y olvidé marcar**). Si la jornada pasa la medianoche, la app sigue en el día en que entró.

## Trabajador · Mis horas, Mi recibo y Perfil

La app tiene cuatro pestañas abajo: **Marcar**, **Mis horas**, **Mi recibo** y **Perfil**. Fuera del taller se puede entrar a ver todo; solo marcar exige estar en el taller.

**Mis horas**

- El calendario del mes con colores, las horas trabajadas, los días completos y las horas extra. Las flechas ‹ › cambian de mes.
- Toca un día para ver sus marcas. Si falta una o hay un error, toca **Pedir corrección**, pon la hora y un comentario.
- La respuesta de gerencia aparece en ese mismo día: aprobada, aprobada con otra hora o rechazada.

**Mi recibo** (recibo por honorarios)

1. Cuando cierra el mes, aparece el mes para enviar.
2. Escribe el **número del recibo** (serie y número, ej. E001-482), el **monto** en soles y adjunta el **PDF**.
3. Toca **Enviar recibo**. Mientras gerencia no lo revise, se puede **Anular envío** si hubo un error.
4. Gerencia lo marca como **Recibido** o lo **Observa** con un comentario; si lo observa, se corrige y se vuelve a enviar.

**Perfil**

- Sus datos (el DNI se ve al tocar **Ver**). Si algún dato está mal, se le pide a RR.HH.
- **PIN de ingreso:** crear o cambiar un PIN de 6 números para abrir la app sin huella. Marcar sigue pidiendo la huella. Un PIN equivocado dice cuántos intentos quedan.
- **Liberar este celular:** si la persona va a dejar de usar ese celular, lo pide y gerencia lo aprueba.
- **Cerrar sesión.**

**Cambio de celular:** en el celular nuevo, dentro del taller, **Cambié de celular** (ver *La primera vez*). Si no tiene su cumpleaños registrado, RR.HH. le genera un enlace de activación nuevo.

## Gerencia · entrar al panel

Gerencia, RR.HH. y los jefes entran por `asistencia.pekokis.com/wp-login.php` con su usuario y contraseña. La cuenta la crea un administrador en **Usuarios → Añadir nuevo**, con el rol que corresponde (ver *Quién usa qué*).

**Entrar con huella o rostro** (sin escribir la contraseña):

1. Entra una vez con la contraseña desde el equipo que vas a usar: celular (iPhone con Safari o Android con Chrome), tablet o laptop con huella.
2. Ve a **Perfil** (arriba a la derecha, o en el celular **Más → Entrar con huella o rostro**) → **Activar huella o rostro en este equipo**.
3. La próxima vez, en la página de acceso toca **Entrar con huella o rostro**.

Se activa una vez en cada equipo, hasta 10 por persona, y se pueden quitar desde el Perfil. La contraseña sigue sirviendo. Las llaves de los trabajadores no abren el panel.

Mientras en producción esté la versión 0.16.0, esos botones dicen *Face ID o huella*; desde la 0.16.1 dicen *huella o rostro*. Funcionan igual.

**Seguridad:** después de 5 intentos fallidos en 15 minutos, el acceso desde esa conexión se bloquea 15 minutos.

**Computadora o celular:** desde la computadora se abre el panel completo (menú **Asistencia** a la izquierda). Desde un celular se abre directo la **versión para celular** (`/gerencia/`). Las dos muestran los mismos datos.

## Gerencia · el día a día

Todo está en el menú **Asistencia**, agrupado en Control, Pagos, Personas y Sistema. Cada jefe ve solo a su equipo; RR.HH. y gerencia ven a todos.

**Dashboard** (la primera pantalla)

- Cuatro cifras del día: en el taller, llegaron tarde, sin marcar y solicitudes por decidir.
- Las horas por día de la semana.
- **Requiere tu atención:** lo pendiente, con un botón para ir a resolverlo. Incluye ausentes, solicitudes, marcas por revisar, recibos sin revisar y una pantalla del QR que no se conecta.

**Asistencia de hoy**

- Una fila por persona con su situación: en el taller, sin marcar, llegó tarde, en comida, fuera, terminó o ausencia justificada.
- Las pestañas de arriba filtran por situación, cada una con su número (ej. *Todos 4 · Sin marcar 4*).
- La pantalla se actualiza sola cada minuto y conserva el filtro.

**Solicitudes y revisión**

- **Correcciones** que piden los trabajadores (olvidé marcar, hora equivocada). Cada una se resuelve de tres formas: **aprobar tal como se pidió**, **aprobar con otra hora** o **rechazar**, con una respuesta opcional que el trabajador ve en su app.
- **Marcas por revisar:** emergencias, salidas personales o de salud, marcas sin comprobar que estaba en el taller y jornadas de más de 16 horas.
- No se puede aprobar una marca propia.

**Registros y revisión**

- Todos los días de todas las personas, con los incompletos primero. Se filtra por persona, fechas y estado.
- **Editar** un día: agregar o corregir una marca (por ejemplo, la salida que faltó). Siempre pide el **motivo del cambio**, y queda en la Auditoría y en la app del trabajador.

**Celulares y llaves**

- **Esperando aprobación:** registros nuevos, cambios de celular y pedidos de liberar. Se resuelven con **Aprobar** o **Rechazar**.
- **Celular de cada persona:** qué equipo usa (ej. *Android · Chrome*) y desde cuándo. **Liberar celular** lo deja sin acceso; la persona tendrá que registrar otro en el taller. Sus datos y horas no se pierden.

## Gerencia en el celular

En el celular, el panel se abre en `asistencia.pekokis.com/gerencia/`, con una barra abajo. Usa los mismos datos y permisos que la computadora.

| Pestaña | Qué muestra |
| --- | --- |
| Inicio | Las cuatro cifras del día, lo que hay por pagar del mes y *Requiere tu atención*; cada aviso abre lo suyo en el celular |
| Hoy | Una tarjeta por persona con su situación y filtros arriba. Avisa si alguien pasó la medianoche sin salir |
| Pagos | Solo para quien ve sueldos. Mes, *Antes de pagar*, cada persona con su estado, *Cómo se calculó* (horas × tarifa), **Marcar como pagado** y la planilla de las listas |
| Solicitudes | Con su número. Al tocar una sube una hoja con el día como está, lo que pide y las tres opciones: tal cual, con otra hora o rechazar |
| Más | El menú completo (Recibos, Seguridad y reglas, etc.), activar huella o rostro, la versión de computadora y Cerrar sesión |

Al tocar a una persona se abre su **calendario del mes** y el detalle de cada día. Con **Editar este día** se corrige en el panel de Registros. En **Recibos** se marca cada recibo como **Recibido**, se **Observa** o se le **Recuerda** a la persona.

## Pagos y recibos

El pago del mes es el **honorario mensual** más las **horas adicionales × su tarifa**. Las horas salen solas de la asistencia: no se digitan. Solo lo ve quien tiene permiso de sueldos (RR.HH. y administrador).

**Sueldos y tarifas** (se configura una vez y se cambia cuando haya aumentos)

- Una fila por persona: honorario mensual y 4 tarifas por hora (extra, sábado, domingo y feriado).
- Lo cambiado se resalta y se cuenta arriba (*2 cambios sin guardar*). Al guardar se elige **desde cuándo rige**; los meses anteriores no cambian.
- Cada cambio queda en el historial de la ficha de la persona.

**Cómo se cuentan las horas**

- **Horas extra:** de lunes a viernes, lo que pase de la jornada efectiva (9 h 30 min con el horario base 9:00–19:30). Son automáticas, no se autorizan.
- **Sábado, domingo y feriado:** todas las horas de ese día, con su tarifa.
- **Comida no marcada:** se descuentan 60 minutos (se cambia en *Seguridad y reglas → Reglas de horas*).

**Calculadora de pagos** (Asistencia → Pagos)

1. Elige el mes. Arriba sale el **total del mes** (honorarios + horas).
2. Revisa **Antes de pagar**: personas con días por resolver, personas sin honorario y el estado de los recibos. Cada aviso lleva a donde se arregla.
3. En cada persona, **Ver** muestra *Cómo se calculó*: horas por tipo × tarifa, y el monto del recibo comparado con el cálculo.
4. Cuando se le paga, **Marcar pagado**. Si fue un error, **Desmarcar**.
5. **Planilla de las listas** o **Planilla de todas** descargan el Excel para pagar.

Si cambia una tarifa o se corrige un registro, el cálculo se actualiza solo. El recibo no frena el pago: puede llegar después.

**Recibos por honorarios** (Asistencia → Recibos por honorarios)

- Por mes, con filtros por estado: por revisar, recibidos, observados y faltan.
- Abre el **PDF** desde el panel (el archivo no se puede abrir con su dirección directa).
- **Recibido** lo da por bueno. **Observar** lo devuelve con un comentario para que lo corrija. **Recordar** le avisa a quien todavía no lo envía.

## Informes y exportación

| Pantalla | Para qué sirve |
| --- | --- |
| Resumen del mes | Cómo va el mes, los totales por trabajador (días, tardanzas, horas, extras) y la comparación con los meses anteriores. Cada jefe ve solo a su equipo |
| Informe por persona | Elige trabajador y mes → **Ver informe**: su calendario y el detalle de cada día. **Descargar Excel** o **Imprimir o guardar PDF** |
| Exportar → Exportar registro | **Descargar Excel** del registro de asistencia de un rango de fechas |
| Exportar → Planilla de pagos | **Descargar planilla de pagos** del mes (la misma de la Calculadora) |
| Exportar → Respaldo por correo | Cada mes se envía solo el Excel al correo indicado; **Enviar respaldo** lo manda ahora. Necesita el correo del sitio configurado (pendiente) |
| Exportar → Copia en la nube | Guarda cada Excel en Google Drive por medio de Make. La dirección del webhook es secreta |

La planilla oficial (PLAME y demás) la sigue haciendo el contador; el sistema le entrega la pre-planilla con las horas.

## Administración

Estas pantallas las usa RR.HH. o el administrador para preparar el sistema y mantenerlo al día.

**Perfiles** (las personas)

- **+ Nuevo trabajador:** nombre, DNI, cumpleaños, teléfono, horario, jefe que aprueba y honorario mensual. Con el cumpleaños registrado, la persona puede activar su celular sola en el taller.
- La **ficha** de cada persona tiene pestañas: **Resumen**, **Asistencia**, **Pagos y recibos**, **Celular** y **Cambios**. El DNI se ve con **Mostrar**, y eso queda en la Auditoría.
- Si un jefe o alguien de RR.HH. también marca, se enlaza su perfil con su usuario del panel, para que no pueda aprobar sus propias marcas.
- **Cesar** a una persona desactiva su celular y su llave; su historial se conserva.

**Horarios:** nombre, días, hora de entrada y salida de cada día, minutos de refrigerio y tolerancia de llegada. El horario base es 9:00–19:30. Cada cambio pide un motivo.

**Feriados:** **Cargar los feriados** del año (revisarlos contra el calendario oficial) y **Agregar** los días no laborables propios de la empresa.

**Ausencias:** registrar vacaciones, descanso médico, licencia, permiso u otra ausencia justificada, desde y hasta qué día, con una nota. Esos días salen como *Ausencia justificada* en Hoy y en la app de la persona. Se pueden **Anular** con un motivo.

**Seguridad y reglas** (6 pestañas; lo cambiado se resalta y avisa si sales sin guardar)

| Pestaña | Qué se configura |
| --- | --- |
| Presencia en el taller | Cómo se prueba que están en el taller: *WiFi del taller o QR* (recomendado), *WiFi del taller y QR*, *Solo QR* o *Solo WiFi*. Cada cuánto cambia el QR. Las redes del WiFi del taller (se agregan conectado a ese WiFi). Las pantallas del QR activas |
| Reglas de horas | Minutos de comida que se descuentan si no la marcan (60), desde cuándo cuenta la tardanza y las horas a partir de las que una jornada queda por revisar (16) |
| Avisos | Avisos por correo (y WhatsApp, opcional): no hay entrada a cierta hora, ausencia, refrigerio largo, salida temporal larga y "No marcaste tu salida" (20:00). Empiezan apagados |
| Avisos de campaña | Un recordatorio en la app antes de marcar, entre dos fechas y a partir de una hora (ej. temporada escolar) |
| PIN de ingreso | Si el PIN está permitido y cuántos números tiene (6) |
| Empresa y datos personales | Razón social, RUC y domicilio, y el texto del aviso de datos personales (Ley 29733). Si se cambia el texto, todos lo vuelven a aceptar al marcar |

Arriba de esta pantalla está **Estado del sistema**: si dice *✔ todo en orden*, el cron, la base de datos y la configuración funcionan.

**Delegar permisos:** **+ Delegar a un trabajador** le da a una persona (con correo) acceso solo a lo que se elija, por ejemplo aprobar a su equipo. Le llega un correo para crear su contraseña. **Quitar todos** se lo retira.

**Auditoría:** el historial de todo lo que se hizo en el sistema (aprobaciones, días editados, cambios de tarifas, llaves activadas, DNI mostrados), con filtros por fechas, persona, tipo y quién lo hizo. Se descarga en CSV.

## La pantalla del QR en la entrada

Una tablet o computadora en la entrada muestra un QR que cambia cada 30 minutos y un código de 6 números. Sirve para marcar a quien no está conectado al WiFi del taller.

**Activarla** (una sola vez por equipo)

1. En ese equipo, entra a `asistencia.pekokis.com/wp-login.php` con un usuario administrador o de RR.HH.
2. Abre `asistencia.pekokis.com/pantalla/`.
3. Ponle un nombre (viene *Pantalla de la entrada*) y toca **Usar este equipo como pantalla**.
4. Aparecen el QR y el código. Ya se puede cerrar la sesión: la pantalla sigue funcionando.

Las pantallas activas se ven, y se desactivan, en **Seguridad y reglas → Presencia en el taller**. Si una deja de conectarse, el Dashboard lo avisa.

**Dejarla fija**

| Equipo | Qué hacer |
| --- | --- |
| Tablet Android | Ajustes → Pantalla → tiempo de espera al máximo (o *Pantalla activa* mientras carga, en Opciones de desarrollador). Ajustes → Seguridad → **Fijar pantalla** y fijar Chrome con la página abierta |
| iPad | Ajustes → Pantalla y brillo → Bloqueo automático: **Nunca**. Ajustes → Accesibilidad → **Acceso guiado**; con la página abierta en Safari, tres toques al botón lateral |
| Computadora | Chrome con la página abierta → **F11** (pantalla completa). En Windows: Energía → apagar la pantalla: **Nunca** |

En todos los casos: enchufada, con el brillo alto y con internet. No borres los datos del navegador en ese equipo; si se borran, hay que activarla de nuevo.

## Problemas frecuentes

| Qué pasa | Qué hacer |
| --- | --- |
| *Para marcar tienes que estar en el taller* | Conectarse al WiFi del taller y tocar **Ya me conecté: revisar otra vez**, o **Escanear el QR** de la pantalla (en iPhone, el código de 6 números) |
| *Este navegador no sirve* o no pide la huella | Abrir en Chrome (Android) o Safari (iPhone) y activar el bloqueo de pantalla del celular |
| Cambió o perdió el celular | En el celular nuevo, dentro del taller: **Cambié de celular**. Si no tiene su cumpleaños registrado, RR.HH. genera un enlace de activación nuevo |
| El enlace de activación no funciona | Vence en 24 horas y sirve una vez. RR.HH. genera otro |
| Olvidó marcar | **¿Olvidaste marcar algo?** el mismo día, o **Mis horas → el día → Pedir corrección** para días anteriores. Gerencia lo aprueba en Solicitudes |
| No marcó la salida ayer | La app lo avisa al abrirla: **Salí ayer y olvidé marcar** |
| *Registro enviado* y no puede entrar | Falta la aprobación en **Celulares y llaves** |
| Otra persona quiere usar el mismo celular | No se puede: un celular, una persona. El dueño anterior pide **Liberar este celular** y gerencia lo aprueba |
| Gerencia no puede entrar al panel | Tras 5 intentos fallidos, esperar 15 minutos. Si olvidó la contraseña, un administrador la cambia en Usuarios |
| La pantalla del QR dice *Pantalla sin activar* | Volver a activarla (ver *La pantalla del QR*) |
| Algo no cuadra en las horas | Revisar el día en **Registros y revisión** y corregirlo con **Editar** (pide motivo). El pago se recalcula solo |

**Pendiente de configurar:** el correo del sitio (SMTP). Hasta entonces no salen los correos de contraseña, avisos ni el respaldo mensual.
