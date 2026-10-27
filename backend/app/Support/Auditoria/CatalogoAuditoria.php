<?php

namespace App\Support\Auditoria;

use Illuminate\Support\Str;

/**
 * M01 (RF-M01-017/025, HU-M01-020), M00 (RNF-TRANS-021) — Fase 3, tarea 1 (12/10/2026).
 *
 * Catálogo de las acciones que se registran en `auditorias`: el nombre técnico que guarda cada
 * módulo ("anular_pago"), el texto que ve el Administrador ("Anulación de pago") y el grupo al
 * que pertenece, para poder filtrar la consulta por tema (p. ej. solo eventos de seguridad).
 *
 * Si un módulo registra una acción que todavía no está aquí, la consulta la muestra igual
 * (con el nombre técnico legible y en el grupo "Otros"): agregarla aquí solo mejora el texto.
 */
final class CatalogoAuditoria
{
    public const CATEGORIAS = [
        'seguridad' => 'Seguridad y sesiones',
        'usuarios' => 'Usuarios',
        'pedidos' => 'Pedidos',
        'caja' => 'Caja y pagos',
        'compras' => 'Compras, gastos y cierre diario',
        'catalogo' => 'Menú y catálogo',
        'mesas' => 'Mesas y reservas',
        'reportes' => 'Reportes',
        'configuracion' => 'Configuración',
        'otros' => 'Otros',
    ];

