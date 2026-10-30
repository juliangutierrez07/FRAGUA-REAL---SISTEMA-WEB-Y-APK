<?php

/**
 * ACTUALIZADO (28/09/2026): estos dos valores ya viven de verdad en la tabla
 * `configuraciones`, editable por el Administrador vía ConfiguracionController
 * (GET/PUT /configuraciones) — ver app/Models/Configuracion.php. Este archivo ahora es
 * solo el valor de RESPALDO (fallback) que usan Configuracion::obtenerNumerico() en
 * CatalogoController/PedidoController/ReservaController cuando todavía no existe la fila
 * en la tabla (por ejemplo, justo después de `migrate` sin haber corrido el seeder).
 *
 * Importante (corrección de seguridad frente al documento original): el Controller SIEMPRE
 * lee estos valores del servidor (tabla `configuraciones`, con este archivo como respaldo),
 * nunca del body que envía el Cliente — de lo contrario cualquier request podría fijar su
 * propio valor_domicilio o deposito_valor.
 */
return [
    // RN business: valor de domicilio fijo $2.000 COP, editable por el Administrador.
    'valor_domicilio_default' => (float) env('FRAGUA_REAL_VALOR_DOMICILIO', 2000),

    // Depósito de reserva de mesa (RF-M05-028, RN-M05-14): valor inicial oficial $20.000 COP
    // (confirmado por el usuario el 06/10/2026), editable por el Administrador.
    'deposito_reserva_default' => (float) env('FRAGUA_REAL_DEPOSITO_RESERVA', 20000),

    // Fase 5, tarea 1 (RF-M06-009/010): datos del negocio que encabezan el comprobante de pago.
    // Nombre, dirección y WhatsApp son los que ya muestra el sitio; el NIT se pone en el .env del
    // servidor (FRAGUA_REAL_NIT) y, mientras esté vacío, el comprobante no lo imprime.
    'negocio' => [
        'nombre' => env('FRAGUA_REAL_NOMBRE', 'Fragua Real Burger'),
        'nit' => env('FRAGUA_REAL_NIT', ''),
        'direccion' => env('FRAGUA_REAL_DIRECCION', 'Esquina frente al malecón turístico, etapa 1'),
        'telefono' => env('FRAGUA_REAL_TELEFONO', '322 725 9965'),
        // 29/10/2026: el municipio, debajo de la dirección en el comprobante.
        'ciudad' => env('FRAGUA_REAL_CIUDAD', 'San José del Fragua'),
        // 29/10/2026: el eslogan oficial, en el comprobante y en el pie de los correos.
        'eslogan' => env('FRAGUA_REAL_ESLOGAN', 'Donde la vida sabe mejor'),
    ],

    // RF-M01-012/013: base del enlace que RecuperarPasswordNotification arma hacia
    // /restablecer-password?email=&token= (ver RestablecerPasswordView.vue, que ya sabe
    // precargar esos dos query params). Default = puerto del servidor de Vite en este
    // proyecto; solo hace falta FRONTEND_URL en .env si el frontend corre en otro puerto/host.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // Fase 1, tarea 4: cuánto tiempo ocupa una reserva su mesa, para no aceptar dos reservas que
    // se crucen. M05 no fija este valor (el plan lo deja para "ventana de reserva configurable",
    // Fase 4); mientras tanto es un supuesto de trabajo de 2 horas.
    'duracion_reserva_minutos' => (int) env('FRAGUA_REAL_DURACION_RESERVA_MINUTOS', 120),

    // Fase 2, tarea 4 — M03, sección 9 ("Tipos y categorías"): categorías V1 de compras y gastos,
    // simples y con "Otros". Viven aquí para poder ampliarlas sin rediseñar nada (RNF-M03-12).
    'categorias_compras_gastos' => [
        'compra' => ['Alimentos', 'Bebidas', 'Empaques', 'Insumos', 'Otros'],
        'gasto' => ['Transporte', 'Limpieza', 'Servicios', 'Mantenimiento', 'Papelería', 'Otros'],
    ],

    // RF-M03-07, RNF-M03-06: soporte de una compra o gasto — tamaño máximo en KB.
    'soporte_max_kb' => (int) env('FRAGUA_REAL_SOPORTE_MAX_KB', 5120),

    // Fase 7, tarea 3 (RF-M04-004): peso máximo de la foto de un producto al subirla. El servidor
    // la reduce después (900 px, WebP), así que en el menú pesa mucho menos.
    'foto_producto_max_kb' => (int) env('FRAGUA_REAL_FOTO_PRODUCTO_MAX_KB', 5120),

    // Fase 4, tarea 2 (RF-M05-017/026, RN-M05-07/08/13, decisión D6 del 12/10/2026): valores
    // iniciales de las reservas. El Administrador los cambia en Configuración (tabla
    // `configuraciones`); estos solo se usan si esa fila aún no existe.
    // Cierre automático de la caja (27/10/2026, RF-M06-039): valores iniciales; el Administrador los cambia
    // en Configuración (tabla `configuraciones`).
    'caja' => [
        'hora_cierre' => env('FRAGUA_REAL_CAJA_HORA_CIERRE', '23:30'),
        'tolerancia_minutos' => (int) env('FRAGUA_REAL_CAJA_TOLERANCIA_MINUTOS', 60),
    ],

    'reservas' => [
        'tolerancia_minutos' => (int) env('FRAGUA_REAL_RESERVA_TOLERANCIA_MINUTOS', 15),
        'recordatorio_minutos' => (int) env('FRAGUA_REAL_RESERVA_RECORDATORIO_MINUTOS', 120),
        'ventana_reservada_minutos' => (int) env('FRAGUA_REAL_RESERVA_VENTANA_MINUTOS', 60),
        // Corrección (19/10/2026, decisión del negocio): las reservas son de 4:00 p. m. a 11:00 p. m.
        // (hora de Colombia, la última a las 11:00 en punto) y se hacen con al menos 3 horas de
        // anticipación. Aplica a la web y a las que registra el personal por teléfono.
        'hora_inicio' => env('FRAGUA_REAL_RESERVA_HORA_INICIO', '16:00'),
        'hora_fin' => env('FRAGUA_REAL_RESERVA_HORA_FIN', '23:00'),
        'anticipacion_minutos' => (int) env('FRAGUA_REAL_RESERVA_ANTICIPACION_MINUTOS', 180),
    ],

    // Fase 3, tarea 4 (RF-M01-010, RNF-M01-005, decisión de seguridad): política de las sesiones.
    // Una sesión se cierra sola si pasa este tiempo sin usarse (inactividad) o, aunque se use
    // todos los días, al cumplir la duración máxima; después hay que volver a iniciar sesión.
    // Valores iniciales propuestos: 7 días sin uso y 30 días como máximo. Se cambian en el .env.
    'sesiones' => [
        'inactividad_minutos' => (int) env('FRAGUA_REAL_SESION_INACTIVIDAD_MINUTOS', 60 * 24 * 7),
        'duracion_maxima_minutos' => (int) env('FRAGUA_REAL_SESION_DURACION_MAXIMA_MINUTOS', 60 * 24 * 30),
    ],

    // Fase 6, tarea 1 (RF-M06-006, decisión D4 del 13/10/2026): pasarela de pago en línea.
    // 'wompi' manda al Cliente al checkout de Wompi (Nequi, botón Bancolombia y lo demás que Wompi
    // ofrezca) y el pago se confirma solo con el evento firmado. 'manual' es el respaldo: el pago
    // queda "en revisión" y lo aprueba el Mesero o el Administrador, como antes. Si se pide 'wompi'
    // pero faltan llaves en config/services.php, el sistema sigue en manual (no se rompe el cobro).
    'pasarela' => [
        'proveedor' => env('PASARELA_PROVEEDOR', 'manual'),
        // Cuánto vale el enlace del checkout. Pasado este tiempo, un pago que nunca llegó a la
        // pasarela se da por abandonado y el Cliente puede intentar de nuevo.
        'minutos_checkout' => (int) env('PASARELA_MINUTOS_CHECKOUT', 30),
        // Endurecimiento: un evento firmado más viejo que esto no se aplica (podría ser uno
        // capturado y reenviado). No se pierde nada: la conciliación pregunta el estado a Wompi.
        'eventos_max_horas' => (int) env('PASARELA_EVENTOS_MAX_HORAS', 72),
    ],

    // Seguridad (21/10/2026, RN-USR-23/24): contraseña segura para toda cuenta nueva o contraseña nueva
    // (registro del Cliente, cuentas que crea el Administrador o el SuperAdmin, cambio y recuperación) y
    // bloqueo temporal del inicio de sesión tras varios intentos fallidos.
    'seguridad' => [
        'clave_minimo' => (int) env('FRAGUA_REAL_CLAVE_MINIMO', 8),
        // Al menos una mayúscula, una minúscula y un número.
        'clave_mezcla' => (bool) env('FRAGUA_REAL_CLAVE_MEZCLA', true),
        // Rechaza las contraseñas que aparecen en filtraciones conocidas (servicio «Have I Been Pwned»;
        // solo se envían los 5 primeros caracteres del hash, nunca la contraseña). Si el servicio no
        // responde, no se bloquea el registro. Por defecto, solo en producción.
        'clave_filtradas' => (bool) env('FRAGUA_REAL_CLAVE_FILTRADAS', env('APP_ENV') === 'production'),
        // Intentos fallidos seguidos de una misma cuenta (correo o usuario) antes de bloquearla.
        'intentos_cuenta' => (int) env('FRAGUA_REAL_LOGIN_INTENTOS_CUENTA', 5),
        // Intentos fallidos desde una misma IP (aunque pruebe cuentas distintas).
        'intentos_ip' => (int) env('FRAGUA_REAL_LOGIN_INTENTOS_IP', 20),
        // Minutos que dura el bloqueo (y la ventana en la que se cuentan los intentos).
        'bloqueo_minutos' => (int) env('FRAGUA_REAL_LOGIN_BLOQUEO_MINUTOS', 15),
    ],

    // Seguridad (28/10/2026, RNF-TRANS-045, RN-PED-36): límites de peticiones de las rutas públicas
    // (App\Support\Seguridad\LimitesDePeticiones) y topes de los pedidos de la web y del QR
    // (App\Support\Seguridad\LimitePedidosPublicos, StorePedidoRequest).
    'limites' => [
        'login_cuenta_minuto' => (int) env('FRAGUA_REAL_LIMITE_LOGIN_CUENTA', 10),
        'login_ip_minuto' => (int) env('FRAGUA_REAL_LIMITE_LOGIN_IP', 40),
        'registro_ip_hora' => (int) env('FRAGUA_REAL_LIMITE_REGISTRO_IP_HORA', 20),
        'pedidos_ip_minuto' => (int) env('FRAGUA_REAL_LIMITE_PEDIDOS_IP_MINUTO', 10),
        'pedidos_ip_hora' => (int) env('FRAGUA_REAL_LIMITE_PEDIDOS_IP_HORA', 60),
        'pedidos_comprador_hora' => (int) env('FRAGUA_REAL_LIMITE_PEDIDOS_COMPRADOR_HORA', 10),
        'pendientes_por_comprador' => (int) env('FRAGUA_REAL_PEDIDOS_PENDIENTES_COMPRADOR', 3),
        'pendientes_por_mesa' => (int) env('FRAGUA_REAL_PEDIDOS_PENDIENTES_MESA', 4),
        'lineas_por_pedido' => 30,
        'unidades_por_linea' => 20,
    ],

    // M11 (21/10/2026): notificaciones del sistema (la campana del Mesero y del Administrador).
    // Horas en hora de Colombia ("HH:MM"). Los avisos se borran solos después de 'dias_guardadas'.
    // M11 — Avisos push del personal (28/10/2026, RF-M11-013): llaves VAPID (php artisan push:generar-llaves).
    // Sin ellas la campana sigue funcionando igual, sin avisos push. `asunto`: un correo de contacto
    // («mailto:…») que los servicios push usan si hay un problema con los envíos.
    'push' => [
        'publica' => env('FRAGUA_REAL_PUSH_PUBLICA', ''),
        'privada' => env('FRAGUA_REAL_PUSH_PRIVADA', ''),
        'asunto' => env('FRAGUA_REAL_PUSH_ASUNTO', 'mailto:'.env('MAIL_FROM_ADDRESS', 'soporte@fraguareal.com')),
    ],

    'notificaciones' => [
        // «La reserva empieza en N minutos».
        'reserva_proxima_minutos' => (int) env('FRAGUA_REAL_NOTIF_RESERVA_PROXIMA_MINUTOS', 30),
        // «La caja sigue abierta» sale a la hora de cierre de la caja ('caja.hora_cierre', 27/10/2026).
        // Desde esta hora se avisa si falta el cierre diario de ayer.
        'cierre_pendiente_hora' => env('FRAGUA_REAL_NOTIF_CIERRE_PENDIENTE_HORA', '10:00'),
        // A esta hora se corre la revisión antes de producción y se avisa si tiene errores.
        'revision_hora' => env('FRAGUA_REAL_NOTIF_REVISION_HORA', '07:00'),
        'dias_guardadas' => (int) env('FRAGUA_REAL_NOTIF_DIAS', 30),
    ],

    // M12 — Impresoras (24/10/2026). Un equipo está «en línea» si mandó su señal en estos minutos (la
    // manda cada minuto). Con la caja abierta, si un equipo que imprime comandas lleva más de
    // 'minutos_desconectado' sin señal, la campana avisa.
    'impresion' => [
        'minutos_en_linea' => (int) env('FRAGUA_REAL_IMPRESION_MINUTOS_EN_LINEA', 3),
        'minutos_desconectado' => (int) env('FRAGUA_REAL_IMPRESION_MINUTOS_DESCONECTADO', 10),
        'minutos_codigo' => (int) env('FRAGUA_REAL_IMPRESION_MINUTOS_CODIGO', 30),
    ],
];