    /** @var array<string, array{0: string, 1: string}> acción => [texto, categoría] */
    public const ACCIONES = [
        // Seguridad y sesiones (Fase 3, tarea 1: RF-M01-017, RF-M01-024)
        'inicio_sesion' => ['Inicio de sesión', 'seguridad'],
        'inicio_sesion_fallido' => ['Intento de inicio de sesión fallido', 'seguridad'],
        // Seguridad (21/10/2026)
        'bloqueo_inicio_sesion' => ['Cuenta bloqueada por intentos fallidos', 'seguridad'],
        'inicio_sesion_bloqueado' => ['Intento de inicio de sesión durante un bloqueo', 'seguridad'],
        'cierre_sesion' => ['Cierre de sesión', 'seguridad'],
        'cierre_sesion_todos' => ['Cierre de sesión en todos los dispositivos', 'seguridad'],
        'cambiar_contrasena' => ['Cambio de contraseña', 'seguridad'],
        'solicitar_recuperacion' => ['Solicitud de recuperación de contraseña', 'seguridad'],
        'restablecer_contrasena' => ['Restablecimiento de contraseña', 'seguridad'],
        'restablecer_contrasena_fallido' => ['Intento fallido de restablecer la contraseña', 'seguridad'],
        'revocar_sesiones' => ['Revocación de sesiones de un usuario', 'seguridad'],
        'revocar_sesion' => ['Cierre de una sesión de un usuario por el Administrador', 'seguridad'],
        'cerrar_sesion_dispositivo' => ['Cierre de una sesión propia en otro dispositivo', 'seguridad'],
        'cerrar_otras_sesiones' => ['Cierre de las demás sesiones propias', 'seguridad'],

        // Usuarios
        'registro_cliente' => ['Registro de cliente', 'usuarios'],
        'crear_usuario' => ['Alta de usuario del personal', 'usuarios'],
        'editar_usuario' => ['Edición de datos de usuario', 'usuarios'],
        'cambiar_rol' => ['Cambio de rol de usuario', 'usuarios'],
        // M09 (19/10/2026)
        'crear_superadmin' => ['Alta de la cuenta SuperAdmin (en el servidor)', 'usuarios'],
        'asignar_superadmin' => ['Cuenta convertida en SuperAdmin (en el servidor)', 'usuarios'],
        'cambiar_menus' => ['Cambio de los menús de una cuenta', 'usuarios'],
        // M10 (20/10/2026)
        'crear_soporte' => ['Alta de la cuenta de Soporte (en el servidor)', 'usuarios'],
        'actualizar_soporte' => ['Cambio de la cuenta de Soporte (en el servidor)', 'usuarios'],
        'consultar_documento' => ['Consulta de un comprobante o un cierre por Soporte (no cuenta como impresión)', 'seguridad'],
        'cambiar_permiso_gastos' => ['Cambio de permiso de compras y gastos', 'usuarios'],
        'activar_usuario' => ['Activación de usuario', 'usuarios'],
        'desactivar_usuario' => ['Desactivación de usuario', 'usuarios'],
        'eliminar_usuario' => ['Usuario eliminado', 'usuarios'],
        'archivar_usuario' => ['Usuario eliminado (se guarda su historial)', 'usuarios'],
        'restaurar_usuario' => ['Usuario restaurado', 'usuarios'],
        'actualizar_perfil' => ['Actualización del perfil propio', 'usuarios'],

        // Pedidos
        'modificar_pedido' => ['Modificación de pedido', 'pedidos'],
        // Decisión del 22/10/2026 (RN-M06-15)
        'reabrir_pedido' => ['Pedido reabierto para cobrar el saldo (pago anulado)', 'pedidos'],
        'cancelar_pedido' => ['Cancelación de pedido', 'pedidos'],
        'aplicar_descuento_manual' => ['Descuento manual aplicado', 'pedidos'],
        'quitar_descuento_manual' => ['Descuento manual quitado', 'pedidos'],
        'enviar_aviso_pedido' => ['Aviso de pedido enviado al cliente', 'pedidos'],
        'falla_aviso_pedido' => ['Falla al enviar un aviso de pedido', 'pedidos'],

        // Caja y pagos
        'abrir_caja' => ['Apertura de caja', 'caja'],
        'cerrar_caja' => ['Cierre de caja', 'caja'],
        'anular_pago' => ['Anulación de pago', 'caja'],
        'reembolsar_pago' => ['Reembolso de pago', 'caja'],
        'anular_comprobante' => ['Comprobante de pago anulado', 'caja'],
        'imprimir_comprobante' => ['Impresión o reimpresión de comprobante', 'caja'],
        // M12 — Impresoras (24/10/2026).
        'registrar_equipo_impresion' => ['Equipo de impresión registrado', 'configuracion'],
        'vincular_equipo_impresion' => ['Equipo de impresión vinculado con código', 'configuracion'],
        'editar_equipo_impresion' => ['Cambio en un equipo de impresión', 'configuracion'],
        'quitar_equipo_impresion' => ['Equipo de impresión quitado', 'configuracion'],
        'prueba_impresion' => ['Impresión de prueba', 'configuracion'],
        'editar_formato_impresion' => ['Cambio en el formato de los tickets', 'configuracion'],
        'descartar_comanda' => ['Comanda descartada (no se imprimirá)', 'pedidos'],

        // Compras, gastos y cierre diario
        'registrar_compra_gasto' => ['Registro de compra o gasto', 'compras'],
        'editar_compra_gasto' => ['Corrección de compra o gasto', 'compras'],
        'anular_compra_gasto' => ['Anulación de compra o gasto', 'compras'],
        'adjuntar_soporte_compra_gasto' => ['Soporte adjuntado a compra o gasto', 'compras'],
        'cierre_diario' => ['Cierre diario', 'compras'],
        'ajuste_cierre_diario' => ['Corrección posterior al cierre diario', 'compras'],
        'imprimir_cierre_diario' => ['Impresión o reimpresión del cierre diario (PDF)', 'compras'],

        // Menú y catálogo
        'crear_categoria' => ['Creación de categoría', 'catalogo'],
        'editar_categoria' => ['Edición de categoría', 'catalogo'],
        'crear_producto' => ['Creación de producto', 'catalogo'],
        'editar_producto' => ['Edición de producto', 'catalogo'],
        'eliminar_producto' => ['Producto eliminado', 'catalogo'],
        'archivar_producto' => ['Producto eliminado (se guarda su historial)', 'catalogo'],
        // 27/10/2026 (RF-M04-057 a 060).
        'eliminar_categoria' => ['Categoría eliminada', 'catalogo'],
        'archivar_categoria' => ['Categoría eliminada (se guarda su historial)', 'catalogo'],
        'eliminar_combo' => ['Combo eliminado', 'catalogo'],
        'archivar_combo' => ['Combo eliminado (se guarda su historial)', 'catalogo'],
        'eliminar_extra' => ['Extra eliminado', 'catalogo'],
        'archivar_extra' => ['Extra eliminado (se guarda su historial)', 'catalogo'],
        'eliminar_promocion' => ['Promoción eliminada', 'catalogo'],
        'archivar_promocion' => ['Promoción eliminada (se guarda su historial)', 'catalogo'],
        'restaurar_producto' => ['Producto restaurado', 'catalogo'],
        'cambiar_foto_producto' => ['Cambio de foto de producto', 'catalogo'],
        'crear_variante' => ['Creación de variante', 'catalogo'],
        'editar_variante' => ['Edición de variante', 'catalogo'],
        'crear_extra' => ['Creación de extra', 'catalogo'],
        'editar_extra' => ['Edición de extra', 'catalogo'],
        'crear_combo' => ['Creación de combo', 'catalogo'],
        'editar_combo' => ['Edición de combo', 'catalogo'],
        'desactivar_combo' => ['Desactivación automática de combo', 'catalogo'],
        'crear_promocion' => ['Creación de promoción', 'catalogo'],
        'editar_promocion' => ['Edición de promoción', 'catalogo'],
        'cambiar_disponibilidad' => ['Cambio de disponibilidad (agotado)', 'catalogo'],

        // Mesas y reservas
        'crear_mesa' => ['Creación de mesa', 'mesas'],
        'editar_mesa' => ['Cambio de nombre de mesa', 'mesas'],
        'activar_mesa' => ['Activación de mesa', 'mesas'],
        'desactivar_mesa' => ['Desactivación de mesa', 'mesas'],
        'eliminar_mesa' => ['Mesa eliminada', 'mesas'],
        'archivar_mesa' => ['Mesa eliminada (se guarda su historial)', 'mesas'],
        'restaurar_mesa' => ['Mesa restaurada', 'mesas'],
        'regenerar_qr_mesa' => ['QR de mesa regenerado', 'mesas'],
        'liberar_mesa_forzada' => ['Liberación forzada de mesa', 'mesas'],
        'crear_reserva_manual' => ['Reserva registrada por el personal', 'mesas'],
        'confirmar_deposito_reserva' => ['Depósito de reserva confirmado', 'mesas'],
        'rechazar_deposito_reserva' => ['Depósito de reserva rechazado', 'mesas'],
        'registrar_deposito_reserva' => ['Depósito de reserva registrado', 'mesas'],
        'confirmar_llegada_reserva' => ['Llegada de reserva confirmada', 'mesas'],
        'abonar_deposito_reserva' => ['Depósito abonado a la cuenta', 'mesas'],
        'ajustar_abono_deposito' => ['Ajuste del abono del depósito', 'mesas'],
        'cancelar_reserva' => ['Cancelación de reserva', 'mesas'],
        'marcar_no_show' => ['No-show marcado por el personal', 'mesas'],
        'no_show_automatico' => ['No-show automático (venció la tolerancia)', 'mesas'],
        'enviar_aviso_reserva' => ['Aviso de reserva enviado al cliente', 'mesas'],
        'falla_aviso_reserva' => ['Falla al enviar un aviso de reserva', 'mesas'],

        // Reportes
        'exportar_reporte' => ['Exportación de reporte', 'reportes'],
        'descargar_reporte' => ['Descarga de un reporte ya exportado', 'reportes'],

        // Configuración
        'editar_configuracion' => ['Cambio de configuración', 'configuracion'],
        // Pago directo (22/10/2026)
        'editar_pagos_directos' => ['Cambio de las cuentas del pago directo (Nequi, Bancolombia, Bre-B)', 'configuracion'],
        'editar_datos_negocio' => ['Cambio de los datos del negocio', 'configuracion'],
        'cambiar_logo_negocio' => ['Cambio del logo del negocio', 'configuracion'],
        'quitar_logo_negocio' => ['Logo del negocio quitado', 'configuracion'],
    ];

    public const ENTIDADES = [
        'User' => 'Usuario',
        'Pedido' => 'Pedido',
        'Pago' => 'Pago',
        'CajaTurno' => 'Turno de caja',
        'CompraGasto' => 'Compra o gasto',
        'CierreDiario' => 'Cierre diario',
        'Categoria' => 'Categoría',
        'Producto' => 'Producto',
        'Variante' => 'Variante',
        'Extra' => 'Extra',
        'Combo' => 'Combo',
        'Promocion' => 'Promoción',
        'Mesa' => 'Mesa',
        'Reserva' => 'Reserva',
        'Reporte' => 'Reporte',
        'Configuracion' => 'Configuración',
    ];

    public static function texto(string $accion): string
    {
        return self::ACCIONES[$accion][0] ?? Str::ucfirst(str_replace('_', ' ', $accion));
    }

    public static function categoria(string $accion): string
    {
        return self::ACCIONES[$accion][1] ?? 'otros';
    }

    public static function entidad(string $entidad): string
    {
        return self::ENTIDADES[$entidad] ?? $entidad;
    }

    /** @return list<string> acciones conocidas de una categoría */
    public static function accionesDe(string $categoria): array
    {
        return array_keys(array_filter(self::ACCIONES, fn (array $a) => $a[1] === $categoria));
    }
}
